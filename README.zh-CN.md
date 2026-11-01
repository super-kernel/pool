<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Pool

`super-kernel/pool` 为调用方持有的 Redis、MySQL、HTTP、cURL、WebSocket 以及自定义适配器提供连接池基础设施。它需要 `ext-swoole >=6.2.3 <6.3`；线程后端还需要 ZTS 版本的 PHP，以及支持线程的 Swoole 构建。不附带任何协议适配器。

## 契约与归属

| API                                      | 职责                                                           |
|------------------------------------------|----------------------------------------------------------------|
| `Contract\ConnectionInterface`           | 建立、检查、重连和关闭一个由调用方定义的连接。                 |
| `Contract\ConnectionFactoryInterface<T>` | 创建一个新的连接包装器。                                       |
| `Contract\ChannelInterface<T>`           | 一个有界的先进先出队列，带有明确的超时和关闭行为。             |
| `Config\PoolOption<T>`                   | 具体的选项类，包含固定的上限、连接工厂、容量协调器和各项策略。 |
| `Contract\CapacityInterface`             | 预留和释放容量，但不传递连接本身。                             |
| `Contract\PoolInterface<T>`              | 获取、归还、丢弃、维护和关闭连接。                             |
| `Contract\FrequencyInterface`            | 根据借用频率选择完全补充或在低需求时收缩。                     |
| `Contract\MaintenanceSchedulerInterface` | 显式启动和停止维护触发器。                                     |

`PoolOption` 是本包提供的 final readonly 选项类。使用方直接构造这个类来配置连接池。它不是 Config 的 `Configuration` 来源。本包不依赖 Config、DI、Attribute、容器、Framework 或 Server，也没有默认的服务绑定。连接设置、凭据和 I/O 超时属于调用方的工厂或适配器。在 DI 应用中，请在所属的驱动或应用提供者中组装连接池，再把得到的类型化服务注入给使用方。

`ConnectionFactoryInterface::create()` 创建一个新的包装器，实现 `connect()`、`isHealthy()`、`reconnect()` 和 `close()`。Pool 在第一次借出之前调用 `connect()`。认证、健康检查、清理部分打开的连接、重置事务/会话以及重连行为都由适配器负责。当前的借用方可以显式地对其适配器调用 `reconnect()`；Pool 不会重放请求，也不会重连已借出的连接。健康检查结果为 false 时，会淘汰该包装器，并允许通过工厂创建替代者。

自定义的容量协调器实现 `CapacityInterface::reserve($timeout, $owner)` 和 `release($reservation, $owner)`。自定义的连接池实现通过 `PoolInterface` 提供 `maintain()`，`SwooleTimerScheduler` 使用的就是这个接口。

## 容量与后端选择

| 后端                         | 共享范围                | 空闲连接                   | 组装方式                                                 |
|------------------------------|-------------------------|----------------------------|----------------------------------------------------------|
| `Capacity\CoroutineCapacity` | 同一个进程/线程内的协程 | 在本地保留并复用           | 每个连接池一个独占的后端；活动操作需要在协程中进行。     |
| `Capacity\ProcessCapacity`   | 相关联的 fork 进程      | 归还的客户端由其所有者关闭 | 在 fork 之前创建后端，在 fork 之后创建各自的 Pool/工厂。 |
| `Capacity\ThreadCapacity`    | 原生 Swoole 线程        | 归还的客户端由其所有者关闭 | 只创建一次共享状态，并在每个线程中挂接它。               |

`CoroutineCapacity($capacity, $channel)` 默认使用 `Channel\SwooleChannel`；也可以传入一个初始为空、独占的 `ChannelInterface<bool>` 作为替代。通道保持先进先出的载荷身份，对于一般的通道使用方也包括 null 和 false。写满时的写入会被立即拒绝；超时为零的读取从不等待。原生通道是延迟构造的，因此构造或关闭一个未使用的协程连接池不需要协程。

