---
eyebrow: 'Docs · API'
lede:    'Three PSR-14 events emitted by the listener during each accept() — observe the wire decode, the whitelist outcome, or both.'

see_also:
  - { href: './listener.md',                meta: '4 min' }
  - { href: '../reference/exceptions.md',   meta: '3 min' }
  - { href: 'https://www.php-fig.org/psr/psr-14/', meta: 'external', label: 'PSR-14 — Event Dispatcher' }

prev: { label: 'Factory API', href: './factory.md' }
next: { label: 'Docker host networking', href: '../recipes/docker-host-networking.md' }
---

# Events

The listener accepts an optional
`Psr\EventDispatcher\EventDispatcherInterface` via its constructor.
When one is provided, three events are dispatched in well-defined
positions of the `accept()` flow. When the dispatcher argument is
`null` the events are not emitted at all — there is no
`NullEventDispatcher` instantiated internally.

All three event classes live under
`PhpOpcua\Client\ExtReverseConnect\Event\`, are `final readonly`, and
carry only the data needed by listeners.

## `ReverseHelloReceived`

```php
final readonly class ReverseHelloReceived
{
    public function __construct(
        public ReverseHelloMessage $message,
    ) {}
}
```

Dispatched **immediately after** a successful frame decode and
**before** the validator runs. Listeners attached here observe every
parseable RHE — including the ones that will then be rejected. Useful
for diagnostics and audit logging.

## `ReverseConnectAccepted`

```php
final readonly class ReverseConnectAccepted
{
    public function __construct(
        public ReverseHelloMessage $message,
    ) {}
}
```

Dispatched **after** the validator silently approves the message and
**before** `accept()` returns the session. By the time a handler
runs, the socket is still owned by the listener; the
`ReverseConnectSession` is yielded immediately afterwards.

## `ReverseConnectRejected`

```php
final readonly class ReverseConnectRejected
{
    public function __construct(
        public ReverseHelloMessage $message,
        public string $reason,
    ) {}
}
```

Dispatched **when the validator rejects** the message — exactly once,
just before `ReverseConnectRejectedException` is raised and before the
socket is closed. `$reason` is the same string the exception carries
in its message, so a single handler is enough to log structured
context without duplicating logic.

A frame that fails to *decode* does **not** dispatch this event —
parse failures raise `ReverseHelloParseException` directly. Listen on
both `ReverseHelloReceived` (parseable) and the exception path
(parse failures) if you need a complete trace.

## Subscription pattern

```php
use Psr\EventDispatcher\EventDispatcherInterface;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectAccepted;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseConnectRejected;
use PhpOpcua\Client\ExtReverseConnect\Event\ReverseHelloReceived;
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;

$dispatcher = new MyDispatcher();   // any PSR-14 implementation

$listener = new ReverseConnectListener(
    bindHost: '0.0.0.0',
    bindPort: 4841,
    validator: new ReverseHelloValidator(['urn:gateway:42']),
    dispatcher: $dispatcher,
);
```

How the dispatcher routes those classes to handlers is the
dispatcher's business — the package ships no provider, no listener
registry, and no global state. Any PSR-14-compatible implementation
(symfony/event-dispatcher, league/event, in-house, etc.) works.

## Ordering guarantees

For a single `accept()` call:

| Outcome              | Events dispatched (in order)                                                              |
| -------------------- | ----------------------------------------------------------------------------------------- |
| Successful accept    | `ReverseHelloReceived` → `ReverseConnectAccepted`                                         |
| Rejected by validator | `ReverseHelloReceived` → `ReverseConnectRejected`                                        |
| Parse failure        | *(no event)* — `ReverseHelloParseException` is thrown                                     |
| Timeout              | *(no event)* — `ReverseConnectTimeoutException` is thrown                                 |
| Bind / accept errors | *(no event)* — `ReverseConnectException` is thrown                                        |

The listener emits no event for its own bind/close operations; if you
need that, hook into your logger output (the listener logs
`Reverse-connect listener bound` at `INFO` after `listen()` succeeds).
