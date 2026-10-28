# CLI-Commands, Logging und System-Checks (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eigene `bin/console`-Commands, einen eigenen Monolog-Channel/Logfile, System-Checks (Health-Checks) oder Feature-Flag-Abfragen braucht.

Abgrenzung: Service-Registrierung allgemein in [T-05](./05-services-registrieren.md); Plugin-Aufbau/`build()` in [T-02](./02-plugin-aufbau.md); Scheduled Tasks in [T-33](./33-scheduled-tasks.md); Plugin-Einstellungen statt eigener Flags in [T-19](./19-plugin-konfiguration.md); Coding-Regeln in [T-45](./45-coding-guidelines.md); Build-Skripte/Deployment in [T-49](./49-build-deployment.md).

## 2. Konzepte

- **Command**: Klasse von `Symfony\Component\Console\Command\Command` mit `#[AsCommand(name: ..., description: ...)]`, als Service mit Tag `console.command` registriert. Core-Vorbilder: `core/Framework/Plugin/Command/PluginListCommand.php`, `core/Framework/SystemCheck/Command/SystemCheckCommand.php`; Registrierung z. B. in `core/Framework/DependencyInjection/health.php`.
- **Logging**: Monolog über das Symfony-Monolog-Bundle. Eigener Channel über `monolog.channels` + Handler in `Resources/config/packages/monolog.yaml`; Logger-Service `monolog.logger.<channel>` (`Psr\Log\LoggerInterface`). **Plugins laden `Resources/config/packages/*.yaml` nicht automatisch**: `Bundle::build()` ruft `buildDefaultConfig()` nicht auf (im Core nur `Framework`, `Profiling`, `Administration`, `Storefront`, `Elasticsearch`). `buildDefaultConfig()` ist `protected` und kann im eigenen `build()` der Plugin-Klasse nach `parent::build()` aufgerufen werden; alternativ den Loader selbst aufsetzen (Beispiel 4.1). Konsistent mit [T-05](./05-services-registrieren.md) und [T-34](./34-message-queue.md). Core-Channel `business_events` (Flow-Events, Tabelle `log_entry` über `DoctrineSQLHandler`).
- **System-Check** (neu in 6.7): Klasse von `Shopware\Core\Framework\SystemCheck\BaseCheck` (`run(): Result`, `category(): Category`, `name(): string`, `allowedSystemCheckExecutionContexts(): array`), Tag **`shopware.system_check`**. Ausführung per `bin/console system:check` oder HTTP.
- **Feature-Flags**: `Shopware\Core\Framework\Feature::isActive('FLAG')` (PHP), `{% if feature('FLAG') %}` (Twig); Flags stehen in `core/Framework/Resources/config/packages/feature.yaml`; CLI `feature:list|enable|disable|dump`.

Plugin-Baum:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php                    # build() lädt packages/*.yaml (z. B. via buildDefaultConfig())
    ├── Command/ExampleCommand.php
    ├── SystemCheck/QueueSizeCheck.php
    ├── Service/ImportService.php
    └── Resources/config/
        ├── services.php                   # XML ist in 6.7 deprecated
        └── packages/monolog.yaml
```

## 3. Anleitung

**Command**
1. Klasse mit `#[AsCommand(name: 'vendor:action', description: '…')]`; im Konstruktor `parent::__construct()` aufrufen; `execute()` gibt `Command::SUCCESS|FAILURE|INVALID` zurück.
2. In `services.php` mit `->tag('console.command')` registrieren (explizit; ob Autoconfigure es setzt, ist nicht gezielt getestet).
3. `bin/console cache:clear`, dann `bin/console list swag` und `bin/console swag:example --dry-run`.
4. Namenskonvention: Core nutzt `namespace:action` (`plugin:list`, `system:check`, `feature:enable`); Plugins mit eigenem Präfix.
5. `ShopwareStyle` nicht mehr verwenden (`@deprecated tag:v6.8.0`), stattdessen `SymfonyStyle`.

