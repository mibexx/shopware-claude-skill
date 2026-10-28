# Produkt-Listing, Suche und Elasticsearch/OpenSearch (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn Listing-Filter, Sortierungen, die Produktsuche (DB-Keyword-Suche) oder Elasticsearch/OpenSearch (Aktivierung, Mapping-Erweiterung, Betrieb) angepasst werden.
Abgrenzung: Twig-Blockmechanik siehe [T-14](./14-storefront-twig-templates.md), Snippets siehe [T-18](./18-snippets-uebersetzungen.md), JS-Filter-Plugins siehe [T-15](./15-storefront-js-plugins.md), Store-API-Route überschreiben siehe [T-30](./30-store-api-routen.md), Events siehe [T-07](./07-events-subscriber.md), Dekoration siehe [T-06](./06-service-dekoration.md), Migrationen siehe [T-04](./04-datenbank-migrationen.md), Extension-Points siehe [T-08](./08-extension-points.md), Caching siehe [T-41](./41-caching.md).

## 2. Konzepte

- **Eine Pipeline für Listing, Suche und Suggest:** Die Routen `ResolveCriteriaProductListingRoute`, `ResolvedCriteriaProductSearchRoute`, `ResolvedCriteriaProductSuggestRoute` rufen `CompositeListingProcessor::prepare()` (Request → `Criteria`), dispatchen `ProductListingCriteriaEvent` / `ProductSearchCriteriaEvent` / `ProductSuggestCriteriaEvent`, laden Produkte, rufen `CompositeListingProcessor::process()` und dispatchen `Product*ResultEvent`. Event-Name = Klassenname (`ProductEvents::PRODUCT_LISTING_CRITERIA = ProductListingCriteriaEvent::class`).
- **Eigener Filter** (empfohlen): von `AbstractListingFilterHandler` ableiten, Tag `shopware.listing.filter.handler`. Alternativ per `ProductListingCollectFilterEvent` (`getFilters()->add(new Filter(...))`, der in der Doku gezeigte Weg). Beide laufen durch `AggregationListingProcessor`, der Aggregationen und (nur bei `isFiltered() === true`) den Filter als **Post-Filter** ins `Criteria` schreibt, damit Facetten der anderen Filter stabil bleiben. `reduce-aggregations` schränkt Aggregationen zusätzlich ein (`Filter::exclude()`).
- **Eigene Sortierung:** persistent per Migration in `product_sorting` + `product_sorting_translation` (im Admin editierbar) oder zur Laufzeit per `ProductListingCollectSortingEvent` (Doku: für Produktion nicht empfohlen). Aktive Sortierung per Request-Parameter `order`, Default aus `core.listing.defaultSorting` bzw. `core.listing.defaultSearchResultSorting`. Suche bekommt `_score DESC` als Fallback (`SortingListingProcessor::prepare`).
- **Eigener Processor** (Criteria für alle drei Kontexte): `AbstractListingProcessor`, Tag `shopware.listing.processor` (höhere Priorität = früher). Kontext über `criteria->hasState('listing-route-context' | 'search-route-context' | 'suggest-route-context')`.
- **Suche ohne ES:** `ProductSearchBuilder` (core) zerlegt den Term über `ProductSearchTermInterpreter` in `product_search_keyword`-Treffer und baut `ScoreQuery`s auf `product.searchKeywords.keyword`. Keywords schreibt `SearchKeywordUpdater` beim Indexing anhand `product_search_config_field` (Admin: Einstellungen → Suche). Ausschaltbar: `shopware.product.search_keyword.indexing: false`.
- **ES/OpenSearch greift nur wenn** (`ElasticsearchHelper::allowSearch`): `searchEnabled` UND Entity hat registrierte `AbstractElasticsearchDefinition` (Tag `shopware.es.definition`, im Core nur `product`) UND `Criteria` hat State `elasticsearchAware` (setzen Listing-Loader, Search-/Suggest-/Detail-Route, Product-Slider-Streams). `throw_exception=1`: Exception, `0`: Fallback auf DB.
- **Wichtig:** Bei aktivem ES gilt die Admin-Suchkonfiguration (`product_search_config`/Keyword-Tabelle) NICHT für die Produktsuche. `Elasticsearch\Product\ProductSearchBuilder` (Decorator auf `ProductSearchBuilderInterface`, Priorität -50000) setzt nur noch `Criteria::setTerm()`. Filter/Sortierungen auf Felder außerhalb des ES-Mappings brauchen Mapping-Erweiterung.
- **Dekorieren im Container:** Ein Symfony-Decorator übernimmt die Tags des dekorierten Service (belegt in `symfony/dependency-injection` `DecoratorServicePass`). Das Tag `shopware.es.definition` daher NICHT zusätzlich setzen.

