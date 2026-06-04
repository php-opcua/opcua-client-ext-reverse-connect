# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.x     | Yes       |

## Reporting a Vulnerability

If you discover a security vulnerability in this library, please report it
responsibly.

**Do not open a public issue.** Instead, send an email to
[gianfri@php-opcua.com](mailto:gianfri@php-opcua.com) with:

- A description of the vulnerability
- Steps to reproduce
- The affected version(s)
- Any potential impact assessment

You should receive an acknowledgment within 48 hours. From there, we'll
work together to understand the scope and develop a fix before any public
disclosure.

## Scope

This policy covers the `php-opcua/opcua-client-ext-reverse-connect`
library itself. For vulnerabilities in the core or related packages,
please report them to the respective maintainers:

- [opcua-client](https://github.com/php-opcua/opcua-client) — the core
  client (transport, secure channel, session, services)
- [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager)
- [laravel-opcua](https://github.com/php-opcua/laravel-opcua)

## Security Considerations

OPC UA Reverse Connect inverts which side opens the TCP connection: the
server dials the client and the client accepts. That inversion shifts
*who exposes a listening port* — and therefore who is exposed to traffic
from anyone who can reach that port. The package is built around two
explicit boundaries that the application must configure correctly.

### Whitelist validation is the gating boundary

`ReverseHelloValidator` rejects every frame whose announced `ServerUri`
is not in the application-supplied whitelist, with exact case-sensitive
match (per RFC 3986). A frame is also rejected if `EndpointUrl` is empty
or does not start with `opc.tcp://`. The validator runs **before** the
UA-TCP handshake touches the socket; an impostor that can reach the
listener port still has to present a `ServerUri` you explicitly trust.

The validator is **fail-secure**: an empty whitelist rejects every
incoming frame. Never construct it with a synthetic "trust everything"
list — if you do not know which servers you are willing to talk to, you
should not run the listener.

### Reverse Connect does not replace the UA secure channel

The wire inversion happens at the TCP layer. From the `HEL` frame
onwards every byte is standard UA-TCP, and certificate-based mutual
authentication still happens in the secure-channel handshake. Configure
security policy, security mode, and trust store inside the `$configure`
closure of `ReverseConnectClientFactory::buildClient()` exactly as you
would for a classic outbound client. Treat the whitelist as
defence-in-depth, not as a replacement for `SecurityPolicy` /
`SecurityMode`.

### Operational guidance

When deploying the listener:

- **Bind narrowly.** Use `'127.0.0.1'` (or a specific interface) whenever
  the server runs on the same host or routes through it. Use `'0.0.0.0'`
  only when external servers need to reach the listener, and pair that
  with a host-level firewall that restricts inbound TCP to the IPs you
  expect.
- **Pick a stable port** for `bindPort` in production. `bindPort: 0` is
  ergonomic for tests but leaves an outsider unable to tell legitimate
  servers where to dial — and the server-side trigger must know the
  number anyway.
- **Always set `SecurityPolicy::Basic256Sha256` or stronger and
  `SecurityMode::SignAndEncrypt`** for non-test deployments, configured
  via the `$configure` closure. Never deploy a production listener that
  accepts `SecurityPolicy::None`.
- **Provide proper CA-signed certificates** for both client and server
  identity. Do not rely on auto-generated self-signed material outside
  controlled test environments.
- **Set a sensible `accept(timeoutSeconds: …)`.** A long timeout keeps
  the listener pinned on a half-open inbound connection — short timeouts
  in a loop are safer.
- **Log the rejected frames.** Attach a PSR-14 dispatcher and watch
  `ReverseConnectRejected` events; unexpected rejections often indicate
  a misconfigured server, a stale whitelist entry, or a probe from
  someone who knows the listener exists.
- **Cap the frame size.** `ReverseConnectListener` accepts a
  `maxFrameSize` constructor argument (default
  `ReverseHelloParser::DEFAULT_MAX_FRAME_SIZE = 65535`). Lower it if
  every legitimate server in your fleet emits a smaller frame — it
  shrinks the buffer an attacker can request the listener to allocate.
- **Keep PHP and OpenSSL up to date.** The listener relies on the PHP
  streams API and on `opcua-client` for the secure channel; both should
  run on supported runtimes.

### Threat model in one paragraph

The listener is a TCP server. Anyone able to reach `bindHost:bindPort`
can send arbitrary bytes. The parser is bounded by `MIN_FRAME_SIZE` /
`maxFrameSize` and rejects malformed framing with
`ReverseHelloParseException` before allocating any payload buffer; the
validator then rejects frames whose `ServerUri` is not whitelisted
before the socket reaches the UA-TCP pipeline. An attacker who controls
a host whose `ServerUri` *is* in your whitelist is treated as a
legitimate server by definition — at that point the OPC UA secure
channel (policy, mode, certificates) is what stops them. Reverse
Connect changes who initiates the TCP connection; it does not change
that the UA secure channel is the authoritative authentication step.

## Sharing Debug Logs and Reproducers

When asking for help in a **public** channel (GitHub issues,
discussions, chat rooms) or attaching logs to a bug report, treat the
following as sensitive and either redact or omit them:

| Information                                          | Why it matters                                                                                              |
|------------------------------------------------------|-------------------------------------------------------------------------------------------------------------|
| Listener bind host / port and the matching DNS name  | Anyone who knows the address can attempt to connect — and the listener will at least parse their frame.    |
| `ServerUri` whitelist                                | Reveals the application URIs your deployment trusts; pair it with a forged server certificate and you have a foothold. |
| Announced `EndpointUrl` values                       | Often embed internal hostnames, ports, and resource paths of the industrial network.                       |
| Server / client certificates and their thumbprints   | Identify specific key pairs — replay-risk if your validation logic relies only on thumbprint matches.       |
| Captured RHE bytes paired with the deployment context | Combined with the items above, they can be replayed against a misconfigured listener.                       |

For **public** bug reports and reproducers, redact the items above
before pasting. For **private** support requests where extra context is
genuinely required, send unmasked material to the maintainer email
above, not to a public thread. Treat any historical paste/gist of
reverse-connect logs as effectively permanent — search engines and
forks may have already indexed it. When in doubt, regenerate trust
material rather than relying on deletion.
