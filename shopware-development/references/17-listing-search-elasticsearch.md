# Product listing, search and Elasticsearch/OpenSearch (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when you customize listing filters, sortings, the product search (DB keyword search) or Elasticsearch/OpenSearch (activation, mapping extension, operation).
Scope: Twig block mechanics see [T-14](./14-storefront-twig-templates.md), snippets see [T-18](./18-snippets-translations.md), JS filter plugins see [T-15](./15-storefront-js-plugins.md), overriding a Store API route see [T-30](./30-store-api-routes.md), events see [T-07](./07-events-subscriber.md), decoration see [T-06](./06-service-decoration.md), migrations see [T-04](./04-database-migrations.md), extension points see [T-08](./08-extension-points.md), caching see [T-41](./41-caching.md).

## 2. Concepts

- **One pipeline for listing, search and suggest:** The routes `ResolveCriteriaProductListingRoute`, `ResolvedCriteriaProductSearchRoute`, `ResolvedCriteriaProductSuggestRoute` call `CompositeListingProcessor::prepare()` (request → `Criteria`), dispatch `ProductListingCriteriaEvent` / `ProductSearchCriteriaEvent` / `ProductSuggestCriteriaEvent`, load products, call `CompositeListingProcessor::process()` and dispatch `Product*ResultEvent`. Event name = class name (`ProductEvents::PRODUCT_LISTING_CRITERIA = ProductListingCriteriaEvent::class`).
- **Custom filter** (recommended): extend `AbstractListingFilterHandler`, tag `shopware.listing.filter.handler`. Alternatively via `ProductListingCollectFilterEvent` (`getFilters()->add(new Filter(...))`, the approach shown in the docs). Both run through `AggregationListingProcessor`, which writes aggregations and (only if `isFiltered() === true`) the filter as a **post filter** into the `Criteria`, so that the facets of the other filters stay stable. `reduce-aggregations` additionally restricts aggregations (`Filter::exclude()`).
- **Custom sorting:** persistent via migration in `product_sorting` + `product_sorting_translation` (editable in the Admin) or at runtime via `ProductListingCollectSortingEvent` (docs: not recommended for production). Active sorting via request parameter `order`, default from `core.listing.defaultSorting` or `core.listing.defaultSearchResultSorting` respectively. Search gets `_score DESC` as a fallback (`SortingListingProcessor::prepare`).
- **Custom processor** (criteria for all three contexts): `AbstractListingProcessor`, tag `shopware.listing.processor` (higher priority = earlier). Context via `criteria->hasState('listing-route-context' | 'search-route-context' | 'suggest-route-context')`.
- **Search without ES:** `ProductSearchBuilder` (core) splits the term via `ProductSearchTermInterpreter` into `product_search_keyword` hits and builds `ScoreQuery`s on `product.searchKeywords.keyword`. Keywords are written by `SearchKeywordUpdater` during indexing based on `product_search_config_field` (Admin: Settings → Search). Can be disabled: `shopware.product.search_keyword.indexing: false`.
- **ES/OpenSearch only applies if** (`ElasticsearchHelper::allowSearch`): `searchEnabled` AND the entity has a registered `AbstractElasticsearchDefinition` (tag `shopware.es.definition`, in the core only `product`) AND the `Criteria` has the state `elasticsearchAware` (set by the listing loader, search/suggest/detail route, product slider streams). `throw_exception=1`: exception, `0`: fallback to DB.
- **Important:** With ES active, the Admin search configuration (`product_search_config`/keyword table) does NOT apply to the product search. `Elasticsearch\Product\ProductSearchBuilder` (decorator on `ProductSearchBuilderInterface`, priority -50000) only sets `Criteria::setTerm()`. Filters/sortings on fields outside the ES mapping require a mapping extension.
- **Decorating in the container:** A Symfony decorator takes over the tags of the decorated service (confirmed in `symfony/dependency-injection` `DecoratorServicePass`). Therefore do NOT additionally set the tag `shopware.es.definition`.

Directory tree:

