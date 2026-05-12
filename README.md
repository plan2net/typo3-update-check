# TYPO3 Update Check

[![CI](https://github.com/plan2net/typo3-update-check/actions/workflows/ci.yml/badge.svg)](https://github.com/plan2net/typo3-update-check/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/github/v/release/plan2net/typo3-update-check?include_prereleases&label=latest)](https://github.com/plan2net/typo3-update-check/releases)
[![PHP Version](https://img.shields.io/packagist/php-v/plan2net/typo3-update-check)](https://packagist.org/packages/plan2net/typo3-update-check)
[![License](https://img.shields.io/github/license/plan2net/typo3-update-check)](https://github.com/plan2net/typo3-update-check/blob/main/LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/plan2net/typo3-update-check)](https://packagist.org/packages/plan2net/typo3-update-check)

A Composer plugin that intercepts TYPO3 core updates and displays breaking changes and security updates before proceeding.

## Purpose

Breaking changes and security fixes are easy to overlook when updating TYPO3. This plugin brings that information directly to your terminal the moment you run `composer update`, highlighting breaking changes (⚠️) and security updates (⚡) so you can make an informed decision before proceeding.

## Installation

```bash
composer require --dev plan2net/typo3-update-check
composer config allow-plugins.plan2net/typo3-update-check true
```

> [!NOTE]
> Composer 2.2+ requires plugins to be explicitly trusted. The second command adds the necessary entry to your `composer.json`. When running `composer require` interactively, Composer will prompt you to allow the plugin — answering yes has the same effect.

> [!WARNING]
> Install this plugin as a development dependency only. It is only useful during development when running `composer update`; production deployments typically use `composer install` with locked versions. If you choose to install it in production environments, you do so at your own risk.

## How it works

The plugin automatically activates during `composer update` and:

1. **Detects TYPO3 core updates** — Monitors when `typo3/cms-core` is being updated
2. **Fetches release information** — Retrieves data from the TYPO3 API for all versions between current and target
3. **Analyzes security bulletins** — Fetches severity levels (Critical, High, Medium, Low) from TYPO3 security advisories
4. **Displays important changes** — Shows only versions with breaking changes or security updates
5. **Requests confirmation** — Prompts before proceeding when breaking changes are found

In non-interactive environments (CI/CD), the plugin displays information but proceeds automatically. If the TYPO3 API is temporarily unavailable, the update continues without interruption.

## Example output

![Demo](documentation/demo.gif)

## Manual check

You can check for breaking changes and security updates between any two versions without running an actual update:

```bash
composer typo3:check-updates 13.0.0 13.0.1
```

![Demo](documentation/check-updates.gif)

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

API responses are cached to improve performance and reduce load on TYPO3 servers:

- **Location** — Composer's global cache directory (`~/.cache/composer` on Linux/macOS, `%LOCALAPPDATA%\Composer` on Windows)
- **Release lists** — 1 hour
- **Release content and security bulletins** — Permanent (content never changes)

The cache is shared across all TYPO3 projects on the same machine.

## Requirements

- PHP 8.1+
- Composer 2.0+

## License

GPL-2.0+