**Logging**
1. `build()` in der Plugin-Klasse überschreiben (Beispiel 4.1) und `packages/monolog.yaml` anlegen.
2. Logger per `service('monolog.logger.<channel>')` injizieren; Name muss exakt dem Eintrag in `monolog.channels` entsprechen.
3. `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`; Logdatei `var/log/swag_example_<env>.log`.
4. Betreiber-Konfiguration `shopware.logger.*` (`core/Framework/Resources/config/packages/shopware.yaml` ab Z. 548): `enforce_throw_exception`, `file_rotation_count: 14`, `exclude_exception`, `exclude_events`, `error_code_log_levels`. Sie wirken über die Decorators `ExcludeExceptionHandler`, `ErrorCodeLogLevelHandler`, `ExcludeFlowEventHandler` **nur auf den Handler `main`** (`core/Framework/DependencyInjection/services.php` ca. Z. 861-880). Prod: `fingers_crossed` mit `action_level: error`, rotating_file; dev: stream, debug.
5. `log_entry`: Der Channel `business_events` schreibt über `DoctrineSQLHandler`; `LoggingService` loggt `FlowLogEvent`s; Events können `LogAware` implementieren (`getLogData()`, `getLogLevel(): Monolog\Level`); Aufräumen via `LogCleanupTask`.
6. `#[Package('…')]` (`core/Framework/Log/Package.php`) ist `@internal`, nicht in Plugin-Code verwenden.

**System-Check**
1. `BaseCheck` ableiten, vier Methoden implementieren, Tag `shopware.system_check` setzen (Tag-Contract: `core/DevOps/StaticAnalyze/PHPStan/tagged-service-contracts.php` Z. 137).
2. `Category` bestimmt die Reihenfolge: `SYSTEM = 0`, `FEATURE = 8`, `EXTERNAL = 32`, `AUXILIARY = 128`. `SystemChecker` führt aufsteigend aus; liefert ein `SYSTEM`-Check `healthy` ungleich true, werden alle weiteren Kategorien als `SKIPPED` übersprungen.
3. Kontexte (`SystemCheckExecutionContext`): `WEB`, `CLI`, `PRE_ROLLOUT`, `RECURRENT`; Helfer `readiness()` = [PRE_ROLLOUT], `longRunning()` = [CLI, RECURRENT, PRE_ROLLOUT]. Nicht erlaubte Checks erscheinen als `SKIPPED` (`healthy` = null) und lassen `system:check` mit Exit-Code 1 (`FAILURE`) enden, ebenso Checks, die nach einem ungesunden `SYSTEM`-Check übersprungen werden.
4. `Result(string $name, Status $status, string $message, ?bool $healthy = null, array $extra = [])`; `Status`: OK, UNKNOWN, SKIPPED, WARNING, ERROR, FAILURE. `healthy` ist ein eigenes Flag; `null` zählt als ungesund (`system:check` liefert dann `FAILURE`). Exceptions in `run()` fängt der `SystemChecker` und macht `Status::FAILURE` daraus.
5. Aufruf: `bin/console system:check [--context=cli|recurrent|pre_rollout] [--format=table|json] [-v]` (Default `cli`; `-v` zeigt `extra`). Die genannten Werte sind nur Vorschläge (Autovervollständigung, `longRunning()`): akzeptiert wird jeder Wert von `SystemCheckExecutionContext`, also auch `--context=web`; nur unbekannte Werte ergeben `Command::INVALID`. HTTP: `GET /api/_info/system-health-check?context=web` (Default `web`; Bearer oder `Authorization: Static <token>` mit `shopware.api.static_token.health_check`, `HealthCheckController`). Einfacher Liveness-Endpoint ohne Auth: `GET /api/_info/health-check`.
6. Core-Referenzen: `core/System/Locale/SystemCheck/LocalesReadinessCheck.php`, `administration/Framework/SystemCheck/AdministrationReadinessCheck.php`.

