# CLI commands, logging and system checks (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin needs its own `bin/console` commands, its own Monolog channel/log file, system checks (health checks) or feature flag queries.

Scope: service registration in general in [T-05](./05-registering-services.md); plugin structure/`build()` in [T-02](./02-plugin-structure.md); scheduled tasks in [T-33](./33-scheduled-tasks.md); plugin settings instead of custom flags in [T-19](./19-plugin-configuration.md); coding rules in [T-45](./45-coding-guidelines.md); build scripts/deployment in [T-49](./49-build-deployment.md).

## 2. Concepts

- **Command**: class extending `Symfony\Component\Console\Command\Command` with `#[AsCommand(name: ..., description: ...)]`, registered as a service with tag `console.command`. Core models: `core/Framework/Plugin/Command/PluginListCommand.php`, `core/Framework/SystemCheck/Command/SystemCheckCommand.php`; registration e.g. in `core/Framework/DependencyInjection/health.php`.
- **Logging**: Monolog via the Symfony Monolog bundle. Custom channel via `monolog.channels` + handler in `Resources/config/packages/monolog.yaml`; logger service `monolog.logger.<channel>` (`Psr\Log\LoggerInterface`). **Plugins do not load `Resources/config/packages/*.yaml` automatically**: `Bundle::build()` does not call `buildDefaultConfig()` (in the core only `Framework`, `Profiling`, `Administration`, `Storefront`, `Elasticsearch` do). `buildDefaultConfig()` is `protected` and can be called in the plugin class's own `build()` after `parent::build()`; alternatively set up the loader yourself (example 4.1). Consistent with [T-05](./05-registering-services.md) and [T-34](./34-message-queue.md). Core channel `business_events` (flow events, table `log_entry` via `DoctrineSQLHandler`).
- **System check** (new in 6.7): class extending `Shopware\Core\Framework\SystemCheck\BaseCheck` (`run(): Result`, `category(): Category`, `name(): string`, `allowedSystemCheckExecutionContexts(): array`), tag **`shopware.system_check`**. Executed via `bin/console system:check` or HTTP.
- **Feature flags**: `Shopware\Core\Framework\Feature::isActive('FLAG')` (PHP), `{% if feature('FLAG') %}` (Twig); flags are defined in `core/Framework/Resources/config/packages/feature.yaml`; CLI `feature:list|enable|disable|dump`.

Plugin tree:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php                    # build() loads packages/*.yaml (e.g. via buildDefaultConfig())
    ├── Command/ExampleCommand.php
    ├── SystemCheck/QueueSizeCheck.php
    ├── Service/ImportService.php
    └── Resources/config/
        ├── services.php                   # XML is deprecated in 6.7
        └── packages/monolog.yaml
```

## 3. Instructions

**Command**
1. Class with `#[AsCommand(name: 'vendor:action', description: '…')]`; call `parent::__construct()` in the constructor; `execute()` returns `Command::SUCCESS|FAILURE|INVALID`.
2. Register in `services.php` with `->tag('console.command')` (explicitly; whether autoconfigure sets it has not been specifically tested).
3. `bin/console cache:clear`, then `bin/console list swag` and `bin/console swag:example --dry-run`.
4. Naming convention: the core uses `namespace:action` (`plugin:list`, `system:check`, `feature:enable`); plugins use their own prefix.
5. Do not use `ShopwareStyle` anymore (`@deprecated tag:v6.8.0`); use `SymfonyStyle` instead.

**Logging**
1. Override `build()` in the plugin class (example 4.1) and create `packages/monolog.yaml`.
2. Inject the logger via `service('monolog.logger.<channel>')`; the name must exactly match the entry in `monolog.channels`.
3. `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`; log file `var/log/swag_example_<env>.log`.
4. Operator configuration `shopware.logger.*` (`core/Framework/Resources/config/packages/shopware.yaml` from line 548): `enforce_throw_exception`, `file_rotation_count: 14`, `exclude_exception`, `exclude_events`, `error_code_log_levels`. They take effect via the decorators `ExcludeExceptionHandler`, `ErrorCodeLogLevelHandler`, `ExcludeFlowEventHandler` **only on the handler `main`** (`core/Framework/DependencyInjection/services.php` approx. lines 861-880). Prod: `fingers_crossed` with `action_level: error`, rotating_file; dev: stream, debug.
5. `log_entry`: the channel `business_events` writes via `DoctrineSQLHandler`; `LoggingService` logs `FlowLogEvent`s; events can implement `LogAware` (`getLogData()`, `getLogLevel(): Monolog\Level`); cleanup via `LogCleanupTask`.
6. `#[Package('…')]` (`core/Framework/Log/Package.php`) is `@internal`; do not use it in plugin code.

