# SuperKernel Pool

`super-kernel/pool` provides connection-pool foundations for caller-owned Redis, MySQL, HTTP,
cURL, WebSocket and custom adapters. Runtime requirements are PHP 8.4.27 or later in the 8.4.x series and
`ext-swoole >=6.2.3 <6.3`. The thread backend additionally needs PHP ZTS and a Swoole build
with thread support. Protocol adapters are not bundled.

## Contracts and ownership

| API | Responsibility |
| --- | --- |
| `Contract\ConnectionInterface` | Establish, check, reconnect and close one caller-defined connection. |
| `Contract\ConnectionFactoryInterface<T>` | Create a fresh connection wrapper. |
| `Contract\ChannelInterface<T>` | A bounded FIFO queue with explicit timeout and shutdown behavior. |
| `Config\PoolOption<T>` | Concrete options with fixed limits, connection factory, capacity coordinator and policies. |
| `Contract\CapacityInterface` | Reserve and release capacity without transporting connections. |
| `Contract\PoolInterface<T>` | Acquire, return, discard, maintain and shut down connections. |
| `Contract\FrequencyInterface` | Select full replenishment or low-demand shrinking from borrow frequency. |
| `Contract\MaintenanceSchedulerInterface` | Explicitly start and stop a maintenance trigger. |

`PoolOption` is a final readonly options class supplied by this package. Consumers construct this exact class
to configure their pools. It is not a Config `Configuration` source. There are no Config, DI, Attribute, container, Framework or Server dependencies or default
service bindings. Connection settings, credentials and I/O deadlines belong to the caller's
factory or adapter. In DI applications, assemble pools in the owning driver/application provider
and inject the resulting typed services into consumers.

`ConnectionFactoryInterface::create()` creates a fresh wrapper implementing `connect()`,
`isHealthy()`, `reconnect()` and `close()`. Pool calls `connect()` before the first borrow.
The adapter owns authentication, health checks, cleanup of partially opened connections,
transaction/session reset and reconnection behavior. The current borrower may explicitly
`reconnect()` its adapter; Pool does not replay requests or reconnect a borrowed connection.
A false health result retires the wrapper and allows replacement through the factory.

## Capacity and backend selection

| Backend | Shared scope | Idle connections | Assembly |
| --- | --- | --- | --- |
| `Capacity\CoroutineCapacity` | Coroutines in one process/thread | Retained and reused locally | One exclusive backend per pool; active operations require a coroutine. |
| `Capacity\ProcessCapacity` | Related forked processes | Returned clients are closed by their owner | Create the backend before forking and each Pool/factory after forking. |
| `Capacity\ThreadCapacity` | Native Swoole threads | Returned clients are closed by their owner | Create shared state once and attach it in each thread. |

The coroutine backend queues boolean capacity tokens using `Channel\SwooleChannel`; idle
connections are stored separately. There is no `ConnectionSlot`. An alternative initially empty,
exclusive `ChannelInterface<bool>` can be passed to `CoroutineCapacity($capacity, $channel)`.
The channel preserves FIFO payload identity, including null and false for general channel users.
Full writes are rejected immediately; zero-timeout reads never wait. Native channel construction
is lazy. Constructing or closing an unused coroutine pool does not require a coroutine.

Process coordination uses a Swoole shared table, mutex and atomic shutdown flag. Thread
coordination uses a native thread map and lock. They coordinate internal capacity reservations; PHP
client objects and sockets stay with the pool that created them. Critical sections contain bookkeeping only. Capacity waiters poll
with a monotonic deadline and at most a 1 ms pause, yielding cooperatively inside a coroutine
and sleeping otherwise. Shared waiters have no FIFO fairness guarantee. A stalled native mutex
can delay a waiter beyond its capacity timeout.

