---
name: Bug Report
about: Report a bug or unexpected behavior in the reverse-connect listener
title: "[BUG] "
labels: bug
assignees: ''
---

## Description

A clear and concise description of the bug.

## Steps to Reproduce

```php
use PhpOpcua\Client\ExtReverseConnect\ReverseConnectListener;
use PhpOpcua\Client\ExtReverseConnect\ReverseHelloValidator;

$listener = new ReverseConnectListener(
    bindHost: '0.0.0.0',
    bindPort: 0,
    validator: new ReverseHelloValidator(['urn:example']),
);
// Minimal code to reproduce the issue
```

## Expected Behavior

What you expected to happen.

## Actual Behavior

What actually happened. Include error messages or exceptions if applicable.

## Environment

- PHP version:
- `opcua-client-ext-reverse-connect` version:
- `opcua-client` (core) version:
- OPC UA server: (e.g., UA-.NETStandard, open62541, Prosys, Unified Automation, …)
- Server-side Reverse Connect mechanism: (e.g., `ReverseConnectManager`, config file, custom)
- OS:

## Reverse-connect configuration

- Listener bind host / port:
- Whitelisted `ServerUri` values:
- Triggering mechanism (how is the server told to dial back?):
- Docker networking involved? (`extra_hosts`, host-gateway, …):

## Frame / log evidence

Paste the relevant log lines from the listener (PSR-3) and, if available, the raw `RHE` bytes — for example via `xxd` or a packet capture trimmed to the reverse-connect handshake.

## Additional Context

Any additional context, stack traces, or test reproductions.