```
custom/plugins/SwagExample/src/
├── Elasticsearch/SwagProductEsDefinition.php   # Decorator of ElasticsearchProductDefinition
├── Listing/CloseoutListingFilterHandler.php    # extends AbstractListingFilterHandler
├── Listing/ListingSubscriber.php               # Sorting via event
├── Migration/Migration1730000000AddMySorting.php
└── Resources/
    ├── config/services.php
    └── views/storefront/component/listing/filter-panel.html.twig
```

Core locations: `core/Content/Product/SalesChannel/Listing/{Filter,FilterCollection,ProductListingLoader}.php`, `.../Listing/Filter/*ListingFilterHandler.php`, `.../Listing/Processor/*ListingProcessor.php`, `.../Sorting/ProductSorting*.php`, `core/Content/Product/SearchKeyword/`, `elasticsearch/Framework/`, `elasticsearch/Product/`, `storefront/Resources/views/storefront/component/listing/{filter-panel.html.twig,filter/*.html.twig}`.

## 3. Instructions

**A. Listing filter**
1. Extend `AbstractListingFilterHandler`: `create()` returns `Filter(name, filtered, aggregations, dalFilter, values)` or `null` (filter disabled). Optionally override `process()` (example `PropertyListingFilterHandler`).
2. Register with the tag `shopware.listing.filter.handler` (alternatively `ProductListingCollectFilterEvent`).
3. Template: extend `filter-panel.html.twig` (blocks `component_filter_panel_items` or `component_filter_panel_item_{manufacturer,properties,price,rating_select,shipping_free}`), include `filter-boolean|multi-select|range|rating-select|property-select` via `sw_include`. Include `name` = request parameter = `Filter::getName()`.
4. Result in Twig: `listing.aggregations.get('<name>')` or `listing.currentFilters`. A pure Twig change needs no JS build, only `bin/console cache:clear`.

**B. Sorting**
- Persistent: migration (below), then `bin/console database:migrate --all SwagExample`. Order in the dropdown = `priority` DESC.
- Runtime: `ProductListingCollectSortingEvent` (below).
- `fields[]`: `field` (DAL path, e.g. `product.name`), `order`, `priority`, `naturalSorting`. With ES, the field must be in the index. Custom fields are only indexed if they are used in a sorting/product stream, have `custom_field.include_in_search = 1`, come from an app or are mapped via parameter/event (`ElasticsearchIndexingUtils::getCustomFieldTypes`).

**C. Influencing the criteria:** `ProductListingCriteriaEvent` (category), `ProductSearchCriteriaEvent` (search), `ProductSuggestCriteriaEvent` (live search); for all three together `AbstractListingProcessor`.

**D. Search without ES**
- Configuration in the Admin (`product_search_config`, `product_search_config_field`): fields, ranking, tokenize, minimum length, AND/OR, stop words/synonyms.
- Rebuild keywords: `bin/console dal:refresh:index --only=product.indexer`. `ProductIndexer::SEARCH_KEYWORD_UPDATER = 'product.search-keyword'` can be skipped (`--skip=product.search-keyword`).
- Custom analysis: decorate `ProductSearchKeywordAnalyzerInterface` (index time), `ProductSearchBuilderInterface`/`ProductSearchTermInterpreterInterface` (search time).
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
   Defaults (`elasticsearch/Resources/config/packages/elasticsearch.yaml`): `enabled`/`indexing_enabled` empty (= false), `index_prefix: sw`, `throw_exception: 1`, `indexing_batch_size: 100`. Others: `SHOPWARE_ES_NGRAM_MIN_GRAM/MAX_GRAM` (4/5), `SHOPWARE_ES_USE_LANGUAGE_ANALYZER` (1), `SHOPWARE_ES_EXCLUDE_SOURCE`, `SHOPWARE_ES_NUMBER_OF_SHARDS/REPLICAS`, `SHOPWARE_ES_DIMENSION_NORMALIZE` (reindex required). Admin search separately: `SHOPWARE_ADMIN_ES_ENABLED`, `ADMIN_OPENSEARCH_URL`, `SHOPWARE_ADMIN_ES_INDEX_PREFIX` (`sw-admin`).