Shared backends bound connections during creation, use and cleanup across their participants.
They retire returned owner-local clients; they do not implement a broker that transfers persistent
idle clients to another process or thread. Supplying the same shared backend intentionally shares its global
capacity and shutdown; distinct backends isolate pools. Independent coroutine capacities do not enforce an
application-wide limit. Native connection transport and a centralized connection broker are not implemented.

`maxConnections()` is a fixed upper bound, not the current number of idle connections. If `M` is that bound
and `B` is the borrowed count, the pool's remaining capacity at rest is `P = M - B`. The number of ready
idle connections `I` may be smaller than `P`; missing connections are replenished during maintenance.

| Completed transition | Remaining capacity `P` | Ready idle connections `I` |
| --- | --- | --- |
| Borrow a healthy idle connection | -1 | -1 |
| Return a reusable connection | +1 | +1 |
| Return and successfully destroy an unusable connection | +1 | Unchanged |
| Destroy an unhealthy idle connection | Unchanged | -1 |
| Successfully replenish a connection | Unchanged | +1 |

Borrowed connections remain registered for counting and return validation, but maintenance never operates
on them. Idle, borrowed, creating, checking and closing connections all count towards `M`, including
failed cleanup awaiting a retry. `CapacityInterface::available()` reports reservable slots, not `I`;
in-flight operations can temporarily occupy slots as well. Only successful creation produces a ready connection.

## Assembly and borrowing

The owning driver or application provider constructs `PoolOption` with its caller-supplied `$factory`:

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

The factory, capacity coordinator and fixed maximum are required. The wait timeout defaults to `1.0` second,
health checks to `HealthCheckMode::OnBorrowAndReturn`, and frequency to a pool-local `ConstantFrequency` instance.
Pass explicit `healthChecks` and `frequency` arguments to select other policies. Options retain fixed values and
collaborator references; the referenced factory, capacity coordinator and frequency policy retain their own runtime state.

Pool validates positive matching capacity and a finite non-negative timeout before connection I/O.
Constructing options or Pool starts no coroutine, timer, worker or connection. Each consuming component uses
`PoolOption` directly; no option interface, subclass or driver-specific option implementation is needed.
Driver configuration remains with its connection factory, and the generic package does not discover it.

Inside a consumer and the backend's required runtime:

```php
$connection = $this->pool->acquire();
try {
    // Use the client and restore its transaction/session state before returning it.
} finally {
    $this->pool->release($connection);
}
```

Use `discard($connection)` instead of `release($connection)` for a broken or non-resettable connection.
Return each acquired object exactly once to the same pool. The pool rejects objects that are not currently
borrowed from it, including duplicate returns and connections from another pool. It removes borrowed ownership
before running health or cleanup callbacks, so a second return is rejected even while cleanup is still running.
Capacity remains reserved through validation and cleanup. Failed cleanup retains its reservation until a
later maintenance pass or `close()` succeeds; never return the same object again to retry cleanup.

Callers own the lifetime of their references: stop using a connection and restore its session state before
returning it. There is no automatic return or access revocation. After the same object is acquired again, the pool
cannot distinguish the former borrower's reference from the current borrower's reference. Prevent such stale
use and delayed returns in application code; no per-borrow lease or public receipt is issued.

`acquire(null)` uses the configured timeout; zero attempts immediate acquisition. Only capacity waiting is timed
by Pool. Connection I/O has the adapter's own deadlines. Exhaustion raises `PoolTimeoutException`, shutdown
raises `PoolClosedException`, and canceled waits or invalid ownership operations raise `PoolException`.
Creation, establishment, health checks and cleanup hold capacity reservations. Original acquisition/health
exceptions survive additional cleanup errors. Failed cleanup removes the wrapper from borrowing but retains
its resource allocation and reservation. Adapters must support repeated `close()` attempts; a failed close
must never allow a replacement to exceed the connection limit.

## Process and thread ownership

