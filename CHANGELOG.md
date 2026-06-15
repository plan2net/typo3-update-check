# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2026-06-15

### Added
- Support for cross-major TYPO3 core updates (e.g. 12.4 → 13.4). The
  report leads with an upgrade banner linking the official upgrade guide
  and the changelog of every crossed major, and collapses breaking
  changes to a per-release count while keeping full security detail
  (CVEs, severities, bulletins). Updates within a major line keep the
  detailed format.
- A major upgrade always asks for confirmation in interactive shells,
  even when release information could not be fetched.
- Jumping more than one major at once (e.g. 12 → 14) prints a warning,
  since TYPO3 supports upgrading one major version at a time.
- The `typo3:check-updates` command accepts cross-major version ranges;
  for an unknown cross-major target it offers the latest release of the
  requested major's line.

## [2.1.0] - 2026-06-11

### Changed
- Security advisories are matched through Packagist's
  security-advisories API (the same data source as `composer audit`)
  instead of scraping typo3.org bulletin pages. The plugin now does one
  cached bulk lookup, matches advisories by Composer version constraints
  for each release, and avoids over-counting advisories from sibling
  TYPO3 release lines.
- Security sections now lead with fixed vulnerabilities, including CVE
  number, severity, title, and link where Packagist has that data,
  followed by the changelog entries that fixed them under a `Fixed by:`
  label. When advisory data is missing, typo3.org bulletin links remain
  visible.
- Reports now distinguish unavailable Packagist advisory data from
  security releases whose CVE/severity details are not yet published, and
  mark those releases as `unrated` in severity totals.

### Removed
- Direct typo3.org security-bulletin scraping (`SecurityBulletinFetcher`).

## [2.0.0] - 2026-06-10

### Added
- The check can be disabled via the `TYPO3_UPDATE_CHECK` environment
  variable (`0`, `false`, `off`, `no`); the manual `typo3:check-updates`
  command is not affected.

### Removed
- The `guzzlehttp/guzzle` dependency. The plugin now uses Composer's own
  HTTP layer (`HttpDownloader`) and has no third-party runtime
  dependencies, eliminating dependency conflicts with host projects.

### Changed
- Retry behavior: transient connection/5xx failures are now retried by
  Composer's transport (Composer ≥ 2.3 with ext-curl); the plugin itself
  retries only HTTP 429, honoring `Retry-After` capped at 5 s. Fail-soft
  behavior, output, caching, and exit codes are unchanged.
- Concurrent fetches follow Composer's parallelism setting
  (`COMPOSER_MAX_PARALLEL_HTTP`) instead of a fixed limit of 5.
- PHP class APIs reshaped around the new `HttpClient` boundary
  (`ReleaseProviderFactory::create()` now requires an `HttpDownloader`;
  `ReleaseProvider`/`SecurityBulletinFetcher` take an `HttpClient`;
  `RetryPolicy` moved to the `Http` namespace with a new
  `shouldRetry()`/`delayMs()` API).
- Security bulletin severities are fetched concurrently instead of
  one request at a time.
- The license identifier in composer.json uses the current SPDX form
  `GPL-2.0-or-later`.

## [1.3.0] - 2026-06-04

### Added
- A one-line digest after the per-version details, summarizing the
  releases scanned, security updates with severity totals, and breaking
  changes.
- A "missing security fixes" warning when the resolved target lands
  below newer security releases, listing the versions you would skip.
- `composer typo3:check-updates` now takes optional arguments: with none
  it uses the installed `typo3/cms-core` version and the latest release
  in that line; with only the current version it defaults the target to
  the latest. Auto-detected or defaulted values are confirmed before the
  check runs.

### Changed
- `composer typo3:check-updates` validates that both versions exist in
  the release line: an unknown target offers the latest instead, and an
  unknown current version is rejected with a hint.

### Fixed
- Compatibility with Composer 2.9 / Symfony Console 8: the
  `typo3:check-updates` command no longer relies on the removed
  `setName()` / `setDescription()` methods, so it builds and runs on the
  latest PHP and Composer.

## [1.2.0] - 2026-05-12

### Added
- Bounded retry (up to 3 attempts) with exponential backoff and honored
  `Retry-After` header (capped at 5 s) on transient TYPO3 API issues,
  so a single hiccup no longer blocks `composer update`.
- Per-version failure messages: when some versions fail to fetch, the
  others are still shown and each failure is categorized (network
  error, server error, not found, malformed response) with a retry
  suggestion.
- Plugin output is now framed by a yellow `TYPO3 Update Check` header
  and a separator so users can see at a glance where the messages come
  from.
- `composer typo3:check-updates` returns a non-zero exit code when
  every version fails, so CI can detect API outages.
