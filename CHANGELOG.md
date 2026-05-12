# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
