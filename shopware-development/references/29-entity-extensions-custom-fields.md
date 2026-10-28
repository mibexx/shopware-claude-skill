# Entity extensions and custom fields (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when an existing core entity (e.g. `product`) is to be extended with fields, associations or custom tables (`EntityExtension`, `BulkEntityExtension`, runtime fields), or when merchant-configurable additional fields (custom fields, custom field sets) are to be provided via a plugin.

Scope: custom, new entities → [T-27](./27-dal-entities.md). Reading/writing/indexers → [T-28](./28-dal-reading-writing.md). Subscribers/events → [T-07](./07-events-subscriber.md). Migrations → [T-04](./04-database-migrations.md). Storefront Twig → [T-14](./14-storefront-twig-templates.md). Admin components → [T-25](./25-admin-components.md). Scaffolds: [../templates/entity/README.md](../templates/entity/README.md), [../templates/migration/README.md](../templates/migration/README.md).

## 2. Concepts

- **EntityExtension** (`core/Framework/DataAbstractionLayer/EntityExtension.php`): abstract `getEntityName(): string`; optional `extendFields(FieldCollection)`, `modifyFields(FieldCollection)` (only change flags/JsonField mappings, do not add/remove anything), `extendProtections(EntityProtectionCollection)`. Tag `shopware.entity.extension` (autoconfigure sets it).
- **Extension data lives in `Entity::$extensions`**, not in the struct (flag `Extension`, `EntityHydrator` line 224 calls `addExtension()`). Access via `$product->getExtension('name')`, Twig `product.extensions.name`.
- **Runtime fields** (flag `Runtime`): not read from the DB, populated by a subscriber (e.g. `product.loaded`).
- **Persisted fields**: only via associations to a custom table (OneToOne/OneToMany/ManyToMany/ManyToOne; FK column in the custom table or `FkField` with a matching ManyToOne association, plus `ReferenceVersionField`). Plain scalar fields (`StringField`, `IntField` etc.) without the `Runtime` flag are rejected by `EntityDefinition::getFields()` with `wrongFieldTypeForExtension`, so a custom column in the core table is not possible via an extension (use custom fields for that). A migration is needed (the DAL does not create tables).
- **BulkEntityExtension** (`BulkEntityExtension.php`): `collect(): \Generator` yields `entityName => list<Field>`. Constructor `final`, parameterless, no dependency injection (the compiler pass instantiates without the constructor and calls `collect()` at compile time). Tag `shopware.bulk.entity.extension`. Docs: since 6.6.10.
- **Custom fields**: admin-configurable fields in the JSON field `customFields`; for scalar values/IDs. Declaratively via `src/Resources/config/custom-fields.xml` (since 6.7.13.0; `PluginLifecycleService::syncPluginCustomFields`) or imperatively via `custom_field_set.repository`.
- **Decision guide**: merchant-configurable, scalar, no join → custom field. Association, struct type, index/FK, runtime computation or custom table → EntityExtension. Never decorate/override core definitions to add fields.

Directory tree (plugin `SwagExample`):

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

## 3. Instructions

**A. Custom fields (standard approach)**
1. Create `src/Resources/config/custom-fields.xml` with a vendor prefix in set and field names (`swag_`).
2. `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample` (install syncs with `deleteMissingXml=false`, `PluginLifecycleService` line 163).
3. Changes: bump the plugin version in `composer.json`, `bin/console plugin:update SwagExample`. Update syncs with `deleteMissingXml=true` (line 325): sets missing from the XML are deleted; if the file is missing entirely, everything belonging to the plugin is deleted.
4. Uninstall: only without `--keep-user-data` does `removePluginCustomFields` remove the sets (line 252).
5. Check: Admin → Settings → Custom fields or Product → Custom fields.

**B. Access in the Storefront**: use `translated.customFields` (inheritance/translation, `TranslatedField('customFields')` in `ProductDefinition` line 224):

```twig
{{ page.product.translated.customFields.swag_example_weight }}
```

Media field (fetch all IDs in one `searchMedia` call, never in loops; `MediaExtension::searchMedia(array $ids, Context $context)`):

```twig
{% set mediaId = page.product.translated.customFields.swag_example_media %}
{% if mediaId %}
    {% set media = searchMedia([mediaId], context.context).get(mediaId) %}
    <img src="{{ media.url }}" alt="{{ media.alt }}">
{% endif %}
```

**C. Resolving an entity selection** (docs pattern): subscriber on `product.loaded`, collect IDs from `getCustomFields()`, load them with `new Criteria($ids)` in ONE query (abort beforehand if the ID list is empty: `if ($ids === []) { return; }`, otherwise `new Criteria([])` loads ALL records) and attach them via `$entity->addExtension('name', $loaded)`. Inject `EntityRepository` via the constructor.

