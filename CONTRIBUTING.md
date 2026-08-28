# Contributing to signalgate/signalgate-php

Thanks for your interest in contributing!

## Development setup

Requires PHP >= 8.1 with the `curl` and `json` extensions, and Composer.

```bash
git clone https://github.com/SignalGate/signalgate-php.git
cd signalgate-php
composer install
composer test            # run the test suite (vendor/bin/phpunit)
```

All tests use an injected fake transport — the suite makes no real network
calls and needs no API key.

## Making changes

1. Fork the repo and create a branch off `main`.
2. Make your change. Keep the public API surface (`SignalGate\Client` and
   friends under `src/`) stable — the test suite pins the SDK's behavioral
   contract, and changes to existing behavior need a very good reason.
3. Add or update tests for anything you change. `composer test` must be
   green, and `composer validate --strict` must pass if you touch
   `composer.json`.
4. Update `CHANGELOG.md` under an `Unreleased` heading if the change is
   user-visible.
5. Open a pull request against `main` describing what changed and why.

## Reporting bugs

Open an issue at
<https://github.com/SignalGate/signalgate-php/issues> with the SDK version,
PHP version, and a minimal reproduction.

**Security vulnerabilities: do not open a public issue.** See
[SECURITY.md](SECURITY.md) for the private disclosure path.

## Releases

Releases follow [PUBLISHING.md](PUBLISHING.md): a maintainer tags `vX.Y.Z` on
a green `main` and pushes the tag, which Packagist's GitHub webhook picks up
automatically. Contributors don't need to do anything version-related in PRs —
maintainers handle versioning and tagging at release time.

## License

By contributing, you agree that your contributions will be licensed under the
[Apache License 2.0](LICENSE).
