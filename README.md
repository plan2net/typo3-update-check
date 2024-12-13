# TYPO3 Update Check

A Composer plugin that intercepts TYPO3 core updates and displays breaking changes and security updates before proceeding.

## Purpose

This Composer plugin helps prevent oversight of important changes in (often) minor updates by highlighting breaking changes (⚠️) and security updates (⚡) before proceeding with TYPO3 core updates.

## Installation

```bash
composer require --dev plan2net/typo3-update-check
```

**Note:** This plugin should only be installed as a development dependency since it's only useful during development when running `composer update`. Production deployments typically use `composer install` with locked versions. If you choose to install it in production environments, you do so at your own risk.

## How it works

The plugin automatically activates during `composer update` and:

1. **Detects TYPO3 core updates** - Monitors when `typo3/cms-core` is being updated
2. **Fetches release information** - Retrieves data from the TYPO3 API for all versions between current and target
3. **Displays important changes** - Shows only versions with breaking changes or security updates
4. **Requests confirmation** - Prompts before proceeding with updates that contain breaking changes

## Example output

![Demo](documentation/render.gif)

```
TYPO3 core will be updated from 12.4.10 to 12.4.15
Fetching version information...

Changes in version 12.4.15:
Security updates found:
  ⚡ [SECURITY] Protect frame GET parameter in tx_cms_showpic eID
  ⚡ [SECURITY] Encode all file properties in tx_cms_showpic output
  ⚡ [SECURITY] Prevent XSS in FormManager backend module

Security advisories:
  - https://typo3.org/security/advisory/typo3-core-sa-2024-008
  - https://typo3.org/security/advisory/typo3-core-sa-2024-009
  - https://typo3.org/security/advisory/typo3-core-sa-2024-010

Release announcement: https://typo3.org/article/typo3-12415-security-release

Do you want to continue with the update? [y/N]
```

## Non-interactive mode

In non-interactive environments (CI/CD), the plugin will display information but automatically proceed with the update.

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
