# SEO URLs and sitemap (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when custom entities/pages get SEO URLs (dynamically via template or statically), canonical/hreflang tags are relevant, or the sitemap is extended with custom URLs.

Scope: controllers and page loaders in [T-16 Storefront controllers](./16-storefront-controller-pages.md); entity definitions in [T-27 DAL entities](./27-dal-entities.md); migrations in [T-04](./04-database-migrations.md); subscribers on `<entity>.written` in [T-07](./07-events-subscriber.md); scheduled tasks in [T-33](./33-scheduled-tasks.md); HTTP cache invalidation in [T-41 Caching](./41-caching.md).

## 2. Concepts

- **Dynamic SEO URLs**: class implementing `SeoUrlRouteInterface` (`getConfig()`, `prepareCriteria()`, `getMapping()`), tag `shopware.seo_url.route` (set automatically via autoconfigure, `AutoconfigureCompilerPass.php:178-179`). One route per route name; `SeoUrlRouteRegistry` indexes by route name and entity name.
- **Additionally required**: (a) a row in `seo_url_template` (migration, default with `sales_channel_id = NULL`), (b) a trigger that calls `SeoUrlUpdater::update($routeName, $ids)` (e.g. a subscriber on `<entity>.written`), (c) a named storefront route, because `SeoUrlGenerator::generateUrls()` calls `$router->generate($routeName, $infoPathContext)`.
- **Template** = Twig snippet, rendered with the escaper `slugifyurlencode` (`SeoUrlGenerator::ESCAPE_SLUGIFY`), in strict-variables mode (`SeoUrlTwigFactory`). Context: `SeoUrlMapping::getSeoPathInfoContext()`.
- **Static SEO URLs**: directly in the table `seo_url` (migration) or via `SeoUrlPersister::updateSeoUrls()`. Manually set URLs (`is_modified = 1`) are not overwritten by the generator.
- **Canonical**: `storefront/layout/meta.html.twig` (block `layout_head_canonical`) renders `page.metaInformation.canonical`; set in the page loader (e.g. `NavigationPageLoader`). In `seo_url`, `is_canonical` marks the main URL.
- **hreflang**: `HreflangLoader` builds a `HreflangCollection` from the sales channel domains; requires `hreflangActive` on the sales channel; `TemplateDataSubscriber::addHreflang()` (on `StorefrontRenderEvent`) provides it; block `layout_head_meta_tags_hreflangs` in `meta.html.twig`. At least two links are required, otherwise the collection stays empty.
- **Sitemap**: custom providers extend `AbstractUrlProvider` (tag `shopware.sitemap_url_provider`, autoconfigure `AutoconfigureCompilerPass.php:162-163`). Additional static URLs without PHP via `shopware.sitemap.custom_urls`.

Plugin tree (entity `swag_example`, route `frontend.swag_example.detail`):
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Controller/ExampleController.php             # Route frontend.swag_example.detail
    ├── Core/Content/Example/
    │   ├── ExampleDefinition.php                    # see T-27
    │   └── Seo/{ExampleSeoUrlRoute,ExampleSeoUrlSubscriber}.php
    ├── Sitemap/ExampleUrlProvider.php
    ├── Migration/Migration1760000000ExampleSeoUrlTemplate.php
    └── Resources/config/services.php