2. Commands (from `#[AsCommand]`):
   - `bin/console es:index [--no-queue] [--no-progress] [--only=product]` (new index, alias via `CreateAliasTask`)
   - `bin/console es:create:alias`, `es:status`, `es:reset`, `es:index:cleanup [-f]`, `es:test:analyzer <term>`
   - `bin/console es:mapping:update` (new fields without full reindex)
   - Admin: `es:admin:index`, `es:admin:reset`, `es:admin:test`, `es:admin:mapping:update`
   - `bin/console dal:refresh:index --use-queue` (full reindex according to the docs)
3. Operation (docs): search enabled with indexing disabled is possible (read-only nodes). With queue indexing a worker must be running (`messenger:consume`, see [T-34](./34-message-queue.md)). Incremental: `Product\ProductUpdater` on `ProductIndexerEvent` and `ProductStockAlteredEvent`.
4. Extension: decorator (example) or a custom `AbstractElasticsearchDefinition` + tag `shopware.es.definition` for custom entities. Afterwards `es:mapping:update` (new fields) or `es:reset` + `es:index` (analyzer/type change).
5. Further hooks: `ElasticsearchIndexConfigEvent`, `ElasticsearchIndexerLanguageCriteriaEvent`, `ElasticsearchIndexIteratorEvent` (property `iterator` writable), `ElasticsearchIndexCreatedEvent`, `ElasticsearchIndexAliasSwitchedEvent`, `ElasticsearchIndexingFinishedEvent`, `ElasticsearchEntitySearcherSearchEvent`/`...SearchedEvent`, `ElasticsearchEntityAggregatorSearchEvent`/`...SearchedEvent`, `ElasticsearchCustomFieldsMappingEvent` (`setMapping($field, CustomFieldTypes::…)`).
6. Changing the term query: decorate `AbstractProductSearchQueryBuilder` (`Product/ProductSearchQueryBuilder`). Boosts: `elasticsearch.search.boost.{exact,phrase,fuzzy,prefix,partial}`, `dismax_tie_breaker`.

## 4. Examples

Scaffolds: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md), [../templates/migration/README.md](../templates/migration/README.md), [../templates/decorator/README.md](../templates/decorator/README.md).

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

    // Tag is also set by the AutoconfigureCompilerPass; explicit is more robust
    $services->set(CloseoutListingFilterHandler::class)
        ->tag('shopware.listing.filter.handler');

    $services->set(ListingSubscriber::class)
        ->tag('kernel.event_subscriber');

    // Decorator inherits the tag shopware.es.definition from the decorated service
    $services->set(SwagProductEsDefinition::class)
        ->decorate(ElasticsearchProductDefinition::class)
        ->args([service('.inner')]);
};
```

Filter `src/Listing/CloseoutListingFilterHandler.php` (pattern `ShippingFreeListingFilterHandler`)
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
            self::FILTER_NAME,                 // Key in listing.aggregations and currentFilters
            $filtered,                         // true => post filter is attached to the criteria
            [
                new FilterAggregation(
                    'closeout-filter',
                    new MaxAggregation(self::FILTER_NAME, 'product.isCloseout'),
                    [new EqualsFilter('product.isCloseout', true)]
                ),
            ],
            new EqualsFilter('product.isCloseout', true),
            $filtered                          // values: end up in result->getCurrentFilter()
        );
    }
}
```

Sorting via event and criteria event: `src/Listing/ListingSubscriber.php`
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
        $sorting->setId(Uuid::randomHex());        // Entity needs an id for Collection::add()
        $sorting->setUniqueIdentifier($sorting->getId());
        $sorting->setKey('swag-rating-desc');      // URL parameter ?order=swag-rating-desc
        $sorting->setLabel('Best rating');
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
        // additional association (category listing only, not search)
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
(Create the snippet key `swagExample.listing.closeout` in the snippet files, see [T-18](./18-snippets-translations.md).)

