# Registering new services (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin needs to register its own classes as Symfony services in the container (services, commands, subscribers, handlers, entity definitions), including tags, parameters and autowiring.

Scope: changing existing services in [T-06 Service decoration](./06-service-decoration.md); event subscriber logic in [T-07](./07-events-subscriber.md); Store API routes in [T-30](./30-store-api-routes.md); CLI commands in [T-43](./43-cli-logging-systemchecks.md). Scaffold: [../templates/plugin/README.md](../templates/plugin/README.md).

## 2. Concepts

- **Standard format in 6.7: `src/Resources/config/services.php`** (PHP closure with `ContainerConfigurator`). `services.xml` is still loaded in 6.7 but is deprecated and will no longer be loaded in 6.8.0.0 (`Bundle::registerContainerFile()`, `triggerXmlConfigDeprecation()`, `Feature::triggerDeprecationOrThrow('v6.8.0.0', ...)`). YAML is loaded and, according to the code, is not deprecated.
- Loading uses the glob `Resources/config/services.*` (XML/YAML/PHP); in the `test` environment additionally `services_test.*`.
- **No autowiring by default.** The scaffold generator creates `services.php` without `defaults()` (`ScaffoldingCollector`). Without `->defaults()->autowire()->autoconfigure()`, every service must be declared explicitly with `->args([...])` and `->tag(...)`. The core mostly declares explicitly.
- **Autoconfigure** (only with `autoconfigure: true`): `AutoconfigureCompilerPass` maps an interface/base class to a tag (table below). The Symfony defaults apply in addition: `EventSubscriberInterface` -> `kernel.event_subscriber`, `Command` + `#[AsCommand]` -> `console.command`, `#[AsMessageHandler]` -> `messenger.message_handler`, `#[AsEventListener]` -> `kernel.event_listener`.
- Services are **private** by default; controllers/routes need `->public()`. `EntityCompilerPass` makes entity definitions public itself.
- Parameters: `$configurator->parameters()->set(...)` in `services.php`. Bundle/extension configuration in `Resources/config/packages/*` is not loaded automatically for plugins (see Gaps and uncertainties).
- Project level (not plugin): `config/services.yaml` with `_defaults: autowire/autoconfigure` and `App\: resource: '../src/'` (`Kernel::configureContainer()` loads `config/packages/*`, `config/packages/<env>/**`, `config/services.*`, `config/services_<env>.*`).

```text
custom/plugins/SwagExample/src/
├── SwagExample.php
├── Service/GreetingService.php
├── Command/GreetCommand.php
├── Subscriber/ProductLoadedSubscriber.php
└── Resources/config/
    ├── services.php          # services + parameters (standard)
    ├── services_test.php     # "test" environment only
    └── packages/<env>/       # NOT loaded automatically for plugins (see Gaps and uncertainties)
```

### Autoconfigure mappings (`AutoconfigureCompilerPass`, excerpt)

| Base / interface | Tag |
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

Others in the pass: `HookableEntityInterface`, `ExceptionHandlerInterface` (DAL), `LineItemGroupPackager/Sorter`, `LegacyEncoderInterface`, `AbstractValueGenerator`, `PeriodicMetricCollectorInterface`, promotion `FilterSorter/Picker`, `CorsHeaderProviderInterface`. Additionally the argument aliases `$privateFilesystem`/`$publicFilesystem` (type `FilesystemOperator`) and per bundle `$<bundleName>PublicFilesystem`/`...PrivateFilesystem` (`Bundle::registerFilesystem()`).

Core tags frequently set explicitly (rough grep count): `shopware.entity.definition`, `kernel.event_subscriber`, `shopware.rule.definition`, `console.command`, `kernel.reset`, `kernel.event_listener`, `messenger.message_handler`, `twig.extension`, `shopware.scheduled.task`, `shopware.cms.data_resolver`, `shopware.entity_indexer`, `flow.storer`, `flow.action`, `document.renderer`, `shopware.cart.processor`, `shopware.payment.method`, `mcp.tool`, `mcp.resource`. Tag attributes: `shopware.feature` with the required attribute `flag` (the service is removed if the flag is inactive); `scheduler.schedule_provider` with `['name' => 'shopware']`; `shopware.entity.definition` in the scaffold with `['entity' => '<table>']` (the `EntityCompilerPass` reads the name from `getEntityName()`, though).

## 3. Instructions

1. Create the class with constructor injection, `declare(strict_types=1)`, `readonly` properties.
2. Create `src/Resources/config/services.php` (or `bin/console plugin:create <Name> <Namespace>` with `--create-command`, `--create-event-subscriber`, `--create-scheduled-task`, `--entities` (flag without a value; the entity names, PascalCase, comma-separated, are asked for interactively, `EntityGenerator`); generators extend `services.php` incrementally).
3. Declare the service: explicitly (`$services->set(X::class)->args([...])->tag(...)`) or via `defaults()->autowire()->autoconfigure()` plus `load()`.
4. Set the tag explicitly or use autoconfigure.
5. Parameters: `->parameters()->set('swag_example.x', ...)`, used via `param('swag_example.x')` or `'%swag_example.x%'`.
6. Access from outside (controller, `$container->get()`): service `->public()`.
7. Reload: `bin/console cache:clear`; for a new plugin `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`.
8. Verify: `bin/console debug:container GreetingService`, `bin/console debug:container --tag=kernel.event_subscriber`, `bin/console debug:container --tags`, `bin/console lint:container` (missing autowiring bindings).

