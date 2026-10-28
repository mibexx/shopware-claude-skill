# Entity-Extensions und Custom Fields (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eine bestehende Core-Entity (z. B. `product`) um Felder, Assoziationen oder eigene Tabellen erweitert werden soll (`EntityExtension`, `BulkEntityExtension`, Runtime-Felder) oder wenn händlerkonfigurierbare Zusatzfelder (Custom Fields, Custom-Field-Sets) per Plugin bereitgestellt werden sollen.

Abgrenzung: Eigene, neue Entities → [T-27](./27-dal-entities.md). Lesen/Schreiben/Indexer → [T-28](./28-dal-lesen-schreiben.md). Subscriber/Events → [T-07](./07-events-subscriber.md). Migrationen → [T-04](./04-datenbank-migrationen.md). Storefront-Twig → [T-14](./14-storefront-twig-templates.md). Admin-Komponenten → [T-25](./25-admin-komponenten.md). Gerüste: [../templates/entity/README.md](../templates/entity/README.md), [../templates/migration/README.md](../templates/migration/README.md).

## 2. Konzepte

- **EntityExtension** (`core/Framework/DataAbstractionLayer/EntityExtension.php`): abstrakt `getEntityName(): string`; optional `extendFields(FieldCollection)`, `modifyFields(FieldCollection)` (nur Flags/JsonField-Mappings ändern, nichts hinzufügen/entfernen), `extendProtections(EntityProtectionCollection)`. Tag `shopware.entity.extension` (Autoconfigure setzt ihn).
- **Extension-Daten liegen in `Entity::$extensions`**, nicht in der Struct (Flag `Extension`, `EntityHydrator` Z. 224 ruft `addExtension()`). Zugriff `$product->getExtension('name')`, Twig `product.extensions.name`.
- **Runtime-Felder** (Flag `Runtime`): nicht aus der DB gelesen, per Subscriber gefüllt (z. B. `product.loaded`).
- **Persistierte Felder**: nur über Assoziationen auf eine eigene Tabelle (OneToOne/OneToMany/ManyToMany/ManyToOne; FK-Spalte in der eigenen Tabelle bzw. `FkField` mit passender ManyToOne-Assoziation, plus `ReferenceVersionField`). Reine Skalarfelder (`StringField`, `IntField` usw.) ohne `Runtime`-Flag lehnt `EntityDefinition::getFields()` mit `wrongFieldTypeForExtension` ab, eine eigene Spalte in der Core-Tabelle ist per Extension also nicht möglich (dafür Custom Fields). Migration nötig (DAL legt keine Tabellen an).
- **BulkEntityExtension** (`BulkEntityExtension.php`): `collect(): \Generator` liefert `entityName => list<Field>`. Konstruktor `final`, parameterlos, keine Dependency Injection (Compiler-Pass instanziiert ohne Konstruktor und ruft `collect()` zur Compile-Zeit). Tag `shopware.bulk.entity.extension`. Doku: seit 6.6.10.
- **Custom Fields**: vom Admin konfigurierbare Felder im JSON-Feld `customFields`; für skalare Werte/IDs. Deklarativ per `src/Resources/config/custom-fields.xml` (seit 6.7.13.0; `PluginLifecycleService::syncPluginCustomFields`) oder imperativ über `custom_field_set.repository`.
- **Entscheidungshilfe**: Händler-konfigurierbar, skalar, kein Join → Custom Field. Assoziation, Struct-Typ, Index/FK, Runtime-Berechnung oder eigene Tabelle → EntityExtension. Nie Core-Definitionen dekorieren/überschreiben, um Felder hinzuzufügen.

Verzeichnisbaum (Plugin `SwagExample`):

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Extension/Content/Product/ProductExampleExtension.php
    ├── Extension/ExampleBulkExtension.php
    ├── Core/Content/Example/{ExampleExtensionDefinition,ExampleExtensionEntity,ExampleExtensionCollection}.php
    ├── Subscriber/ProductSubscriber.php
    ├── Migration/Migration1700000000AddExampleTable.php
    └── Resources/config/{services.php, custom-fields.xml}