进程间协调使用 Swoole 共享内存，线程间协调使用原生线程映射；共享的只有容量预留，PHP 客户端对象和 socket 仍归创建它们的连接池所有。等待容量的一方以单调时钟为截止依据进行轮询，每次最多暂停 1 毫秒，在协程中协作式让出，否则休眠。共享的等待方没有先进先出的公平性保证。卡住的原生互斥锁可能让等待方超出其容量超时。

共享后端在所有参与方之间限制连接在创建、使用和清理期间的数量。它们会淘汰归还的、仅属于所有者本地的客户端；不会把持久的空闲客户端转交给其他进程或线程。传入同一个共享后端，就是有意共享其全局容量和关闭状态；不同的后端则相互隔离。彼此独立的协程容量不会强制执行应用范围的上限。不提供原生连接的传递，也没有集中式的连接代理。

`maxConnections()` 是一个固定的上限，而不是当前空闲连接的数量。设该上限为 `M`、已借出数量为 `B`，静止状态下连接池的剩余容量为 `P = M - B`。就绪的空闲连接数 `I` 可能小于 `P`；缺少的连接会在维护时补充。

| 完成的状态转换                 | 剩余容量 `P` | 就绪空闲连接 `I` |
|--------------------------------|--------------|------------------|
| 借出一个健康的空闲连接         | -1           | -1               |
| 归还一个可复用的连接           | +1           | +1               |
| 归还并成功销毁一个不可用的连接 | +1           | 不变             |
| 销毁一个不健康的空闲连接       | 不变         | -1               |
| 成功补充一个连接               | 不变         | +1               |

已借出的连接仍会登记，用于计数和归还校验，但维护从不操作它们。空闲、已借出、正在创建、正在检查和正在关闭的连接都计入 `M`，包括清理失败、等待重试的连接。`CapacityInterface::available()` 报告的是可预留的位置，而不是 `I`；进行中的操作也可能暂时占用位置。只有创建成功才会产生一个就绪的连接。

## 组装与借用

所属的驱动或应用提供者用调用方提供的 `$factory` 构造 `PoolOption`：

```php
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Pool;

$options = new PoolOption(
    factory: $factory,
    capacity: new CoroutineCapacity(8),
    maxConnections: 8,
    waitTimeout: 0.5,
);
$pool = new Pool($options);
```

工厂、容量协调器和固定上限是必需的。等待超时默认为 `1.0` 秒，健康检查默认为 `HealthCheckMode::OnBorrowAndReturn`，频率策略默认为连接池本地的 `ConstantFrequency` 实例。如需其他策略，请显式传入 `healthChecks` 和 `frequency` 参数。选项保存固定的值和协作对象的引用；被引用的工厂、容量协调器和频率策略各自保留其运行时状态。

在进行任何连接 I/O 之前，Pool 会校验容量为正数且与上限一致，并校验超时是有限的非负数。构造选项或 Pool 不会启动任何协程、定时器、工作进程或连接。每个使用方组件直接使用 `PoolOption`；不需要选项接口、子类或驱动专属的选项实现。驱动的配置留在其连接工厂中，通用包不会去发现它。

在使用方内部、并处于后端所要求的运行时中：

```php
$connection = $this->pool->acquire();
try {
    // Use the client and restore its transaction/session state before returning it.
} finally {
    $this->pool->release($connection);
}
```

对于已损坏或无法重置的连接，请用 `discard($connection)` 代替 `release($connection)`。每个获取到的对象都必须恰好归还一次，并归还给同一个连接池。连接池会拒绝当前不是从它借出的对象，包括重复归还和来自其他连接池的连接。它在运行健康检查或清理回调之前就会解除借用关系，因此即使清理仍在进行，第二次归还也会被拒绝。在校验和清理期间，容量保持预留。清理失败时，其预留会一直保留，直到之后的某次维护或 `close()` 成功；切勿为了重试清理而再次归还同一个对象。

