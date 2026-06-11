# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - unreleased

### Changed
- Security severities and CVE numbers are now sourced from the Packagist
  security-advisories API (the same data `composer audit` uses) instead of
  scraping typo3.org bulletin pages: one bulk request, version-constraint
  matching per release, and severity labels in lowercase. This also fixes
  miscounted severities when a release-day announcement listed bulletins
  of sibling release lines.
- The report leads with the fixed vulnerabilities per release (CVE number,
  right-aligned severity, title, link in aligned columns, with a total in
  the heading) and lists the fixing changelog commits beneath a `Fixed by:`
  label — one commit often fixes several CVEs; typo3.org bulletin URLs are
  shown only when no advisory data is available.

### Removed
- typo3.org security-bulletin scraping (`SecurityBulletinFetcher`).

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