```

## 3. Anleitung

**A. Custom Fields (Standardweg)**
1. `src/Resources/config/custom-fields.xml` mit Vendor-Präfix in Set- und Feldnamen (`swag_`) anlegen.
2. `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample` (Install synchronisiert mit `deleteMissingXml=false`, `PluginLifecycleService` Z. 163).
3. Änderungen: Plugin-Version in `composer.json` erhöhen, `bin/console plugin:update SwagExample`. Update synchronisiert mit `deleteMissingXml=true` (Z. 325): in der XML fehlende Sets werden gelöscht; fehlt die Datei ganz, wird alles des Plugins gelöscht.
4. Deinstallation: nur ohne `--keep-user-data` entfernt `removePluginCustomFields` die Sets (Z. 252).
5. Prüfen: Admin → Einstellungen → Zusatzfelder bzw. Produkt → Zusatzfelder.

**B. Zugriff in der Storefront**: `translated.customFields` verwenden (Vererbung/Übersetzung, `TranslatedField('customFields')` in `ProductDefinition` Z. 224):

```twig
{{ page.product.translated.customFields.swag_example_weight }}
```

Medien-Feld (alle IDs in einem `searchMedia`-Aufruf holen, nie in Schleifen; `MediaExtension::searchMedia(array $ids, Context $context)`):

```twig
{% set mediaId = page.product.translated.customFields.swag_example_media %}
{% if mediaId %}
    {% set media = searchMedia([mediaId], context.context).get(mediaId) %}
    <img src="{{ media.url }}" alt="{{ media.alt }}">
{% endif %}
```

**C. Entity-Selection auflösen** (Doku-Muster): Subscriber auf `product.loaded`, IDs aus `getCustomFields()` sammeln, mit `new Criteria($ids)` in EINEM Query laden (bei leerer ID-Liste vorher abbrechen: `if ($ids === []) { return; }`, sonst lädt `new Criteria([])` ALLE Datensätze) und per `$entity->addExtension('name', $loaded)` anhängen. `EntityRepository` per Konstruktor injizieren.

**D. Admin (eigene Komponente)**: Komponente `sw-custom-field-set-renderer` (Props u. a. `sets`, `entity`, `disabled`, `showCustomFieldSetSelection`):

```js
const { Criteria } = Shopware.Data;

customFieldSetCriteria() {
    const criteria = new Criteria();
    criteria.addFilter(Criteria.equals('relations.entityName', 'product'));
    criteria.getAssociation('customFields')
        .addSorting(Criteria.sort('config.customFieldPosition', 'ASC', true));
    return criteria;
}
```

```twig
<sw-custom-field-set-renderer :entity="product" :sets="customFieldSets" show-custom-field-set-selection />
```

**E. EntityExtension**
1. Migration schreiben (`bin/console database:create-migration -p SwagExample --name AddExampleTable`).
2. Definition/Entity/Collection (T-27), Extension-Klasse, Services mit Tag.
3. `bin/console database:migrate --all SwagExample && bin/console cache:clear`.
4. `bin/console dal:validate` prüft Definitionen gegen das Schema.

## 4. Beispiele

`src/Extension/Content/Product/ProductExampleExtension.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Extension\Content\Product;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use SwagExample\Core\Content\Example\ExampleExtensionDefinition;

class ProductExampleExtension extends EntityExtension
{
    public function getEntityName(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }

    public function extendFields(FieldCollection $collection): void
    {
        $collection->add(
            (new OneToOneAssociationField(
                'exampleExtension',
                'id',
                'product_id',
                ExampleExtensionDefinition::class,
                false
            ))->addFlags(new ApiAware(), new CascadeDelete())
        );
    }
}
```

Konstruktor von `OneToOneAssociationField` wie im Core-Fixture `core/Framework/Test/DataAbstractionLayer/Field/TestDefinition/ProductExtension.php`.

`src/Core/Content/Example/ExampleExtensionDefinition.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class ExampleExtensionDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'swag_example_extension';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ExampleExtensionEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ExampleExtensionCollection::class;
    }

    public function since(): ?string
    {
        return '1.0.0';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new FkField('product_id', 'productId', ProductDefinition::class))->addFlags(new ApiAware(), new Required()),
            (new ReferenceVersionField(ProductDefinition::class))->addFlags(new Required()),
            (new StringField('note', 'note'))->addFlags(new ApiAware()),
        ]);
    }
}
```

`src/Core/Content/Example/ExampleExtensionEntity.php` und `ExampleExtensionCollection.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class ExampleExtensionEntity extends Entity
{
    use EntityIdTrait;

    protected string $productId;

    protected ?string $note = null;

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function setProductId(string $productId): void
    {
        $this->productId = $productId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }
}
```

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ExampleExtensionEntity>
 */
class ExampleExtensionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ExampleExtensionEntity::class;
    }
}
```