引用的生命周期由调用方负责：归还连接之前，请停止使用它并恢复其会话状态。没有自动归还，也不会撤销访问权。同一个对象再次被借出后，连接池无法区分前一个借用方的引用和当前借用方的引用。请在应用代码中防止这种过期使用和延迟归还；连接池不会为每次借用签发租约或公开的凭证。

`acquire(null)` 使用配置的超时；为零时尝试立即获取。Pool 只对等待容量计时，连接 I/O 使用适配器自己的超时。容量耗尽时抛出 `PoolTimeoutException`，已关闭时抛出 `PoolClosedException`，等待被取消或归属操作无效时抛出 `PoolException`。创建、建立连接、健康检查和清理都会占用容量预留。原始的获取异常和健康检查异常不会被后续的清理错误覆盖。清理失败会让包装器不再可借，但保留其资源分配和预留。适配器必须支持多次调用 `close()`；一次失败的关闭绝不能让替代连接超出连接上限。

## 进程与线程的归属

每个连接都在同一个进程或线程中，通过其所属的连接池获取和归还。共享后端协调的是容量和关闭，而不是跨进程的归还。应用代码只会拿到连接对象。清理成功时，归还在本地完成，不需要通知队列，也不需要等之后的维护。清理失败会由维护或关闭在本地重试。

对于进程，请在 `Swoole\Process::start()` 之前构造 `new ProcessCapacity($limit)`，并在每个进程中用继承来的后端构造新的 `Pool`。不要使用从父进程继承来的 Pool 或客户端包装器。

对于线程，创建方使用 `ThreadCapacity::create($limit)`，并通过 `Swoole\Thread` 的参数传递 `$backend->state()` 和 `$backend->mutex()`。接收方线程挂接 `new ThreadCapacity($state, $mutex)`，并构造自己的工厂和 Pool。只有原生的共享句柄会跨越边界。

预留没有自动过期，也没有崩溃恢复。如果某个资源所有者死亡，仅靠超时无法安全地回收容量；应用的监管程序必须在确认旧的所有者都已停止之后，淘汰该后端并建立新的一代。本包不会推断进程是否死亡，不会重放请求，也不会抢占仍然存活的预留。

## 健康检查与调度

`Constants\HealthCheckMode` 控制对保留连接的同步健康检查：

| 模式                        | 复用空闲连接之前 | 保留归还的连接之前 |
|-----------------------------|------------------|--------------------|
| `OnBorrow`                  | 是               | 否                 |
| `OnReturn`                  | 否               | 是                 |
| `OnBorrowAndReturn`（默认） | 是               | 是                 |
| `Manual`                    | 否               | 否                 |

共享后端总是关闭归还的客户端，因此不会为保留它们而运行健康检查。复用空闲连接之前，借出时的检查会淘汰不健康的候选，并检查剩余的空闲快照，然后才创建一个替代连接。意外的健康检查错误会向外传播；创建不会无限重试。所有模式都允许显式调用 `maintain($minimumIdle = 0)`。一次维护会重试尚未进行的失败清理，检查空闲资源，并补充到频率策略选定的目标。它从不检查活动的借用方，会跳过重叠的维护，并把进行中的创建和清理计入容量。这个最小值只是尽力而为的下限，在连接被借出时不作保证。共享后端要求空闲最小值为零，因为它们不保留可转交的空闲客户端。

`PoolOption::frequency()` 提供补充和收缩的策略。默认的 `Frequency\ConstantFrequency()` 报告每秒 `1.0` 次借用尝试、低频阈值为 `1.0`，因此维护会补满所有未被借用方或待处理操作占用的容量。低于阈值的速率属于低频；等于阈值不算低频。固定策略会忽略借用事件，例如 `new ConstantFrequency(0.25, 1.0)` 在维护时总是请求收缩。

使用 `Frequency\SmartLowFrequency` 适应实际的需求：

