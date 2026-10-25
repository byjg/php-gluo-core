---
sidebar_key: gluo-core
sidebar_category: gluo
tags: [php, http]
---

# Gluo Core

The Gluo framework core — the glue ("gluo" is Esperanto for glue) that binds the
[byjg components](https://opensource.byjg.com) into a REST architecture.

[![Sponsor](https://img.shields.io/badge/Sponsor-%23ea4aaa?logo=githubsponsors&logoColor=white&labelColor=0d1117)](https://github.com/sponsors/byjg)
[![Build Status](https://github.com/byjg/php-gluo-core/actions/workflows/phpunit.yml/badge.svg?branch=master)](https://github.com/byjg/php-gluo-core/actions/workflows/phpunit.yml)
[![Opensource ByJG](https://img.shields.io/badge/opensource-byjg-success.svg)](https://opensource.byjg.com)
[![Install MCP Server](https://img.shields.io/badge/Install-MCP_Server-8A2BE2?logo=modelcontextprotocol&logoColor=white)](https://opensource.byjg.com/docs/ai/mcpserver-byjg-docs/)
[![GitHub source](https://img.shields.io/badge/Github-source-informational?logo=github)](https://github.com/byjg/php-gluo-core/)
[![GitHub license](https://img.shields.io/github/license/byjg/php-gluo-core.svg)](https://opensource.byjg.com/license/)
[![GitHub release](https://img.shields.io/github/release/byjg/php-gluo-core.svg)](https://github.com/byjg/php-gluo-core/releases/)

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
- **Utilities** — `JwtContext`, `OpenApiContext`, `FakeApiRequester`, `OrderBy`
- **Builder** — `BaseScripts`: database migration entry points, OpenAPI generation, and the
  code generator (`php gluo codegen:*`) with its Jinja templates
- **Console** — `Launcher`, the `php gluo` command line (built-in and project commands), and
  `Checker`, the built-in rows of `php gluo list --check`
- **Test harness** — `BaseApiTestCase` for contract-testing your REST endpoints
  against the OpenAPI definition
- **Traits** — `OaCreatedAt`, `OaUpdatedAt`, `OaDeletedAt`

## Installation

```bash
composer require byjg/gluo-core
```

## Documentation

The full documentation (getting started, guides, and reference) lives in the
[byjg/gluo](https://github.com/byjg/php-gluo) starter repository.

## Running Tests

```bash
composer install
vendor/bin/phpunit
```

----
[Open source ByJG](https://opensource.byjg.com)