**Feature-Flags**
1. Namen normalisiert `Feature::normalizeName()` (Punkte/Doppelpunkte/Bindestriche zu `_`, Großbuchstaben).
2. Auflösung in `Feature::isActive()` (`core/Framework/Feature.php` ab Z. 139): unbekannte Flags warnen außerhalb `prod`; Env-Variable des Flags hat höchste Priorität; dann `FEATURE_ALL` (`1`/`minor`, `major`, `v6.8.0.0`); dann `Feature::setActive`-Registry; zuletzt der Default aus der Konfiguration.
3. Weitere API: `Feature::ifActive/ifNotActive(closure)`, `has()`, `getAll()`, `throwException()`, `triggerDeprecationOrThrow()`; Test-Helfer `fake()`, `withFeatureEnabled/Disabled()`. `registerFeature(s)`/`getRegisteredFeatures()` sind `@internal`. `Feature::ifActiveCall(...)` aus der Doku existiert in 6.7.15.1 **nicht** (vorhanden: `ifActive`, `ifNotActive`, `callSilentIfInactive`, `silent`).
4. Twig: `feature('FLAG')`, `getAllFeatures()` (`FeatureFlagExtension`); unbekannte Flags liefern `false`.
5. Services hinter Flag: Tag `shopware.feature` mit Attribut `flag`; der Service wird vor der Optimierung entfernt, wenn das Flag inaktiv ist (`FeatureFlagCompilerPass`, Priorität 1000).
6. CLI: `bin/console feature:list`, `feature:enable <flag>`, `feature:disable <flag>`, `feature:dump` (Alias `administration:dump:features`). Toggle nur bei `toggleable: true` und aktivem `shopware.feature_toggle.enable` (Default `true`); Speicherung im Key-Value-Storage `feature.flags`; Major-Flags werden nicht aus dem Storage geladen. Env-Aktivierung in `.env`: z. B. `TELEMETRY_METRICS=1`, `FEATURE_ALL=1`.

Flags in dieser Installation (`feature.yaml`; zur Laufzeit gilt `bin/console feature:list`):

| Flag | default | major | toggleable | Hinweis |
|---|---|---|---|---|
| v6.5.0.0 / v6.6.0.0 / v6.7.0.0 | true | ja | nein | bereits aktive Majors |
| v6.8.0.0 | false | ja | nein | nächster Major |
| DISABLE_VUE_COMPAT | true | ja | nein | |
| ACCESSIBILITY_TWEAKS | true | ja | ja | ab 6.7 Standard |
| TELEMETRY_METRICS | false | nein | ja | experimentell |
| FLOW_EXECUTION_AFTER_BUSINESS_PROCESS | false | ja | ja | Standard ab 6.8 |
| DOCUMENT_GENERATION_REWORK | false | ja | ja | experimentell, siehe [T-38](./38-order-statemachine-dokumente.md) |
| PERFORMANCE_TWEAKS | false | ja | ja | experimentell |
| TWIG_COMPILE_TIME_OPTIMIZATION | false | nein | ja | experimentell |
| ENABLE_OPENSEARCH_FOR_ADMIN_API | false | nein | ja | experimentell |
| CACHE_CONTEXT_HASH_RULES_OPTIMIZATION | false | nein | ja | deprecated, ersetzt durch CACHE_REWORK |
| CACHE_REWORK | false | nein | ja | experimentell, siehe [T-41](./41-caching.md) |
| DEFERRED_CART_ERRORS | false | nein | ja | Standard ab 6.8 |
| SHIPPING_PRICE_RANGE_CURRENCY_CONVERSION | false | ja | ja | Standard ab 6.8 |
| REPEATED_PAYMENT_FINALIZE | false | ja | ja | |
| PERMANENT_AUTOMATIC_PROMOTIONS | false | ja | ja | |
| METEOR_TEXT_EDITOR | false | nein | ja | |
| JSON_LD_DATA | false | ja | ja | JSON-LD statt Microdata |
| BREADCRUMB_REWORK | false | ja | ja | experimentell |
| WEBHOOKS_REWORK | false | ja | ja | experimentell |
| ADMIN_EXTENSION_TOOLING | false | nein | ja | experimentell |

## 4. Beispiele

### 4.1 Plugin-Klasse mit `build()` (lädt `packages/*.yaml`)

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

Kürzere Variante: In `build()` nach `parent::build($container)` einfach `$this->buildDefaultConfig($container);` aufrufen (`protected` in `Shopware\Core\Framework\Bundle`, lädt `Resources/config/packages/*` und `packages/<env>/*`).

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

### 4.3 Service mit Logger und Command

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
Core-Konstruktoren tragen `@internal`; für Plugin-Code optional. Weitere Gerüste: [Plugin](../templates/plugin/README.md), [Konfiguration](../templates/config/README.md).