`src/Migration/Migration1700000000AddExampleTable.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1700000000AddExampleTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1700000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_example_extension` (
                `id` BINARY(16) NOT NULL,
                `product_id` BINARY(16) NOT NULL,
                `product_version_id` BINARY(16) NOT NULL,
                `note` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk.swag_example_extension.product_id` FOREIGN KEY (`product_id`, `product_version_id`)
                    REFERENCES `product` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}
```

`src/Extension/ExampleBulkExtension.php` (mehrere Entities, keine Services nutzbar)

```php
<?php declare(strict_types=1);

namespace SwagExample\Extension;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\BulkEntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Runtime;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\System\Tax\TaxDefinition;
use SwagExample\Core\Content\Example\ExampleExtensionDefinition;

class ExampleBulkExtension extends BulkEntityExtension
{
    public function collect(): \Generator
    {
        yield ProductDefinition::ENTITY_NAME => [
            // Assoziation: persistiert in der eigenen Tabelle swag_example_extension (FK product_id)
            (new OneToManyAssociationField('swagExampleNotes', ExampleExtensionDefinition::class, 'product_id'))
                ->addFlags(new ApiAware(), new CascadeDelete()),
        ];

        yield TaxDefinition::ENTITY_NAME => [
            // Skalarfeld nur als Runtime-Feld erlaubt (nicht persistiert, per Subscriber befüllt)
            (new StringField('swag_example_label', 'swagExampleLabel'))->addFlags(new ApiAware(), new Runtime()),
        ];
    }
}
```

`swagExampleNotes` nutzt die Tabelle `swag_example_extension` aus dem Migrationsbeispiel (FK `product_id`; bei OneToMany/CascadeDelete muss `product_version_id` passen). `swagExampleLabel` hat keine Spalte und wird per Subscriber befüllt. Ein `StringField` ohne `Runtime` an einer Core-Entity wirft `DataAbstractionLayerException::wrongFieldTypeForExtension` (`EntityDefinition::getFields()`). Unbekannter Entity-Name wirft `DependencyInjectionException::definitionNotFound` beim Container-Build.

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Core\Content\Example\ExampleExtensionDefinition;
use SwagExample\Extension\Content\Product\ProductExampleExtension;
use SwagExample\Extension\ExampleBulkExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // Ohne ->defaults()->autoconfigure() müssen die Tags explizit gesetzt werden (siehe T-27).
    $services->set(ExampleExtensionDefinition::class)->tag('shopware.entity.definition');
    $services->set(ProductExampleExtension::class)->tag('shopware.entity.extension');
    $services->set(ExampleBulkExtension::class)->tag('shopware.bulk.entity.extension');
};
```

Runtime-Feld (in `extendFields()` der Extension, plus Subscriber):

```php
$collection->add(
    (new ObjectField('swagComputed', 'swagComputed'))->addFlags(new Runtime())
);
```

`src/Subscriber/ProductSubscriber.php` (Event `ProductEvents::PRODUCT_LOADED_EVENT` = `product.loaded`)

```php
public static function getSubscribedEvents(): array
{
    return [ProductEvents::PRODUCT_LOADED_EVENT => 'onProductLoaded'];
}

public function onProductLoaded(EntityLoadedEvent $event): void
{
    foreach ($event->getEntities() as $product) {
        $product->addExtension('swagComputed', new ArrayStruct(['value' => 42]));
    }
}
```

Subscriber-Grundlagen und `use`-Statements siehe [T-07](./07-events-subscriber.md), Gerüst [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

`src/Resources/config/custom-fields.xml` (ab 6.7.13.0)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<custom-fields xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/CustomField/Schema/custom-fields-1.0.xsd">
    <custom-field-set>
        <name>swag_example_set</name>
        <label>Example Set</label>
        <label lang="de-DE">Beispiel-Set</label>
        <related-entities>
            <product/>
        </related-entities>
        <fields>
            <int name="swag_example_weight">
                <label>Weight</label>
                <position>1</position>
            </int>
            <media-selection name="swag_example_media">
                <label>Bild</label>
                <position>2</position>
            </media-selection>
            <single-entity-select name="swag_example_manufacturer">
                <label>Hersteller</label>
                <position>3</position>
                <entity>product_manufacturer</entity>
                <label-property>name</label-property>
            </single-entity-select>
        </fields>
    </custom-field-set>
</custom-fields>
```

