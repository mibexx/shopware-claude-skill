# Neue Services registrieren (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eigene Klassen als Symfony-Services im Container registrieren soll (Services, Commands, Subscriber, Handler, Entity-Definitionen) inklusive Tags, Parametern und Autowiring.

Abgrenzung: Bestehende Services ändern in [T-06 Service-Dekoration](./06-service-dekoration.md); Event-Subscriber-Logik in [T-07](./07-events-subscriber.md); Store-API-Routen in [T-30](./30-store-api-routen.md); CLI-Commands in [T-43](./43-cli-logging-systemchecks.md). Gerüst: [../templates/plugin/README.md](../templates/plugin/README.md).

## 2. Konzepte

- **Standardformat in 6.7: `src/Resources/config/services.php`** (PHP-Closure mit `ContainerConfigurator`). `services.xml` wird in 6.7 noch geladen, ist aber deprecated und wird in 6.8.0.0 nicht mehr geladen (`Bundle::registerContainerFile()`, `triggerXmlConfigDeprecation()`, `Feature::triggerDeprecationOrThrow('v6.8.0.0', ...)`). YAML wird geladen und ist laut Code nicht deprecated.
- Geladen wird per Glob `Resources/config/services.*` (XML/YAML/PHP); in der `test`-Umgebung zusätzlich `services_test.*`.
- **Kein Autowiring per Default.** Der Scaffold-Generator erzeugt `services.php` ohne `defaults()` (`ScaffoldingCollector`). Ohne `->defaults()->autowire()->autoconfigure()` muss jeder Service explizit mit `->args([...])` und `->tag(...)` deklariert werden. Der Core deklariert überwiegend explizit.
- **Autoconfigure** (nur bei `autoconfigure: true`): `AutoconfigureCompilerPass` ordnet Interface/Basisklasse einem Tag zu (Tabelle unten). Symfony-Standard gilt zusätzlich: `EventSubscriberInterface` -> `kernel.event_subscriber`, `Command` + `#[AsCommand]` -> `console.command`, `#[AsMessageHandler]` -> `messenger.message_handler`, `#[AsEventListener]` -> `kernel.event_listener`.
- Services sind per Default **private**; Controller/Routen brauchen `->public()`. Entity-Definitionen setzt `EntityCompilerPass` selbst public.
- Parameter: `$configurator->parameters()->set(...)` in `services.php`. Bundle-/Extension-Konfiguration in `Resources/config/packages/*` wird bei Plugins nicht automatisch geladen (siehe Lücken und Hinweise).
- Projektebene (nicht Plugin): `config/services.yaml` mit `_defaults: autowire/autoconfigure` und `App\: resource: '../src/'` (`Kernel::configureContainer()` lädt `config/packages/*`, `config/packages/<env>/**`, `config/services.*`, `config/services_<env>.*`).

```text
custom/plugins/SwagExample/src/
├── SwagExample.php
├── Service/GreetingService.php
├── Command/GreetCommand.php
├── Subscriber/ProductLoadedSubscriber.php
└── Resources/config/
    ├── services.php          # Services + Parameter (Standard)
    ├── services_test.php     # nur Umgebung "test"
    └── packages/<env>/       # wird bei Plugins NICHT automatisch geladen (siehe Hinweise)
```

### Autoconfigure-Zuordnungen (`AutoconfigureCompilerPass`, Auszug)

