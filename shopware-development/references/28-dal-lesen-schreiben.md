# DAL: Lesen, Schreiben, Indexer (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn PHP-Code Entities über den `EntityRepository` lesen (Criteria, Filter, Aggregationen, Associations), schreiben/löschen (`create`, `update`, `upsert`, `delete`) oder einen eigenen Indexer (`EntityIndexer`) bereitstellen soll. Sie erklärt auch Context-Varianten und `SalesChannelRepository`.

Abgrenzung: Entities definieren → [T-27 DAL-Entities](./27-dal-entities.md). Extensions/Custom Fields → [T-29](./29-entity-extensions-custom-fields.md). DAL-Events (`EntityLoadedEvent`, `EntitySearchedEvent`, Written) → [T-07 Events](./07-events-subscriber.md). Service-Registrierung → [T-05](./05-services-registrieren.md). Migrationen → [T-04](./04-datenbank-migrationen.md). Queue → [T-34](./34-message-queue.md). Gerüst: [../templates/entity/README.md](../templates/entity/README.md).

## 2. Konzepte

- Zugriff über `Shopware\Core\Framework\DataAbstractionLayer\EntityRepository` (Service-ID `<entity_name>.repository`, z. B. `product.repository`). Die Klasse ist `@final`, nicht erben, sondern per Konstruktor injizieren. Erweitern über Events/Subscriber.
- Lesen: `search(Criteria, Context): EntitySearchResult`, `searchIds(...): IdSearchResult`, `aggregate(...): AggregationResultCollection`. Schreiben: `create` (INSERT), `update` (UPDATE), `upsert` (beides), `delete`. Dazu `clone`, `createVersion`, `merge`. Schreibmethoden nehmen ein Array von Arrays und liefern `EntityWrittenContainerEvent`.
- `Criteria` bündelt Filter, Sortierung, Aggregationen, Associations, Limit/Offset, Total-Count-Mode, Term, Post-Filter. Defaults: `limit = null`, `offset = null`, `totalCountMode = TOTAL_COUNT_MODE_NONE`. Ohne `EXACT` ist `getTotal()` nicht die exakte Gesamtzahl.
- Schreiben mit Associations FÜGT HINZU, ersetzt nicht. Ersetzen = erst Mapping-/Kind-Datensatz löschen, dann neu schreiben. ToOne: ID-Feld setzen oder `null`.
- Context-Varianten: `Context` (Controller/Event/Message), `SalesChannelContext` für `SalesChannelRepository` (Storefront/Store-API), `Context::createDefaultContext()` nur CLI/Tests (`@internal`, System-Scope).
- Indexer: von `EntityIndexer` erben (abstrakte Methoden `getName`, `iterate`, `update`, `handle`, `getTotal`, `getDecorated`). Autoconfigure tagt jede Unterklasse mit `shopware.entity_indexer` (`AutoconfigureCompilerPass`); manuelles Tag bleibt möglich.

Verzeichnisbaum (Plugin `SwagExample`):

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Resources/config/services.php
    ├── Service/ProductReader.php
    └── Indexing/ProductStockIndexer.php
