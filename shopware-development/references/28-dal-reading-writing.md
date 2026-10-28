# DAL: reading, writing, indexers (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when PHP code is to read entities via the `EntityRepository` (Criteria, filters, aggregations, associations), write/delete them (`create`, `update`, `upsert`, `delete`) or provide a custom indexer (`EntityIndexer`). It also explains context variants and `SalesChannelRepository`.

Scope: defining entities → [T-27 DAL entities](./27-dal-entities.md). Extensions/custom fields → [T-29](./29-entity-extensions-custom-fields.md). DAL events (`EntityLoadedEvent`, `EntitySearchedEvent`, Written) → [T-07 Events](./07-events-subscriber.md). Service registration → [T-05](./05-registering-services.md). Migrations → [T-04](./04-database-migrations.md). Queue → [T-34](./34-message-queue.md). Scaffold: [../templates/entity/README.md](../templates/entity/README.md).

## 2. Concepts

- Access via `Shopware\Core\Framework\DataAbstractionLayer\EntityRepository` (service ID `<entity_name>.repository`, e.g. `product.repository`). The class is `@final`; do not extend it, inject it via the constructor. Extend via events/subscribers.
- Reading: `search(Criteria, Context): EntitySearchResult`, `searchIds(...): IdSearchResult`, `aggregate(...): AggregationResultCollection`. Writing: `create` (INSERT), `update` (UPDATE), `upsert` (both), `delete`. Also `clone`, `createVersion`, `merge`. Write methods take an array of arrays and return `EntityWrittenContainerEvent`.
- `Criteria` bundles filters, sorting, aggregations, associations, limit/offset, total count mode, term, post filters. Defaults: `limit = null`, `offset = null`, `totalCountMode = TOTAL_COUNT_MODE_NONE`. Without `EXACT`, `getTotal()` is not the exact total.
- Writing with associations ADDS, it does not replace. Replacing = first delete the mapping/child record, then write anew. ToOne: set the ID field or `null`.
- Context variants: `Context` (controller/event/message), `SalesChannelContext` for `SalesChannelRepository` (Storefront/Store API), `Context::createDefaultContext()` only for CLI/tests (`@internal`, system scope).
- Indexer: extend `EntityIndexer` (abstract methods `getName`, `iterate`, `update`, `handle`, `getTotal`, `getDecorated`). Autoconfigure tags every subclass with `shopware.entity_indexer` (`AutoconfigureCompilerPass`); a manual tag remains possible.

Directory tree (plugin `SwagExample`):

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Resources/config/services.php
    ├── Service/ProductReader.php
    └── Indexing/ProductStockIndexer.php