| Basis / Interface | Tag |
|---|---|
| `Attribute\Entity` | `shopware.entity` |
| `EntityDefinition` / `SalesChannelDefinition` | `shopware.entity.definition` / `shopware.sales_channel.entity.definition` |
| `EntityExtension` / `BulkEntityExtension` | `shopware.entity.extension` / `shopware.bulk.entity.extension` |
| `EntityIndexer` | `shopware.entity_indexer` |
| `FieldSerializerInterface` | `shopware.field_serializer` |
| `CartProcessorInterface` / `CartDataCollectorInterface` / `CartValidatorInterface` | `shopware.cart.processor` / `shopware.cart.collector` / `shopware.cart.validator` |
| `LineItemFactoryInterface` | `shopware.cart.line_item.factory` |
| `AbstractPaymentHandler` | `shopware.payment.method` |
| `AbstractDocumentRenderer` | `document.renderer` |
| `CmsElementResolverInterface` | `shopware.cms.data_resolver` |
| `ScheduledTask` | `shopware.scheduled.task` |
| `Rule` | `shopware.rule.definition` |
| `AbstractRouteScope` | `shopware.route_scope` |
| `AbstractTaxProvider` / `TaxRuleTypeFilterInterface` | `shopware.tax.provider` / `tax.rule_type_filter` |
| `SeoUrlRouteInterface` | `shopware.seo_url.route` |
| `AbstractUrlProvider` | `shopware.sitemap_url_provider` |
| `AbstractListingProcessor` / `AbstractListingFilterHandler` | `shopware.listing.processor` / `shopware.listing.filter.handler` |
| `FlowStorer` | `flow.storer` |
| `AdapterFactoryInterface` | `shopware.filesystem.factory` |
| `TemplateNamespaceHierarchyBuilderInterface` | `shopware.twig.hierarchy_builder` |

Weitere im Pass: `HookableEntityInterface`, `ExceptionHandlerInterface` (DAL), `LineItemGroupPackager/Sorter`, `LegacyEncoderInterface`, `AbstractValueGenerator`, `PeriodicMetricCollectorInterface`, Promotion-`FilterSorter/Picker`, `CorsHeaderProviderInterface`. Zusätzlich Argument-Aliase `$privateFilesystem`/`$publicFilesystem` (Typ `FilesystemOperator`) und pro Bundle `$<bundleName>PublicFilesystem`/`...PrivateFilesystem` (`Bundle::registerFilesystem()`).

Häufig explizit gesetzte Core-Tags (grobe grep-Zählung): `shopware.entity.definition`, `kernel.event_subscriber`, `shopware.rule.definition`, `console.command`, `kernel.reset`, `kernel.event_listener`, `messenger.message_handler`, `twig.extension`, `shopware.scheduled.task`, `shopware.cms.data_resolver`, `shopware.entity_indexer`, `flow.storer`, `flow.action`, `document.renderer`, `shopware.cart.processor`, `shopware.payment.method`, `mcp.tool`, `mcp.resource`. Tag-Attribute: `shopware.feature` mit Pflichtattribut `flag` (Service wird entfernt, wenn Flag inaktiv); `scheduler.schedule_provider` mit `['name' => 'shopware']`; `shopware.entity.definition` im Scaffold mit `['entity' => '<table>']` (der `EntityCompilerPass` liest den Namen aber aus `getEntityName()`).

## 3. Anleitung

1. Klasse mit Konstruktor-Injection, `declare(strict_types=1)`, `readonly`-Properties anlegen.
2. `src/Resources/config/services.php` anlegen (oder `bin/console plugin:create <Name> <Namespace>` mit `--create-command`, `--create-event-subscriber`, `--create-scheduled-task`, `--entities` (Flag ohne Wert; die Entity-Namen PascalCase, kommagetrennt, werden interaktiv abgefragt, `EntityGenerator`); Generatoren ergänzen `services.php` inkrementell).
3. Service deklarieren: explizit (`$services->set(X::class)->args([...])->tag(...)`) oder per `defaults()->autowire()->autoconfigure()` plus `load()`.
4. Tag explizit setzen oder Autoconfigure nutzen.
5. Parameter: `->parameters()->set('swag_example.x', ...)`, Verwendung via `param('swag_example.x')` oder `'%swag_example.x%'`.
6. Zugriff von außen (Controller, `$container->get()`): Service `->public()`.
7. Neu laden: `bin/console cache:clear`; bei neuem Plugin `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`.
8. Prüfen: `bin/console debug:container GreetingService`, `bin/console debug:container --tag=kernel.event_subscriber`, `bin/console debug:container --tags`, `bin/console lint:container` (fehlende Autowiring-Bindungen).