Feldtypen laut XSD (`core/System/CustomField/Schema/custom-fields-1.0.xsd`): `int, float, text, text-area, bool, datetime, single-select, multi-select, single-entity-select, multi-entity-select, color-picker, media-selection, price`. Gemeinsame Kindelemente: `label, help-text, required, position, allow-customer-write, allow-cart-expose, include-in-search`; `global` ist Attribut an `custom-field-set`. `related-entities` u. a. `product, order, category, customer, customer_address, media, product_manufacturer, sales_channel, landing_page, promotion, shipping_method, payment_method, newsletter_recipient` (vollständig im XSD).

Imperative Alternative (laut Doku), `src/SwagExample.php`:

```php
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class SwagExample extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        /** @var EntityRepository $repo */
        $repo = $this->container->get('custom_field_set.repository');
        $repo->create([[
            'name' => 'swag_example_set',
            'config' => ['label' => ['en-GB' => 'Example', 'de-DE' => 'Beispiel']],
            'customFields' => [[
                'name' => 'swag_example_size',
                'type' => CustomFieldTypes::INT,
                'config' => ['label' => ['en-GB' => 'Size'], 'customFieldPosition' => 1],
            ]],
            'relations' => [['entityName' => 'product']],
        ]], $installContext->getContext());
    }
}
```

Plugin-Lifecycle (insb. Deinstallation der Sets) siehe [T-03](./03-plugin-lifecycle.md).

⚠ UNGEPRÜFT: Beispiele (inkl. Runtime-Beispiel und imperatives Anlegen) nicht in einer laufenden Installation ausgeführt.

## 5. Fallstricke

- Kein Vendor-Präfix bei Set-/Feldnamen: Kollisionen. Feldnamen müssen `CUSTOM_FIELD_NAME_PATTERN = /^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/` entsprechen (`CustomFieldService`), sonst `CustomFieldException::customFieldNameInvalid`.
- Custom Field in der Storefront nicht sichtbar: Kunden-Schreibzugriff braucht `allow-customer-write`, Warenkorb-Zugriff `allow-cart-expose` (aus dem XSD abgeleitet).
- Typ nach dem Anlegen ändern: Daten im JSON haben evtl. den alten Typ; neuen Feldnamen verwenden (Annahme).
- XML löscht Sets beim Update (`deleteMissingXml=true`), ggf. auch vom Händler im Admin ergänzte Felder im selben Set.
- `BulkEntityExtension` mit Konstruktor-Abhängigkeiten: nicht möglich (`final`, parameterlos), Services gehören in Subscriber.
- `modifyFields` zum Hinzufügen nutzen: nicht möglich.
- Extension-Felder mit Storage ohne Migration: SQL-Fehler beim Lesen.
- `searchMedia` in Schleifen oder einzelne ID-Loads im Subscriber statt Batch.
- Core-Definition überschreiben/dekorieren, um Felder hinzuzufügen (Anti-Pattern).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `custom-fields.xml` für Plugins: neu in 6.7.13.0 (Doku, im Code bestätigt). Vorher (6.5/6.6) nur imperativ in `install()/uninstall()`; Apps kannten die XML schon früher.
- `BulkEntityExtension`: laut Doku ab 6.6.10; in 6.5 nicht vorhanden (Annahme aus Doku).
- `modifyFields()` in 6.7.15.1 vorhanden; seit wann: nicht geprüft.
- `EntityCustomFieldsTrait` (`getCustomFieldsValue`, `getCustomFieldsValues`, `changeCustomFields`, `getTranslatedCustomFieldsValue`) und `#[CustomFields]` (Property `$customFields`) sind für Attribut-Entities gedacht, der Trait wird aber auch von klassischen Core-Entities genutzt (z. B. `AppEntity`, `PluginEntity`, `WebhookEventLogEntity`; `EntityCollection` referenziert ihn ebenfalls). Siehe T-27.
- `CustomFieldTypes`: `BOOL, CHECKBOX, COLORPICKER, DATE, DATETIME, ENTITY, FLOAT, INT, JSON, NUMBER, PRICE, HTML, MEDIA, SELECT, SWITCH, TEXT`.
- Keine `@deprecated`-Markierungen in `EntityExtension`, `BulkEntityExtension`, `CustomFieldService` gefunden. Services: `services.php` bevorzugt (XML deprecated, siehe [T-05](./05-services-registrieren.md)).

