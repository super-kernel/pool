<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Pool

`super-kernel/pool` 为调用方包装的任何客户端（例如 Redis、MySQL、HTTP 或 WebSocket）提供连接池，其上限可以在一个进程内、在派生的多个进程之间或在多个 Swoole 线程之间生效。

## 安装

```bash
composer require super-kernel/pool
```

本包需要 `>=6.2.3 <6.3` 的 Swoole 扩展；线程后端还需要 ZTS 版 PHP 和支持线程的 Swoole 构建。它没有其他依赖，无需容器或构建即可使用：由调用方提供连接类并组装连接池。

## 提供的能力

- `Pool`：用 `acquire()` 借出连接，用 `release()` 或 `discard()` 收回连接。
- 对所有连接（空闲的、借出的、正在创建的、正在检查的或正在关闭的）的上限，以及有界的等待。
- 面向单个进程内协程、派生进程或 Swoole 线程的容量。
- 在借出、归还、二者兼有或从不进行的健康检查。
- 维护：替换不健康的连接，并按需求补足或收缩空闲连接，可以手动进行，也可以由 Swoole 定时器进行。
- 有序的关闭：关闭空闲连接以及之后归还的连接。

它不会：

- 提供客户端或协议适配器；
- 重新连接已借出的连接或重放请求；
- 在重用之前重置事务或会话；
- 自动归还连接或撤销借用方的引用；
- 在进程或线程之间转移连接；
- 回收已终止进程所持有的容量。

## 快速开始

把客户端包装在连接类中。这个连接类只统计打开了多少个连接。将它保存为 `src/Pool/CounterConnection.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Pool;

use SuperKernel\Pool\Contract\ConnectionInterface;

final class CounterConnection implements ConnectionInterface
{
    private static int $opened = 0;

    public ?int $id = null;

    public bool $broken = false;

    public function connect(): void
    {
        $this->id = ++self::$opened;
    }

    public function isHealthy(): bool
    {
        return !$this->broken;
    }

    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    public function close(): void
    {
        $this->id = null;
    }
}
```

连接池通过工厂创建连接。将它保存为 `src/Pool/CounterConnectionFactory.php`：

```php
<?php

declare(strict_types=1);

namespace Src\Pool;

use SuperKernel\Pool\Contract\ConnectionFactoryInterface;

/**
 * @implements ConnectionFactoryInterface<CounterConnection>
 */
final class CounterConnectionFactory implements ConnectionFactoryInterface
{
    public int $created = 0;

    public function create(): CounterConnection
    {
        ++$this->created;

        return new CounterConnection();
    }
}
```

在协程中借用连接两次。将脚本保存为 `bin/pool.php`：

```php
<?php

declare(strict_types=1);

use Src\Pool\CounterConnection;
use Src\Pool\CounterConnectionFactory;
use SuperKernel\Pool\Capacity\CoroutineCapacity;
use SuperKernel\Pool\Config\PoolOption;
use SuperKernel\Pool\Pool;

use function Swoole\Coroutine\run;

require dirname(__DIR__) . '/vendor/autoload.php';

run(static function (): void {
    $pool = new Pool(new PoolOption(
        factory: new CounterConnectionFactory(),
        capacity: new CoroutineCapacity(2),
        maxConnections: 2,
        waitTimeout: 0.5,
    ));

    foreach (['first', 'second'] as $borrower) {
        $connection = $pool->acquire();
        assert($connection instanceof CounterConnection);

        try {
            echo "The {$borrower} borrower uses connection {$connection->id}", PHP_EOL;
        } finally {
            $pool->release($connection);
        }
    }

    $pool->close();
});
```

构建项目并运行脚本：

```bash
skernel build
php bin/pool.php
```

它会打印：

```text
The first borrower uses connection 1
The second borrower uses connection 1
```

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `SuperKernel\Pool\Contract\ConnectionInterface` | 接口 | 由调用方的连接实现：`connect()`、`isHealthy()`、`reconnect()`、`close()`。 |
| `SuperKernel\Pool\Contract\ConnectionFactoryInterface` | 接口 | 由调用方的工厂实现：`create()` 返回一个新连接。 |
| `SuperKernel\Pool\Config\PoolOption` | 类 | 连接池的工厂、容量、上限、等待超时、健康检查和频率策略。 |
| `SuperKernel\Pool\Pool` | 类 | 借出、收回、维护和关闭连接。 |
| `SuperKernel\Pool\Contract\PoolInterface` | 接口 | 连接池的操作，供接收连接池的代码使用。 |
| `SuperKernel\Pool\Capacity\CoroutineCapacity` | 类 | 面向单个进程或线程内协程的容量。 |
| `SuperKernel\Pool\Capacity\ProcessCapacity` | 类 | 由派生进程共享的容量。 |
| `SuperKernel\Pool\Capacity\ThreadCapacity` | 类 | 由 Swoole 线程共享的容量。 |
| `SuperKernel\Pool\Contract\CapacityInterface` | 接口 | 由自定义容量实现。 |
| `SuperKernel\Pool\Constants\HealthCheckMode` | 枚举 | `OnBorrow`、`OnReturn`、`OnBorrowAndReturn` 或 `Manual`。 |
| `SuperKernel\Pool\Frequency\ConstantFrequency` | 类 | 用于维护的固定需求速率。 |
| `SuperKernel\Pool\Frequency\SmartLowFrequency` | 类 | 根据借用尝试测得的需求速率。 |
| `SuperKernel\Pool\Contract\FrequencyInterface` | 接口 | 由自定义需求策略实现。 |
| `SuperKernel\Pool\Maintenance\SwooleTimerScheduler` | 类 | 在 Swoole 定时器上运行维护。 |
| `SuperKernel\Pool\Contract\MaintenanceSchedulerInterface` | 接口 | 启动和停止维护触发器。 |
| `SuperKernel\Pool\Channel\SwooleChannel` | 类 | 有界的先进先出协程通道。 |
| `SuperKernel\Pool\Contract\ChannelInterface` | 接口 | 由自定义通道实现。 |

## 需要了解的行为

- 使用 `CoroutineCapacity` 的连接池只能在协程中工作；在协程之外，`acquire()` 会抛出 `SuperKernel\Pool\Exception\PoolException`，消息为 `Connection pool operations require an active Swoole coroutine.` 创建或关闭未使用的连接池不需要协程。
- `acquire()` 最多等待到超时（默认为 `1.0` 秒，`0` 表示不等待），然后抛出 `SuperKernel\Pool\Exception\PoolTimeoutException`。只有等待容量的过程会计时；连接自身的 I/O 保留它自己的期限。
- 每个连接都要恰好归还一次给借出它的连接池；第二次归还或外来的连接会抛出 `The connection is not currently borrowed from this pool.`
- 连接池属于创建它的进程或线程；从其他进程或线程使用它会抛出 `Create each resource-owning pool in its own process or thread.`
- `close()` 之后，`acquire()` 会抛出 `SuperKernel\Pool\Exception\PoolClosedException`，已借出的连接会在归还时关闭。
- `maxConnections` 必须等于容量的上限，超时必须是有限且非负的值；否则连接池会抛出 `Positive matching capacity and a finite timeout are required.`

## 文档

开发者文档介绍了连接与工厂、借用与归还、协程、进程和线程中的容量、健康检查、维护与需求、关闭、错误消息以及不要这样做的事项：[SuperKernel Pool](https://github.com/super-kernel/.github/blob/main/docs/components/pool.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
