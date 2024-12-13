# TYPO3 Core Update Check

A Composer plugin that checks for breaking changes and security updates when updating TYPO3 core packages. It shows warnings and requires confirmation before proceeding with potentially breaking updates.

## Installation

```bash
composer require --dev plan2net/typo3-update-check
```

## Features

- Detects breaking changes during TYPO3 core updates
- Shows security advisories and their details
- Lists upgrade instructions when available
- Requires confirmation for updates with breaking changes
- Works with Composer's version constraints

## Example Output

```sh
Loading composer repositories with package information
TYPO3 core will be updated from 12.4.2 to 12.4.13

Changes in version 12.4.11:
Breaking Changes Found:
  ! [!!!][SECURITY] Enforce absolute path checks in FAL local driver

Security Updates Found:
  ✓ [SECURITY] Deny directly modifying file abstraction layer entities
  ✓ [SECURITY] Prevent arbitrary access to privileged resources via t3://

Security Advisories:
  - https://typo3.org/security/advisory/typo3-core-sa-2024-001

Release announcement: https://typo3.org/article/typo3-1301-12411-security-releases-published

Breaking changes were found. Do you want to continue with the update? [y/N]
```

## Usage

The plugin activates automatically during `composer update`. To check for updates:

```sh
# Update all TYPO3 packages
composer update typo3/*

# Update specific TYPO3 package
composer update typo3/cms-core
```

## Requirements

- PHP 8.2 or higher
- Composer 2.0 or higher
- GuzzleHttp 7.0 or higher
