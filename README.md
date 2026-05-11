# TYPO3 Update Check

[![CI](https://github.com/plan2net/typo3-update-check/actions/workflows/ci.yml/badge.svg)](https://github.com/plan2net/typo3-update-check/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/github/v/release/plan2net/typo3-update-check?include_prereleases&label=latest)](https://github.com/plan2net/typo3-update-check/releases)
[![PHP Version](https://img.shields.io/packagist/php-v/plan2net/typo3-update-check)](https://packagist.org/packages/plan2net/typo3-update-check)
[![License](https://img.shields.io/github/license/plan2net/typo3-update-check)](https://github.com/plan2net/typo3-update-check/blob/main/LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/plan2net/typo3-update-check)](https://packagist.org/packages/plan2net/typo3-update-check)

A Composer plugin that intercepts TYPO3 core updates and displays breaking changes and security updates before proceeding.

## Purpose and motivation

When updating TYPO3, it's easy to overlook critical changes buried in release notes and announcements. Even minor version updates can introduce breaking changes or important security fixes that require immediate attention. Traditionally, developers need to manually check release announcements, security advisories, and changelogs—a time-consuming process that's often skipped under deadline pressure.

This Composer plugin solves this problem by bringing important information directly to your terminal, exactly when and where you need it. During the update process, it automatically highlights breaking changes (⚠️) and security updates (⚡), ensuring you never miss critical changes that could impact your application's functionality or security.

## Installation

```bash
composer require --dev plan2net/typo3-update-check
composer config allow-plugins.plan2net/typo3-update-check true
```

> [!NOTE]
> Composer 2.2+ requires plugins to be explicitly trusted. The second command adds the necessary entry to your `composer.json`. When running `composer require` interactively, Composer will prompt you to allow the plugin — answering yes has the same effect.

> [!WARNING]
> This plugin should only be installed as a development dependency since it's only useful during development when running `composer update`. Production deployments typically use `composer install` with locked versions. If you choose to install it in production environments, you do so at your own risk.

## How it works

The plugin automatically activates during `composer update` and:

1. **Detects TYPO3 core updates** - Monitors when `typo3/cms-core` is being updated
2. **Fetches release information** - Retrieves data from the TYPO3 API for all versions between current and target
3. **Analyzes security bulletins** - Fetches severity levels (Critical, High, Medium, Low) from security advisories
4. **Displays important changes** - Shows only versions with breaking changes or security updates, including severity summary
5. **Requests confirmation** - Prompts before proceeding with updates that contain breaking changes

## Example output

![Demo](documentation/demo.gif)

## Non-interactive mode

In non-interactive environments (CI/CD), the plugin will display information but automatically proceed with the update.

## Manual check

Once installed, you can manually check for breaking changes and security updates between any two versions:

```bash
composer typo3:check-updates 12.4.10 12.4.20
```

This is useful for planning upgrades or reviewing changes without actually performing an update.

![Demo](documentation/check-updates.gif)

## Security severity information

When security updates are detected, the plugin automatically fetches severity information from TYPO3 security bulletins and displays a summary:

- **Severity levels**: Critical, High, Medium, Low

This helps developers quickly assess the urgency of security updates without manually checking each bulletin.

## API availability

The plugin tolerates transient TYPO3 API issues automatically:

- **Bounded retry:** each request is retried up to two times (three
  attempts total) on connection errors, timeouts, HTTP 5xx, and HTTP
  429 responses. Backoff is exponential (1 s, 2 s) and honors the
  server's `Retry-After` header when present, capped at 5 s so a
  `composer update` is never delayed for long.
- **No retry on deterministic errors:** HTTP 4xx responses other than
  429 are treated as final and reported immediately.
- **Per-version reporting:** when only some versions fail to fetch,
  the others are still shown. Each failure is categorized — network
  error, server error, not found, or malformed response — and the
  plugin suggests retrying with `composer typo3:check-updates` for the
  skipped versions.
- **Fail-soft:** if every request fails after retries, the plugin
  reports the dominant failure category and lets the update proceed
  so your development workflow is never blocked.

## Caching

The plugin caches API responses to improve performance and reduce load on the TYPO3 API servers:

- **Cache location**: Uses Composer's global cache directory (`~/.cache/composer` on Linux/macOS, `%LOCALAPPDATA%\Composer` on Windows)
- **Cache duration**: 
  - Release lists: 1 hour (automatically refreshed)
  - Release content: Permanent (version content never changes)
  - Security bulletins: Permanent (bulletin content never changes)
- **Shared cache**: Works across all TYPO3 projects on the same machine
- **Automatic cleanup**: Expired cache entries are automatically removed

The caching system ensures fast subsequent runs while keeping release information up-to-date.

## Development

### Setup
```bash
composer install
```

### Testing
```bash
composer test
```

### Code quality
```bash
composer analyse
composer cs-fix
```

## Requirements

- PHP 8.1+
- Composer 2.0+

## License

GPL-2.0+
