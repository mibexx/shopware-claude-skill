# SEO-URLs und Sitemap (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eigene Entities/Seiten SEO-URLs bekommen (dynamisch per Template oder statisch), Canonical-/hreflang-Tags relevant sind oder die Sitemap um eigene URLs erweitert wird.

Abgrenzung: Controller und Page-Loader in [T-16 Storefront-Controller](./16-storefront-controller-pages.md); Entity-Definitionen in [T-27 DAL-Entities](./27-dal-entities.md); Migrationen in [T-04](./04-datenbank-migrationen.md); Subscriber auf `<entity>.written` in [T-07](./07-events-subscriber.md); Scheduled Tasks in [T-33](./33-scheduled-tasks.md); HTTP-Cache-Invalidierung in [T-41 Caching](./41-caching.md).

## 2. Konzepte

- **Dynamische SEO-URLs**: Klasse mit `SeoUrlRouteInterface` (`getConfig()`, `prepareCriteria()`, `getMapping()`), Tag `shopware.seo_url.route` (wird per Autoconfigure automatisch gesetzt, `AutoconfigureCompilerPass.php:178-179`). Eine Route pro Route-Name; `SeoUrlRouteRegistry` indiziert nach Route-Name und Entity-Name.
- **Zusätzlich nötig**: (a) eine Zeile in `seo_url_template` (Migration, Default mit `sales_channel_id = NULL`), (b) ein Trigger, der `SeoUrlUpdater::update($routeName, $ids)` aufruft (z. B. Subscriber auf `<entity>.written`), (c) eine benannte Storefront-Route, denn `SeoUrlGenerator::generateUrls()` ruft `$router->generate($routeName, $infoPathContext)`.
- **Template** = Twig-Snippet, gerendert mit Escaper `slugifyurlencode` (`SeoUrlGenerator::ESCAPE_SLUGIFY`), im strict-variables-Modus (`SeoUrlTwigFactory`). Kontext: `SeoUrlMapping::getSeoPathInfoContext()`.
- **Statische SEO-URLs**: direkt in Tabelle `seo_url` (Migration) oder per `SeoUrlPersister::updateSeoUrls()`. Manuell gesetzte URLs (`is_modified = 1`) werden vom Generator nicht überschrieben.
- **Canonical**: `storefront/layout/meta.html.twig` (Block `layout_head_canonical`) rendert `page.metaInformation.canonical`; gesetzt im Page-Loader (z. B. `NavigationPageLoader`). In `seo_url` markiert `is_canonical` die Haupt-URL.
- **hreflang**: `HreflangLoader` baut eine `HreflangCollection` aus den Sales-Channel-Domains; setzt `hreflangActive` am Sales Channel voraus; `TemplateDataSubscriber::addHreflang()` (auf `StorefrontRenderEvent`) stellt sie bereit; Block `layout_head_meta_tags_hreflangs` in `meta.html.twig`. Mindestens zwei Links nötig, sonst bleibt die Collection leer.
- **Sitemap**: eigene Provider erweitern `AbstractUrlProvider` (Tag `shopware.sitemap_url_provider`, Autoconfigure `AutoconfigureCompilerPass.php:162-163`). Statische Zusatz-URLs ohne PHP über `shopware.sitemap.custom_urls`.

Plugin-Baum (Entity `swag_example`, Route `frontend.swag_example.detail`):
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Controller/ExampleController.php             # Route frontend.swag_example.detail
    ├── Core/Content/Example/
    │   ├── ExampleDefinition.php                    # siehe T-27
    │   └── Seo/{ExampleSeoUrlRoute,ExampleSeoUrlSubscriber}.php
    ├── Sitemap/ExampleUrlProvider.php
    ├── Migration/Migration1760000000ExampleSeoUrlTemplate.php
    └── Resources/config/services.php