Extending the ES mapping/data: `src/Elasticsearch/SwagProductEsDefinition.php`
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

        // Return value is indexed by hex id; here derived from already loaded fields
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
`fetch()` receives binary IDs (the indexer calls `fetch(Uuid::fromHexToBytesList($ids), …)`), the result must be indexed by hex ID (`ElasticsearchIndexer::handleIndexingMessage`). For data from custom tables, provide your own query. Afterwards `bin/console es:mapping:update`.

Persistent sorting: `src/Migration/Migration1730000000AddMySorting.php`
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
Columns of `product_sorting` according to `Migration1600338271AddTopsellerSorting` (`id, url_key, priority, active, locked, fields, created_at`). The core fills the translation table via `importTranslation` (trait `ImportTranslationsTrait`).

## 5. Pitfalls

1. Filter does not appear: include missing, `name` in the include ≠ `Filter::getName()` or aggregation name ≠ `listing.aggregations.get('x')` (in the example the `MaxAggregation` name `closeout`).
2. Facets collapse: filter set as a normal `Criteria::addFilter` instead of via `Filter` (post filter).
3. Filter only works without ES: field not in the ES mapping → error or 0 hits. Extend the mapping + `es:mapping:update`/reindex.
4. Sorting does not appear: event approach without id/`uniqueIdentifier`, `active=false`, duplicate `key`; DB approach: migration not executed or sorting hidden per sales channel (`availableSortings`).
5. Keyword search does not find new data: `product_search_keyword` outdated (indexer/queue did not run) or `shopware.product.search_keyword.indexing=false`.
6. Search configuration has no effect: ES is active (the Admin search config does not apply then).
7. ES changes only visible after reindex (analyzer/index config: `es:reset` + `es:index`). Queue worker forgotten → index stays empty.
8. Do not extend/instantiate `@internal` classes (`ElasticsearchProductDefinition`, `CompositeListingProcessor`, processor constructors); only decorate them or extend via tags/events.
9. Filter names collide with the core: `manufacturer`, `properties`, `price`, `rating`, `shipping-free`.
10. Listing/search are HTTP/route cached; changes only take effect after invalidation (`cache:clear`), see [T-41](./41-caching.md).

### Deprecations and version notes (6.5/6.6 → 6.7)

- Events are named after classes (`ProductEvents::PRODUCT_LISTING_CRITERIA = ProductListingCriteriaEvent::class`). String event names like `product.listing.criteria` no longer exist.
- `RequestParamHelper::get($request, …)` in 6.7 (without flag `v6.8.0.0`) still reads from request attributes first, from 6.8 strictly query/body. Custom filters use it instead of `$request->get()` (the docs still show `$request->get('isCloseout')`).
- `ProductSortingEntity::createDalSorting` has `#[NewOptionalParameter(version: 'v6.8.0', parameterName: 'fallbackSorting')]`.
- New in the extension system: `ProductListingCriteriaExtension` (`core/Content/Product/Extension`), triggered in `ProductListingRoute::load` (see [T-08](./08-extension-points.md)).
- ES: `buildTextFieldConfig(withExact, technicalTerms, lengthNorm)`, `getTextFieldConfig` becomes `@internal` in 6.8. `product.states` in the mapping is `@deprecated tag:v6.8.0`. Admin env `SHOPWARE_ADMIN_ES_NUMBER_OF_SHARDS/REPLICAS` (default `3`/`3`) is `@deprecated tag:v6.8.0`.
- OpenSearch: client/DSL are `OpenSearchDSL`, variable `OPENSEARCH_URL`.
- The configuration parameters `shopware.product.search_keyword.indexing` and `relevant_keyword_count` are confirmed in 6.7.
- The docs URL from the plan no longer exists; working: `/docs/guides/plugins/plugins/storefront/add-listing-filters.html`.

## 6. Checklist