```

## 3. Anleitung

### Lesen

1. Repository injizieren (`product.repository`), Collection per `@param EntityRepository<ProductCollection>` typisieren.
2. `new Criteria()` bzw. `new Criteria([$id])` (leere ID-Liste wirft `DataAbstractionLayerException::invalidCriteriaIds`), danach `->first()`.
3. Filter (`Search/Filter/`): `EqualsFilter`, `EqualsAnyFilter`, `ContainsFilter`, `PrefixFilter`, `SuffixFilter`, `RangeFilter` (`LT/LTE/GT/GTE`), `NotFilter`, `NotEqualsFilter`, `NotEqualsAnyFilter`, `MultiFilter(AND|OR|XOR)` (nur diese drei Operatoren, `MultiFilter::VALID_OPERATORS`) sowie `AndFilter/OrFilter/XOrFilter`. NAND/NOR gibt es als `NandFilter`/`NorFilter`, beides Subklassen von `NotFilter` (negierte AND-/OR-Verknüpfung), nicht von `MultiFilter`. Association-Pfade per Punkt: `categories.id`.
4. `addPostFilter()` wirkt nur auf die Ergebnisliste, nicht auf Aggregationen (Listing-Filter).
5. Sortierung: `FieldSorting(field, ASC|DESC, naturalSorting)`, `CountSorting`.
6. Aggregationen: Metrik `Avg`, `Count`, `Max`, `Min`, `Sum`, `Stats`, `Range`, `Entity`; Bucket `TermsAggregation`, `DateHistogramAggregation`, `FilterAggregation`. Ergebnis `$result->getAggregations()->get('name')`; ohne Entities `$repo->aggregate()`.
7. `addAssociation('a.b.c')` legt je Segment ein Sub-Criteria an, `getAssociation('x')` zum Filtern/Sortieren/Limitieren. Nicht geladene Associations sind `null`.
8. Pagination: `setLimit`, `setOffset`, `setTotalCountMode` (NONE=0, EXACT=1, NEXT_PAGES=2 mit Lookahead `limit*6+1`).
9. Große Mengen: `RepositoryIterator($repo, $context, $criteria)` + `fetch()` in Schleife (setzt `TOTAL_COUNT_MODE_NONE`); stabile Sortierung (zusätzlich `id`).
10. `searchIds()` wenn nur IDs gebraucht werden (`getIds()`, `firstId()`, `getTotal()`); bei Mapping-Entities statt `search`.
11. `setTitle('vendor::use-case')` markiert die Query im Profiler/Log.
12. `addFields([...])` (Allowlist) liefert `PartialEntity`-Instanzen. `excludeFields([...])` (Denylist) behält die typisierte Entity und lässt nur die genannten Felder aus; Required- und WriteProtected-Felder sowie unbekannte Felder lassen sich nicht ausschließen (Exception). `addFields` und `excludeFields` sind gegenseitig exklusiv (`criteriaFieldsAndExcludedFieldsAreMutuallyExclusive`); `resetFields()` verwirft die Allowlist (`Search/Criteria.php`, `Dbal/EntityReader.php`).

### Schreiben

1. `create`: INSERT, vorhandene ID → Fehler. `update`: nur vorhandene IDs. `upsert`: beides. `delete([['id' => ...]])`: bei zusammengesetzten Keys alle Key-Felder angeben.
2. IDs vorab mit `Uuid::randomHex()` erzeugen.
3. Nested-Writes: ManyToOne/OneToOne über `xxxId`; OneToMany/ManyToMany als Array unter dem Association-Namen. Nur `['id' => ...]` verknüpft vorhandene Datensätze, volle Daten legen neu an (`ManyToManyAssociationFieldSerializer`).
4. Ersetzen/Entfernen: siehe Beispiel `replaceCategory`/`removeManufacturer`. OneToMany ohne Extra-Daten: FK auf `null` (wenn nullable) oder Kindentität löschen; "Hidden ManyToMany" (z. B. `product_media`): Kindentität per `searchIds` suchen und per ID löschen. Pflicht-Associations lassen sich nicht löschen.
5. Rückgabe `EntityWrittenContainerEvent`: `getPrimaryKeys('product')`, `getErrors()`, `getEventByEntityName()`.

### Context

- `Context` (`core/Framework/Context.php`): Source, RuleIds, CurrencyId, LanguageIdChain, VersionId, CurrencyFactor, considerInheritance, TaxState, Rounding.
- `Context::createDefaultContext()` (`@internal`): `SystemSource`, Default-Sprache, Live-Version; `createCLIContext()` identisch. Im Request-Code den vorhandenen Context durchreichen (`$salesChannelContext->getContext()`, `$event->getContext()`).
- Scopes `Context::SYSTEM_SCOPE|USER_SCOPE|CRUD_API_SCOPE`: `$context->scope(Context::SYSTEM_SCOPE, fn (Context $c) => ...)` hebt Schreibschutz/ACL temporär auf.
- Vererbung: `$context->enableInheritance(fn (Context $c) => ...)` / `disableInheritance` (bei Varianten nötig, um Werte des Hauptprodukts zu lesen).
- `createWithVersionId()` für Versionierung.
- `SalesChannelRepository` (`core/System/SalesChannel/Entity/SalesChannelRepository.php`): `search/searchIds/aggregate` mit `SalesChannelContext`, ruft vorher `processCriteria` der `SalesChannelDefinitionInterface`. Nur lesend. Service-ID `sales_channel.<entity>.repository` (z. B. `sales_channel.product.repository`). Limit: max. 100 Criteria-Knoten (`CRITERIA_LIMIT`, `SalesChannelException::tooManyNestedCriteria`).

### Indexer

1. `getName()` eindeutig (`<vendor>.<name>.indexer`).
2. `iterate()` über `IteratorFactory::createIterator($definition, $offset)` → `fetch()` + `getOffset()`, `null` wenn keine IDs mehr.
3. `update()` reagiert auf `EntityWrittenContainerEvent` (`getPrimaryKeys`, `getPrimaryKeysWithPropertyChange($entity, ['stock'])`), gibt `EntityIndexingMessage` oder `null` zurück. Referenzmuster: `core/Content/Rule/DataAbstractionLayer/RuleIndexer.php`.
4. `handle()` besser mit `Connection` (SQL) statt DAL, sonst Endlosschleifen. Ist DAL nötig: `$context->addState(EntityIndexerRegistry::DISABLE_INDEXING)`.
5. Asynchron: `new EntityIndexingMessage($ids, null, $context, forceQueue: true)` bzw. `Context::addState(EntityIndexerRegistry::USE_INDEXING_QUEUE)`. Worker: `bin/console messenger:consume`.
6. `getOptions()` (`list<string>`) erlaubt Teil-Updater via Skip/Only; im Message `$message->allow('name')`.

### CLI

```bash
bin/console dal:refresh:index                                   # alle Indexer
bin/console dal:refresh:index --only=swag_example.product_stock.indexer
bin/console dal:refresh:index --skip=product.indexer --use-queue
bin/console messenger:consume async -vv                         # Queue verarbeiten
```

`--only`/`--skip` nehmen Indexer-Namen (`RefreshIndexCommand`).

## 4. Beispiele

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
            'taxId' => '...',                     // existierende Tax-ID einsetzen
            'price' => [['currencyId' => '...', 'gross' => 10, 'net' => 8.4, 'linked' => true]],
        ]], $context);

        return $id;
    }

    /** Ersetzen einer ManyToMany-Zuordnung: alt löschen, neu hinzufügen */
    public function replaceCategory(string $productId, string $oldId, string $newId, Context $context): void
    {
        $this->productCategoryRepository->delete([['productId' => $productId, 'categoryId' => $oldId]], $context);
        $this->productRepository->update([['id' => $productId, 'categories' => [['id' => $newId]]]], $context);
    }

    /** ToOne-Zuordnung entfernen */
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

        // Platzhalter: eigene Berechnung/Zieltabelle aus dem eigenen Migration-Schema (T-04).
        // Direktes SQL statt DAL, damit keine Event-Schleife entsteht.
        $this->connection->executeStatement(
            'UPDATE `product` SET `sales` = `sales` WHERE `id` IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ids)],
            ['ids' => ArrayParameterType::BINARY]
        );
    }
}
```

