---
eyebrow: 'Docs · Reference'
lede:    'Every exception the listener can raise, with the matching cause, the call sites that throw it, and the catch strategy that fits.'

see_also:
  - { href: '../api/listener.md',  meta: '4 min' }
  - { href: '../api/validator.md', meta: '3 min' }

prev: { label: 'Docker host networking', href: '../recipes/docker-host-networking.md' }
next: { label: 'No next page',           href: '#' }
---

# Exceptions

All exception classes live under
`PhpOpcua\Client\ExtReverseConnect\Exception\`. They form a small
hierarchy rooted in `ReverseConnectException`, which itself extends
`\RuntimeException`. Catching the base class is the right move when
the application only needs a single "Reverse Connect failed" branch;
catching specific subclasses lets you log and recover with finer
granularity.

```
\RuntimeException
└── ReverseConnectException                  (base)
    ├── ReverseHelloParseException
    ├── ReverseConnectRejectedException      (carries $rejectedMessage)
    └── ReverseConnectTimeoutException
```

## `ReverseConnectException`

The base class. Raised directly when the listener cannot perform its
own bookkeeping — bind failed, `accept()` called before `listen()`,
`stream_select()` returned `false`, `stream_socket_accept()` returned
`false`, `stream_socket_get_name()` returned `false`. Concrete
messages start with strings such as:

- `Failed to bind reverse-connect listener on <host>:<port>: [<errno>] <errstr>`
- `Listener is not started; call listen() first.`
- `stream_select() failed while waiting for inbound connection`
- `stream_socket_accept() failed`
- `Failed to read the listener bind address`

No additional public state.

## `ReverseHelloParseException`

Raised when the bytes on the wire are not a valid RHE frame. Thrown
either by `ReverseHelloParser::parse()` or by
`ReverseConnectListener::accept()` when reading from the inbound
socket. Causes include:

- Frame shorter than `ReverseHelloParser::MIN_FRAME_SIZE` (`16`)
- MessageType other than `"RHE"`
- ChunkType other than `"F"`
- Declared MessageSize below `MIN_FRAME_SIZE` or above the configured
  `$maxFrameSize`
- Declared MessageSize different from the received byte count
- OPC UA String length prefix pointing past the end of the payload
- Trailing bytes after the EndpointUrl
- Peer closed the socket before the full frame arrived
- Read timed out while collecting the frame

The original `EncodingException` from `php-opcua/opcua-client` is
attached as `previous` when the failure originated in the OPC UA
String decoder.

The listener closes the inbound socket *before* raising this exception
on the `accept()` path.

## `ReverseConnectRejectedException`

Raised by `ReverseHelloValidator::ensureAccepted()` — directly, and
by extension via `ReverseConnectListener::accept()`. Carries the
rejected message so handlers can include it without re-parsing:

```php
public readonly ReverseHelloMessage $rejectedMessage;
```

Possible causes (and the matching message prefix):

| Cause                                       | Message prefix                                            |
| ------------------------------------------- | --------------------------------------------------------- |
| `ServerUri` empty                           | `ReverseHello rejected: ServerUri is empty`               |
| `ServerUri` not in the whitelist            | `ReverseHello rejected: ServerUri "<…>" is not in the configured whitelist` |
| `EndpointUrl` empty                         | `ReverseHello rejected: EndpointUrl is empty`             |
| `EndpointUrl` does not start with `opc.tcp://` | `ReverseHello rejected: EndpointUrl "<…>" does not use the opc.tcp scheme` |

On the `accept()` path the listener also dispatches the
`ReverseConnectRejected` event and closes the inbound socket *before*
raising this exception.

## `ReverseConnectTimeoutException`

Raised by `ReverseConnectListener::accept()` when no inbound
connection arrives within the supplied `$timeoutSeconds`. The message
has the form:

```
No inbound reverse-connect connection within <seconds>s
```

No event is dispatched on timeout — the listener never decoded a
frame, so there is nothing to report. Catch this when polling in a
loop:

```php
while ($keepRunning) {
    try {
        $session = $listener->accept(timeoutSeconds: 5.0);
    } catch (ReverseConnectTimeoutException) {
        continue;
    }
    // …handle $session…
}
```

## Catch hierarchy at a glance

| Application intent                                  | Catch                                  |
| --------------------------------------------------- | -------------------------------------- |
| Any reverse-connect failure                          | `ReverseConnectException`              |
| Distinguish wire-format from policy failures        | `ReverseHelloParseException` vs `ReverseConnectRejectedException` |
| Run the listener as a polling loop                  | `ReverseConnectTimeoutException`       |
| Inspect the rejected message in audit logs          | `ReverseConnectRejectedException::$rejectedMessage` |
