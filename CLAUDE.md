# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A Composer plugin that intercepts TYPO3 core updates during `composer update` and displays breaking changes and security updates before proceeding. It fetches release information from the TYPO3 API and prompts for user confirmation when important changes are found.

## Commands

```bash
composer install          # Install dependencies
composer test             # Run PHPUnit tests
composer analyse          # Run PHPStan (level 8)
composer cs-fix           # Run PHP CS Fixer
```

Run a single test:
```bash
vendor/bin/phpunit tests/UpdateCheckerTest.php --filter testMethodName
```

Manual version check (useful for testing without running composer update):
```bash
composer typo3:check-updates 12.4.10 12.4.20
```

## Architecture

The plugin hooks into Composer's `PRE_POOL_CREATE` event via `Plugin.php`:

1. **Plugin** (`src/Plugin.php`) - Entry point implementing `PluginInterface` and `EventSubscriberInterface`. Orchestrates the update check flow.

2. **ReleaseProvider** (`src/Release/`) - Fetches release data from `https://get.typo3.org/api/v1`. Returns `Release` objects (version metadata) and `ReleaseContent` (parsed changes).

3. **ChangeParser** (`src/Change/ChangeParser.php`) - Parses API response to extract breaking changes and security updates. Uses `ChangeFactory` to create typed `Change` objects (`BreakingChange`, `SecurityUpdate`, `RegularChange`).

4. **SecurityBulletinFetcher** (`src/Security/`) - Fetches severity levels (Critical/High/Medium/Low) from TYPO3 security bulletin pages.

5. **CacheManager** (`src/Cache/`) - Caches API responses in Composer's global cache directory. Release lists expire after 1 hour; release content and security bulletins are cached indefinitely.

6. **ConsoleFormatter** (`src/ConsoleFormatter.php`) - Formats `ReleaseContent` for terminal output with proper escaping.

## Code Style

- PHP 8.1+ with strict types
- PSR-12 coding standard (enforced by PHP CS Fixer)
- PHPStan level 8
- Use readonly classes/properties where applicable
- Prefer `match` expressions over switch statements
