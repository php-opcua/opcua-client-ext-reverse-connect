---
eyebrow: 'Docs · API'
lede:    'ReverseHelloValidator and ReverseHelloParser — the wire-format decoder and the whitelist-based security gate that runs between accept() and the UA secure channel.'

see_also:
  - { href: './listener.md',                meta: '4 min' }
  - { href: '../concepts/how-it-works.md',  meta: '5 min' }
  - { href: '../reference/exceptions.md',   meta: '3 min' }

prev: { label: 'Listener API',  href: './listener.md' }
next: { label: 'Factory API',   href: './factory.md' }
---

# `ReverseHelloValidator` and `ReverseHelloParser`

Two classes that together turn raw bytes into a trusted
[`ReverseHelloMessage`](#reversehellomessage). The listener invokes
both internally; both are also public so callers can pre-decode a
captured frame, validate a forged message in a test, or build a
custom transport.

## `ReverseHelloMessage`

Fully qualified name:
`PhpOpcua\Client\ExtReverseConnect\ReverseHelloMessage`. Immutable
readonly DTO.

```php
final readonly class ReverseHelloMessage
{
    public function __construct(
        public string $serverUri,
        public string $endpointUrl,
    ) {}
}
```

Both fields are non-null after a successful
[`ReverseHelloParser::parse()`](#reversehelloparser) — the parser
normalises the OPC UA null string (length `-1`) to the empty string
before constructing the DTO.

## `ReverseHelloParser`

Fully qualified name:
`PhpOpcua\Client\ExtReverseConnect\ReverseHelloParser`. Stateless.

```php
public const MIN_FRAME_SIZE         = 16;
public const DEFAULT_MAX_FRAME_SIZE = 65535;

public static function parse(
    string $frame,
    int $maxFrameSize = self::DEFAULT_MAX_FRAME_SIZE,
): ReverseHelloMessage;
```

Throws `ReverseHelloParseException` (see
[Exceptions](../reference/exceptions.md#reversehelloparseexception))
when any of these rules is violated:

| Rule                                                              | Error message includes                  |
| ----------------------------------------------------------------- | --------------------------------------- |
| `strlen($frame) < MIN_FRAME_SIZE`                                 | `too short`                             |
| MessageType ≠ `"RHE"`                                             | `Expected MessageType "RHE"`            |
| ChunkType ≠ `"F"`                                                 | `Expected ChunkType "F"`                |
| Declared MessageSize < `MIN_FRAME_SIZE`                           | `below the minimum`                     |
| Declared MessageSize > `$maxFrameSize`                            | `exceeds the configured maximum`        |
| Declared MessageSize ≠ received length                            | `does not match received length`        |
| OPC UA String length points past the end of the payload           | `Malformed OPC UA String`               |
| Trailing bytes after the EndpointUrl                              | `Trailing N byte(s) after EndpointUrl`  |

Non-printable bytes in MessageType / ChunkType are escaped to
`\xHH` form in error messages so binary garbage cannot corrupt the
log line.

## `ReverseHelloValidator`

Fully qualified name:
`PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator`.

```php
public function __construct(iterable $allowedServerUris);

public function getAllowedServerUris(): array;     // list<string>
public function ensureAccepted(ReverseHelloMessage $message): void;
public function isAccepted(ReverseHelloMessage $message): bool;
```

The constructor accepts any `iterable<string>` — array, generator,
custom traversable — and snapshots it into a `list<string>` once.

### Validation rules

`ensureAccepted()` raises
[`ReverseConnectRejectedException`](../reference/exceptions.md#reverseconnectrejectedexception)
on the *first* rule that fails, in this order:

1. **`serverUri === ''`** → `ReverseHello rejected: ServerUri is empty`
2. **`serverUri` not in the whitelist** (exact, case-sensitive match
   per RFC 3986) → `ReverseHello rejected: ServerUri "<…>" is not in
   the configured whitelist`
3. **`endpointUrl === ''`** → `ReverseHello rejected: EndpointUrl is
   empty`
4. **`endpointUrl` does not start with `opc.tcp://`** → `ReverseHello
   rejected: EndpointUrl "<…>" does not use the opc.tcp scheme`

The exception carries the rejected message as
`$exception->rejectedMessage` so logs and event listeners can include
the original payload without re-parsing.

`isAccepted()` is the non-throwing variant: it returns `true` if
`ensureAccepted()` would have been silent, `false` otherwise.

### Fail-secure default

A `ReverseHelloValidator` constructed with an empty whitelist rejects
every message — the validator never grants implicit trust. This is
intentional: the whitelist is the only application-supplied piece of
information that distinguishes a real server from anyone able to
reach the listener port.

### Case sensitivity

`ServerUri` matching is case-sensitive: `urn:My:Server` and
`urn:my:server` are different identifiers per RFC 3986. Callers that
want case-insensitive matching can normalise both the whitelist and
the incoming `serverUri` themselves before constructing the
validator and the message.