**System check**
1. Extend `BaseCheck`, implement the four methods, set tag `shopware.system_check` (tag contract: `core/DevOps/StaticAnalyze/PHPStan/tagged-service-contracts.php` line 137).
2. `Category` determines the order: `SYSTEM = 0`, `FEATURE = 8`, `EXTERNAL = 32`, `AUXILIARY = 128`. `SystemChecker` executes in ascending order; if a `SYSTEM` check returns `healthy` other than true, all further categories are skipped as `SKIPPED`.
3. Contexts (`SystemCheckExecutionContext`): `WEB`, `CLI`, `PRE_ROLLOUT`, `RECURRENT`; helpers `readiness()` = [PRE_ROLLOUT], `longRunning()` = [CLI, RECURRENT, PRE_ROLLOUT]. Checks that are not allowed appear as `SKIPPED` (`healthy` = null) and make `system:check` end with exit code 1 (`FAILURE`), as do checks that are skipped after an unhealthy `SYSTEM` check.
4. `Result(string $name, Status $status, string $message, ?bool $healthy = null, array $extra = [])`; `Status`: OK, UNKNOWN, SKIPPED, WARNING, ERROR, FAILURE. `healthy` is a separate flag; `null` counts as unhealthy (`system:check` then returns `FAILURE`). Exceptions in `run()` are caught by `SystemChecker` and turned into `Status::FAILURE`.
5. Invocation: `bin/console system:check [--context=cli|recurrent|pre_rollout] [--format=table|json] [-v]` (default `cli`; `-v` shows `extra`). The listed values are only suggestions (autocompletion, `longRunning()`): any value of `SystemCheckExecutionContext` is accepted, i.e. also `--context=web`; only unknown values result in `Command::INVALID`. HTTP: `GET /api/_info/system-health-check?context=web` (default `web`; Bearer or `Authorization: Static <token>` with `shopware.api.static_token.health_check`, `HealthCheckController`). Simple liveness endpoint without auth: `GET /api/_info/health-check`.
6. Core references: `core/System/Locale/SystemCheck/LocalesReadinessCheck.php`, `administration/Framework/SystemCheck/AdministrationReadinessCheck.php`.

**Feature flags**
1. Names are normalized by `Feature::normalizeName()` (dots/colons/hyphens to `_`, uppercase).
2. Resolution in `Feature::isActive()` (`core/Framework/Feature.php` from line 139): unknown flags warn outside `prod`; the flag's env variable has the highest priority; then `FEATURE_ALL` (`1`/`minor`, `major`, `v6.8.0.0`); then the `Feature::setActive` registry; finally the default from the configuration.
3. Further API: `Feature::ifActive/ifNotActive(closure)`, `has()`, `getAll()`, `throwException()`, `triggerDeprecationOrThrow()`; test helpers `fake()`, `withFeatureEnabled/Disabled()`. `registerFeature(s)`/`getRegisteredFeatures()` are `@internal`. `Feature::ifActiveCall(...)` from the docs does **not** exist in 6.7.15.1 (available: `ifActive`, `ifNotActive`, `callSilentIfInactive`, `silent`).
4. Twig: `feature('FLAG')`, `getAllFeatures()` (`FeatureFlagExtension`); unknown flags return `false`.
5. Services behind a flag: tag `shopware.feature` with attribute `flag`; the service is removed before optimization if the flag is inactive (`FeatureFlagCompilerPass`, priority 1000).
6. CLI: `bin/console feature:list`, `feature:enable <flag>`, `feature:disable <flag>`, `feature:dump` (alias `administration:dump:features`). Toggling only with `toggleable: true` and active `shopware.feature_toggle.enable` (default `true`); stored in the key-value storage `feature.flags`; major flags are not loaded from the storage. Env activation in `.env`: e.g. `TELEMETRY_METRICS=1`, `FEATURE_ALL=1`.

Flags in this installation (`feature.yaml`; at runtime `bin/console feature:list` is authoritative):