```php
use SuperKernel\Pool\Frequency\SmartLowFrequency;

$options = new PoolOption(
    factory: $factory,
    capacity: new CoroutineCapacity(8),
    maxConnections: 8,
    waitTimeout: 0.5,
    frequency: new SmartLowFrequency(lowFrequencyThreshold: 1.0, windowSeconds: 60.0),
);
$pool = new Pool($options);
```

`recordBorrow()` 在等待容量之前，统计每一次有效的 `acquire()` 尝试，包括之后超时或连接失败的尝试。无效的参数、对已关闭连接池的调用以及来自不同执行上下文的调用都不计入。每个事件给每秒尝试次数的估计值加上 `1 / windowSeconds`。两次事件之间，估计值按 `exp(-elapsedSeconds / windowSeconds)` 衰减，使用单调时间和常量存储。`frequency()` 也会应用衰减，因此即使没有新的借用，活跃度也会下降。速率在 `PHP_FLOAT_MAX` 处饱和，而不会变成无穷大。在第一个观察窗口内，该策略不会报告低频；之后它把衰减后的估计值与配置的阈值比较。这是一个平滑的需求估计，而不是精确的滑动窗口计数。

保留空闲连接的、处于打开状态的连接池，在每次维护开始时评估一次策略。低频模式下，它们淘汰超过 `minimumIdle` 的空闲连接；最小值为零时淘汰所有空闲连接。维护会检查保留的连接，并补充到这个最小值。正常频率下，即使 `minimumIdle` 为零，它也会填满未被占用的容量。两种目标都不会挤占已借出的连接或待处理的操作。归还会恢复其位置，但不会当场创建替代连接；补充由维护完成。清理失败时保留其位置，其余资源仍会尝试清理，并传播第一个错误。之后的每次维护对一个待处理的关闭只重试一次；从不与正在进行的关闭重叠。创建失败会给下一次维护留下真实的缺口。重试成功会先释放容量，然后再继续补充。已关闭的连接池和共享后端会跳过频率评估。归还连接从不评估这个策略。

频率策略不会启动定时器，也不进行连接 I/O。请为每个连接池提供各自的可变策略；常量策略可以共享。自定义策略不得让出协程或进行 I/O。`SmartLowFrequency` 可以选择接收一个不会让出的 `Closure(): float` 时钟，返回有限的单调秒数，便于编写确定性的测试；默认使用 `hrtime()`。时钟失败、返回非有限值或发生回退时，会传播错误，而不会悄悄改变估计值。

可以手动调用 `maintain()`，也可以从应用的事件或任务中，或从任何外部调度器中调用。使用 Swoole 定时器时，请显式创建并启动提供的适配器：

```php
use SuperKernel\Pool\Maintenance\SwooleTimerScheduler;

$scheduler = new SwooleTimerScheduler(
    $pool,
    intervalMilliseconds: 1000,
    onError: static function (\Throwable $error) use ($logger): void {
        $logger->error($error->getMessage());
    },
    minimumIdle: 2,
);
$scheduler->start();
```

进程和线程后端请使用 `minimumIdle: 0`。它们不保留空闲连接，也不需要维护来完成归还。定时器在需要时创建协程，跳过重叠的维护，并把失败报告给必需的错误回调；该回调不得抛出异常。`stop()` 取消之后的维护，并让正在进行的那次完成。连接池关闭后，定时器会在下一次触发时停止；如有需要，请通过显式调用 `close()` 重试关闭时的清理。构造函数从不启动调度或连接 I/O。

## 关闭

`close()` 永久关闭后端，并拒绝等待容量的一方。它会尝试清理本地所有的空闲连接，然后传播第一个错误。活动的借用方在归还时关闭；进行中的创建或健康检查在关闭之后不能再交出连接。每个调用方仍必须把借出的对象归还给其所属的连接池，清理在归还时进行。重复调用 `close()` 会重试失败的清理，已成功关闭的资源不受影响。没有异步的析构清理。请在协程连接池自己的运行时中关闭它，并把停止调度作为所有者生命周期的一部分。