Verzeichnisbaum:

```
custom/plugins/SwagExample/src/
├── Elasticsearch/SwagProductEsDefinition.php   # Decorator von ElasticsearchProductDefinition
├── Listing/CloseoutListingFilterHandler.php    # extends AbstractListingFilterHandler
├── Listing/ListingSubscriber.php               # Sortierung per Event
├── Migration/Migration1730000000AddMySorting.php
└── Resources/
    ├── config/services.php
    └── views/storefront/component/listing/filter-panel.html.twig
```

Core-Orte: `core/Content/Product/SalesChannel/Listing/{Filter,FilterCollection,ProductListingLoader}.php`, `.../Listing/Filter/*ListingFilterHandler.php`, `.../Listing/Processor/*ListingProcessor.php`, `.../Sorting/ProductSorting*.php`, `core/Content/Product/SearchKeyword/`, `elasticsearch/Framework/`, `elasticsearch/Product/`, `storefront/Resources/views/storefront/component/listing/{filter-panel.html.twig,filter/*.html.twig}`.

## 3. Anleitung

**A. Listing-Filter**
1. `AbstractListingFilterHandler` ableiten: `create()` liefert `Filter(name, filtered, aggregations, dalFilter, values)` oder `null` (Filter deaktiviert). Optional `process()` überschreiben (Beispiel `PropertyListingFilterHandler`).
2. Mit Tag `shopware.listing.filter.handler` registrieren (alternativ `ProductListingCollectFilterEvent`).
3. Template: `filter-panel.html.twig` erweitern (Blöcke `component_filter_panel_items` oder `component_filter_panel_item_{manufacturer,properties,price,rating_select,shipping_free}`), `filter-boolean|multi-select|range|rating-select|property-select` per `sw_include` einbinden. Include-`name` = Request-Parameter = `Filter::getName()`.
4. Ergebnis im Twig: `listing.aggregations.get('<name>')` bzw. `listing.currentFilters`. Reine Twig-Änderung braucht keinen JS-Build, nur `bin/console cache:clear`.

**B. Sortierung**
- Persistent: Migration (unten), dann `bin/console database:migrate --all SwagExample`. Reihenfolge im Dropdown = `priority` DESC.
- Laufzeit: `ProductListingCollectSortingEvent` (unten).
- `fields[]`: `field` (DAL-Pfad, z. B. `product.name`), `order`, `priority`, `naturalSorting`. Bei ES muss das Feld im Index stehen. Custom Fields werden nur indexiert, wenn sie in einer Sortierung/einem Product-Stream stehen, `custom_field.include_in_search = 1` haben, aus einer App stammen oder per Parameter/Event gemappt sind (`ElasticsearchIndexingUtils::getCustomFieldTypes`).

**C. Criteria beeinflussen:** `ProductListingCriteriaEvent` (Kategorie), `ProductSearchCriteriaEvent` (Suche), `ProductSuggestCriteriaEvent` (Live-Suche); für alle drei gemeinsam `AbstractListingProcessor`.