```

## 3. Anleitung

1. Controller mit benannter Route anlegen (`frontend.swag_example.detail`, Parameter `exampleId`, `StorefrontRouteScope`). Name abweichend vom Route-Namen führt zu `RouteNotFoundException`.
2. `ExampleSeoUrlRoute` anlegen und registrieren (Beispiele).
3. Migration mit Default-Template ausführen: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample` (bzw. `bin/console database:migrate --all SwagExample`).
4. URLs erzeugen: Subscriber auf `swag_example.written` (inkrementell). Initialbestand: `SeoUrlUpdater::update()` mit allen IDs aus eigenem Command oder `activate()`-Lifecycle ([T-03](./03-plugin-lifecycle.md)). Ein generischer CLI-Befehl nur für SEO-URLs wurde nicht gefunden.
5. Prüfen: `SELECT route_name, path_info, seo_path_info, is_canonical, is_modified, is_deleted, error FROM seo_url WHERE route_name='frontend.swag_example.detail';` (`error` wird bei nicht renderbarem Template gesetzt).
6. Template pro Sales Channel anpassen: Admin (Einstellungen, SEO) bzw. DAL-Entity `seo_url_template` (`salesChannelId` null = Default); Änderungen lösen `SeoUrlTemplateChangeSubscriber`/`SeoUrlTemplateIndexingHandler` aus.
7. Link im Twig: `{{ seoUrl('frontend.swag_example.detail', { exampleId: example.id }) }}` (Muster `storefront/page/product-detail/meta.html.twig:13`). `SeoUrlPlaceholderHandler::replace()` ersetzt Platzhalter in der Antwort durch SEO-Pfade.
8. Canonical für eigene Seiten: im Page-Loader `$page->getMetaInformation()->setCanonical($url)` (`MetaInformation.php:114`, Muster `NavigationPageLoader`, URL über `SeoUrlPlaceholderHandlerInterface::generate()`); die Basis-Vorlage rendert ihn.
9. hreflang: im Admin am Sales Channel "Hreflang" aktivieren (`hreflangActive`), optional Default-Domain (`hreflangDefaultDomainId`) und je Domain `hreflang_use_only_locale`. `TemplateDataSubscriber::addHreflang()` überspringt ESI-Requests (`_esi`) und nutzt `HreflangLoaderParameter`.
10. Statische SEO-URL: Zeile in `seo_url` mit `route_name`, `path_info` (interner Pfad), `seo_path_info`, `language_id`, `sales_channel_id` (oder NULL), `foreign_key` (stabile ID), `is_canonical = 1`. Alternativ `SeoUrlPersister::updateSeoUrls($context, $routeName, $foreignKeys, $urls, $salesChannel)`; Löschen mit leerem URL-Array (markiert `is_deleted`).
11. Sitemap: `bin/console sitemap:generate` (Optionen `--salesChannelId/-i`, `--force/-f`). Dateien liegen im Filesystem `shopware.filesystem.sitemap` ([T-39](./39-media-filesystem.md)); Auslieferung über Store-API `SitemapRoute`/`SitemapFileRoute`. `shopware.sitemap.batchsize` (Default 100) bestimmt `$limit` in `getUrls()`. Scheduled Task `SitemapGenerateTask` abschaltbar über `shopware.sitemap.scheduled_task.enabled`.
12. Bestehende Provider anpassen ohne eigenen Provider: Event `SitemapQueryEvent` (z. B. `sitemap.query.product`, `ProductUrlProvider::QUERY_EVENT_NAME`). Weitere Events in `core/Content/Sitemap/Event/`: `SitemapGeneratedEvent`, `SitemapGenerationStartEvent`, `SitemapFilterOpenTagEvent`.

## 4. Beispiele

`src/Core/Content/Example/Seo/ExampleSeoUrlRoute.php` (Vorbild `ProductPageSeoUrlRoute`)
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
            true,        // skipInvalid: fehlerhafte Templates überspringen statt Exception
            'exampleId'  // Name des Route-Parameters für die ID
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
            ['exampleId' => $entity->getUniqueIdentifier()],  // infoPathContext: Router-Parameter
            ['example' => $entity->jsonSerialize()]           // seoPathInfoContext: Twig-Variablen
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
`is_valid` hat den DB-Default 1 (`Migration1595492053SeoUrlTemplate`), `is_headless` seit `Migration1784801407AddHeadlessSeoUrlTemplate` den Default 0; beide müssen nicht gesetzt werden. Migrations-Gerüst: [../templates/migration/README.md](../templates/migration/README.md).

`src/Sitemap/ExampleUrlProvider.php` (liest SEO-URLs über die geschützte Hilfsmethode `getSeoUrls()`, die `is_canonical = 1`, `is_deleted = 0` filtert)
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
                continue; // ohne SEO-URL nicht in die Sitemap
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
Die Kernklassen nutzen Entity-Iteratoren mit ID-basiertem Offset (`ProductUrlProvider`); der numerische Offset ist eine Vereinfachung.

`src/Resources/config/services.php` (explizite Tags, auch wenn Autoconfigure sie setzt)
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
(`ExampleDefinition` muss selbst als Service mit Tag `shopware.entity.definition` registriert sein, siehe [T-27](./27-dal-entities.md).) Das Entity-Gerüst: [../templates/entity/README.md](../templates/entity/README.md).

