## Summary

Brief description of the changes.

## Type of Change

- [ ] Bug fix
- [ ] New feature
- [ ] Enhancement / improvement
- [ ] Refactoring (no functional changes)
- [ ] Documentation
- [ ] Tests

## Changes

- Change 1
- Change 2

## Testing

- [ ] Unit tests added/updated
- [ ] Integration tests added/updated (run against `uanetstandard-test-suite` v1.4.0+ on `opc.tcp://localhost:4840`)
- [ ] All existing tests pass (`./vendor/bin/pest`)

## Documentation

- [ ] `docs/` updated (if applicable)
- [ ] `README.md` updated (if API surface changed)
- [ ] `CHANGELOG.md` updated

## Checklist

- [ ] Code follows the existing style and conventions (`composer format:check`)
- [ ] No breaking changes to the public API (`ReverseConnectListener`, `ReverseHelloValidator`, `ReverseConnectClientFactory`, events, exceptions)
- [ ] `opcua-client` (core) compatibility constraint in `composer.json` still satisfied
- [ ] Cross-platform considerations checked (no Unix-only APIs — the listener targets Linux/macOS/Windows)
