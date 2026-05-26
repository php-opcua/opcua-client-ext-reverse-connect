---
eyebrow: 'Docs · Concepts'
lede:    'Wire format of the ReverseHello frame, lifecycle of a reverse-connected session, and the security implications of letting the server dial the client.'

see_also:
  - { href: 'https://reference.opcfoundation.org/Core/Part6/v105/docs/7.1.2.3', meta: 'external', label: 'OPC UA Part 6 §7.1.2.3' }
  - { href: '../api/listener.md',                                                meta: '4 min' }
  - { href: '../api/validator.md',                                               meta: '3 min' }

prev: { label: 'Quick start',  href: '../getting-started/quick-start.md' }
next: { label: 'Listener API', href: '../api/listener.md' }
---

# How Reverse Connect works

Reverse Connect inverts who initiates the TCP connection. Everything
after the inversion is the standard UA-TCP protocol.

## Sequence

```
   ┌───────────┐                            ┌───────────┐
   │  Client   │                            │  Server   │
   │ (this lib)│                            │ (RC-aware)│
   └─────┬─────┘                            └─────┬─────┘
         │                                        │
         │   stream_socket_server / accept()      │
         │ ◄──────────  TCP SYN  ─────────────────│  initiates
         │                                        │
         │ ◄──────────  RHE frame  ───────────────│
         │  decode + validate ServerUri           │
         │                                        │
         │   ─────────  HEL  ──────────────────►  │
         │ ◄──────────  ACK  ─────────────────────│
         │   ─────────  OPN  ──────────────────►  │
         │ ◄──────────  OPN  ─────────────────────│
         │   ─────────  CreateSession ─────────►  │
         │ ◄──────────  CreateSession ────────────│
         │           …business calls…             │
```

The only step that differs from the classic flow is the direction of
the TCP `SYN`. From `HEL` onwards every byte is byte-for-byte the
same as a regular UA-TCP session.

## RHE frame layout

The wire format is defined in OPC UA Part 6 §7.1.2.3:

| Field         | Type             | Size      | Notes                                             |
| ------------- | ---------------- | --------- | ------------------------------------------------- |
| MessageType   | 3 bytes ASCII    | 3         | Always `"RHE"`                                    |
| ChunkType     | 1 byte ASCII     | 1         | Always `"F"` (final)                              |
| MessageSize   | UInt32 LE        | 4         | Total frame size including header                 |
| ServerUri     | OPC UA String    | variable  | Application URI announced by the server           |
| EndpointUrl   | OPC UA String    | variable  | Endpoint the client uses in the CreateSession     |

OPC UA String encoding: Int32 little-endian length prefix followed by
UTF-8 bytes. Length `-1` represents the null string; the parser
normalises it to the empty string.

The package exposes the two compile-time bounds as constants on
`ReverseHelloParser`:

- `ReverseHelloParser::MIN_FRAME_SIZE = 16` — header + two empty string
  length prefixes
- `ReverseHelloParser::DEFAULT_MAX_FRAME_SIZE = 65535` — soft upper
  bound applied by the parser when the caller does not pass an
  explicit `$maxFrameSize`

A declared `MessageSize` outside that window raises
[`ReverseHelloParseException`](../reference/exceptions.md#reversehelloparseexception)
before any further bytes are interpreted.

## Lifecycle of a session

1. **`ReverseConnectListener::__construct(...)`** — set bind host /
   port, supply a validator (and optionally a logger / dispatcher /
   max frame size).
2. **`listen()`** — open the TCP server socket. Idempotent.
3. **`accept(float $timeoutSeconds)`** — block until a server
   connects and sends a full RHE, or until the timeout elapses.
4. **`ReverseConnectSession`** — returned on success. Owns the live
   socket; the consumer is responsible for closing it (or for letting
   the resulting transport close it).
5. **`ReverseConnectClientFactory::buildClient($session, $configure)`**
   — wrap the socket into a `TcpTransport::fromConnectedSocket()`,
   apply caller configuration to a fresh `ClientBuilder`, then call
   `connect($session->endpointUrl)`.
6. **Use the `Client`** as you would after a normal connect.
7. **`close()`** — release the listener socket when the loop ends.
   Subsequent `accept()` calls on a closed listener raise
   [`ReverseConnectException`](../reference/exceptions.md#reverseconnectexception).

## Security model

Two checks gate every connection:

1. **Whitelist validation, before UA framing.** The validator —
   [`ReverseHelloValidator`](../api/validator.md) — refuses messages
   whose `ServerUri` is not in the allow list, with case-sensitive
   exact match (per RFC 3986). Anyone capable of reaching the listener
   port can present a frame; without this check an impostor could
   reach the UA secure channel before being rejected.
2. **UA secure channel certificate validation, after HEL/ACK.** The
   inversion changes who opens the socket, not who validates which
   certificate. If you want mutual authentication, configure security
   policy and trust store inside the `$configure` closure passed to
   `ReverseConnectClientFactory::buildClient()` exactly as you would
   for a classic client.

The whitelist is mandatory in the sense that an empty whitelist
rejects every incoming frame — the validator is fail-secure by
default.

## Threading

The listener does not spin a thread or run an event loop. `accept()`
is a single blocking call bounded by a caller-supplied timeout. To
service multiple servers, call `accept()` in a loop on the same
listener instance, or run multiple listeners on different ports.

The PHP CLI process is the natural host for this kind of long-running
loop; running the listener inside a request-scoped FPM worker is
possible but uncommon, since the listener has to outlive a single
request.