- [ ] Filter extends `AbstractListingFilterHandler`, `getDecorated()` throws `DecorationPatternException`.
- [ ] Filter name, aggregation name and template `name` are identical.
- [ ] Request parameters read via `RequestParamHelper::get`.
- [ ] Filter via the `Filter` struct (post filter), not via `Criteria::addFilter`.
- [ ] Filter/sorting fields are present in the mapping when ES is active.
- [ ] After a mapping change `es:mapping:update` or `es:reset` + `es:index`.
- [ ] Queue worker is running when indexing via the queue.
- [ ] `@internal` classes only decorated, decorator without duplicate `shopware.es.definition` tag.
- [ ] Cache cleared, listing checked in the storefront.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-listing-filters.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-custom-sorting-product-listing.html
- https://developer.shopware.com/docs/guides/hosting/infrastructure/elasticsearch/elasticsearch-setup.html

Source code (relative to `vendor/shopware/`): `core/Content/Product/SalesChannel/Listing/{Filter,FilterCollection,ProductListingRoute,ResolveCriteriaProductListingRoute,ProductListingLoader}.php`, `.../Filter/{AbstractListingFilterHandler,ShippingFreeListingFilterHandler,PropertyListingFilterHandler}.php`, `.../Processor/{AbstractListingProcessor,CompositeListingProcessor,AggregationListingProcessor,SortingListingProcessor}.php`, `core/Content/Product/Events/ProductListing*Event.php`, `ProductEvents.php`, `core/Content/Product/SalesChannel/Sorting/ProductSortingEntity.php`, `core/Migration/V6_3/Migration1600338271AddTopsellerSorting.php`, `core/Content/DependencyInjection/product.php`, `core/Content/Product/SearchKeyword/*`, `core/Content/Product/DataAbstractionLayer/{SearchKeywordUpdater,ProductIndexer}.php`, `elasticsearch/Framework/{AbstractElasticsearchDefinition,ElasticsearchHelper,ElasticsearchIndexingUtils}.php`, `elasticsearch/Framework/Indexing/*`, `elasticsearch/Framework/Command/*`, `elasticsearch/Product/{ElasticsearchProductDefinition,ProductSearchBuilder,ProductUpdater,ProductSearchQueryBuilder}.php`, `elasticsearch/DependencyInjection/services.php`, `elasticsearch/Resources/config/packages/elasticsearch.yaml`, `storefront/Resources/views/storefront/component/listing/filter-panel.html.twig`.

Confirmed against the code (sample): signatures of `AbstractListingFilterHandler` (`getDecorated`, `create`, `process`), `AbstractElasticsearchDefinition` (`getEntityDefinition`, `getMapping`, `getIterator`, `fetch`, `buildTermQuery`, `KEYWORD_FIELD`), `RequestParamHelper::get`, setters of `ProductSortingEntity`, filter panel blocks, tag takeover for decorators (Symfony `DecoratorServicePass`).

### Gaps and uncertainties

- ⚠ UNVERIFIED: No example class was executed (neither `php` nor `docker` available). Command names come from `#[AsCommand]`.
- ⚠ UNVERIFIED: `ElasticsearchProductDefinition` is `@internal`. Whether Shopware officially supports the decoration is not confirmed; no official docs page on extending ES was found (`extend-elasticsearch.html` returns 404).
- ⚠ UNVERIFIED: Columns of `product_sorting_translation` (`product_sorting_id`, `language_id`, `label`, `created_at`) not individually checked against the schema.
- ⚠ UNVERIFIED: Necessity of `setId`/`setUniqueIdentifier` for `Collection::add()` derived from struct/entity behavior.
- ⚠ UNVERIFIED: Admin menu path for sortings/search (Settings → Shop → Product listings / Search) not checked against the Admin code.
- ⚠ UNVERIFIED: Docs statement "event sorting since 6.7.15.0": the event exists in 6.7.15.1, no `@since` evidence.
- ⚠ UNVERIFIED: Statements about 6.5/6.6 (e.g. the old `SHOPWARE_ES_HOSTS`) only from memory/deprecation notes.
- ⚠ GAP: Advanced Search extension (ES search configuration) only mentioned in the docs, not in the local code. Further subpages of the ES docs not read.
- ⚠ GAP: Conflicts of filter names with the JS plugin registry not checked. `listing` in the template comes from the listing page (`ProductListingResult`).