| Flag | default | major | toggleable | Note |
|---|---|---|---|---|
| v6.5.0.0 / v6.6.0.0 / v6.7.0.0 | true | yes | no | already active majors |
| v6.8.0.0 | false | yes | no | next major |
| DISABLE_VUE_COMPAT | true | yes | no | |
| ACCESSIBILITY_TWEAKS | true | yes | yes | default from 6.7 |
| TELEMETRY_METRICS | false | no | yes | experimental |
| FLOW_EXECUTION_AFTER_BUSINESS_PROCESS | false | yes | yes | default from 6.8 |
| DOCUMENT_GENERATION_REWORK | false | yes | yes | experimental, see [T-38](./38-order-statemachine-documents.md) |
| PERFORMANCE_TWEAKS | false | yes | yes | experimental |
| TWIG_COMPILE_TIME_OPTIMIZATION | false | no | yes | experimental |
| ENABLE_OPENSEARCH_FOR_ADMIN_API | false | no | yes | experimental |
| CACHE_CONTEXT_HASH_RULES_OPTIMIZATION | false | no | yes | deprecated, replaced by CACHE_REWORK |
| CACHE_REWORK | false | no | yes | experimental, see [T-41](./41-caching.md) |
| DEFERRED_CART_ERRORS | false | no | yes | default from 6.8 |
| SHIPPING_PRICE_RANGE_CURRENCY_CONVERSION | false | yes | yes | default from 6.8 |
| REPEATED_PAYMENT_FINALIZE | false | yes | yes | |
| PERMANENT_AUTOMATIC_PROMOTIONS | false | yes | yes | |
| METEOR_TEXT_EDITOR | false | no | yes | |
| JSON_LD_DATA | false | yes | yes | JSON-LD instead of microdata |
| BREADCRUMB_REWORK | false | yes | yes | experimental |
| WEBHOOKS_REWORK | false | yes | yes | experimental |
| ADMIN_EXTENSION_TOOLING | false | no | yes | experimental |

## 4. Examples

### 4.1 Plugin class with `build()` (loads `packages/*.yaml`)

`src/SwagExample.php`
```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class SwagExample extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $locator = new FileLocator('Resources/config');
        $resolver = new LoaderResolver([
            new YamlFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
        ]);

        $configLoader = new DelegatingLoader($resolver);
        $confDir = \rtrim($this->getPath(), '/') . '/Resources/config';

        $configLoader->load($confDir . '/{packages}/*.yaml', 'glob');
    }
}
```

Shorter variant: in `build()`, after `parent::build($container)`, simply call `$this->buildDefaultConfig($container);` (`protected` in `Shopware\Core\Framework\Bundle`, loads `Resources/config/packages/*` and `packages/<env>/*`).

`src/Resources/config/packages/monolog.yaml`
```yaml
monolog:
    channels: ['swag_example']
    handlers:
        swagExampleHandler:
            type: rotating_file
            path: "%kernel.logs_dir%/swag_example_%kernel.environment%.log"
            level: info
            channels: ['swag_example']
```

### 4.2 Services

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use SwagExample\Command\ExampleCommand;
use SwagExample\Service\ImportService;
use SwagExample\SystemCheck\QueueSizeCheck;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ImportService::class)
        ->args([service('monolog.logger.swag_example')]);

    $services->set(ExampleCommand::class)
        ->args([service(ImportService::class)])
        ->tag('console.command');

    $services->set(QueueSizeCheck::class)
        ->args([service(Connection::class)])
        ->tag('shopware.system_check');
};
```

### 4.3 Service with logger and command

`src/Service/ImportService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Psr\Log\LoggerInterface;

class ImportService
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function run(): int
    {
        $this->logger->info('Import started', ['source' => 'swag-example']);

        return 0;
    }
}
```

`src/Command/ExampleCommand.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Command;

use SwagExample\Service\ImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'swag:example', description: 'Runs the example import')]
class ExampleCommand extends Command
{
    public function __construct(private readonly ImportService $importService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('dry-run')) {
            $this->importService->run();
        }

        $io->success('Done');

        return Command::SUCCESS;
    }
}
```
Core constructors carry `@internal`; optional for plugin code. Further scaffolds: [Plugin](../templates/plugin/README.md), [Configuration](../templates/config/README.md).

### 4.4 System check

`src/SystemCheck/QueueSizeCheck.php` (signatures according to `BaseCheck.php` and `Result.php`, verified against the source code)
```php
<?php declare(strict_types=1);