```

## 3. Instructions

1. Create a controller with a named route (`frontend.swag_example.detail`, parameter `exampleId`, `StorefrontRouteScope`). A name that differs from the route name leads to `RouteNotFoundException`.
2. Create and register `ExampleSeoUrlRoute` (examples).
3. Run the migration with the default template: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample` (or `bin/console database:migrate --all SwagExample`).
4. Generate URLs: subscriber on `swag_example.written` (incremental). Initial stock: `SeoUrlUpdater::update()` with all IDs from a custom command or the `activate()` lifecycle ([T-03](./03-plugin-lifecycle.md)). No generic CLI command just for SEO URLs was found.
5. Check: `SELECT route_name, path_info, seo_path_info, is_canonical, is_modified, is_deleted, error FROM seo_url WHERE route_name='frontend.swag_example.detail';` (`error` is set if the template cannot be rendered).
6. Adapt the template per sales channel: Admin (Settings, SEO) or DAL entity `seo_url_template` (`salesChannelId` null = default); changes trigger `SeoUrlTemplateChangeSubscriber`/`SeoUrlTemplateIndexingHandler`.
7. Link in Twig: `{{ seoUrl('frontend.swag_example.detail', { exampleId: example.id }) }}` (pattern `storefront/page/product-detail/meta.html.twig:13`). `SeoUrlPlaceholderHandler::replace()` replaces placeholders in the response with SEO paths.
8. Canonical for custom pages: in the page loader `$page->getMetaInformation()->setCanonical($url)` (`MetaInformation.php:114`, pattern `NavigationPageLoader`, URL via `SeoUrlPlaceholderHandlerInterface::generate()`); the base template renders it.
9. hreflang: activate "Hreflang" on the sales channel in the Admin (`hreflangActive`), optionally a default domain (`hreflangDefaultDomainId`) and per domain `hreflang_use_only_locale`. `TemplateDataSubscriber::addHreflang()` skips ESI requests (`_esi`) and uses `HreflangLoaderParameter`.
10. Static SEO URL: row in `seo_url` with `route_name`, `path_info` (internal path), `seo_path_info`, `language_id`, `sales_channel_id` (or NULL), `foreign_key` (stable ID), `is_canonical = 1`. Alternatively `SeoUrlPersister::updateSeoUrls($context, $routeName, $foreignKeys, $urls, $salesChannel)`; delete with an empty URL array (marks `is_deleted`).
11. Sitemap: `bin/console sitemap:generate` (options `--salesChannelId/-i`, `--force/-f`). Files are stored in the filesystem `shopware.filesystem.sitemap` ([T-39](./39-media-filesystem.md)); delivered via Store API `SitemapRoute`/`SitemapFileRoute`. `shopware.sitemap.batchsize` (default 100) determines `$limit` in `getUrls()`. Scheduled task `SitemapGenerateTask` can be disabled via `shopware.sitemap.scheduled_task.enabled`.
12. Adjust existing providers without a custom provider: event `SitemapQueryEvent` (e.g. `sitemap.query.product`, `ProductUrlProvider::QUERY_EVENT_NAME`). Further events in `core/Content/Sitemap/Event/`: `SitemapGeneratedEvent`, `SitemapGenerationStartEvent`, `SitemapFilterOpenTagEvent`.

## 4. Examples

`src/Core/Content/Example/Seo/ExampleSeoUrlRoute.php` (model: `ProductPageSeoUrlRoute`)
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Seo;

use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlMapping;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteConfig;
use Shopware\Core\Content\Seo\SeoUrlRoute\SeoUrlRouteInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use SwagExample\Core\Content\Example\ExampleDefinition;

class ExampleSeoUrlRoute implements SeoUrlRouteInterface
{
    public const ROUTE_NAME = 'frontend.swag_example.detail';
    public const DEFAULT_TEMPLATE = 'example/{{ example.translated.name }}';

    public function __construct(private readonly ExampleDefinition $definition)
    {
    }

    public function getConfig(): SeoUrlRouteConfig
    {
        return new SeoUrlRouteConfig(
            $this->definition,
            self::ROUTE_NAME,
            self::DEFAULT_TEMPLATE,
            true,        // skipInvalid: skip faulty templates instead of throwing an exception
            'exampleId'  // name of the route parameter for the ID
        );
    }

    public function prepareCriteria(Criteria $criteria, SalesChannelEntity $salesChannel): void
    {
        $criteria->addFilter(new EqualsFilter('active', true));
    }

    public function getMapping(Entity $entity, ?SalesChannelEntity $salesChannel): SeoUrlMapping
    {
        return new SeoUrlMapping(
            $entity,
            ['exampleId' => $entity->getUniqueIdentifier()],  // infoPathContext: router parameters
            ['example' => $entity->jsonSerialize()]           // seoPathInfoContext: Twig variables
        );
    }
}
```

`src/Core/Content/Example/Seo/ExampleSeoUrlSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Seo;

use Shopware\Core\Content\Seo\SeoUrlUpdater;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ExampleSeoUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SeoUrlUpdater $seoUrlUpdater)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return ['swag_example.written' => 'onWritten'];
    }

    public function onWritten(EntityWrittenEvent $event): void
    {
        $this->seoUrlUpdater->update(ExampleSeoUrlRoute::ROUTE_NAME, $event->getIds());
    }
}
```

`src/Migration/Migration1760000000ExampleSeoUrlTemplate.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagExample\Core\Content\Example\Seo\ExampleSeoUrlRoute;