## 4. Beispiele

`src/Service/GreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class GreetingService
{
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly string $greeting,
    ) {
    }

    public function greet(string $name): string
    {
        $suffix = $this->systemConfigService->getString('SwagExample.config.suffix');

        return \sprintf('%s, %s%s', $this->greeting, $name, $suffix);
    }
}
```

`src/Command/GreetCommand.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Command;

use SwagExample\Service\GreetingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'swag:greet', description: 'Greets someone')]
class GreetCommand extends Command
{
    public function __construct(private readonly GreetingService $greetingService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->greetingService->greet((string) $input->getArgument('name')));

        return self::SUCCESS;
    }
}
```

`src/Resources/config/services.php`, Variante A (explizit, Core-/Scaffold-Stil):

```php
<?php declare(strict_types=1);

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagExample\Command\GreetCommand;
use SwagExample\Service\GreetingService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->parameters()->set('swag_example.greeting', 'Hello');

    $services = $containerConfigurator->services();

    $services->set(GreetingService::class)
        ->args([
            service(SystemConfigService::class),
            param('swag_example.greeting'),
        ]);

    $services->set(GreetCommand::class)
        ->args([service(GreetingService::class)])
        ->tag('console.command');
};
```

Variante B (Autowire + Autoconfigure, `src` scannen; `__DIR__ . '/../../'` = `src/`):

```php
<?php declare(strict_types=1);

use SwagExample\Service\GreetingService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('SwagExample\\', __DIR__ . '/../../')
        ->exclude([__DIR__ . '/../../Resources', __DIR__ . '/../../Migration', __DIR__ . '/../../SwagExample.php']);

    // skalare Argumente lassen sich nicht autowiren
    $services->set(GreetingService::class)
        ->arg('$greeting', 'Hello');
};
```

Dekoration (Muster, Details in [T-06](./06-service-dekoration.md)): `$services->set(GreetingServiceDecorator::class)->decorate(GreetingService::class)->args([service('.inner')]);`

## 5. Fallstricke

- **Autowiring angenommen, aber nicht aktiv**: Scaffold-`services.php` hat kein `defaults()` -> „Cannot autowire"/fehlende Argumente. `defaults()->autowire()` setzen oder `->args()` angeben.
- **Autoconfigure erwartet ohne `autoconfigure: true`**: Tag manuell setzen.
- **Doppelte Tags** bei Autoconfigure plus manuellem Tag (Annahme, Symfony-Verhalten, nicht geprüft).
- **Controller nicht public**: Storefront-/Store-API-Controller brauchen `->public()` (Scaffold: `->public()->call('setContainer', [service('service_container')])`).
- **Message-Handler ohne `#[AsMessageHandler]`/Tag**: Message wird nicht verarbeitet.
- **Entity-Definition ohne Tag/falsche Basisklasse**: `EntityCompilerPass` wirft `DependencyInjectionException::taggedServiceHasWrongType`.
- **Skalare Parameter** (Strings, Ints) werden nicht autowired: `->arg('$name', ...)`, `bind` oder Parameter.
- **Container-Cache**: Änderungen an `services.php` wirken erst nach `cache:clear` (in `dev` meist automatisch, nicht belegt).
- **Core-Service überschreiben statt dekorieren**: Anti-Pattern, siehe [T-06](./06-service-dekoration.md).
- **Plugin-Klasse mit Konstruktor-Injection**: Konstruktor ist `final`/`@internal` ([T-02](./02-plugin-aufbau.md)).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- XML-Service-/Routen-/Package-Dateien sind deprecated, Entfernung `v6.8.0.0` (`Bundle.php`, `Kernel.php`); in 6.5/6.6 war `services.xml` Standard (aus Wissen, nicht an altem Code geprüft). Plugins mit `services.xml` laufen in 6.7 mit Deprecation weiter.
- `Kernel::CONFIG_EXTS = '.{php,xml,yaml,yml}'`; Core-Services liegen als `DependencyInjection/*.php`.
- Attribut-Entities (`#[Entity]` -> Tag `shopware.entity`, `AttributeEntityCompilerPass`): Vergleich zu 6.5/6.6 nicht geprüft. `#[Package('...')]` an Core-Klassen ist Core-intern.

