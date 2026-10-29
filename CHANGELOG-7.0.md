# Changelog - Version 7.0

> **Status: in development.** This document tracks changes landing on the `7.0` branch.
> Nothing here is released yet, and the contents may still change.

## Breaking Changes

- `BaseUser` hashes passwords with bcrypt (byjg/authuser's `PasswordBcryptMapper`) instead of
  unsalted SHA-1. **Widen the `users.password` column to `VARCHAR(255)`** (a bcrypt hash has 60
  characters; a new project gets it from the starter's `db/base.sql`, an existing one adds a migration:
  `ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;`). Existing users keep logging in: a SHA-1 hash still
  verifies, and the login (`createAuthToken()`) replaces it with a bcrypt hash; until the column is
  widened, the SHA-1 hash is kept. To stay on SHA-1, redeclare `$password` in your `User` model with
  `#[FieldAttribute(updateFunction: PasswordSha1Mapper::class)]`.
- `BaseUser` has no constructor any more: it fetched the `PasswordDefinition` with `Config::get()`, so
  creating a user needed the container. The definition reaches `UsersService` through DI, and
  byjg/authuser 7.0 checks it on every save of the users repository (`PasswordDefinitionValidator`)
  and applies it to the users the service creates or loads. A `new User(...)` no longer validates
  its password in `setPassword()`; it is checked when it is saved.
- The routes are bound as `RouteListInterface::class` instead of `OpenApiRouteList::class`: the
  `Application` and `FakeApiRequester` (the API tests) get them by that key. Rename the key in
  `config/dev/03-api.php`; the binding itself stays the same:

  ```php
  RouteListInterface::class => DI::bind(OpenApiRouteList::class)
      ->withConstructorArgs([__DIR__ . '/../../public/docs/openapi.json'])
      // ...
      ->toSingleton(),
  ```
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
- The generated `PUT` takes the key from the path: `PUT /product/{id}`, as `GET` and `DELETE`
  already did, instead of `PUT /product` with the key in the body. Its body is the same as the
  `POST`'s: the writable fields only. A key sent in the body of either is now an unknown
  property, and the request is answered with 400. To move an existing controller, give its
  `OA\Put` the path `/{resource}/{id}` and an `OA\Parameter` for `id` (as its `OA\Get` has),
  use the `OA\RequestBody` of its `OA\Post`, and read the key with `$request->attribute('id')`.
  The clients change from `PUT /product` to `PUT /product/{id}`.
- `BaseService::update(array $payload)` is now `update(mixed $id, array $payload)`: the record
  is the one `$id` names, as in `getOrFail($id)`, and a key in the payload is ignored instead
  of selecting the record. Before, a payload without the key failed with 422.
- `BaseService`'s 404 says which model was not found: `"Product not found"`, as the ActiveRecord
  trait's `getOrFail()` does. It was `"Id not found"`. A client or a test that matches the old
  message has to change, or pass the message it wants to `getOrFail()`, `update()` or `delete()`.
- `BaseService::delete($id)` answers 404 when there is no record with that key. It used to succeed
  without deleting anything, unlike the ActiveRecord controllers, which already looked the
  record up first.

## New Features

- `ByJG\Gluo\Http\Application`: the HTTP entry point of a Gluo application, what `public/app.php`
  did itself. It gets the `Server` and the `OpenApiRouteList` from the container, and `handle()`
  serves one request: `handle()` alone handles the request PHP's globals describe, as before, and
  `handle($psr7Request)` a PSR-7 request (byjg/restserver's `Server::withRequestObject()`),
  rendering RestServer's HTTP errors itself. A persistent worker (RoadRunner, Swoole, a CLI worker)
  gets the `Application` once and calls `handle()` for each request. A project binds it in
  `config/dev/03-api.php`:

  ```php
  Application::class => DI::bind(Application::class)
      ->withInjectedConstructor()
      ->toSingleton(),
  ```

  and `public/app.php` becomes `Config::get(Application::class)->handle();` (the starter does both).
- `ByJG\Gluo\Trait\ActiveRecord`: MicroOrm's `ActiveRecord` plus `getOrFail($pk, ?string $message = null)`,
  which returns the record or throws `Error404Exception` (`"<Model> not found"` by default, or the
  message given). It is the ActiveRecord counterpart of `BaseService::getOrFail()`, like Laravel's
  `findOrFail()`, so a controller does not write the null check before it shows, changes or
  deletes a record. codegen uses it in ActiveRecord models, and the generated controllers call
  `Model::getOrFail($request->attribute('id'))`. An existing model switches by importing
  `ByJG\Gluo\Trait\ActiveRecord` instead of `ByJG\MicroOrm\Trait\ActiveRecord`.
- `BaseService::getOrFail()`, `update()` and `delete()` take the message of their 404 as an optional
  last argument: `getOrFail($id, $message)`, `update($id, $payload, $message)`,
  `delete($id, $message)`.

- `ByJG\Gluo\Util\SchemaValidator`: a model is validated against its own OpenAPI schema
  every time it is saved, not only when it comes in through an endpoint with
  `#[ValidateRequest]`. A command, a queue consumer or a service that saves a model is held to
  the same rules: the types, lengths, ranges and required fields of its `OA\Property`
  attributes. It is a MicroOrm validator, declared in the table attribute:

  ```php
  #[TableAttribute("product", validator: SchemaValidator::class)]
  ```

  The model is serialized as the API returns it and matched against
  `#/components/schemas/<Model>` (`byjg/swagger-test`'s `Schema::matchDefinition()`). A
  `readOnly` property, such as a generated key, is not required, and a null property is
  treated as not set. A mismatch throws the same 400 as `#[ValidateRequest]`. Writes that do
  not go through `save()` (`UpdateQuery`, `InsertBulkQuery`, raw SQL) are not validated.
  codegen declares it on every generated model.
- codegen marks the generated values `readOnly` in the model's schema: the primary key (an
  auto-increment column, or a UUID seeded by `TableUuidPKAttribute`) and the virtual
  columns. `OaCreatedAt`, `OaUpdatedAt` and `OaDeletedAt` mark their dates `readOnly` too.
  The API returns them and does not take them.

- `ByJG\Gluo\Console\Launcher`: the `php gluo` command line entry point.

  Built-in commands replace the Composer scripts and run through the project's
  `Builder\Scripts`, so project overrides still apply:

  | Command | Was |
  |---|---|
  | `php gluo migrate:update` (`create`, `version`, `reset`, `up`, `down`) | `composer migrate -- update` |
  | `php gluo codegen:all --table=x` (`model`, `repository`, `service`, `controller`, `test`) | `composer codegen -- --table=x all` |
  | `php gluo openapi`, `php gluo openapi:check` | `composer openapi`, `composer openapi:check` |
  | `php gluo terminal` | `composer terminal` |
  | `php gluo test` (`openapi:check`, then PHPUnit with the arguments passed through) | `composer test` |

  Project commands come from a `ByJG\Scriptify\CommandProvider` class of the project,
  which the `gluo` script passes to the launcher:
  `new Launcher(__DIR__, new \App\Command\Commands())`. Its `commands()` maps a name to a
  target with class references: `'user:cleanup' => Maintenance::class . '::cleanOldRecords'`,
  or a class for every public method it declares, in kebab-case
  (`'payment' => PaymentCommands::class` gives `payment:create`, `payment:clean-old-records`,
  ...). A typo fails in the IDE and in Psalm, and a rename is refactor-safe. Arguments are
  positional and map to the method parameters; `php gluo user:cleanup --help` prints the
  method's PHPDoc, and `php gluo list` its first line.

  The registry, the class expansion, the name rules, the help and the project rows of
  `list --check` are Scriptify's `Launcher`; `ByJG\Gluo\Console\Launcher` extends it with
  the built-ins, `--env` and the database rows, and reserves the built-in names. The class
  of a project command is built by the Gluo container (`Config::getContainer()`), only when
  a project command runs or `list --check` asks `has()`: `php gluo list` and the built-ins
  never build it.

- `php gluo --env=<env> install [--template=systemd|crond|upstart|initd] <command> [args...]`
  installs a project command as a system service, and `php gluo uninstall <command>` removes
  it. The service runs `php gluo` itself, so the Gluo container is built when it starts, and
  its command line is written in full: the PHP binary, the absolute path to `php gluo`,
  `--env`, the command and its arguments, in the project root. The command must pass
  `list --check`. The service is named after the project directory and the command
  (`shop-payment-sweep`), or `--name`; `--description` sets its description. Scriptify's
  `installCommand()` writes it.

  `--env=<env>` (also `--env <env>` and `-e <env>`, before or after the command) is
  consumed by the launcher and exported as `APP_ENV` before Config is initialised.

  `php gluo list` shows both groups; `php gluo list --check` adds a status per command
  (environment, configuration and database connectivity for the built-ins; name, target,
  visibility and container registration for project commands, without building them) and
  exits 1 if any is not `OK`.

  `php gluo migrate <sub>` and `php gluo codegen <components>` without the colon are accepted
  so the Composer scripts can stay as aliases.

- `BaseScripts::getMigrateHelp()` and `getCodeGeneratorHelp()` are now public, and their
  text uses the `php gluo` syntax.

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

- The generated REST controller (Repository pattern) receives its service through the
  constructor instead of calling `Config::get()` in every method. The project's `Server`
  must resolve controllers from the container: the starter does it with `withContainer()`
  in `config/dev/03-api.php` and an `Autowire` rule for the controller namespace in
  `config/dev/07-controllers.php`. Codegen writes no DI entry for controllers; repositories
  and services still get one; when `04-repositories.php`/`05-services.php` does not exist,
  codegen prints a warning to register the class by hand.

- `FakeApiRequester` gives its `MockServer` the Gluo container
  (`withContainer(Config::getContainer(), allowUnregistered: true)`), so controllers with
  constructor dependencies work under test as they do in production.

- `codegen` emits `maxLength` for `char` and `varchar` columns, on the model property and on
  the request body of the generated controllers. Templates get the declared length as
  `field.openapi_max_length` (`0` for any other type).

- **OpenAPI 3.1.** `php gluo openapi` writes the spec as OpenAPI 3.1.0 (it was swagger-php's
  default, 3.0.0). A nullable property is written as a type union
  (`"type": ["string", "null"]`) instead of `nullable: true`; the attributes in the code do
  not change. Override `BaseScripts::getOpenApiVersion()` to keep 3.0.0.
- **codegen: `minLength: 1` for required text.** A `NOT NULL` `char`/`varchar`/`text` column
  with no default (not a key, not generated) gets `minLength: 1` in the model and in the
  `POST` body, so an empty string is refused like a missing value, as Laravel's `required`
  and Symfony's `NotBlank` do. New template variable: `openapi_min_length`.
- **codegen: a test that reaches the server.** The generated test has
  `testInvalidPayloadIsRejectedByTheServer`: a `PUT` without the primary key, a `POST`
  without the required fields and an empty value for each `minLength` column, sent without
  the OpenAPI spec on the client side so they reach `#[ValidateRequest]`, expecting 400.

## Bug Fixes

- The generated test chose between an integer and a UUID key by the column's database type
  being exactly `int`, so a key declared `int(11)` got UUID paths (`/product/<uuid>`). It now
  uses the PHP type of the column.

- The Repository-pattern controller accepted `createdAt`, `updatedAt` and `deletedAt` in the
  `POST` body, which the ActiveRecord one leaves out: the dates are set by the database and
  the traits. Both now leave them out.

- codegen wrote the `OA\Property` arguments out of the order the attribute declares them
  (`maxLength` before `nullable`), which IDE inspections flag. It now writes `type`,
  `format`, `nullable`, `maxLength`, `minLength`.

- The generated list endpoints documented `orderBy` and `filter` query parameters but
  only read `page` and `size`. `orderBy` is now applied, through the new
  `ByJG\Gluo\Util\OrderBy::fromQueryString()`: one or more fields separated by commas,
  each optionally followed by `asc` or `desc`, accepted only when they are fields of the
  model (by property or column name) and rejected with a 400 otherwise, so nothing from
  the request reaches the SQL. `filter` is no longer generated: it had no defined format.

  `BaseService::list()` takes an optional third argument, `$orderBy`, passed to the
  repository. The generated test gains `testListOrderBy`.

  Controllers generated before this change keep working and keep ignoring both
  parameters; regenerate the controller, or copy the list method from a new one.

- `BaseLoginController::postResetRequest()` validated the request twice: once through the
  `#[ValidateRequest]` attribute on the route and again with
  `OpenApiContext::validateRequest()`. It now reads `ValidateRequest::getPayload()`, like
  `post()`, so the route must keep its `#[ValidateRequest]` attribute (the starter's
  `LoginController` has it).

- `BaseRepository::getUuid()` asked the database for the UUID with `SELECT upper(uuid())`,
  a MySQL-only function, so the password-reset flow of `BaseLoginController` failed on
  SQLite (and PostgreSQL) with `no such function: uuid`. It now generates a random version 4
  UUID in PHP, in the same format (upper case, with dashes), and needs no database
  connection.

- `codegen` read the table with MySQL-only statements (`EXPLAIN`, `SHOW INDEX`,
  `information_schema.key_column_usage`), so it failed on any other database; on SQLite with
  `near "payment": syntax error`. It now reads the table through the anydataset-db dialect
  (`getTableMetadata()`, `getTableIndexes()`, `getTableForeignKeys()`), on MySQL, SQLite,
  PostgreSQL, SQL Server and Oracle. Types such as PostgreSQL's `character varying(n)`,
  `numeric` and `timestamp without time zone` are mapped like their MySQL counterparts.

  The template variables are unchanged, so custom templates keep working. On MySQL there are
  two differences: `field.key` is `PRI` or empty (it used to be `UNI` or `MUL` for other
  indexed columns), and `field.extra` is `auto_increment`, `VIRTUAL GENERATED` (for any
  generated column, stored ones included, which are not writable either) or empty.
  `indexes` no longer has an entry for the primary key, which the templates skipped anyway.

  A table whose primary key is not auto-increment (a `binary(16)` UUID) gets a model with
  micro-orm's `TableUuidPKAttribute`, which generates the UUID in PHP on every database, instead
  of `TableMySqlUuidPKAttribute`, which asked MySQL for `uuid_to_bin(uuid())` and is now
  deprecated.

  `BaseScripts::buildCodegenData()` now takes the `ColumnMetadata`, `IndexMetadata` and
  `ForeignKeyMetadata` objects of anydataset-db instead of the raw MySQL rows; a project that
  overrides it has to follow. The new `buildCodegenDataFromDatabase()` reads them.

- `BaseApiTestCase` reset the test database with `MySqlDatabase` unless `getDatabaseClass()` was
  overridden, so the tests of a PostgreSQL or SQLite project failed with
  `Scheme "sqlite" does not found`. It now registers every class of the new
  `BaseScripts::MIGRATION_DATABASES` (MySQL, PostgreSQL, SQLite), the list `php gluo migrate`
  already used, and the scheme of `DBDRIVER_CONNECTION` picks one. `getDatabaseClass()` returns
  `null` by default; a project that overrides it keeps its class.

- `php gluo list --check` masks the database password in a `DB_UNREACHABLE` message with a
  literal replace, which corrupted the message when the password was short: a password `200`
  turned `SQLSTATE[HY000] [2002]` into `SQLSTATE[HY000] [****2]`. The literal replace now needs a password of
  at least `Checker::MASK_MIN_LENGTH` (4) characters; a password inside a URL (`://user:pw@`)
  is masked whatever its length, including `"0"` and a password-only URL (`://:pw@`), which
  was left unmasked before.

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
- `byjg/scriptify` `^7.0` is a new requirement: the launcher runs project commands through it.
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
