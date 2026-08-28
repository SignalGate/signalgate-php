# Publishing `signalgate/signalgate-php`

The algorithm for taking this package from its current state to publicly
installable via `composer require signalgate/signalgate-php`.

Packagist is unlike npm or a language registry: **it hosts no artifacts at
all.** A Packagist package is a thin pointer at a public git repository; every
`composer install` clones (or downloads a zip of) the tagged commit directly
from GitHub. That makes publication mechanically trivial — there is no upload
step, no build step, no tarball to inspect — but it also means the *tag itself*
is the release. There is nothing to "unpublish" independently of the repo; get
the tag right or don't push it.

Because publication is this cheap, CI is the safety net instead of a rehearsal
phase: green CI on the tagged commit **is** the release gate (see
[Adding CI](#already-have-ci) below — for this package it exists from day one).

---

## Phase 0 — Prerequisites (one-time, human)

1. **Packagist account.** Sign up at <https://packagist.org> (GitHub OAuth is
   the easy path and also sets up the webhook in one step — see below).
2. **Claim the `signalgate` vendor namespace.** Composer package names are
   `vendor/name`; the vendor prefix (`signalgate`) is claimed implicitly by
   whichever account first submits a package under it. Submitting
   `signalgate/signalgate-php` for the first time claims `signalgate` for
   this account (or org, if submitted from an org-owned Packagist team).
   Prefer an org-owned Packagist account with more than one human able to
   submit/update, so a single lost login doesn't strand the package.
3. **Connect the GitHub webhook.** From the package page on Packagist:
   Settings → GitHub Hook → enable. This makes Packagist re-read
   `composer.json` and register new tags automatically on every push —
   without it you'd have to click "Update" on Packagist by hand after every
   release.
4. **Repo must be public.** Packagist (and every `composer install` that
   depends on this package) pulls directly from the GitHub repo. A private
   repo means installs fail for everyone except users with their own deploy
   key configured — not the point of a public SDK. Confirm the repo's
   visibility is Public before the first submission.

Verify: the package page at
<https://packagist.org/packages/signalgate/signalgate-php> loads and shows
"auto-updated" (webhook active) once submission + webhook are both done.

---

## Phase 1 — Close the release-blocking gaps

Each of these is a defect that would either make submission fail or ship
something misleading.

### 1.1 `composer.json` must pass strict validation

Packagist submission (and this repo's CI) both run:

```bash
composer validate --strict
```

This rejects, among other things, a `"version"` field in `composer.json` for
a library package — Packagist derives the version from the git tag, not from
a field in the file. `CHANGELOG.md`'s top `## [x.y.z]` entry is this repo's
single source of truth for "what version is this", and a version-parity test
(`tests/ConstantsTest.php`) pins `Constants::SDK_VERSION` against it.

### 1.2 Repository metadata

`composer.json` should carry `"homepage"` so the Packagist page links back to
<https://signalgate.ai>. (`"support"` keys are optional; GitHub Issues is
already discoverable from the `homepage`/repo link.)

### 1.3 No CHANGELOG gap

Consumers of a versioned SDK need to know what changed between versions.
`CHANGELOG.md` in [Keep a Changelog](https://keepachangelog.com/) format,
with `0.1.0` as the initial entry, ships in the repo (not merely on GitHub
Releases) so it travels with every clone and every tagged tarball Composer
downloads.

---

## Phase 2 — Public-repo hygiene

The GitHub repo becomes the front door the moment Packagist links to it.

1. **`CONTRIBUTING.md`** — how to run tests, the branch/PR flow, and who cuts
   releases.
2. **`SECURITY.md`** — where to report a vulnerability privately. This one is
   non-optional for an *antifraud* SDK: the responsible-disclosure path must
   not be a public GitHub issue.
3. **`CODE_OF_CONDUCT.md`** — the Contributor Covenant is the default choice.
4. **License headers** — `LICENSE` is Apache-2.0 with `Copyright 2026
   SignalGate`. Confirm that entity is the correct legal copyright holder
   before publishing.
5. **Confirm no secrets are in git history:**
   `git log -p | grep -iE 'pk_live|secret|token'`

Branch protection requiring green CI (this repo's `ci.yml`) should be turned
on before the first tag, alongside protecting `main` against force-push.

---

## Phase 3 — Rehearse with a scratch tag

Even with no upload step, **do not let the first real tag be the first time
you see what Composer actually installs.** Rehearse against a throwaway
pre-release tag first.

1. **Run the suite and validate on a clean tree:**
   ```bash
   git status --short                              # must be empty
   composer install --prefer-dist --no-progress
   composer validate --strict
   vendor/bin/phpunit
   ```

2. **Push a scratch tag** that will never be treated as a real release
   (a `-rc`/`-alpha` suffix keeps Composer from ever resolving it by default
   under a plain `^0.1` constraint):
   ```bash
   git tag v0.1.0-rc1
   git push origin v0.1.0-rc1
   ```

3. **Install the scratch tag for real, from a scratch directory** — this is
   the actual thing a new user's `composer require` will do:
   ```bash
   mkdir /tmp/sdk-smoke && cd /tmp/sdk-smoke
   composer init --no-interaction --require=signalgate/signalgate-php:0.1.0-rc1
   composer require signalgate/signalgate-php:0.1.0-rc1
   php -r 'require "vendor/autoload.php"; var_dump(class_exists(\SignalGate\Client::class));'
   ```
   Confirm autoloading resolves `SignalGate\Client` and that the installed
   package contains exactly the files you expect (no `tests/`, no `.git/`,
   no local dotfiles) — Packagist packages the git archive of the tag, so
   anything gitignored is already excluded, but double-check with:
   ```bash
   composer show signalgate/signalgate-php --path
   ls -la "$(composer show signalgate/signalgate-php --path 2>/dev/null | awk '{print $2}')"
   ```

4. **Confirm PHP-version coverage isn't just a claim.** `composer.json`
   claims `php >=8.1`; this repo's CI matrix (`.github/workflows/ci.yml`)
   actually runs the suite on 8.1 through 8.4 on every push, so the scratch
   tag's CI run is your evidence, not a manual local check.

5. **Delete the scratch tag** once satisfied, both locally and on the
   remote, so it doesn't linger as a confusing pseudo-release:
   ```bash
   git tag -d v0.1.0-rc1
   git push origin :refs/tags/v0.1.0-rc1
   ```

6. **Run the php-fpm shutdown-hook check (manual, not unit-testable).** The
   whole point of the log buffer's `register_shutdown_function` design is that
   the retry ladder runs *after* the HTTP response has been sent, so it costs
   the tenant's end user zero added latency. PHPUnit cannot simulate a real
   FPM worker process, so this is a **release-gate item done by hand**, not a
   frozen test:
   - Stand up the quickstart under a real `php-fpm` container (or
     `php -S`/nginx+fpm locally) with `register_shutdown` left at its
     default (`true`).
   - Have the request handler call `fastcgi_finish_request()` immediately
     after writing the HTTP response, then call `$client->log(...)` and let
     the script continue (or simply let the shutdown hook fire).
   - Point the transport at an endpoint that forces at least one retry (e.g.
     a fake `/v0/log` that 503s once, or measure timing against the
     unreachable production host with a short `log_timeout_ms`).
   - Confirm with a timer on the client side of the HTTP request: the
     response completes in roughly the time of the request itself, **not**
     `request_time + Σ(retry backoff + timeout)`. If the client-observed
     latency includes the retry ladder, the hook is draining before
     `fastcgi_finish_request()` took effect — do not ship until this passes.

---

## Phase 4 — Publish

1. Land every Phase 1–2 change on `main`, tests green, working tree clean.
2. Confirm `CHANGELOG.md`'s top entry is the version you're about to ship —
   `Constants::SDK_VERSION` and the CHANGELOG heading are asserted equal by
   `tests/ConstantsTest.php`, so a mismatched pair fails CI before it ever
   reaches a tag.
3. If this is the very first release, submit the package on Packagist:
   <https://packagist.org/packages/submit>, pointing at
   `https://github.com/SignalGate/signalgate-php`. (Only needed once — after
   that, the GitHub webhook from Phase 0 keeps Packagist in sync
   automatically on every tag push.)
4. Tag and push:
   ```bash
   git tag v0.1.0
   git push --tags
   ```
5. **Verify from the outside** — a different directory, nothing cached:
   ```bash
   cd /tmp && composer require signalgate/signalgate-php
   ```
   Confirm the Packagist page shows the new version, the repo link, and that
   the README renders.
6. Cut a GitHub Release on the tag with the CHANGELOG entry as the body.

---

## Phase 5 — Post-publish

- **Packagist has no unpublish window at all** — the "release" is a git tag
  on a public repo, and once Composer has resolved it into someone's
  `composer.lock`, it is effectively permanent. A bad release is fixed by
  publishing a new patch/minor version, never by force-pushing or deleting
  the tag.
- **Docs:** `README.md` links to <https://signalgate.ai/docs/php>. Confirm
  that page exists and matches the published API before announcing broadly.

---

## Already have CI

Unlike a manual-release package, this repo ships `.github/workflows/ci.yml`
from day one: a matrix over PHP 8.1–8.4 running `composer validate --strict`,
`composer install`, `vendor/bin/phpunit`, and a `php -l` lint sweep, on every
push and pull request. That means:

- **Green CI on the tagged commit is the release gate.** There is no separate
  "build" artifact to inspect the way there would be for a compiled or
  bundled package — the tag itself, if CI passed on it, is what ships.
- Phase 3's scratch-tag rehearsal still matters because CI validates the
  *source tree*, not what a fresh `composer require` actually resolves and
  autoloads for a consumer — that gap is exactly what the scratch tag closes.

---

## Checklist

```
Phase 0  [ ] Packagist account            [ ] signalgate vendor claimed
         [ ] GitHub webhook connected     [ ] repo is public
Phase 1  [ ] composer validate --strict passes (no "version" field)
         [ ] homepage in composer.json    [ ] CHANGELOG.md present
Phase 2  [ ] CONTRIBUTING  [ ] SECURITY  [ ] CoC
         [ ] no secrets in history        [ ] copyright holder confirmed
Phase 3  [ ] clean tree + suite green     [ ] scratch tag installed fresh
         [ ] PHP 8.1-8.4 CI green on scratch tag
         [ ] php-fpm fastcgi_finish_request() + drain latency check passed
         [ ] scratch tag deleted
Phase 4  [ ] Packagist submission (first release only)
         [ ] CHANGELOG/SDK_VERSION match  [ ] tagged  [ ] tag pushed
         [ ] verified from outside        [ ] GH Release
Phase 5  [ ] docs live
```