Statische Zusatz-URLs (Projekt `config/packages/shopware.yaml`, Schlüssel laut `core/Framework/DependencyInjection/Configuration.php` um Z. 300):
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
`changeFreq` ist ein Enum: `always, hourly, daily, weekly, monthly, yearly` (am Quellcode verifiziert). `never` aus der Doku steht NICHT im Enum.

Einzelne Ressourcen aus der Sitemap ausschließen (`excluded_urls` mit `resource`, `identifier`, `salesChannelId`; `Configuration::createSitemapSection`, ausgewertet über `ConfigHandler::EXCLUDED_URLS_KEY` in `ProductUrlProvider`, `CategoryUrlProvider`, `LandingPageUrlProvider`). `resource` ist die Entity-Klasse (`ProductEntity::class`, `CategoryEntity::class`, `LandingPageEntity::class`), `identifier` die Entity-ID; nur Einträge mit passender `salesChannelId` greifen:
```yaml
shopware:
    sitemap:
        excluded_urls:
            - resource: 'Shopware\Core\Content\Product\ProductEntity'
              identifier: '0190a1b2c3d44e5f8a9b0c1d2e3f4a5b'
              salesChannelId: '98432def39fc4624b33213a56b8c944d'
```

## 5. Fallstricke

- **Default-Template fehlt**: `SeoException::invalidTemplate('Default templates not configured')` im `SeoUrlUpdater`; Default (`sales_channel_id = NULL`) per Migration anlegen.
- **Route-Name passt nicht zur Controller-Route**: `RouteNotFoundException`.
- **Strict Variables**: undefinierte Variable im Template ist ein Fehler. Bei `skipInvalid = true` wird die URL übersprungen/mit `error` markiert und gewarnt, bei `false` kommt eine Exception.
- **Leerer Pfad**: `SeoUrlGenerator` liefert bewusst eine URL mit `error` und leerem `seoPathInfo`, damit die bestehende URL nicht als gelöscht markiert wird.
- **Assoziationen/Übersetzungen**: Variablen wie `example.translated.name` lädt `getAssociations()` automatisch über `TwigVariableParser`; für eigene `jsonSerialize()`-Daten Associations über `prepareCriteria()` ergänzen.
- **Doku-Fehler Sitemap**: Setter heißen `setLoc()`, `setLastmod()`, `setChangefreq()`, `setPriority()`, `setResource()`, `setIdentifier()` (`Struct/Url.php`), nicht wie in der Doku. Der Code gilt.
- **`getDecorated()` vergessen**: abstrakte Methode; Provider ohne sie laufen nicht (Core-Provider werfen `DecorationPatternException`).
- **`is_modified = 1`**: manuell bearbeitete URLs werden bei Template-Änderungen nicht überschrieben; Zurücksetzen muss explizit geschehen.
- **`limit`/`offset`** im Provider respektieren; `nextOffset = null` beendet den Provider, sonst Endlosschleife oder fehlende URLs. Sitemap nur für aktive Sales Channels mit Domain.
- **`@internal`-Hinweis**: `SeoUrlRouteInterface` erweitert das `@internal` markierte `EntitySeoUrlRouteInterface`, ist aber selbst nicht `@internal` und der dokumentierte Erweiterungspunkt.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Neu in 6.7: Headless-SEO-URLs (Migration `V6_7/Migration1784801407AddHeadlessSeoUrlTemplate`, Spalte `seo_url_template.is_headless`). `ProductStoreApiUrlRoute`, `CategoryStoreApiUrlRoute`, `LandingPageStoreApiUrlRoute` laufen über `EntitySeoUrlRouteInterface` und `SeoUrlUpdater::findEntitySeoUrlRoute()`; für API-Sales-Channels wird nur mit Domain `is_external_storefront = 1` generiert.
- `@deprecated tag:v6.8.0`: `SeoResolver::resolve()`/`AbstractSeoResolver`/`EmptyPathInfoResolver` (Ersatz `resolveUrl()`), `SeoResolverData::getAll()`-Vorgänger, `TemplateGroup`, `SeoUrlRouteNotFoundException`, `SeoUrlUpdateEvent` (Context künftig Pflicht im Konstruktor), Collection-Fallback in `StoreApiSeoResolver`.
- `SeoUrlUpdater` wird in 6.7 durch `SeoUrlUpdateListener` (`storefront/Framework/Seo/SeoUrlRoute/`) auf Indexer-Events angestoßen; über `$event->getSkip()` (`product.seo-url`, `category.seo-url`, `landing_page.seo-url`) überspringbar.
- Sitemap-Provider-Vertrag (`AbstractUrlProvider`, Decoration-Pattern) unverändert.
- XML-Service-Konfiguration ist in 6.7 deprecated, daher `services.php`.

## 6. Checkliste