```

## 3. Instructions

### Reading

1. Inject the repository (`product.repository`), type the collection via `@param EntityRepository<ProductCollection>`.
2. `new Criteria()` or `new Criteria([$id])` (an empty ID list throws `DataAbstractionLayerException::invalidCriteriaIds`), then `->first()`.
3. Filters (`Search/Filter/`): `EqualsFilter`, `EqualsAnyFilter`, `ContainsFilter`, `PrefixFilter`, `SuffixFilter`, `RangeFilter` (`LT/LTE/GT/GTE`), `NotFilter`, `NotEqualsFilter`, `NotEqualsAnyFilter`, `MultiFilter(AND|OR|XOR)` (only these three operators, `MultiFilter::VALID_OPERATORS`) as well as `AndFilter/OrFilter/XOrFilter`. NAND/NOR exist as `NandFilter`/`NorFilter`, both subclasses of `NotFilter` (negated AND/OR combination), not of `MultiFilter`. Association paths with dots: `categories.id`.
4. `addPostFilter()` only affects the result list, not aggregations (listing filters).
5. Sorting: `FieldSorting(field, ASC|DESC, naturalSorting)`, `CountSorting`.
6. Aggregations: metric `Avg`, `Count`, `Max`, `Min`, `Sum`, `Stats`, `Range`, `Entity`; bucket `TermsAggregation`, `DateHistogramAggregation`, `FilterAggregation`. Result `$result->getAggregations()->get('name')`; without entities `$repo->aggregate()`.
7. `addAssociation('a.b.c')` creates a sub-Criteria per segment, `getAssociation('x')` for filtering/sorting/limiting. Associations that are not loaded are `null`.
8. Pagination: `setLimit`, `setOffset`, `setTotalCountMode` (NONE=0, EXACT=1, NEXT_PAGES=2 with lookahead `limit*6+1`).
9. Large volumes: `RepositoryIterator($repo, $context, $criteria)` + `fetch()` in a loop (sets `TOTAL_COUNT_MODE_NONE`); stable sorting (additionally by `id`).
10. `searchIds()` when only IDs are needed (`getIds()`, `firstId()`, `getTotal()`); for mapping entities instead of `search`.
11. `setTitle('vendor::use-case')` marks the query in the profiler/log.
12. `addFields([...])` (allowlist) returns `PartialEntity` instances. `excludeFields([...])` (denylist) keeps the typed entity and only omits the named fields; required and WriteProtected fields as well as unknown fields cannot be excluded (exception). `addFields` and `excludeFields` are mutually exclusive (`criteriaFieldsAndExcludedFieldsAreMutuallyExclusive`); `resetFields()` discards the allowlist (`Search/Criteria.php`, `Dbal/EntityReader.php`).

### Writing

1. `create`: INSERT, existing ID → error. `update`: only existing IDs. `upsert`: both. `delete([['id' => ...]])`: for composite keys specify all key fields.
2. Generate IDs beforehand with `Uuid::randomHex()`.
3. Nested writes: ManyToOne/OneToOne via `xxxId`; OneToMany/ManyToMany as an array under the association name. Only `['id' => ...]` links existing records, full data creates new ones (`ManyToManyAssociationFieldSerializer`).
4. Replacing/removing: see the example `replaceCategory`/`removeManufacturer`. OneToMany without extra data: set the FK to `null` (if nullable) or delete the child entity; "hidden ManyToMany" (e.g. `product_media`): find the child entity via `searchIds` and delete it by ID. Required associations cannot be deleted.
5. Return value `EntityWrittenContainerEvent`: `getPrimaryKeys('product')`, `getErrors()`, `getEventByEntityName()`.

### Context

- `Context` (`core/Framework/Context.php`): Source, RuleIds, CurrencyId, LanguageIdChain, VersionId, CurrencyFactor, considerInheritance, TaxState, Rounding.
- `Context::createDefaultContext()` (`@internal`): `SystemSource`, default language, live version; `createCLIContext()` identical. In request code, pass on the existing context (`$salesChannelContext->getContext()`, `$event->getContext()`).
- Scopes `Context::SYSTEM_SCOPE|USER_SCOPE|CRUD_API_SCOPE`: `$context->scope(Context::SYSTEM_SCOPE, fn (Context $c) => ...)` temporarily lifts write protection/ACL.
- Inheritance: `$context->enableInheritance(fn (Context $c) => ...)` / `disableInheritance` (needed for variants to read values of the parent product).
- `createWithVersionId()` for versioning.
- `SalesChannelRepository` (`core/System/SalesChannel/Entity/SalesChannelRepository.php`): `search/searchIds/aggregate` with `SalesChannelContext`, calls `processCriteria` of the `SalesChannelDefinitionInterface` beforehand. Read-only. Service ID `sales_channel.<entity>.repository` (e.g. `sales_channel.product.repository`). Limit: max. 100 Criteria nodes (`CRITERIA_LIMIT`, `SalesChannelException::tooManyNestedCriteria`).

### Indexer

1. `getName()` unique (`<vendor>.<name>.indexer`).
2. `iterate()` via `IteratorFactory::createIterator($definition, $offset)` → `fetch()` + `getOffset()`, `null` when there are no more IDs.
3. `update()` reacts to `EntityWrittenContainerEvent` (`getPrimaryKeys`, `getPrimaryKeysWithPropertyChange($entity, ['stock'])`), returns an `EntityIndexingMessage` or `null`. Reference pattern: `core/Content/Rule/DataAbstractionLayer/RuleIndexer.php`.
4. `handle()` preferably with `Connection` (SQL) instead of the DAL, otherwise infinite loops. If the DAL is needed: `$context->addState(EntityIndexerRegistry::DISABLE_INDEXING)`.
5. Asynchronous: `new EntityIndexingMessage($ids, null, $context, forceQueue: true)` or `Context::addState(EntityIndexerRegistry::USE_INDEXING_QUEUE)`. Worker: `bin/console messenger:consume`.
6. `getOptions()` (`list<string>`) allows partial updaters via skip/only; in the message `$message->allow('name')`.

### CLI

```bash
bin/console dal:refresh:index                                   # all indexers
bin/console dal:refresh:index --only=swag_example.product_stock.indexer
bin/console dal:refresh:index --skip=product.indexer --use-queue
bin/console messenger:consume async -vv                         # process queue
```

`--only`/`--skip` take indexer names (`RefreshIndexCommand`).

## 4. Examples

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use SwagExample\Indexing\ProductStockIndexer;
use SwagExample\Service\ProductReader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(ProductReader::class)
        ->args([service('product.repository'), service('product_category.repository')]);

    $services->set(ProductStockIndexer::class)
        ->args([service(IteratorFactory::class), service('Doctrine\DBAL\Connection'), service('product.repository')])
        ->tag('shopware.entity_indexer');
};
```