**D. Suche ohne ES**
- Konfiguration im Admin (`product_search_config`, `product_search_config_field`): Felder, Ranking, Tokenize, Mindestlänge, UND/ODER, Stopwörter/Synonyme.
- Keywords neu bauen: `bin/console dal:refresh:index --only=product.indexer`. `ProductIndexer::SEARCH_KEYWORD_UPDATER = 'product.search-keyword'` ist überspringbar (`--skip=product.search-keyword`).
- Eigene Analyse: `ProductSearchKeywordAnalyzerInterface` (Index-Zeit), `ProductSearchBuilderInterface`/`ProductSearchTermInterpreterInterface` (Such-Zeit) dekorieren.
- `config/packages/shopware.yaml`: `shopware.search.term_max_length` (300), `shopware.search.preserved_chars`, `shopware.product.search_keyword.indexing`, `...relevant_keyword_count` (8).

**E. Elasticsearch/OpenSearch**
1. `.env`:
   ```
   OPENSEARCH_URL=http://localhost:9200
   SHOPWARE_ES_ENABLED=1
   SHOPWARE_ES_INDEXING_ENABLED=1
   SHOPWARE_ES_INDEX_PREFIX=sw
   SHOPWARE_ES_THROW_EXCEPTION=1
   ```
   Defaults (`elasticsearch/Resources/config/packages/elasticsearch.yaml`): `enabled`/`indexing_enabled` leer (= false), `index_prefix: sw`, `throw_exception: 1`, `indexing_batch_size: 100`. Weitere: `SHOPWARE_ES_NGRAM_MIN_GRAM/MAX_GRAM` (4/5), `SHOPWARE_ES_USE_LANGUAGE_ANALYZER` (1), `SHOPWARE_ES_EXCLUDE_SOURCE`, `SHOPWARE_ES_NUMBER_OF_SHARDS/REPLICAS`, `SHOPWARE_ES_DIMENSION_NORMALIZE` (Reindex nötig). Admin-Suche separat: `SHOPWARE_ADMIN_ES_ENABLED`, `ADMIN_OPENSEARCH_URL`, `SHOPWARE_ADMIN_ES_INDEX_PREFIX` (`sw-admin`).
2. Befehle (aus `#[AsCommand]`):
   - `bin/console es:index [--no-queue] [--no-progress] [--only=product]` (neuer Index, Alias per `CreateAliasTask`)
   - `bin/console es:create:alias`, `es:status`, `es:reset`, `es:index:cleanup [-f]`, `es:test:analyzer <term>`
   - `bin/console es:mapping:update` (neue Felder ohne Vollreindex)
   - Admin: `es:admin:index`, `es:admin:reset`, `es:admin:test`, `es:admin:mapping:update`
   - `bin/console dal:refresh:index --use-queue` (Gesamt-Reindex laut Doku)
3. Betrieb (Doku): Suche an bei Indexing aus ist möglich (Read-only-Nodes). Bei Queue-Indexing muss ein Worker laufen (`messenger:consume`, siehe [T-34](./34-message-queue.md)). Inkrementell: `Product\ProductUpdater` auf `ProductIndexerEvent` und `ProductStockAlteredEvent`.
4. Erweiterung: Decorator (Beispiel) oder eigene `AbstractElasticsearchDefinition` + Tag `shopware.es.definition` für eigene Entities. Danach `es:mapping:update` (neue Felder) oder `es:reset` + `es:index` (Analyzer/Typänderung).
5. Weitere Hooks: `ElasticsearchIndexConfigEvent`, `ElasticsearchIndexerLanguageCriteriaEvent`, `ElasticsearchIndexIteratorEvent` (Property `iterator` schreibbar), `ElasticsearchIndexCreatedEvent`, `ElasticsearchIndexAliasSwitchedEvent`, `ElasticsearchIndexingFinishedEvent`, `ElasticsearchEntitySearcherSearchEvent`/`...SearchedEvent`, `ElasticsearchEntityAggregatorSearchEvent`/`...SearchedEvent`, `ElasticsearchCustomFieldsMappingEvent` (`setMapping($field, CustomFieldTypes::…)`).
6. Termquery ändern: `AbstractProductSearchQueryBuilder` (`Product/ProductSearchQueryBuilder`) dekorieren. Boosts: `elasticsearch.search.boost.{exact,phrase,fuzzy,prefix,partial}`, `dismax_tie_breaker`.

