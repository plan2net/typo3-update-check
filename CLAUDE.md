# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Composer plugin that intercepts TYPO3 core updates during `composer update` and displays breaking changes and security updates before proceeding. It fetches release information from the TYPO3 API and prompts for user confirmation when important changes are found. It also ships a standalone Composer command (`typo3:check-updates`) for ad-hoc checks.

This is a Composer **plugin/library** (`composer-plugin`), not a TYPO3 site. A local DDEV environment (PHP 8.2) is present — per the global instructions, run commands inside the container: `ddev exec 'cd /var/www/html && <command>'`.

## Commands

```bash
composer install          # Install dependencies
composer test             # PHPUnit (unit + E2E suites under tests/)
composer analyse          # PHPStan (level 8)
composer cs-fix           # PHP CS Fixer (PSR-12)
```

Run a single test:
```bash
vendor/bin/phpunit --filter testMethodName
vendor/bin/phpunit tests/UpdateCheckerTest.php
```

Run only the E2E suite (spins up a `php -S` stub server, no network):
```bash
vendor/bin/phpunit tests/E2E
```

Manual version check (exercises the same flow without running `composer update`):
```bash
composer typo3:check-updates 12.4.10 12.4.20   # explicit from → to
composer typo3:check-updates 12.4.10           # → latest in the line
composer typo3:check-updates                   # installed version → latest
```

PHPUnit runs in strict mode (`failOnRisky`, `failOnWarning`, `beStrictAboutOutputDuringTests`, random order). Output written directly during a test (not via the IO mock) will fail the run.

## Architecture

The plugin both subscribes to a Composer event and exposes a command capability (`Plugin` implements `PluginInterface`, `EventSubscriberInterface`, and `Capable`).

**Update-check flow** (`Plugin::checkForBreakingChanges`, hooked on `PRE_POOL_CREATE` at priority 1000, guarded by `hasChecked`):

1. **Plugin** (`src/Plugin.php`) — detects a `typo3/cms-core` upgrade (current installed version → highest target in the pool), orchestrates the check, prints the report, and prompts for confirmation. In non-interactive shells it prints and proceeds; on API failure it prints a humanized reason and proceeds without blocking the update.

2. **UpdateChecker** (`src/UpdateChecker.php`) — pure version logic, no I/O: `findTargetVersion` (highest core version in the pool), `filterVersionsBetween`, and `securityReleasesAbove` (security releases newer than the target — the "security gap" warning).

3. **VersionParser** (`src/VersionParser.php`) — normalizes pretty versions to comparable `x.y.z` strings; returns `null` for unparseable input (callers must handle null).

4. **HTTP boundary** (`src/Http/`) — `HttpClient` interface (`get`/`getMany`), `HttpResponse`/`HttpTransportException` value types, and `ComposerHttpClient`, the only class translating Composer HTTP types (`HttpDownloader`, `TransportException`). `getMany()` guarantees an outcome per input key and never throws.

5. **ReleaseProviderFactory** (`src/ReleaseProviderFactory.php`) — composition root. Wires two `ComposerHttpClient` adapters over the injected `Composer\Util\HttpDownloader` (API client with `Accept: application/json` + 10s timeout, bulletin client without the header), plus `CacheManager`, `SecurityBulletinFetcher`, `ChangeParser`, and `ReleaseProvider`. Both `Plugin` and `CheckUpdatesCommand` pass `$composer->getLoop()->getHttpDownloader()`. `Plugin::setReleaseProvider` is a test seam only.

6. **ReleaseProvider** (`src/Release/`) — fetches release lists per major version and release content. Content is fetched **concurrently** via `HttpClient::getMany()` (Composer's HTTP layer, parallelism per `COMPOSER_MAX_PARALLEL_HTTP`) and returned as a `ReleaseContentBatch`. Per-version fetch failures are captured (not thrown) so partial results still display; list-fetch failure throws `ApiFailureException`.

7. **ReleaseContentBatch** (`src/Release/ReleaseContentBatch.php`) — value object holding `results` (version → `ReleaseContent`) and `failures` (version → `ApiFailure`), plus `hasImportantChanges()` and `dominantFailureCategory()`.

8. **Error categorization** (`src/Release/`) — `ApiFailureClassifier` maps any `Throwable` to an `ApiFailure` with an `ApiFailureCategory` (ConnectionError, ServerError, NotFound, MalformedResponse, Unknown). `ApiFailureException` wraps a failure; `FailureMessageFormatter` turns it into user-facing text.

9. **RetryPolicy** (`src/Http/RetryPolicy.php`) — 429 top-up policy used by `ComposerHttpClient`: up to 2 retries on HTTP 429 only (transient connect/5xx retries are delegated to Composer's transport). Exponential backoff (1s, 2s); honors `Retry-After`, capped at 5s.

10. **ChangeParser** (`src/Change/ChangeParser.php`) — parses API content into typed `Change` objects (`BreakingChange`, `SecurityUpdate`, `RegularChange`) via `ChangeFactory`, enriching security updates with severities from the bulletin fetcher.

11. **SecurityBulletinFetcher** (`src/Security/`) — fetches severity levels (Critical/High/Medium/Low) from TYPO3 security bulletin pages.

12. **CacheManager** (`src/Cache/`) — caches API responses in Composer's global cache dir. Release lists expire after 1 hour; release content and security bulletins are cached permanently.

13. **ConsoleFormatter** (`src/ConsoleFormatter.php`) — `formatBatchReport` renders the per-version report plus a one-line digest (releases scanned, security updates with severity totals, breaking changes); `formatSecurityGap` renders the warning for newer security releases above the target.

14. **CheckUpdatesCommand** (`src/Command/`) — the `typo3:check-updates` command (registered via `CommandProvider`). Resolves/validates from/to versions against the released list, offers the latest when a target is unknown, then runs the same batch + formatter pipeline. Exit codes: `SUCCESS`, `FAILURE`, `INVALID` (results empty but failures present).

## Testing

- **Unit tests** mock the IO / HTTP boundary and exercise classes directly.
- **E2E tests** (`tests/E2E/`) start a real `php -S` stub server (`tests/E2E/stub/server.php`) on a random port and drive `ReleaseProvider` against it — used to verify retry/backoff and partial-failure behavior without hitting the network. `BaseE2ETestCase::makeProvider()` builds providers with a real `ComposerHttpClient` pointed at the stub (secure-http disabled).

## Code Style

- PHP 8.1+ with `declare(strict_types=1)`
- PSR-12 (enforced by PHP CS Fixer)
- PHPStan level 8
- `final` + readonly classes/properties where applicable; prefer `match` over `switch` and early returns
- No abbreviated names
