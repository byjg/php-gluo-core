# Changelog - Version 7.0

> **Status: in development.** This document tracks changes landing on the `7.0` branch.
> Nothing here is released yet, and the contents may still change.

## Breaking Changes

- `BaseConfigBootstrap` no longer caches the resolved container for `staging` and `prod`.
  It used `FileSystemCacheEngine` in the system temp dir, which only works when that dir is
  thrown away on every deploy (e.g. a fresh container); on a cache that outlives the deploy
  (a shared memcached/Redis, a persistent volume) the new release kept loading the previous
  release's container. Where the cache lives now is the project's decision, in
  `config/ConfigBootstrap.php`; the starter template already does it. To keep the previous
  behaviour in an existing project:

  ```php
  return new class extends BaseConfigBootstrap {
      #[\Override]
      protected function configureDefinition(Definition $definition): void
      {
          parent::configureDefinition($definition);
          $definition->getConfigObject('staging')->withCache(new FileSystemCacheEngine());
          $definition->getConfigObject('prod')->withCache(new FileSystemCacheEngine());
      }
  };
  ```
- Generated tests are now in the `Tests\Controller` namespace (it was `Test\Controller`), to
  match the other ByJG components. A project created before this change maps `Test\` to
  `tests/` in its `composer.json`: either rename that namespace to `Tests\` (the `autoload-dev`
  entry and the `namespace`/`use` lines under `tests/`), or adjust the namespace of each newly
  generated test by hand.

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
- Psalm is installed as `psalm/phar` instead of `vimeo/psalm`.

  `vimeo/psalm` lists the PHP versions it supports and no published release includes
  8.6, so as a dev dependency it made `composer install` fail on the 8.6 build job
  before any test ran. `psalm/phar` requires only `php ^8.2` and bundles its own
  dependencies, so it installs on every PHP version in the matrix and cannot conflict
  with the project's. Psalm itself still refuses to *run* on 8.6, which is why the
  Psalm job uses 8.5. `composer psalm` runs it.

- PHPUnit 13 is deliberately **not** used. It requires PHP `>=8.4.1`, breaking the
  8.3 floor.

## Continuous Integration

- The build matrix now includes PHP 8.6.
- The Psalm job runs on PHP 8.5.

## Housekeeping

- `phpunit.xml.dist` renamed to `phpunit.xml`.
- Added the `LICENSE` file (MIT); the license was already declared in `composer.json`.