**D. Admin (custom component)**: component `sw-custom-field-set-renderer` (props among others `sets`, `entity`, `disabled`, `showCustomFieldSetSelection`):

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
1. Write the migration (`bin/console database:create-migration -p SwagExample --name AddExampleTable`).
2. Definition/entity/collection (T-27), extension class, services with tag.
3. `bin/console database:migrate --all SwagExample && bin/console cache:clear`.
4. `bin/console dal:validate` checks definitions against the schema.

## 4. Examples

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

Constructor of `OneToOneAssociationField` as in the core fixture `core/Framework/Test/DataAbstractionLayer/Field/TestDefinition/ProductExtension.php`.

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

`src/Core/Content/Example/ExampleExtensionEntity.php` and `ExampleExtensionCollection.php`

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

`src/Extension/ExampleBulkExtension.php` (multiple entities, no services usable)

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
            // Association: persisted in the custom table swag_example_extension (FK product_id)
            (new OneToManyAssociationField('swagExampleNotes', ExampleExtensionDefinition::class, 'product_id'))
                ->addFlags(new ApiAware(), new CascadeDelete()),
        ];

        yield TaxDefinition::ENTITY_NAME => [
            // Scalar field only allowed as a runtime field (not persisted, populated by a subscriber)
            (new StringField('swag_example_label', 'swagExampleLabel'))->addFlags(new ApiAware(), new Runtime()),
        ];
    }
}
```

`swagExampleNotes` uses the table `swag_example_extension` from the migration example (FK `product_id`; with OneToMany/CascadeDelete, `product_version_id` must match). `swagExampleLabel` has no column and is populated by a subscriber. A `StringField` without `Runtime` on a core entity throws `DataAbstractionLayerException::wrongFieldTypeForExtension` (`EntityDefinition::getFields()`). An unknown entity name throws `DependencyInjectionException::definitionNotFound` during the container build.

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Core\Content\Example\ExampleExtensionDefinition;
use SwagExample\Extension\Content\Product\ProductExampleExtension;
use SwagExample\Extension\ExampleBulkExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // Without ->defaults()->autoconfigure() the tags must be set explicitly (see T-27).
    $services->set(ExampleExtensionDefinition::class)->tag('shopware.entity.definition');
    $services->set(ProductExampleExtension::class)->tag('shopware.entity.extension');
    $services->set(ExampleBulkExtension::class)->tag('shopware.bulk.entity.extension');
};
```