## 6. Checkliste

- [ ] `services.php` (PHP) statt `services.xml` angelegt.
- [ ] Autowiring bewusst gewählt: entweder `defaults()->autowire()->autoconfigure()` oder explizite `args()`.
- [ ] Skalare Argumente/Parameter explizit gesetzt.
- [ ] Tags gesetzt oder per Autoconfigure/Attribut abgedeckt (Commands, Subscriber, Handler).
- [ ] Controller und von außen genutzte Services `->public()`.
- [ ] Entity-Definitionen erben von `EntityDefinition`.
- [ ] `cache:clear` ausgeführt, `debug:container` zeigt den Service.
- [ ] Kein Core-Service überschrieben (Dekoration oder Event nutzen).

## 7. Quellen

Doku (Pfade im Recherche-Plan auf `plugin-fundamentals` korrigiert):
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-custom-service.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/dependency-injection.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/adjusting-service.html
- https://symfony.com/doc/current/service_container.html (nicht abgerufen)

Quellcode (relativ zu `vendor/shopware/`): `core/Framework/Bundle.php` (`build`, `registerContainerFile`, `buildDefaultConfig`, `registerFilesystem`); `core/Framework/Plugin/KernelPluginLoader/KernelPluginLoader.php` (Plugin-Klasse als Service); `core/Kernel.php` (`configureContainer`, `CONFIG_EXTS`); `core/Framework/Framework.php`; `core/Framework/DependencyInjection/CompilerPass/{AutoconfigureCompilerPass,EntityCompilerPass,FeatureFlagCompilerPass,RemoveEventListener}.php`; `core/Framework/Plugin/Command/Scaffolding/ScaffoldingCollector.php` und `Generator/*`; Projekt: `config/services.yaml`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Keine Tag-Übersicht in der Doku gefunden; die Tabelle stammt aus dem Code (`AutoconfigureCompilerPass`). Referenzseite „Tags"/„Extension Points" nicht geprüft.
- ⚠ UNGEPRÜFT: Variante B (`load()` + `exclude`) ist aus der Doku-Beschreibung abgeleitet, nicht in einer Instanz ausgeführt; Doku-Code wurde nicht wörtlich geprüft. Doku-Aussage „XML deprecated as of Symfony 7.4" nur aus Zusammenfassung; im Code gilt `v6.8.0.0`.
- Belegt: `Bundle::buildDefaultConfig()` (lädt `Resources/config/packages/*` und `packages/<env>/*`) ist `protected`; `Bundle::build()` ruft es nicht auf (nur `registerContainerFile`, Migrationen, Filesystem, Events), `Plugin` überschreibt `build()` nicht. Aufrufer im Core: `Framework`, `Profiling`, `Administration`, `Storefront`, `Elasticsearch`. Plugin-eigene `Resources/config/packages/<env>/` werden daher nicht geladen, außer das Plugin ruft `buildDefaultConfig()` selbst in `build()` auf. Parameter in `services.php` setzen, Projektkonfiguration in `config/packages/`.
- Belegt: Autoconfigure greift in Plugins mit `->defaults()->autowire()->autoconfigure()` (live bestätigt), ohne `defaults()` nicht.
- ⚠ UNGEPRÜFT: Doppelregistrierung bei Autoconfigure plus manuellem Tag und `cache:clear`-Verhalten in dev nicht verifiziert; `debug:container` nicht ausgeführt. Vergleich 6.5/6.6 nicht an altem Code geprüft.