⚠ UNGEPRÜFT: Beispiele nicht ausgeführt; Platzhalter (`taxId`, `currencyId`, SQL im Indexer) anpassen. Die Signaturen der abstrakten Methoden von `EntityIndexer` und `IteratorFactory`-Konstruktor wurden am Quellcode bestätigt.

## 5. Fallstricke

- "update ersetzt die Association": falsch, es wird hinzugefügt.
- `getTotal()` ohne `TOTAL_COUNT_MODE_EXACT` für Pagination → falsche Seitenzahl.
- `Criteria` ohne `setLimit` auf großen Tabellen → Speicher/Laufzeit; `RepositoryIterator` nutzen.
- Association nicht mit `addAssociation` geladen → `null`-Zugriff.
- `search()` auf Mapping-Entity (`product_category`) → `searchIds()`/`delete` mit Key-Array.
- `create()` mit vorhandener ID → Write-Exception; `upsert` verwenden.
- Context ohne Vererbung: Varianten liefern leere Felder (`considerInheritance`).
- Im Indexer-`handle()` per DAL schreiben → Re-Trigger-Schleife.
- `EntityRepository` erben/überschreiben (`@final`): stattdessen Events (`EntitySearchedEvent`, `EntityLoadedEvent`, `BeforeEntityAggregationEvent`, [T-07](./07-events-subscriber.md)).
- `Context::createDefaultContext()` im Request-Code: umgeht Sprach-/Kontextlogik und ACL; nur CLI/Tests.
- `SalesChannelRepository`: Entities ohne `SalesChannelDefinitionInterface` erhalten keine Zusatzfilter.
- Indexer-Autoconfigure hängt von der Plugin-Servicekonfiguration ab: im Zweifel Tag explizit setzen.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `EntityWrittenContainerEvent::getPrimaryKeysWithPayload()` ist `@deprecated tag:v6.8.0`; stattdessen `getPrimaryKeysWithPayloadIgnoringFields` bzw. `getPrimaryKeysWithPropertyChange`.
- `EntityRepository` nutzt in 6.7.15.1 `LazyGhostTrait` und optional `DalSearchInstrumentor`; Konstruktor `@internal`.
- `Context::createDefaultContext()` ist `@internal`.
- `IteratorFactory::createIterator(string|EntityDefinition, ?array $lastId = null, int $limit = 50, ?string $versionId = null)`.
- `TOTAL_COUNT_MODE_NEXT_PAGES` (2) existiert in 6.7.15.1.
- Autoconfigure für `EntityIndexer` im Code vorhanden; Doku verlangt weiterhin das explizite Tag (zu streng, Tag schadet nicht).
- Services in 6.7 bevorzugt als `services.php` (XML deprecated, siehe [T-05](./05-services-registrieren.md)).
- ⚠ UNGEPRÜFT: Vergleich zu 6.5/6.6 nicht am Quellcode dieser Versionen verifiziert.