Runtime field (in the extension's `extendFields()`, plus subscriber):

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

For subscriber basics and `use` statements see [T-07](./07-events-subscriber.md), scaffold [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

`src/Resources/config/custom-fields.xml` (since 6.7.13.0)

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
                <label>Image</label>
                <position>2</position>
            </media-selection>
            <single-entity-select name="swag_example_manufacturer">
                <label>Manufacturer</label>
                <position>3</position>
                <entity>product_manufacturer</entity>
                <label-property>name</label-property>
            </single-entity-select>
        </fields>
    </custom-field-set>
</custom-fields>
```

Field types according to the XSD (`core/System/CustomField/Schema/custom-fields-1.0.xsd`): `int, float, text, text-area, bool, datetime, single-select, multi-select, single-entity-select, multi-entity-select, color-picker, media-selection, price`. Common child elements: `label, help-text, required, position, allow-customer-write, allow-cart-expose, include-in-search`; `global` is an attribute on `custom-field-set`. `related-entities` among others `product, order, category, customer, customer_address, media, product_manufacturer, sales_channel, landing_page, promotion, shipping_method, payment_method, newsletter_recipient` (complete list in the XSD).

Imperative alternative (according to the docs), `src/SwagExample.php`:

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

For the plugin lifecycle (in particular uninstalling the sets) see [T-03](./03-plugin-lifecycle.md).

⚠ UNVERIFIED: examples (including the runtime example and imperative creation) not run in a live installation.

## 5. Pitfalls

- No vendor prefix in set/field names: collisions. Field names must match `CUSTOM_FIELD_NAME_PATTERN = /^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/` (`CustomFieldService`), otherwise `CustomFieldException::customFieldNameInvalid`.
- Custom field not visible in the Storefront: customer write access needs `allow-customer-write`, cart access `allow-cart-expose` (derived from the XSD).
- Changing the type after creation: data in the JSON may have the old type; use a new field name (assumption).
- The XML deletes sets on update (`deleteMissingXml=true`), possibly including fields that the merchant added in the Admin to the same set.
- `BulkEntityExtension` with constructor dependencies: not possible (`final`, parameterless), services belong in subscribers.
- Using `modifyFields` to add fields: not possible.
- Extension fields with storage but without a migration: SQL error on read.
- `searchMedia` in loops or single ID loads in the subscriber instead of a batch.
- Overriding/decorating a core definition to add fields (anti-pattern).

### Deprecations and version notes (6.5/6.6 → 6.7)

- `custom-fields.xml` for plugins: new in 6.7.13.0 (docs, confirmed in the code). Before (6.5/6.6) only imperatively in `install()/uninstall()`; apps already supported the XML earlier.
- `BulkEntityExtension`: according to the docs since 6.6.10; not present in 6.5 (assumption based on the docs).
- `modifyFields()` present in 6.7.15.1; since when: not checked.
- `EntityCustomFieldsTrait` (`getCustomFieldsValue`, `getCustomFieldsValues`, `changeCustomFields`, `getTranslatedCustomFieldsValue`) and `#[CustomFields]` (property `$customFields`) are intended for attribute entities, but the trait is also used by classic core entities (e.g. `AppEntity`, `PluginEntity`, `WebhookEventLogEntity`; `EntityCollection` references it as well). See T-27.
- `CustomFieldTypes`: `BOOL, CHECKBOX, COLORPICKER, DATE, DATETIME, ENTITY, FLOAT, INT, JSON, NUMBER, PRICE, HTML, MEDIA, SELECT, SWITCH, TEXT`.
- No `@deprecated` markers found in `EntityExtension`, `BulkEntityExtension`, `CustomFieldService`. Services: `services.php` preferred (XML deprecated, see [T-05](./05-registering-services.md)).

## 6. Checklist

- [ ] Decision documented: custom field or EntityExtension
- [ ] Vendor prefix in set/field names and tables/columns
- [ ] Migration present for all extension fields/tables, `dal:validate` runs clean
- [ ] Extension/definition services tagged, definition with argument-less constructor
- [ ] `ApiAware` on fields that should be visible in the Store/Admin API
- [ ] Runtime fields with `Runtime` flag and populated by a subscriber (batch instead of single loads)
- [ ] `custom-fields.xml`: update behavior (`deleteMissingXml`) considered
- [ ] Storefront access via `translated.customFields`
- [ ] Uninstall only drops tables without `keepUserData()`

## 7. Sources

Docs (https://developer.shopware.com/docs/guides/plugins/plugins/): `framework/data-handling/add-complex-data-to-existing-entities.html`, `framework/custom-field/add-custom-field.html`, `framework/custom-field/fetching-data-from-entity-selection.html`, `database/custom-fields-of-type-media.html`, `storefront/howto/using-custom-fields-storefront.html`, `administration/data-handling-processing/using-custom-fields.html`.

Source code (`core/`): `Framework/DataAbstractionLayer/{EntityExtension,BulkEntityExtension,FilteredBulkEntityExtension,EntityDefinition,EntityCustomFieldsTrait}.php`, `Field/Flag/{Extension,Runtime}.php`, `Dbal/EntityHydrator.php` (line 224), `Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`, `System/DependencyInjection/CompilerPass/SalesChannelEntityCompilerPass.php` (lines 255–300), `Framework/Notification/NotificationBulkEntityExtension.php` (only core example for bulk), `System/CustomField/{CustomFieldService,CustomFieldSetPersister,CustomFieldXmlLoader,CustomFieldTypes}.php`, `System/CustomField/Schema/custom-fields-1.0.xsd`, `Framework/Plugin/PluginLifecycleService.php` (lines 163, 252, 325, 569–589), `Framework/Adapter/Twig/Extension/MediaExtension.php`, `Content/Product/ProductDefinition.php` (line 224). Admin: `administration/Resources/app/administration/src/app/component/form/sw-custom-field-set-renderer/index.js`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: examples not run (no test plugins available); syntax based on the docs and core fixtures.
- ⚠ UNVERIFIED: flags `ApiAware`/`CascadeDelete` on the OneToOne extension not verified against a core example.
- ⚠ UNVERIFIED: runtime example with `ObjectField` + `Runtime` derived, no core fixture checked.
- ⚠ GAP: `relations` vs. `global` for imperative creation: the docs show `global`, the code (`CustomFieldSetRelationDefinition`) provides the relation; semantics of `global` not verified.
- ⚠ GAP: the docs use `services.php`; whether a plugin loads `services.xml` and `services.php` in parallel has not been established.
- ⚠ GAP: whether a field type change in the XML is migrated on update has not been checked.
- ⚠ UNVERIFIED: `allow-customer-write`/`allow-cart-expose` only derived from the XSD.
- ⚠ UNVERIFIED: docs examples for admin custom fields use the Options API; Composition API variant not checked.