### 4.4 System-Check

`src/SystemCheck/QueueSizeCheck.php` (Signaturen laut `BaseCheck.php` und `Result.php` am Quellcode verifiziert)
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
⚠ UNGEPRÜFT: Die Tabelle `messenger_messages` (Doctrine-Transport-Standardname) ist nur Beispielziel und nicht gegen die Installation geprüft.

## 5. Fallstricke

- **Command nicht in `bin/console list`**: Tag `console.command` fehlt, Service nicht in `Resources/config/services.php`, Cache nicht geleert oder `parent::__construct()` im Konstruktor vergessen.
- **Plugin-`monolog.yaml` ignoriert**: `build()` nicht überschrieben bzw. ruft `buildDefaultConfig()` nicht auf (Plugins erhalten `packages/*` nicht automatisch).
- **Channel-Name und Service-ID passen nicht**: `monolog.logger.<channel>` muss exakt `monolog.channels` entsprechen.
- **Info/Debug fehlt im Prod-Log**: Der Standard-Handler `main` ist `fingers_crossed` mit `action_level: error`; Info erscheint nur bei einem Error im selben Request. Eigener Handler mit `channels` und `level` löst das.
- **Exceptions fehlen im Log**: `shopware.logger.error_code_log_levels` stuft herab, `exclude_exception` unterdrückt; wirkt nur auf Handler `main`.
- **`healthy` nicht gesetzt**: Default `null` gilt als ungesund (Exit-Code 1 bei `system:check`); explizit setzen.
- **`Category::SYSTEM` mit `healthy=false`** stoppt alle weiteren Checks; nur für echte Grundvoraussetzungen.
- **Check nur in `WEB` erlaubt**: im Default-Kontext `cli` erscheint er als `SKIPPED` (nicht "ohne Ergebnis") und färbt den Exit-Code wegen `healthy = null` auf 1. `--context=web` ist am CLI trotz fehlender Vorschlagsliste zulässig. Übersprungene Checks (falscher Kontext oder nach ungesundem `SYSTEM`-Check) führen immer zu `FAILURE`; in CI den passenden `--context` wählen.
- **Teure Checks in `WEB`** (Request-Pfad): `longRunning()` wählen.
- **Unregistriertes Flag**: außerhalb `prod` löst `isActive()` eine `E_USER_WARNING` aus.
- **`Feature` erweitern**: Klasse ist `#[BecomesFinal('v6.8.0')]`.
- **`@internal`**: `SystemChecker` und Core-Checks nicht verwenden; nur `BaseCheck` ableiten bzw. über HTTP/CLI konsumieren.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- **System-Check-Framework** (`BaseCheck`, `SystemChecker`, `system:check`, `/api/_info/system-health-check`) im 6.7.15.1-Code vorhanden; Einführungsversion nicht belegt (Annahme: nicht in 6.5).
- **Service-Konfiguration**: XML (`services.xml`, `packages/*.xml`) wird geladen, löst aber `@deprecated tag:v6.8.0` aus (`Bundle::registerContainerFile`, `triggerXmlConfigDeprecation`); ab 6.8 nicht mehr geladen. Empfohlen: `services.php`. In 6.5/6.6 war XML Standard.
- **Commands**: `#[AsCommand]` ist Standard (statt `$defaultName`/`setName()`).
- **Feature-Flags**: `v6.5.0.0`, `v6.6.0.0`, `v6.7.0.0` per Default aktiv, `v6.8.0.0` Vorschau-Major. Alte `FEATURE_NEXT_xxxx`-Flags weitgehend entfernt, `normalizeName()` unterstützt die Schreibweise weiter. `FEATURE_ALL=v6.8.0.0` als Einzel-Major-Testmodus.
- **Logger**: Monolog 3, `LogAware::getLogLevel()` liefert `Monolog\Level`.
- **Deprecated**: `ShopwareStyle` (6.8), `CACHE_CONTEXT_HASH_RULES_OPTIMIZATION`.

## 6. Checkliste