## 6. Checkliste

- [ ] Repository per Konstruktor injiziert, Service-ID `<entity>.repository` korrekt
- [ ] Benötigte Associations per `addAssociation` geladen
- [ ] Pagination mit `setLimit`/`setOffset` und `TOTAL_COUNT_MODE_EXACT` falls Gesamtzahl nötig
- [ ] Große Mengen über `RepositoryIterator`
- [ ] `create` vs. `update` vs. `upsert` bewusst gewählt
- [ ] Ersetzen von Associations als Löschen plus Hinzufügen umgesetzt
- [ ] Context aus Request/Event durchgereicht, kein `createDefaultContext()` im Request-Code
- [ ] Indexer: `getName()` eindeutig, `handle()` mit SQL, Tag gesetzt
- [ ] `bin/console dal:refresh:index --only=...` getestet

## 7. Quellen

Doku (https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/): `reading-data.html`, `writing-data.html`, `replacing-associated-data.html`, `deleting-associated-data.html`, `add-data-indexer.html`.

Quellcode (`core/`): `Framework/DataAbstractionLayer/EntityRepository.php`, `Search/Criteria.php`, `Search/Filter/*`, `Search/Sorting/*`, `Search/Aggregation/*`, `Dbal/Common/{IteratorFactory,RepositoryIterator}.php`, `Indexing/{EntityIndexer,EntityIndexerRegistry,EntityIndexingMessage}.php`, `Command/RefreshIndexCommand.php`, `Event/EntityWrittenContainerEvent.php`, `FieldSerializer/ManyToManyAssociationFieldSerializer.php`, `Framework/Context.php`, `System/SalesChannel/Entity/{SalesChannelRepository,SalesChannelDefinitionInterface}.php`, `Content/Rule/DataAbstractionLayer/RuleIndexer.php`, `Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele nicht ausgeführt/getestet.
- ⚠ LÜCKE: Fehlerverhalten von `create()`/`update()` bei vorhandener/fehlender ID nicht im Writer geprüft (nur aus `VersionManager::insert/update/upsert`-Aufrufen abgeleitet).
- ⚠ UNGEPRÜFT: Partial-Loading (`addFields`) und `Context::scope`-Semantik nur aus Signaturen abgeleitet.
- ⚠ LÜCKE: `sales_channel.<entity>.repository` stützt sich auf Verwendung in `seo.php`, Doku erwähnt es nicht.
- ⚠ UNGEPRÜFT: Vergleich 6.5/6.6 nicht am dortigen Quellcode belegt.