Every connection is acquired and returned through its owning pool in the same process or thread.
Shared backends coordinate capacity and shutdown, not remote returns. Pool retains backend reservation tokens
internally; application code only receives connection objects. Returns complete locally without a notification
queue or a later maintenance pass when cleanup succeeds. Failed cleanup is retried locally by maintenance or shutdown.

For processes, construct `new ProcessCapacity($limit)` before `Swoole\Process::start()`, and
construct a new `Pool` with that inherited backend inside each process. Do not use a Pool or
client wrapper inherited from the parent.

For threads, the creator uses `ThreadCapacity::create($limit)` and passes `$backend->state()`
and `$backend->mutex()` through `Swoole\Thread` arguments. The receiving thread attaches
`new ThreadCapacity($state, $mutex)` and constructs its own factory and Pool. Only the native
shared handles cross the boundary. The runtime tests contain executable examples.

There is no automatic expiration or crash recovery for reservations. If a resource owner dies,
capacity cannot be safely reclaimed by a timeout alone; the application supervisor must retire
that backend and establish a new generation after confirming old owners have stopped. The
package does not infer process death, replay requests or steal live reservations.

## Health checks and scheduling

`HealthCheckMode` controls synchronous health checks on retained connections:

| Mode | Before idle reuse | Before retaining a returned connection |
| --- | --- | --- |
| `OnBorrow` | Yes | No |
| `OnReturn` | No | Yes |
| `OnBorrowAndReturn` (default) | Yes | Yes |
| `Manual` | No | No |

A shared backend always closes returned clients and therefore does not run a health check to
retain them. Before idle reuse, borrow checking retires unhealthy candidates and examines the remaining
idle snapshot before creating one replacement. Unexpected health errors propagate; creation is not retried
indefinitely. All modes allow explicit `maintain($minimumIdle = 0)`. A pass retries inactive failed cleanup,
checks idle resources and replenishes the target selected by frequency. It never checks active borrowers,
skips overlapping passes and counts in-flight creation and cleanup against capacity. The minimum is a
best-effort floor, not a guarantee while connections are borrowed.
Shared backends require a zero idle minimum because they do not retain transferable idle clients.

`PoolOption::frequency()` supplies the policy for replenishment and shrinking. The default
`Frequency\ConstantFrequency()` reports `1.0` borrow attempts per second with a `1.0` low-frequency threshold,
so maintenance replenishes all capacity not occupied by borrowers or pending operations. A rate below the threshold is low;
equality is not low frequency. Fixed strategies ignore borrow events, for example
`new ConstantFrequency(0.25, 1.0)` always requests shrinking during maintenance.

Use `Frequency\SmartLowFrequency` to adapt to actual demand:

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

`recordBorrow()` counts each valid `acquire()` attempt before waiting for capacity, including attempts that later time
out or fail to connect. Invalid arguments, closed-pool calls and foreign execution contexts do not count. Each event
adds `1 / windowSeconds` to the estimated attempts per second. Between events, the estimate decays by
`exp(-elapsedSeconds / windowSeconds)`, using monotonic time and constant storage. `frequency()` also applies decay,
so activity falls without new borrows. Rates saturate at `PHP_FLOAT_MAX` instead of becoming infinite. The strategy
does not report low frequency during its first observation window; after that it compares the decayed estimate
with the configured threshold. This is a smoothed demand estimate, not an exact sliding-window count.

Open pools that retain idle connections evaluate the strategy once at the beginning of each maintenance pass.
In low-frequency mode they retire idle connections above `minimumIdle`; with zero minimum they retire all idle
connections. Maintenance checks retained connections and replenishes that minimum. At normal frequency it fills
unoccupied capacity, even when `minimumIdle` is zero. Neither target can displace borrowed connections or pending
operations. Returns restore their slots without creating replacements inline; a maintenance pass performs replenishment.
Cleanup failures retain their slots, remaining resources are attempted, and the first error propagates. Each later pass
retries a pending close once; it never overlaps a close already in progress. Failed creation leaves a real deficit
for the next pass. Successful retry frees capacity before replenishment resumes.
Closed pools and shared backends skip frequency evaluation. Returning a connection never evaluates this policy.