## 6. Checkliste

- [ ] Entscheidung dokumentiert: Custom Field oder EntityExtension
- [ ] Vendor-Präfix in Set-/Feldnamen und Tabellen/Spalten
- [ ] Migration für alle Extension-Felder/Tabellen vorhanden, `dal:validate` läuft sauber
- [ ] Extension-/Definition-Services getaggt, Definition mit argumentlosem Konstruktor
- [ ] `ApiAware` an Feldern, die in Store-/Admin-API sichtbar sein sollen
- [ ] Runtime-Felder mit `Runtime`-Flag und Subscriber befüllt (Batch statt Einzel-Loads)
- [ ] `custom-fields.xml`: Update-Verhalten (`deleteMissingXml`) bedacht
- [ ] Storefront-Zugriff über `translated.customFields`
- [ ] Deinstallation räumt Tabellen nur ohne `keepUserData()` ab

## 7. Quellen

Doku (https://developer.shopware.com/docs/guides/plugins/plugins/): `framework/data-handling/add-complex-data-to-existing-entities.html`, `framework/custom-field/add-custom-field.html`, `framework/custom-field/fetching-data-from-entity-selection.html`, `database/custom-fields-of-type-media.html`, `storefront/howto/using-custom-fields-storefront.html`, `administration/data-handling-processing/using-custom-fields.html`.

Quellcode (`core/`): `Framework/DataAbstractionLayer/{EntityExtension,BulkEntityExtension,FilteredBulkEntityExtension,EntityDefinition,EntityCustomFieldsTrait}.php`, `Field/Flag/{Extension,Runtime}.php`, `Dbal/EntityHydrator.php` (Z. 224), `Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`, `System/DependencyInjection/CompilerPass/SalesChannelEntityCompilerPass.php` (Z. 255–300), `Framework/Notification/NotificationBulkEntityExtension.php` (einziges Core-Beispiel Bulk), `System/CustomField/{CustomFieldService,CustomFieldSetPersister,CustomFieldXmlLoader,CustomFieldTypes}.php`, `System/CustomField/Schema/custom-fields-1.0.xsd`, `Framework/Plugin/PluginLifecycleService.php` (Z. 163, 252, 325, 569–589), `Framework/Adapter/Twig/Extension/MediaExtension.php`, `Content/Product/ProductDefinition.php` (Z. 224). Admin: `administration/Resources/app/administration/src/app/component/form/sw-custom-field-set-renderer/index.js`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele nicht ausgeführt (keine Test-Plugins vorhanden); Syntax an Doku und Core-Fixtures angelehnt.
- ⚠ UNGEPRÜFT: Flags `ApiAware`/`CascadeDelete` an der OneToOne-Extension nicht gegen ein Core-Beispiel verifiziert.
- ⚠ UNGEPRÜFT: Runtime-Beispiel mit `ObjectField` + `Runtime` abgeleitet, kein Core-Fixture geprüft.
- ⚠ LÜCKE: `relations` vs. `global` beim imperativen Anlegen: Doku zeigt `global`, Code (`CustomFieldSetRelationDefinition`) liefert die Beziehung; Semantik von `global` nicht verifiziert.
- ⚠ LÜCKE: Doku nutzt `services.php`; ob ein Plugin `services.xml` und `services.php` parallel lädt, nicht nachgewiesen.
- ⚠ LÜCKE: Ob eine Feldtyp-Änderung in der XML beim Update migriert, nicht geprüft.
- ⚠ UNGEPRÜFT: `allow-customer-write`/`allow-cart-expose` nur aus dem XSD abgeleitet.
- ⚠ UNGEPRÜFT: Doku-Beispiele zu Admin-Custom-Fields in Options-API; Composition-API-Variante nicht geprüft.
