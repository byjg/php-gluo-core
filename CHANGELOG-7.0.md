# Changelog - Version 7.0

> **Status: in development.** This document tracks changes landing on the `7.0` branch.
> Nothing here is released yet, and the contents may still change.

## Breaking Changes

- None.

## New Features

- `BaseLoginController::getResetEmailSubject()` sets the subject of the password reset
  email, next to the existing `getResetEmailTemplate()` hook. The subject used to be
  hard-coded as `"Service - Password Reset"`, a leftover from the template's rename
  placeholder, which nothing could rename once the code moved into `vendor/`. The default
  is now `"Password Reset"`; override the hook to brand it:

  ```php
  protected function getResetEmailSubject(): string
  {
      return 'Acme - Password Reset';
  }
  ```

## Bug Fixes

- `BaseLoginController::postResetRequest()` validated the request twice: once through the
  `#[ValidateRequest]` attribute on the route and again with
  `OpenApiContext::validateRequest()`. It now reads `ValidateRequest::getPayload()`, like
  `post()`, so the route must keep its `#[ValidateRequest]` attribute (the starter's
  `LoginController` has it).

## Requirements

- PHP 8.3, 8.4, 8.5 and 8.6 are now supported: `"php": ">=8.3 <8.7"`.
  The previous `<8.6` upper bound excluded PHP 8.6, since `<8.6` is exclusive.

### ByJG dependencies

- `byjg/anydataset-db` is now `^7.0`.
- `byjg/authuser` is now `^7.0`.
- `byjg/cache-engine` is now `^7.0`.
- `byjg/config` is now `^7.0`.
- `byjg/jinja-php` is now `^7.0`.
- `byjg/mailwrapper` is now `^7.0`.
- `byjg/micro-orm` is now `^7.0`.
- `byjg/migration` is now `^7.0`.
- `byjg/restserver` is now `^7.0`.
- `byjg/serializer` is now `^7.0`.
- `byjg/swagger-test` is now `^7.0`.

While 7.0 is unreleased these resolve to `7.0.x-dev` from each component's
`7.0` branch, via `minimum-stability: dev` with `prefer-stable: true`.

## Toolchain

- PHPUnit updated to `^12.5`.
- Psalm moved out of `require-dev` into its own manifest, `tools/psalm/composer.json`.

  Psalm enumerates the PHP versions it supports and no published release lists
  8.6. As a dev dependency it made `composer install` fail on the 8.6 build job
  before any test ran. It now installs separately, only for the Psalm job.

  `composer psalm` still works — it bootstraps the tool and runs it.

- PHPUnit 13 is deliberately **not** used. It requires PHP `>=8.4.1`, breaking the
  8.3 floor, and needs `sebastian/diff ^9.0`, which stable Psalm 6.16.1 rejects —
  a combination that silently resolves Psalm to an unreleased `6.x-dev` branch.

## Continuous Integration

- The build matrix now includes PHP 8.6.
- The Psalm job runs on PHP 8.5 and installs Psalm from `tools/psalm`.

## Housekeeping

- `phpunit.xml.dist` renamed to `phpunit.xml`.