Frequency strategies start no timer and perform no connection I/O. Give each pool its own mutable strategy; constant
strategies can be shared. Custom strategies must not yield or perform I/O. `SmartLowFrequency` optionally accepts a
non-yielding `Closure(): float` clock returning finite monotonic seconds, allowing deterministic tests; the default
uses `hrtime()`. A failing, non-finite or regressing clock propagates an error without silently changing the estimate.

Call `maintain()` manually, from an application event/task, or from any external scheduler. For
a Swoole timer, explicitly create and start the supplied adapter:

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

Use `minimumIdle: 0` for process/thread backends. They do not retain idle connections or need maintenance
to finish returns. The timer creates a coroutine when needed,
skips overlapping passes and reports failures to the required error callback; that callback
must not throw. `stop()` cancels future passes and lets an active pass finish. A closed pool
stops the timer on the next tick; retry shutdown cleanup with explicit `close()` calls if needed.
Scheduling and connection I/O never start in constructors.

## Shutdown

`close()` permanently closes the backend and rejects capacity waiters. It attempts every local
idle cleanup and then propagates the first error. Active borrowers close when returned;
in-flight creation or health checks cannot hand out connections after shutdown. Each caller must still
return its borrowed objects to their owning pool; cleanup happens during that return.
Repeated `close()` calls retry failed cleanup and leave successfully closed resources untouched.
There is no asynchronous destructor cleanup. Close coroutine pools inside their runtime and
stop scheduling as part of the owner's lifecycle.

Capacity implementations use the `SuperKernel\Pool\Capacity` namespace and a `Capacity` class suffix to name
their capacity-coordination responsibility. The contract is `Contract\CapacityInterface`.
Applications construct `Config\PoolOption` with the factory, capacity coordinator, limits, timeout, health and
frequency policies, then pass it to `new Pool($option)`. Custom pool implementations provide `maintain()` through
`PoolInterface`; `SwooleTimerScheduler` uses that interface. Options default to `OnBorrowAndReturn`; choose `OnBorrow`
for borrow-only health validation. Failed cleanup retains capacity until a successful retry; close must be retryable.

Borrow through `acquire()` and return the identical local connection through `release()` or `discard()`.
Custom capacity coordinators implement `reserve($timeout, $owner)` and `release($reservation, $owner)`.
`CoroutineCapacity` accepts an optional channel. `HealthCheckMode` lives under `Constants`; applications own assembly,
with no generic Config/DI defaults. Rebuild application snapshots after persisted class-layout changes.

## Development and evidence

Install dependencies only at the repository root. Create a temporary bootstrap loading root
`vendor/autoload.php` and registering `SuperKernel\Pool\Tests\` for this package's `tests/`, then run:

```sh
php84 vendor/bin/phpunit -c .vendor/pool/phpunit.xml.dist --bootstrap /path/to/bootstrap.php
php84 vendor/bin/php-cs-fixer check --config=.vendor/pool/.php-cs-fixer.dist.php --diff
composer84 validate --strict --working-dir=.vendor/pool
```

Run the same complete suite under PHP 8.4.27 or later 8.4.x ZTS with Swoole thread support to execute native thread
tests. On NTS, the `thread` group is skipped; `--exclude-group thread` selects the runnable host
suite explicitly. A skipped thread suite is not thread verification. Tests spawn real Swoole
processes and threads and use caller-owned in-memory connection fixtures to verify shared
capacity during creation/use/cleanup, local object returns, owner cleanup and global shutdown.
They also exercise coroutine contention, cancellation, maintenance, timer errors and shutdown
races. They do not establish interoperability with a particular Redis/MySQL/HTTP/WebSocket client.
