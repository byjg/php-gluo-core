# Gluo Core

[![Build Status](https://github.com/byjg/php-gluo-core/actions/workflows/phpunit.yml/badge.svg?branch=master)](https://github.com/byjg/php-gluo-core/actions/workflows/phpunit.yml)
[![Opensource ByJG](https://img.shields.io/badge/opensource-byjg-success.svg)](http://opensource.byjg.com)
[![GitHub source](https://img.shields.io/badge/Github-source-informational?logo=github)](https://github.com/byjg/php-gluo-core/)
[![GitHub license](https://img.shields.io/github/license/byjg/php-gluo-core.svg)](https://opensource.byjg.com/opensource/licensing.html)
[![GitHub release](https://img.shields.io/github/release/byjg/php-gluo-core.svg)](https://github.com/byjg/php-gluo-core/releases/)

The Gluo framework core — the glue ("gluo" is Esperanto for glue) that binds the
[byjg components](https://opensource.byjg.com) into a REST architecture.

You normally don't install this package directly. Start a project with the
[byjg/gluo](https://github.com/byjg/php-gluo) starter instead:

```bash
composer create-project byjg/gluo my-api
```

The starter generates a project you fully own; `byjg/gluo-core` stays in `vendor/`
and framework improvements arrive with a plain `composer update`.

## What's inside

- **Base classes** — `BaseLoginController`, `BaseRepository`, `BaseService`, `BaseUser`,
  `BaseUserProperties`, `BaseConfigBootstrap`
- **Attributes** — `RequireAuthenticated`, `RequireRole`, `ValidateRequest`
- **Utilities** — `JwtContext`, `OpenApiContext`, `FakeApiRequester`
- **Builder** — database migration entry points, OpenAPI generation, and the code
  generator (`composer codegen`) with its Jinja templates
- **Test harness** — `BaseApiTestCase` for contract-testing your REST endpoints
  against the OpenAPI definition
- **Traits** — `OaCreatedAt`, `OaUpdatedAt`, `OaDeletedAt`

## Install

```bash
composer require byjg/gluo-core
```

## Documentation

The full documentation (getting started, guides, and reference) lives in the
[byjg/gluo](https://github.com/byjg/php-gluo) starter repository.

## Running the tests

```bash
composer install
vendor/bin/phpunit
```

----
[Open source ByJG](http://opensource.byjg.com)