class Migration1760000000ExampleSeoUrlTemplate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1760000000;
    }

    public function update(Connection $connection): void
    {
        $connection->insert('seo_url_template', [
            'id' => Uuid::randomBytes(),
            'sales_channel_id' => null,
            'route_name' => ExampleSeoUrlRoute::ROUTE_NAME,
            'entity_name' => 'swag_example',
            'template' => ExampleSeoUrlRoute::DEFAULT_TEMPLATE,
            'is_valid' => 1,
            'created_at' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}
```
`is_valid` has the DB default 1 (`Migration1595492053SeoUrlTemplate`), `is_headless` has the default 0 since `Migration1784801407AddHeadlessSeoUrlTemplate`; neither needs to be set. Migration scaffold: [../templates/migration/README.md](../templates/migration/README.md).

`src/Sitemap/ExampleUrlProvider.php` (reads SEO URLs via the protected helper method `getSeoUrls()`, which filters `is_canonical = 1`, `is_deleted = 0`)
```php
<?php declare(strict_types=1);

namespace SwagExample\Sitemap;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Content\Sitemap\Provider\AbstractUrlProvider;
use Shopware\Core\Content\Sitemap\Struct\Url;
use Shopware\Core\Content\Sitemap\Struct\UrlResult;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagExample\Core\Content\Example\Seo\ExampleSeoUrlRoute;

class ExampleUrlProvider extends AbstractUrlProvider
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getDecorated(): AbstractUrlProvider
    {
        throw new DecorationPatternException(self::class);
    }

    public function getName(): string
    {
        return 'swag_example';
    }

    public function getUrls(SalesChannelContext $context, int $limit, ?int $offset = null): UrlResult
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, COALESCE(updated_at, created_at) AS lastmod
             FROM swag_example WHERE active = 1
             ORDER BY id LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset ?? 0],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        );

        if ($rows === []) {
            return new UrlResult([], null);
        }

        $seoUrls = [];
        foreach ($this->getSeoUrls(array_column($rows, 'id'), ExampleSeoUrlRoute::ROUTE_NAME, $context, $this->connection) as $seoUrl) {
            $seoUrls[$seoUrl['foreign_key']] = $seoUrl['seo_path_info'];
        }

        $urls = [];
        foreach ($rows as $row) {
            if (!isset($seoUrls[$row['id']])) {
                continue; // no SEO URL, not in the sitemap
            }
            $url = new Url();
            $url->setLoc($seoUrls[$row['id']]);
            $url->setLastmod(new \DateTime($row['lastmod']));
            $url->setChangefreq('weekly');
            $url->setPriority(0.5);
            $url->setResource('swag_example');
            $url->setIdentifier($row['id']);
            $urls[] = $url;
        }

        $nextOffset = \count($rows) < $limit ? null : ($offset ?? 0) + $limit;

        return new UrlResult($urls, $nextOffset);
    }
}
```
The core classes use entity iterators with an ID-based offset (`ProductUrlProvider`); the numeric offset is a simplification.

`src/Resources/config/services.php` (explicit tags, even though autoconfigure sets them)
```php
<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Seo\SeoUrlUpdater;
use SwagExample\Core\Content\Example\ExampleDefinition;
use SwagExample\Core\Content\Example\Seo\ExampleSeoUrlRoute;
use SwagExample\Core\Content\Example\Seo\ExampleSeoUrlSubscriber;
use SwagExample\Sitemap\ExampleUrlProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ExampleSeoUrlRoute::class)
        ->args([service(ExampleDefinition::class)])
        ->tag('shopware.seo_url.route');

    $services->set(ExampleSeoUrlSubscriber::class)
        ->args([service(SeoUrlUpdater::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ExampleUrlProvider::class)
        ->args([service(Connection::class)])
        ->tag('shopware.sitemap_url_provider');
};
```
(`ExampleDefinition` itself must be registered as a service with tag `shopware.entity.definition`, see [T-27](./27-dal-entities.md).) The entity scaffold: [../templates/entity/README.md](../templates/entity/README.md).

Additional static URLs (project `config/packages/shopware.yaml`, keys according to `core/Framework/DependencyInjection/Configuration.php` around line 300):
```yaml
shopware:
    sitemap:
        custom_urls:
            - url: 'impressum-extra'
              salesChannelId: '98432def39fc4624b33213a56b8c944d'
              changeFreq: 'weekly'
              priority: 0.5
              lastMod: '2026-01-01 00:00:00'
```
`changeFreq` is an enum: `always, hourly, daily, weekly, monthly, yearly` (verified against the source code). `never` from the docs is NOT in the enum.

Exclude individual resources from the sitemap (`excluded_urls` with `resource`, `identifier`, `salesChannelId`; `Configuration::createSitemapSection`, evaluated via `ConfigHandler::EXCLUDED_URLS_KEY` in `ProductUrlProvider`, `CategoryUrlProvider`, `LandingPageUrlProvider`). `resource` is the entity class (`ProductEntity::class`, `CategoryEntity::class`, `LandingPageEntity::class`), `identifier` the entity ID; only entries with a matching `salesChannelId` take effect:
```yaml
shopware:
    sitemap:
        excluded_urls:
            - resource: 'Shopware\Core\Content\Product\ProductEntity'
              identifier: '0190a1b2c3d44e5f8a9b0c1d2e3f4a5b'
              salesChannelId: '98432def39fc4624b33213a56b8c944d'
```

## 5. Pitfalls

- **Default template missing**: `SeoException::invalidTemplate('Default templates not configured')` in `SeoUrlUpdater`; create the default (`sales_channel_id = NULL`) via migration.
- **Route name does not match the controller route**: `RouteNotFoundException`.
- **Strict variables**: an undefined variable in the template is an error. With `skipInvalid = true`, the URL is skipped/marked with `error` and a warning is logged; with `false`, an exception is thrown.
- **Empty path**: `SeoUrlGenerator` deliberately returns a URL with `error` and an empty `seoPathInfo`, so that the existing URL is not marked as deleted.
- **Associations/translations**: `getAssociations()` loads variables such as `example.translated.name` automatically via `TwigVariableParser`; for custom `jsonSerialize()` data, add associations via `prepareCriteria()`.
- **Docs error sitemap**: the setters are named `setLoc()`, `setLastmod()`, `setChangefreq()`, `setPriority()`, `setResource()`, `setIdentifier()` (`Struct/Url.php`), not as in the docs. The code is authoritative.
- **Forgetting `getDecorated()`**: abstract method; providers without it do not run (core providers throw `DecorationPatternException`).
- **`is_modified = 1`**: manually edited URLs are not overwritten on template changes; resetting must be done explicitly.
- **Respect `limit`/`offset`** in the provider; `nextOffset = null` ends the provider, otherwise an endless loop or missing URLs. Sitemap only for active sales channels with a domain.
- **`@internal` note**: `SeoUrlRouteInterface` extends the `@internal`-marked `EntitySeoUrlRouteInterface`, but is itself not `@internal` and is the documented extension point.

### Deprecations and version notes (6.5/6.6 → 6.7)

- New in 6.7: headless SEO URLs (migration `V6_7/Migration1784801407AddHeadlessSeoUrlTemplate`, column `seo_url_template.is_headless`). `ProductStoreApiUrlRoute`, `CategoryStoreApiUrlRoute`, `LandingPageStoreApiUrlRoute` run via `EntitySeoUrlRouteInterface` and `SeoUrlUpdater::findEntitySeoUrlRoute()`; for API sales channels, generation only happens with a domain with `is_external_storefront = 1`.
- `@deprecated tag:v6.8.0`: `SeoResolver::resolve()`/`AbstractSeoResolver`/`EmptyPathInfoResolver` (replacement `resolveUrl()`), `SeoResolverData::getAll()` predecessor, `TemplateGroup`, `SeoUrlRouteNotFoundException`, `SeoUrlUpdateEvent` (context will be mandatory in the constructor), collection fallback in `StoreApiSeoResolver`.
- In 6.7, `SeoUrlUpdater` is triggered by `SeoUrlUpdateListener` (`storefront/Framework/Seo/SeoUrlRoute/`) on indexer events; can be skipped via `$event->getSkip()` (`product.seo-url`, `category.seo-url`, `landing_page.seo-url`).
- Sitemap provider contract (`AbstractUrlProvider`, decoration pattern) unchanged.
- XML service configuration is deprecated in 6.7, hence `services.php`.

## 6. Checklist

- [ ] Named storefront route exists and its name matches `ROUTE_NAME`
- [ ] `SeoUrlRouteInterface` implementation registered (tag `shopware.seo_url.route`)
- [ ] Migration creates the default template in `seo_url_template` (`sales_channel_id = NULL`)
- [ ] Subscriber calls `SeoUrlUpdater::update()` on `<entity>.written`; initial stock covered
- [ ] Template variables and associations in `prepareCriteria()`/`getMapping()` complete
- [ ] `seo_url` checked via SQL (`error`, `is_canonical`)
- [ ] Canonical via `MetaInformation::setCanonical()` for custom pages
- [ ] hreflang activated on the sales channel (at least two domains/languages)
- [ ] Sitemap provider: `getDecorated()`, `limit`/`offset`, tag `shopware.sitemap_url_provider`; `sitemap:generate` tested
- [ ] `changeFreq` values from the enum

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/content/seo/add-custom-seo-url.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/sitemap/add-custom-sitemap-entries.html

Source code (relative to `vendor/shopware/`):
- `core/Content/Seo/SeoUrlRoute/{SeoUrlRouteInterface,EntitySeoUrlRouteInterface,SeoUrlRouteConfig,SeoUrlMapping,SeoUrlRouteRegistry,EntityRouteResolver,ProductStoreApiUrlRoute}.php`
- `core/Content/Seo/{SeoUrlUpdater,SeoUrlGenerator,SeoUrlPersister,SeoUrlTwigFactory,SeoUrlPlaceholderHandler,HreflangLoader,HreflangLoaderInterface,HreflangLoaderParameter}.php`
- `core/Content/Seo/SeoUrl/SeoUrlDefinition.php`, `SeoUrlTemplate/SeoUrlTemplateDefinition.php`
- `core/Content/Sitemap/Provider/{AbstractUrlProvider,ProductUrlProvider,CustomUrlProvider}.php`, `Struct/{Url,UrlResult}.php`, `Commands/SitemapGenerateCommand.php`, `Event/*`, `Service/SitemapExporter.php`
- `core/Framework/DependencyInjection/{Configuration.php,seo.php,CompilerPass/AutoconfigureCompilerPass.php}`, `core/Content/DependencyInjection/sitemap.php`
- `core/Migration/V6_3/Migration1595492053SeoUrlTemplate.php`, `V6_7/Migration1784801407AddHeadlessSeoUrlTemplate.php`
- `storefront/Framework/Seo/SeoUrlRoute/{ProductPageSeoUrlRoute,NavigationPageSeoUrlRoute,LandingPageSeoUrlRoute,SeoUrlUpdateListener}.php`
- `storefront/Framework/Routing/TemplateDataSubscriber.php`, `storefront/Page/{MetaInformation,Navigation/NavigationPageLoader}.php`
- `storefront/Resources/views/storefront/layout/meta.html.twig`, `page/product-detail/meta.html.twig`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Examples were neither executed nor statically checked; the controller, `ExampleDefinition` and the entity migration are not part of this reference.
- Plugins do NOT load `Resources/config/packages/*.yaml` automatically: `Bundle::build()` does not call `buildDefaultConfig()` (only Framework, Storefront, Administration, Elasticsearch, Profiling call it themselves). `shopware.sitemap.custom_urls` from a plugin can only be set if the plugin class calls `$this->buildDefaultConfig($container)` (protected) in `build()`, otherwise in the project under `config/packages/shopware.yaml`; see [T-43](./43-cli-logging-systemchecks.md).
- ⚠ GAP: No generic CLI command found for regenerating all SEO URLs for plugin routes. `dal:refresh:index` applies to product/category/landing page (indexer, `SeoUrlUpdateListener`), not confirmed for custom entities.
- ⚠ GAP: Relationship between `is_canonical` and 301 redirects of old URLs (`SeoResolver`) not examined.
- ⚠ UNVERIFIED: hreflang parameter determination in `TemplateDataSubscriber` only partially read; required request attributes for custom controllers not verified.
- ⚠ UNVERIFIED: The statement "6.5/6.6 had no headless SEO templates" is based solely on the existence of the 6.7 migration.