`src/Service/ProductReader.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\AvgAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;

class ProductReader
{
    /**
     * @param EntityRepository<ProductCollection> $productRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $productCategoryRepository,
    ) {
    }

    public function findActiveInStock(Context $context, int $page = 1, int $limit = 25): ProductCollection
    {
        $criteria = new Criteria();
        $criteria->setTitle('swag-example::active-in-stock');
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('active', true),
            new RangeFilter('stock', [RangeFilter::GT => 0]),
        ]));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('categories');
        $criteria->setLimit($limit);
        $criteria->setOffset(($page - 1) * $limit);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);
        $criteria->addAggregation(new AvgAggregation('avg-stock', 'stock'));

        $result = $this->productRepository->search($criteria, $context);
        // $result->getTotal(), $result->getAggregations()->get('avg-stock')

        return $result->getEntities();
    }

    public function create(Context $context): string
    {
        $id = Uuid::randomHex();
        $this->productRepository->create([[
            'id' => $id,
            'productNumber' => 'SWAG-' . $id,
            'name' => 'Demo',
            'stock' => 10,
            'taxId' => '...',                     // insert an existing tax ID
            'price' => [['currencyId' => '...', 'gross' => 10, 'net' => 8.4, 'linked' => true]],
        ]], $context);

        return $id;
    }

    /** Replace a ManyToMany assignment: delete the old one, add the new one */
    public function replaceCategory(string $productId, string $oldId, string $newId, Context $context): void
    {
        $this->productCategoryRepository->delete([['productId' => $productId, 'categoryId' => $oldId]], $context);
        $this->productRepository->update([['id' => $productId, 'categories' => [['id' => $newId]]]], $context);
    }

    /** Remove a ToOne assignment */
    public function removeManufacturer(string $productId, Context $context): void
    {
        $this->productRepository->update([['id' => $productId, 'manufacturerId' => null]], $context);
    }
}
```