## 4. Beispiele

Gerüste: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md), [../templates/migration/README.md](../templates/migration/README.md), [../templates/decorator/README.md](../templates/decorator/README.md).

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Shopware\Elasticsearch\Product\ElasticsearchProductDefinition;
use SwagExample\Elasticsearch\SwagProductEsDefinition;
use SwagExample\Listing\CloseoutListingFilterHandler;
use SwagExample\Listing\ListingSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Tag wird zusätzlich vom AutoconfigureCompilerPass gesetzt; explizit ist robuster
    $services->set(CloseoutListingFilterHandler::class)
        ->tag('shopware.listing.filter.handler');

    $services->set(ListingSubscriber::class)
        ->tag('kernel.event_subscriber');

    // Decorator erbt das Tag shopware.es.definition vom dekorierten Service
    $services->set(SwagProductEsDefinition::class)
        ->decorate(ElasticsearchProductDefinition::class)
        ->args([service('.inner')]);
};
```

Filter `src/Listing/CloseoutListingFilterHandler.php` (Muster `ShippingFreeListingFilterHandler`)
```php
<?php declare(strict_types=1);

namespace SwagExample\Listing;

use Shopware\Core\Content\Product\SalesChannel\Listing\Filter;
use Shopware\Core\Content\Product\SalesChannel\Listing\Filter\AbstractListingFilterHandler;
use Shopware\Core\Framework\Adapter\Request\RequestParamHelper;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\FilterAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

final class CloseoutListingFilterHandler extends AbstractListingFilterHandler
{
    public const FILTER_NAME = 'closeout';

    public function getDecorated(): AbstractListingFilterHandler
    {
        throw new DecorationPatternException(self::class);
    }

    public function create(Request $request, SalesChannelContext $context): ?Filter
    {
        $filtered = (bool) RequestParamHelper::get($request, self::FILTER_NAME, false);

        return new Filter(
            self::FILTER_NAME,                 // Key in listing.aggregations und currentFilters
            $filtered,                         // true => Post-Filter wird ans Criteria gehängt
            [
                new FilterAggregation(
                    'closeout-filter',
                    new MaxAggregation(self::FILTER_NAME, 'product.isCloseout'),
                    [new EqualsFilter('product.isCloseout', true)]
                ),
            ],
            new EqualsFilter('product.isCloseout', true),
            $filtered                          // values: landen in result->getCurrentFilter()
        );
    }
}
```

Sortierung per Event und Criteria-Event: `src/Listing/ListingSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Listing;

use Shopware\Core\Content\Product\Events\ProductListingCollectSortingEvent;
use Shopware\Core\Content\Product\Events\ProductListingCriteriaEvent;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ListingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductListingCollectSortingEvent::class => 'addSorting',
            ProductListingCriteriaEvent::class => 'onCriteria',
        ];
    }

    public function addSorting(ProductListingCollectSortingEvent $event): void
    {
        $sorting = new ProductSortingEntity();
        $sorting->setId(Uuid::randomHex());        // Entity braucht eine Id für Collection::add()
        $sorting->setUniqueIdentifier($sorting->getId());
        $sorting->setKey('swag-rating-desc');      // URL-Parameter ?order=swag-rating-desc
        $sorting->setLabel('Beste Bewertung');
        $sorting->setPriority(5);
        $sorting->setActive(true);
        $sorting->setLocked(false);
        $sorting->setFields([
            ['field' => 'product.ratingAverage', 'order' => 'desc', 'priority' => 1, 'naturalSorting' => 0],
        ]);

        $event->getSortings()->add($sorting);
    }

    public function onCriteria(ProductListingCriteriaEvent $event): void
    {
        // zusätzliche Association (nur Kategorie-Listing, nicht Suche)
        $event->getCriteria()->addAssociation('manufacturer');
    }
}
```

`src/Resources/views/storefront/component/listing/filter-panel.html.twig`
```twig
{% sw_extends '@Storefront/storefront/component/listing/filter-panel.html.twig' %}