- [ ] Command mit `#[AsCommand]`, `parent::__construct()` und Tag `console.command`
- [ ] Namenspräfix `vendor:action` gewählt, Exit-Code über `Command::SUCCESS/FAILURE`
- [ ] Plugin-Klasse überschreibt `build()` und lädt `packages/*.yaml`, wenn ein eigener Monolog-Channel gebraucht wird
- [ ] Logger über `monolog.logger.<channel>` injiziert; Logdatei unter `var/log` geprüft
- [ ] Log-Level/Handler so gewählt, dass Plugin-Logs nicht im `fingers_crossed`-Standard verschwinden
- [ ] System-Check: Tag `shopware.system_check`, `healthy` explizit gesetzt, sinnvolle `Category` und Kontexte (`longRunning()` für teure Checks)
- [ ] `bin/console system:check -v` bzw. `GET /api/_info/system-health-check` ausprobiert
- [ ] Feature-Flags nur aus der Core-Liste abfragen; für Plugin-Schalter die Plugin-Konfiguration nutzen
- [ ] `services.php` statt XML verwendet
- [ ] Siehe [Review-Checkliste](../checklists/review-checklist.md)

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-custom-commands.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/logging.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/system-check/add-custom-check.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/feature-flags.html

Quellcode (relativ zu `vendor/shopware/`):
- `core/Framework/SystemCheck/{BaseCheck,SystemChecker}.php`, `Check/{Category,Status,Result,SystemCheckExecutionContext}.php`, `Command/SystemCheckCommand.php`
- `core/Framework/DependencyInjection/health.php`, `core/Framework/Api/Controller/HealthCheckController.php`
- `core/System/Locale/SystemCheck/LocalesReadinessCheck.php`, `administration/Framework/SystemCheck/AdministrationReadinessCheck.php`, `core/DevOps/StaticAnalyze/PHPStan/tagged-service-contracts.php`
- `core/Framework/Feature.php`, `Feature/FeatureFlagRegistry.php`, `Feature/Command/Feature{List,Enable,Disable,Dump}Command.php`, `DependencyInjection/CompilerPass/FeatureFlagCompilerPass.php`, `Resources/config/packages/feature.yaml`, `Adapter/Twig/Extension/FeatureFlagExtension.php`
- `core/Framework/Log/{LoggingService,LogAware,Package}.php`, `Log/Monolog/{DoctrineSQLHandler,ErrorCodeLogLevelHandler,ExcludeExceptionHandler,ExcludeFlowEventHandler}.php`, `Log/ScheduledTask/LogCleanupTask*.php`
- `core/Framework/DependencyInjection/services.php`, `core/Framework/Resources/config/packages/{dev,prod}/monolog.yaml`, `shopware.yaml` (`shopware.logger`)
- `core/Framework/Bundle.php` (`registerContainerFile`, `buildDefaultConfig`), `core/Framework/Framework.php`
- `core/Framework/Plugin/Command/PluginListCommand.php`, `core/Framework/Adapter/Console/ShopwareStyle.php` (deprecated)

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Einführungsversion des System-Check-Frameworks nicht belegt (Annahme ab 6.6.x).
- ⚠ UNGEPRÜFT: Beispiele nicht ausgeführt (nur statische Quellcodeprüfung); `service(Connection::class)` im Plugin und die Tabelle `messenger_messages` nicht gegen die Installation geprüft.
- Belegt (live bestätigt für Subscriber/Decorator): Autoconfigure greift in Plugin-`services.php` nur mit `->defaults()->autowire()->autoconfigure()`, ohne `defaults()` nicht. ⚠ UNGEPRÜFT: `console.command` live nicht gezielt getestet; das Beispiel taggt explizit.
- ⚠ LÜCKE: Wie ein Plugin eigene Feature-Flags registrieren kann (`shopware.feature.flags` aus Plugin-`packages/*.yaml`) ist nicht belegt; Plugin-Flags nicht empfohlen, stattdessen [T-19 Plugin-Konfiguration](./19-plugin-konfiguration.md).
- ⚠ UNGEPRÜFT: Dass das Monolog-Bundle `monolog.logger.<channel>` automatisch registriert, stammt aus der Doku (nicht im Shopware-Code geprüft). Details zu `shopware.api.static_token.health_check` nur in `shopware.yaml` Z. 254 gesehen.