- [ ] Benannte Storefront-Route existiert und Name entspricht `ROUTE_NAME`
- [ ] `SeoUrlRouteInterface`-Implementierung registriert (Tag `shopware.seo_url.route`)
- [ ] Migration legt Default-Template in `seo_url_template` (`sales_channel_id = NULL`) an
- [ ] Subscriber ruft `SeoUrlUpdater::update()` bei `<entity>.written`; Initialbestand abgedeckt
- [ ] Template-Variablen und Associations in `prepareCriteria()`/`getMapping()` vollständig
- [ ] `seo_url` per SQL geprüft (`error`, `is_canonical`)
- [ ] Canonical über `MetaInformation::setCanonical()` bei eigenen Seiten
- [ ] hreflang am Sales Channel aktiviert (mindestens zwei Domains/Sprachen)
- [ ] Sitemap-Provider: `getDecorated()`, `limit`/`offset`, Tag `shopware.sitemap_url_provider`; `sitemap:generate` getestet
- [ ] `changeFreq`-Werte aus dem Enum

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/content/seo/add-custom-seo-url.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/sitemap/add-custom-sitemap-entries.html

Quellcode (relativ zu `vendor/shopware/`):
- `core/Content/Seo/SeoUrlRoute/{SeoUrlRouteInterface,EntitySeoUrlRouteInterface,SeoUrlRouteConfig,SeoUrlMapping,SeoUrlRouteRegistry,EntityRouteResolver,ProductStoreApiUrlRoute}.php`
- `core/Content/Seo/{SeoUrlUpdater,SeoUrlGenerator,SeoUrlPersister,SeoUrlTwigFactory,SeoUrlPlaceholderHandler,HreflangLoader,HreflangLoaderInterface,HreflangLoaderParameter}.php`
- `core/Content/Seo/SeoUrl/SeoUrlDefinition.php`, `SeoUrlTemplate/SeoUrlTemplateDefinition.php`
- `core/Content/Sitemap/Provider/{AbstractUrlProvider,ProductUrlProvider,CustomUrlProvider}.php`, `Struct/{Url,UrlResult}.php`, `Commands/SitemapGenerateCommand.php`, `Event/*`, `Service/SitemapExporter.php`
- `core/Framework/DependencyInjection/{Configuration.php,seo.php,CompilerPass/AutoconfigureCompilerPass.php}`, `core/Content/DependencyInjection/sitemap.php`
- `core/Migration/V6_3/Migration1595492053SeoUrlTemplate.php`, `V6_7/Migration1784801407AddHeadlessSeoUrlTemplate.php`
- `storefront/Framework/Seo/SeoUrlRoute/{ProductPageSeoUrlRoute,NavigationPageSeoUrlRoute,LandingPageSeoUrlRoute,SeoUrlUpdateListener}.php`
- `storefront/Framework/Routing/TemplateDataSubscriber.php`, `storefront/Page/{MetaInformation,Navigation/NavigationPageLoader}.php`
- `storefront/Resources/views/storefront/layout/meta.html.twig`, `page/product-detail/meta.html.twig`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele wurden weder ausgeführt noch statisch geprüft; Controller, `ExampleDefinition` und Entity-Migration sind nicht Teil dieser Referenz.
- Plugins laden `Resources/config/packages/*.yaml` NICHT automatisch: `Bundle::build()` ruft `buildDefaultConfig()` nicht auf (nur Framework, Storefront, Administration, Elasticsearch, Profiling rufen es selbst auf). `shopware.sitemap.custom_urls` aus einem Plugin setzt man nur, wenn die Plugin-Klasse in `build()` `$this->buildDefaultConfig($container)` aufruft (protected), sonst im Projekt unter `config/packages/shopware.yaml`; siehe [T-43](./43-cli-logging-systemchecks.md).
- ⚠ LÜCKE: Kein generischer CLI-Befehl zum Neugenerieren aller SEO-URLs für Plugin-Routen gefunden. `dal:refresh:index` greift für Product/Category/LandingPage (Indexer, `SeoUrlUpdateListener`), für eigene Entities nicht bestätigt.
- ⚠ LÜCKE: Zusammenhang von `is_canonical` mit 301-Redirects alter URLs (`SeoResolver`) nicht untersucht.
- ⚠ UNGEPRÜFT: hreflang-Parameterermittlung im `TemplateDataSubscriber` nur teilweise gelesen; Pflicht-Request-Attribute für eigene Controller nicht verifiziert.
- ⚠ UNGEPRÜFT: Die Aussage "6.5/6.6 hatten keine Headless-SEO-Templates" beruht nur auf dem Vorhandensein der 6.7-Migration.