{% block component_filter_panel_item_shipping_free %}
    {{ parent() }}

    {% block swag_example_filter_closeout %}
        {% set closeout = listing.aggregations.get('closeout') %}
        {% if closeout.max > 0 %}
            {% sw_include '@Storefront/storefront/component/listing/filter/filter-boolean.html.twig' with {
                name: 'closeout',
                displayName: 'swagExample.listing.closeout'|trans
            } %}
        {% endif %}
    {% endblock %}
{% endblock %}
```
(Snippet-Schlüssel `swagExample.listing.closeout` in den Snippet-Dateien anlegen, siehe [T-18](./18-snippets-uebersetzungen.md).)

ES-Mapping/Daten erweitern: `src/Elasticsearch/SwagProductEsDefinition.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Elasticsearch;

use OpenSearchDSL\BuilderInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IterableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;

final class SwagProductEsDefinition extends AbstractElasticsearchDefinition
{
    public function __construct(
        private readonly AbstractElasticsearchDefinition $inner,
    ) {
    }

    public function getEntityDefinition(): EntityDefinition
    {
        return $this->inner->getEntityDefinition();
    }

    public function getMapping(Context $context): array
    {
        $mapping = $this->inner->getMapping($context);
        $mapping['properties']['swagBadge'] = self::KEYWORD_FIELD;

        return $mapping;
    }

    public function fetch(array $ids, Context $context): array
    {
        $documents = $this->inner->fetch($ids, $context);

        // Rückgabe ist nach Hex-Id indiziert; hier aus bereits geladenen Feldern abgeleitet
        foreach ($documents as &$document) {
            $document['swagBadge'] = ((int) ($document['availableStock'] ?? 0)) > 0 ? 'in-stock' : 'sold-out';
        }
        unset($document);

        return $documents;
    }

    public function buildTermQuery(Context $context, Criteria $criteria): BuilderInterface
    {
        return $this->inner->buildTermQuery($context, $criteria);
    }

