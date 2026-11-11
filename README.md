<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Pool

`super-kernel/pool` provides a connection pool for any client a caller wraps, such as Redis, MySQL, HTTP, or WebSocket, with a limit that holds within one process, across forked processes, or across Swoole threads.

## Installation

```bash
composer require super-kernel/pool
```

The package needs the Swoole extension `>=6.2.3 <6.3`; the thread backend also needs a ZTS PHP and a Swoole build with thread support. It has no other dependency and works without the container or a build: the caller supplies the connection class and assembles the pool.

## What it provides

- `Pool`, which lends connections with `acquire()` and takes them back with `release()` or `discard()`.
- A limit on all connections, whether idle, borrowed, being created, checked, or closed, with a bounded wait.
- Capacity for the coroutines of one process, for forked processes, or for Swoole threads.
- Health checks when a connection is borrowed, returned, both, or never.
- Maintenance that replaces unhealthy connections and refills or shrinks the idle set by demand, by hand or on a Swoole timer.
- An orderly shutdown that closes idle connections and those returned afterwards.

It does not:

- provide clients or protocol adapters;
- reconnect a borrowed connection or repeat a request;
- reset transactions or sessions before reuse;
- return a connection automatically or revoke a borrower's reference;
- move a connection between processes or threads;
- recover capacity held by a process that died.

## Quick start

Wrap a client in a connection class. This one only counts how many connections were opened. Save it as `src/Pool/CounterConnection.php`:

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

The pool creates connections through a factory. Save it as `src/Pool/CounterConnectionFactory.php`:

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

Borrow a connection twice inside a coroutine. Save the script as `bin/pool.php`:

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

Build the project and run the script:

```bash
skernel build
php bin/pool.php
```

It prints:

```text
The first borrower uses connection 1
The second borrower uses connection 1
```

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `SuperKernel\Pool\Contract\ConnectionInterface` | Interface | Implemented by the caller's connection: `connect()`, `isHealthy()`, `reconnect()`, `close()`. |
| `SuperKernel\Pool\Contract\ConnectionFactoryInterface` | Interface | Implemented by the caller's factory: `create()` returns a new connection. |
| `SuperKernel\Pool\Config\PoolOption` | Class | The pool's factory, capacity, limit, wait timeout, health checks, and frequency policy. |
| `SuperKernel\Pool\Pool` | Class | Lends, takes back, maintains, and closes connections. |
| `SuperKernel\Pool\Contract\PoolInterface` | Interface | The pool's operations, for code that receives a pool. |
| `SuperKernel\Pool\Capacity\CoroutineCapacity` | Class | Capacity for the coroutines of one process or thread. |
| `SuperKernel\Pool\Capacity\ProcessCapacity` | Class | Capacity shared by forked processes. |
| `SuperKernel\Pool\Capacity\ThreadCapacity` | Class | Capacity shared by Swoole threads. |
| `SuperKernel\Pool\Contract\CapacityInterface` | Interface | Implemented by a custom capacity. |
| `SuperKernel\Pool\Constants\HealthCheckMode` | Enum | `OnBorrow`, `OnReturn`, `OnBorrowAndReturn`, or `Manual`. |
| `SuperKernel\Pool\Frequency\ConstantFrequency` | Class | A fixed demand rate for maintenance. |
| `SuperKernel\Pool\Frequency\SmartLowFrequency` | Class | A demand rate measured from borrow attempts. |
| `SuperKernel\Pool\Contract\FrequencyInterface` | Interface | Implemented by a custom demand policy. |
| `SuperKernel\Pool\Maintenance\SwooleTimerScheduler` | Class | Runs maintenance on a Swoole timer. |
| `SuperKernel\Pool\Contract\MaintenanceSchedulerInterface` | Interface | Starts and stops a maintenance trigger. |
| `SuperKernel\Pool\Channel\SwooleChannel` | Class | A bounded first-in, first-out coroutine channel. |
| `SuperKernel\Pool\Contract\ChannelInterface` | Interface | Implemented by a custom channel. |

## Behavior to know

- A pool with `CoroutineCapacity` works only inside a coroutine; outside one, `acquire()` raises `SuperKernel\Pool\Exception\PoolException` with `Connection pool operations require an active Swoole coroutine.` Creating or closing an unused pool needs no coroutine.
- `acquire()` waits up to the timeout, `1.0` second by default and `0` for no wait, then raises `SuperKernel\Pool\Exception\PoolTimeoutException`. Only the wait for capacity is timed; the connection's own I/O keeps its own deadlines.
- Return every connection exactly once to the pool that lent it; a second return or a foreign connection raises `The connection is not currently borrowed from this pool.`
- A pool belongs to the process or thread that created it; using it from another raises `Create each resource-owning pool in its own process or thread.`
- After `close()`, `acquire()` raises `SuperKernel\Pool\Exception\PoolClosedException`, and borrowed connections are closed when they are returned.
- `maxConnections` must equal the capacity's limit, and the timeout must be finite and not negative; otherwise the pool raises `Positive matching capacity and a finite timeout are required.`

## Documentation

The developer documentation covers connections and factories, borrowing and returning, capacity in coroutines, processes, and threads, health checks, maintenance and demand, shutdown, the error messages, and what not to do: [SuperKernel Pool](https://github.com/super-kernel/.github/blob/main/docs/components/pool.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
