# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-11

### Added
- Bounded retry (up to 3 attempts) with exponential backoff and honored
  `Retry-After` header (capped at 5 s) on TYPO3 API requests.
- Per-version failure categorization: connection error, server error,
  not found, malformed response, unknown.
- `ReleaseContentBatch` value object returned by
  `ReleaseProvider::getReleaseContents()` carrying both results and
  per-version `ApiFailure` entries.
- `CheckUpdatesCommand` now returns a non-zero exit code on total
  failure so CI can detect API outages.

### Changed
- `ReleaseProvider::getReleaseContents()` return type changed from
  `array<string, ReleaseContent>` to `ReleaseContentBatch`.
- `getReleasesForMajorVersion()` now throws `ApiFailureException`
  (a `RuntimeException` subclass carrying an `ApiFailure`) instead of
  a generic `RuntimeException`.
- Plugin and command output: per-version warnings with a retry
  suggestion; when every version fails, the dominant category is
  reported.

### Fixed
- JSON decoding errors inside the batch's `fulfilled` callback were
  silently swallowed; they are now classified as
  `MalformedResponse` and reported.
- Pool requests that fail (network, 5xx, 4xx) no longer disappear
  silently; the `rejected` callback now records them.