    public function getIterator(): ?IterableQuery
    {
        return $this->inner->getIterator();
    }
}
```
`fetch()` bekommt Binary-IDs (Indexer ruft `fetch(Uuid::fromHexToBytesList($ids), …)`), das Ergebnis muss nach Hex-ID indiziert sein (`ElasticsearchIndexer::handleIndexingMessage`). Für Daten aus eigenen Tabellen eine eigene Abfrage liefern. Danach `bin/console es:mapping:update`.

Persistente Sortierung: `src/Migration/Migration1730000000AddMySorting.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000000AddMySorting extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        $id = Uuid::randomBytes();
        $connection->insert('product_sorting', [
            'id' => $id,
            'url_key' => 'swag-name-desc',
            'priority' => 3,
            'active' => 1,
            'locked' => 0,
            'fields' => json_encode([['field' => 'product.name', 'order' => 'desc', 'priority' => 1, 'naturalSorting' => 0]], \JSON_THROW_ON_ERROR),
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
        $connection->insert('product_sorting_translation', [
            'product_sorting_id' => $id,
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'label' => 'Name Z-A',
            'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
        ]);
    }
}
```
Spalten von `product_sorting` nach `Migration1600338271AddTopsellerSorting` (`id, url_key, priority, active, locked, fields, created_at`). Der Core befüllt die Translation-Tabelle über `importTranslation` (Trait `ImportTranslationsTrait`).

## 5. Fallstricke

1. Filter erscheint nicht: Include fehlt, `name` im Include ≠ `Filter::getName()` oder Aggregationsname ≠ `listing.aggregations.get('x')` (im Beispiel `MaxAggregation`-Name `closeout`).
2. Facetten kollabieren: Filter als normales `Criteria::addFilter` statt über `Filter` (Post-Filter) gesetzt.
3. Filter geht nur ohne ES: Feld nicht im ES-Mapping → Fehler oder 0 Treffer. Mapping erweitern + `es:mapping:update`/Reindex.
4. Sortierung erscheint nicht: Event-Weg ohne Id/`uniqueIdentifier`, `active=false`, doppelter `key`; DB-Weg: Migration nicht ausgeführt oder Sortierung per Verkaufskanal (`availableSortings`) ausgeblendet.
5. Keyword-Suche findet neue Daten nicht: `product_search_keyword` veraltet (Indexer/Queue nicht gelaufen) oder `shopware.product.search_keyword.indexing=false`.
6. Suchkonfiguration wirkt nicht: ES ist aktiv (Admin-Suchkonfig gilt dann nicht).
7. ES-Änderungen erst nach Reindex sichtbar (Analyzer/Index-Config: `es:reset` + `es:index`). Queue-Worker vergessen → Index bleibt leer.
8. `@internal`-Klassen (`ElasticsearchProductDefinition`, `CompositeListingProcessor`, Processor-Konstruktoren) nicht ableiten/instanziieren, nur dekorieren bzw. über Tags/Events erweitern.
9. Filter-Namen kollidieren mit Core: `manufacturer`, `properties`, `price`, `rating`, `shipping-free`.
10. Listing/Suche sind HTTP-/Route-gecached; Änderungen wirken erst nach Invalidierung (`cache:clear`), siehe [T-41](./41-caching.md).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Events heißen nach Klassen (`ProductEvents::PRODUCT_LISTING_CRITERIA = ProductListingCriteriaEvent::class`). String-Eventnamen wie `product.listing.criteria` gibt es nicht mehr.
- `RequestParamHelper::get($request, …)` liest in 6.7 (ohne Flag `v6.8.0.0`) noch zuerst aus Request-Attributes, ab 6.8 strikt Query/Body. Eigene Filter nutzen es statt `$request->get()` (die Doku zeigt noch `$request->get('isCloseout')`).
- `ProductSortingEntity::createDalSorting` hat `#[NewOptionalParameter(version: 'v6.8.0', parameterName: 'fallbackSorting')]`.
- Neu im Extension-System: `ProductListingCriteriaExtension` (`core/Content/Product/Extension`), ausgelöst in `ProductListingRoute::load` (siehe [T-08](./08-extension-points.md)).
- ES: `buildTextFieldConfig(withExact, technicalTerms, lengthNorm)`, `getTextFieldConfig` wird in 6.8 `@internal`. `product.states` im Mapping ist `@deprecated tag:v6.8.0`. Admin-Env `SHOPWARE_ADMIN_ES_NUMBER_OF_SHARDS/REPLICAS` (Default `3`/`3`) ist `@deprecated tag:v6.8.0`.
- OpenSearch: Client/DSL sind `OpenSearchDSL`, Variable `OPENSEARCH_URL`.
- Konfigurationsparameter `shopware.product.search_keyword.indexing` und `relevant_keyword_count` sind in 6.7 belegt.
- Doku-URL aus dem Plan existiert nicht mehr, funktionierend: `/docs/guides/plugins/plugins/storefront/add-listing-filters.html`.

## 6. Checkliste

- [ ] Filter von `AbstractListingFilterHandler` abgeleitet, `getDecorated()` wirft `DecorationPatternException`.
- [ ] Filter-Name, Aggregationsname und Template-`name` sind identisch.
- [ ] Request-Parameter über `RequestParamHelper::get` gelesen.
- [ ] Filter über `Filter`-Struct (Post-Filter), nicht per `Criteria::addFilter`.
- [ ] Filter-/Sortierfelder sind bei aktivem ES im Mapping vorhanden.
- [ ] Nach Mapping-Änderung `es:mapping:update` bzw. `es:reset` + `es:index`.
- [ ] Queue-Worker läuft, wenn mit Queue indexiert wird.
- [ ] `@internal`-Klassen nur dekoriert, Decorator ohne doppeltes `shopware.es.definition`-Tag.
- [ ] Cache geleert, Listing im Storefront geprüft.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-listing-filters.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-custom-sorting-product-listing.html
- https://developer.shopware.com/docs/guides/hosting/infrastructure/elasticsearch/elasticsearch-setup.html