## 4. Examples

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

`src/Resources/config/services.php`, variant A (explicit, core/scaffold style):

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

Variant B (autowire + autoconfigure, scan `src`; `__DIR__ . '/../../'` = `src/`):

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

    // scalar arguments cannot be autowired
    $services->set(GreetingService::class)
        ->arg('$greeting', 'Hello');
};
```

Decoration (pattern, details in [T-06](./06-service-decoration.md)): `$services->set(GreetingServiceDecorator::class)->decorate(GreetingService::class)->args([service('.inner')]);`

## 5. Pitfalls

- **Autowiring assumed but not active**: the scaffold `services.php` has no `defaults()` -> "Cannot autowire"/missing arguments. Set `defaults()->autowire()` or specify `->args()`.
- **Autoconfigure expected without `autoconfigure: true`**: set the tag manually.
- **Duplicate tags** with autoconfigure plus a manual tag (assumption, Symfony behavior, not verified).
- **Controller not public**: Storefront/Store API controllers need `->public()` (scaffold: `->public()->call('setContainer', [service('service_container')])`).
- **Message handler without `#[AsMessageHandler]`/tag**: the message is not processed.
- **Entity definition without tag/wrong base class**: `EntityCompilerPass` throws `DependencyInjectionException::taggedServiceHasWrongType`.
- **Scalar parameters** (strings, ints) are not autowired: `->arg('$name', ...)`, `bind` or parameters.
- **Container cache**: changes to `services.php` only take effect after `cache:clear` (in `dev` usually automatic, not established).
- **Overriding a core service instead of decorating it**: anti-pattern, see [T-06](./06-service-decoration.md).
- **Plugin class with constructor injection**: the constructor is `final`/`@internal` ([T-02](./02-plugin-structure.md)).

### Deprecations and version notes (6.5/6.6 → 6.7)

- XML service/route/package files are deprecated, removal in `v6.8.0.0` (`Bundle.php`, `Kernel.php`); in 6.5/6.6, `services.xml` was the standard (from knowledge, not checked against old code). Plugins with `services.xml` keep working in 6.7 with a deprecation.
- `Kernel::CONFIG_EXTS = '.{php,xml,yaml,yml}'`; core services live as `DependencyInjection/*.php`.
- Attribute entities (`#[Entity]` -> tag `shopware.entity`, `AttributeEntityCompilerPass`): comparison with 6.5/6.6 not checked. `#[Package('...')]` on core classes is core-internal.

## 6. Checklist

- [ ] `services.php` (PHP) created instead of `services.xml`.
- [ ] Autowiring chosen deliberately: either `defaults()->autowire()->autoconfigure()` or explicit `args()`.
- [ ] Scalar arguments/parameters set explicitly.
- [ ] Tags set or covered by autoconfigure/attribute (commands, subscribers, handlers).
- [ ] Controllers and services used from outside are `->public()`.
- [ ] Entity definitions extend `EntityDefinition`.
- [ ] `cache:clear` run, `debug:container` shows the service.
- [ ] No core service overridden (use decoration or an event).

## 7. Sources

Docs (paths in the research plan corrected to `plugin-fundamentals`):
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-custom-service.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/dependency-injection.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/adjusting-service.html
- https://symfony.com/doc/current/service_container.html (not fetched)

Source code (relative to `vendor/shopware/`): `core/Framework/Bundle.php` (`build`, `registerContainerFile`, `buildDefaultConfig`, `registerFilesystem`); `core/Framework/Plugin/KernelPluginLoader/KernelPluginLoader.php` (plugin class as a service); `core/Kernel.php` (`configureContainer`, `CONFIG_EXTS`); `core/Framework/Framework.php`; `core/Framework/DependencyInjection/CompilerPass/{AutoconfigureCompilerPass,EntityCompilerPass,FeatureFlagCompilerPass,RemoveEventListener}.php`; `core/Framework/Plugin/Command/Scaffolding/ScaffoldingCollector.php` and `Generator/*`; project: `config/services.yaml`.

### Gaps and uncertainties

- ⚠ GAP: No tag overview found in the docs; the table comes from the code (`AutoconfigureCompilerPass`). Reference page "Tags"/"Extension Points" not checked.
- ⚠ UNVERIFIED: Variant B (`load()` + `exclude`) is derived from the docs description, not executed in an instance; the docs code was not checked verbatim. The docs statement "XML deprecated as of Symfony 7.4" only from a summary; in the code, `v6.8.0.0` applies.
- Established: `Bundle::buildDefaultConfig()` (loads `Resources/config/packages/*` and `packages/<env>/*`) is `protected`; `Bundle::build()` does not call it (only `registerContainerFile`, migrations, filesystem, events), and `Plugin` does not override `build()`. Callers in the core: `Framework`, `Profiling`, `Administration`, `Storefront`, `Elasticsearch`. A plugin's own `Resources/config/packages/<env>/` is therefore not loaded unless the plugin calls `buildDefaultConfig()` itself in `build()`. Set parameters in `services.php`, project configuration in `config/packages/`.
- Established: Autoconfigure takes effect in plugins with `->defaults()->autowire()->autoconfigure()` (verified live), not without `defaults()`.
- ⚠ UNVERIFIED: Double registration with autoconfigure plus a manual tag and `cache:clear` behavior in dev not verified; `debug:container` not executed. Comparison with 6.5/6.6 not checked against old code.