`src/Indexing/ProductStockIndexer.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Indexing;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\IteratorFactory;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexer;
use Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;

class ProductStockIndexer extends EntityIndexer
{
    public function __construct(
        private readonly IteratorFactory $iteratorFactory,
        private readonly Connection $connection,
        private readonly EntityRepository $productRepository,
    ) {
    }

    public function getName(): string
    {
        return 'swag_example.product_stock.indexer';
    }

    public function getTotal(): int
    {
        return $this->iteratorFactory->createIterator($this->productRepository->getDefinition())->fetchCount();
    }

    public function getDecorated(): EntityIndexer
    {
        throw new DecorationPatternException(static::class);
    }

    public function iterate(?array $offset): ?EntityIndexingMessage
    {
        $iterator = $this->iteratorFactory->createIterator($this->productRepository->getDefinition(), $offset);
        $ids = $iterator->fetch();

        if ($ids === []) {
            return null;
        }

        return new EntityIndexingMessage(array_values($ids), $iterator->getOffset());
    }

    public function update(EntityWrittenContainerEvent $event): ?EntityIndexingMessage
    {
        $ids = $event->getPrimaryKeys(ProductDefinition::ENTITY_NAME);

        if ($ids === []) {
            return null;
        }

        return new EntityIndexingMessage(array_values($ids), null, $event->getContext());
    }

    public function handle(EntityIndexingMessage $message): void
    {
        $ids = $message->getData();
        if (!\is_array($ids) || $ids === []) {
            return;
        }

        // Placeholder: your own calculation/target table from your own migration schema (T-04).
        // Direct SQL instead of the DAL so that no event loop occurs.
        $this->connection->executeStatement(
            'UPDATE `product` SET `sales` = `sales` WHERE `id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }
}
```

⚠ UNVERIFIED: examples not run; adapt the placeholders (`taxId`, `currencyId`, SQL in the indexer). The signatures of the abstract methods of `EntityIndexer` and the `IteratorFactory` constructor were verified against the source code.

## 5. Pitfalls

- "update replaces the association": wrong, it is added.
- `getTotal()` without `TOTAL_COUNT_MODE_EXACT` for pagination → wrong page count.
- `Criteria` without `setLimit` on large tables → memory/runtime; use `RepositoryIterator`.
- Association not loaded with `addAssociation` → `null` access.
- `search()` on a mapping entity (`product_category`) → `searchIds()`/`delete` with a key array.
- `create()` with an existing ID → write exception; use `upsert`.
- Context without inheritance: variants return empty fields (`considerInheritance`).
- Writing via the DAL in the indexer's `handle()` → re-trigger loop.
- Extending/overriding `EntityRepository` (`@final`): use events instead (`EntitySearchedEvent`, `EntityLoadedEvent`, `BeforeEntityAggregationEvent`, [T-07](./07-events-subscriber.md)).
- `Context::createDefaultContext()` in request code: bypasses language/context logic and ACL; CLI/tests only.
- `SalesChannelRepository`: entities without `SalesChannelDefinitionInterface` get no additional filters.
- Indexer autoconfigure depends on the plugin's service configuration: when in doubt, set the tag explicitly.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `EntityWrittenContainerEvent::getPrimaryKeysWithPayload()` is `@deprecated tag:v6.8.0`; use `getPrimaryKeysWithPayloadIgnoringFields` or `getPrimaryKeysWithPropertyChange` instead.
- In 6.7.15.1, `EntityRepository` uses `LazyGhostTrait` and optionally `DalSearchInstrumentor`; constructor `@internal`.
- `Context::createDefaultContext()` is `@internal`.
- `IteratorFactory::createIterator(string|EntityDefinition, ?array $lastId = null, int $limit = 50, ?string $versionId = null)`.
- `TOTAL_COUNT_MODE_NEXT_PAGES` (2) exists in 6.7.15.1.
- Autoconfigure for `EntityIndexer` exists in the code; the docs still require the explicit tag (too strict, the tag does no harm).
- Services in 6.7 preferably as `services.php` (XML deprecated, see [T-05](./05-registering-services.md)).
- ⚠ UNVERIFIED: comparison with 6.5/6.6 not verified against the source code of those versions.

## 6. Checklist

- [ ] Repository injected via constructor, service ID `<entity>.repository` correct
- [ ] Required associations loaded via `addAssociation`
- [ ] Pagination with `setLimit`/`setOffset` and `TOTAL_COUNT_MODE_EXACT` if the total is needed
- [ ] Large volumes via `RepositoryIterator`
- [ ] `create` vs. `update` vs. `upsert` chosen deliberately
- [ ] Replacing associations implemented as delete plus add
- [ ] Context passed on from request/event, no `createDefaultContext()` in request code
- [ ] Indexer: `getName()` unique, `handle()` with SQL, tag set
- [ ] `bin/console dal:refresh:index --only=...` tested

## 7. Sources

Docs (https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/): `reading-data.html`, `writing-data.html`, `replacing-associated-data.html`, `deleting-associated-data.html`, `add-data-indexer.html`.

Source code (`core/`): `Framework/DataAbstractionLayer/EntityRepository.php`, `Search/Criteria.php`, `Search/Filter/*`, `Search/Sorting/*`, `Search/Aggregation/*`, `Dbal/Common/{IteratorFactory,RepositoryIterator}.php`, `Indexing/{EntityIndexer,EntityIndexerRegistry,EntityIndexingMessage}.php`, `Command/RefreshIndexCommand.php`, `Event/EntityWrittenContainerEvent.php`, `FieldSerializer/ManyToManyAssociationFieldSerializer.php`, `Framework/Context.php`, `System/SalesChannel/Entity/{SalesChannelRepository,SalesChannelDefinitionInterface}.php`, `Content/Rule/DataAbstractionLayer/RuleIndexer.php`, `Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: examples not run/tested.
- ⚠ GAP: error behavior of `create()`/`update()` with an existing/missing ID not checked in the writer (only derived from `VersionManager::insert/update/upsert` calls).
- ⚠ UNVERIFIED: partial loading (`addFields`) and `Context::scope` semantics only derived from signatures.
- ⚠ GAP: `sales_channel.<entity>.repository` relies on its use in `seo.php`; the docs do not mention it.
- ⚠ UNVERIFIED: comparison with 6.5/6.6 not backed by the source code of those versions.