Quellcode (relativ zu `vendor/shopware/`): `core/Content/Product/SalesChannel/Listing/{Filter,FilterCollection,ProductListingRoute,ResolveCriteriaProductListingRoute,ProductListingLoader}.php`, `.../Filter/{AbstractListingFilterHandler,ShippingFreeListingFilterHandler,PropertyListingFilterHandler}.php`, `.../Processor/{AbstractListingProcessor,CompositeListingProcessor,AggregationListingProcessor,SortingListingProcessor}.php`, `core/Content/Product/Events/ProductListing*Event.php`, `ProductEvents.php`, `core/Content/Product/SalesChannel/Sorting/ProductSortingEntity.php`, `core/Migration/V6_3/Migration1600338271AddTopsellerSorting.php`, `core/Content/DependencyInjection/product.php`, `core/Content/Product/SearchKeyword/*`, `core/Content/Product/DataAbstractionLayer/{SearchKeywordUpdater,ProductIndexer}.php`, `elasticsearch/Framework/{AbstractElasticsearchDefinition,ElasticsearchHelper,ElasticsearchIndexingUtils}.php`, `elasticsearch/Framework/Indexing/*`, `elasticsearch/Framework/Command/*`, `elasticsearch/Product/{ElasticsearchProductDefinition,ProductSearchBuilder,ProductUpdater,ProductSearchQueryBuilder}.php`, `elasticsearch/DependencyInjection/services.php`, `elasticsearch/Resources/config/packages/elasticsearch.yaml`, `storefront/Resources/views/storefront/component/listing/filter-panel.html.twig`.

Am Code bestätigt (Stichprobe): Signaturen von `AbstractListingFilterHandler` (`getDecorated`, `create`, `process`), `AbstractElasticsearchDefinition` (`getEntityDefinition`, `getMapping`, `getIterator`, `fetch`, `buildTermQuery`, `KEYWORD_FIELD`), `RequestParamHelper::get`, Setter von `ProductSortingEntity`, Filter-Panel-Blöcke, Tag-Übernahme beim Decorator (Symfony `DecoratorServicePass`).

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Keine Beispielklasse wurde ausgeführt (weder `php` noch `docker` verfügbar). Befehlsnamen stammen aus `#[AsCommand]`.
- ⚠ UNGEPRÜFT: `ElasticsearchProductDefinition` ist `@internal`. Ob Shopware die Dekoration offiziell unterstützt, ist nicht belegt, eine offizielle Doku-Seite zum ES-Erweitern wurde nicht gefunden (`extend-elasticsearch.html` liefert 404).
- ⚠ UNGEPRÜFT: Spalten von `product_sorting_translation` (`product_sorting_id`, `language_id`, `label`, `created_at`) nicht einzeln gegen das Schema geprüft.
- ⚠ UNGEPRÜFT: Notwendigkeit von `setId`/`setUniqueIdentifier` für `Collection::add()` aus Struct-/Entity-Verhalten abgeleitet.
- ⚠ UNGEPRÜFT: Admin-Menüpfad für Sortierungen/Suche (Einstellungen → Shop → Produktlisten / Suche) nicht am Admin-Code geprüft.
- ⚠ UNGEPRÜFT: Doku-Aussage „Event-Sortierung seit 6.7.15.0“: Event existiert in 6.7.15.1, kein `@since`-Beleg.
- ⚠ UNGEPRÜFT: Aussagen zu 6.5/6.6 (z. B. altes `SHOPWARE_ES_HOSTS`) nur aus Erinnerung/Deprecation-Hinweisen.
- ⚠ LÜCKE: Advanced-Search-Extension (ES-Suchkonfiguration) nur in der Doku erwähnt, nicht im lokalen Code. Weitere Unterseiten der ES-Doku nicht gelesen.
- ⚠ LÜCKE: Konflikte von Filter-Namen mit der JS-Plugin-Registry nicht geprüft. `listing` im Template stammt aus der Listing-Seite (`ProductListingResult`).