namespace SwagExample\SystemCheck;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\SystemCheck\BaseCheck;
use Shopware\Core\Framework\SystemCheck\Check\Category;
use Shopware\Core\Framework\SystemCheck\Check\Result;
use Shopware\Core\Framework\SystemCheck\Check\Status;
use Shopware\Core\Framework\SystemCheck\Check\SystemCheckExecutionContext;

class QueueSizeCheck extends BaseCheck
{
    private const THRESHOLD = 10000;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(): Result
    {
        $size = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `messenger_messages`');

        if ($size > self::THRESHOLD) {
            return new Result($this->name(), Status::WARNING, 'Queue is large', true, ['size' => $size]);
        }

        return new Result($this->name(), Status::OK, 'Queue size is fine', true, ['size' => $size]);
    }

    public function category(): Category
    {
        return Category::FEATURE;
    }

    public function name(): string
    {
        return 'SwagQueueSize';
    }

    protected function allowedSystemCheckExecutionContexts(): array
    {
        return SystemCheckExecutionContext::longRunning();
    }
}
```
⚠ UNVERIFIED: The table `messenger_messages` (Doctrine transport default name) is only an example target and has not been checked against the installation.

## 5. Pitfalls

- **Command not in `bin/console list`**: tag `console.command` missing, service not in `Resources/config/services.php`, cache not cleared, or `parent::__construct()` forgotten in the constructor.
- **Plugin `monolog.yaml` ignored**: `build()` not overridden or does not call `buildDefaultConfig()` (plugins do not get `packages/*` automatically).
- **Channel name and service ID do not match**: `monolog.logger.<channel>` must exactly match `monolog.channels`.
- **Info/debug missing in the prod log**: the default handler `main` is `fingers_crossed` with `action_level: error`; info only appears when an error occurs in the same request. A custom handler with `channels` and `level` solves this.
- **Exceptions missing from the log**: `shopware.logger.error_code_log_levels` downgrades, `exclude_exception` suppresses; only affects handler `main`.
- **`healthy` not set**: default `null` counts as unhealthy (exit code 1 for `system:check`); set it explicitly.
- **`Category::SYSTEM` with `healthy=false`** stops all further checks; only for real basic prerequisites.
- **Check only allowed in `WEB`**: in the default context `cli` it appears as `SKIPPED` (not "without result") and turns the exit code to 1 because of `healthy = null`. `--context=web` is permitted on the CLI despite the missing suggestion list. Skipped checks (wrong context or after an unhealthy `SYSTEM` check) always lead to `FAILURE`; choose the appropriate `--context` in CI.
- **Expensive checks in `WEB`** (request path): choose `longRunning()`.
- **Unregistered flag**: outside `prod`, `isActive()` triggers an `E_USER_WARNING`.
- **Extending `Feature`**: the class is `#[BecomesFinal('v6.8.0')]`.
- **`@internal`**: do not use `SystemChecker` and core checks; only extend `BaseCheck` or consume via HTTP/CLI.

### Deprecations and version notes (6.5/6.6 → 6.7)

- **System check framework** (`BaseCheck`, `SystemChecker`, `system:check`, `/api/_info/system-health-check`) introduced in **6.6.6.0** (verified against the GitHub tags: `src/Core/Framework/SystemCheck/{SystemChecker,BaseCheck}.php` and `Command/SystemCheckCommand.php` are missing in `v6.6.5.1` and present from `v6.6.6.0`; the route `/api/_info/system-health-check` exists in `HealthCheckController` of `v6.6.6.0`). Not available in 6.5 and in 6.6.0–6.6.5.
- **Service configuration**: XML (`services.xml`, `packages/*.xml`) is loaded but triggers `@deprecated tag:v6.8.0` (`Bundle::registerContainerFile`, `triggerXmlConfigDeprecation`); no longer loaded from 6.8. Recommended: `services.php`. In 6.5/6.6 XML was the default.
- **Commands**: `#[AsCommand]` is the standard (instead of `$defaultName`/`setName()`).
- **Feature flags**: `v6.5.0.0`, `v6.6.0.0`, `v6.7.0.0` active by default, `v6.8.0.0` preview major. Old `FEATURE_NEXT_xxxx` flags largely removed; `normalizeName()` still supports that notation. `FEATURE_ALL=v6.8.0.0` as single-major test mode.
- **Logger**: Monolog 3, `LogAware::getLogLevel()` returns `Monolog\Level`.
- **Deprecated**: `ShopwareStyle` (6.8), `CACHE_CONTEXT_HASH_RULES_OPTIMIZATION`.

## 6. Checklist

- [ ] Command with `#[AsCommand]`, `parent::__construct()` and tag `console.command`
- [ ] Name prefix `vendor:action` chosen, exit code via `Command::SUCCESS/FAILURE`
- [ ] Plugin class overrides `build()` and loads `packages/*.yaml` if a custom Monolog channel is needed
- [ ] Logger injected via `monolog.logger.<channel>`; log file under `var/log` checked
- [ ] Log level/handler chosen so that plugin logs do not disappear in the `fingers_crossed` default
- [ ] System check: tag `shopware.system_check`, `healthy` set explicitly, sensible `Category` and contexts (`longRunning()` for expensive checks)
- [ ] `bin/console system:check -v` or `GET /api/_info/system-health-check` tried out
- [ ] Only query feature flags from the core list; use the plugin configuration for plugin switches
- [ ] `services.php` used instead of XML
- [ ] See [review checklist](../checklists/review-checklist.md)

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-custom-commands.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/logging.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/system-check/add-custom-check.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/feature-flags.html

Source code (relative to `vendor/shopware/`):
- `core/Framework/SystemCheck/{BaseCheck,SystemChecker}.php`, `Check/{Category,Status,Result,SystemCheckExecutionContext}.php`, `Command/SystemCheckCommand.php`
- `core/Framework/DependencyInjection/health.php`, `core/Framework/Api/Controller/HealthCheckController.php`
- `core/System/Locale/SystemCheck/LocalesReadinessCheck.php`, `administration/Framework/SystemCheck/AdministrationReadinessCheck.php`, `core/DevOps/StaticAnalyze/PHPStan/tagged-service-contracts.php`
- `core/Framework/Feature.php`, `Feature/FeatureFlagRegistry.php`, `Feature/Command/Feature{List,Enable,Disable,Dump}Command.php`, `DependencyInjection/CompilerPass/FeatureFlagCompilerPass.php`, `Resources/config/packages/feature.yaml`, `Adapter/Twig/Extension/FeatureFlagExtension.php`
- `core/Framework/Log/{LoggingService,LogAware,Package}.php`, `Log/Monolog/{DoctrineSQLHandler,ErrorCodeLogLevelHandler,ExcludeExceptionHandler,ExcludeFlowEventHandler}.php`, `Log/ScheduledTask/LogCleanupTask*.php`
- `core/Framework/DependencyInjection/services.php`, `core/Framework/Resources/config/packages/{dev,prod}/monolog.yaml`, `shopware.yaml` (`shopware.logger`)
- `core/Framework/Bundle.php` (`registerContainerFile`, `buildDefaultConfig`), `core/Framework/Framework.php`
- `core/Framework/Plugin/Command/PluginListCommand.php`, `core/Framework/Adapter/Console/ShopwareStyle.php` (deprecated)

### Gaps and uncertainties

- Introduction version of the system check framework: 6.6.6.0 (verified against the GitHub tags `v6.6.5.1`/`v6.6.6.0`, see version notes).
- ⚠ UNVERIFIED: Examples not executed (static source code review only); `service(Connection::class)` in the plugin and the table `messenger_messages` not checked against the installation.
- Confirmed (verified live for subscriber/decorator): autoconfigure takes effect in plugin `services.php` only with `->defaults()->autowire()->autoconfigure()`, not without `defaults()`. ⚠ UNVERIFIED: `console.command` not specifically tested live; the example tags explicitly.
- ⚠ GAP: How a plugin can register its own feature flags (`shopware.feature.flags` from plugin `packages/*.yaml`) is not confirmed; plugin flags are not recommended, use [T-19 plugin configuration](./19-plugin-configuration.md) instead.
- ⚠ UNVERIFIED: That the Monolog bundle registers `monolog.logger.<channel>` automatically comes from the docs (not checked in the Shopware code). Details on `shopware.api.static_token.health_check` only seen in `shopware.yaml` line 254.
