# Custom entities in the DAL: definition, entity, collection, associations (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a new database entity is to be created in the plugin: classically with `EntityDefinition`/`Entity`/`EntityCollection` or via the PHP attribute `#[Entity]`, including translations, associations (1:n, n:1, n:m), flags, inheritance and versioning.

Scope: reading/writing/indexers → [T-28](./28-dal-reading-writing.md). Extending existing entities, custom fields → [T-29](./29-entity-extensions-custom-fields.md). Migrations → [T-04](./04-database-migrations.md). Uninstall/dropping tables → [T-03](./03-plugin-lifecycle.md). Services → [T-05](./05-registering-services.md). Matching admin page → [T-24](./24-admin-modules.md), [T-26](./26-admin-data-handling.md). Scaffold: [../templates/entity/README.md](../templates/entity/README.md), [../templates/migration/README.md](../templates/migration/README.md).

## 2. Concepts

- Classic: three PHP classes (`EntityDefinition`, `Entity`, `EntityCollection`) plus your own migration. The DAL does NOT create tables; `bin/console dal:validate` checks the schema against the definition.
- **Registration**: tag `shopware.entity.definition`. In 6.7, autoconfigure sets it automatically for services that extend `EntityDefinition` (`AutoconfigureCompilerPass`, `registerForAutoconfiguration(EntityDefinition::class)`). The tag attribute `entity="..."` is NOT evaluated by the `EntityCompilerPass`; the name comes from `new $class()->getEntityName()` (`EntityCompilerPass` lines 63-65: `$instance = new $class()`, `$instance->getEntityName()`). Consequence: argument-less constructor in the definition.
- `EntityCompilerPass` creates the service `<entity_name>.repository` (public, `EntityRepository`).
- **Attribute entities** (`#[Entity('name')]`, tag `shopware.entity`, also via autoconfigure): definition, translation and mapping definition and repository are generated at compile time (`AttributeEntityCompiler`, `AttributeEntityCompilerPass`). No getters/setters needed. You still write the migration yourself (or `dal:migration:create`).
- `created_at`/`updated_at` come from `EntityDefinition::defaultFields()` (`CreatedAtField`, `UpdatedAtField`); the columns must exist in the migration.
- Translations: `*_translation` table + `EntityTranslationDefinition` + `TranslatedField` + `TranslationsAssociationField`. Inheritance: `Inherited` + `ParentFkField`/`ParentAssociationField`/`ChildrenAssociationField` + `isInheritanceAware()`. Versioning: `VersionField` (+ `ReferenceVersionField` in foreign/mapping tables).
- Decision guide: classic for full control (hydrator, extensions, inheritance, custom fields); attribute approach for lean entities.
- CLI names: `dal:migration:create`, `dal:create:entities`, `dal:create:hydrators`, `dal:validate`, `dal:refresh:index` (NOT `dal:create:*` for migrations).

Directory tree (plugin `SwagExample`, namespace `SwagExample`):

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Core/Content/Example/
    │   ├── ExampleDefinition.php
    │   ├── ExampleEntity.php
    │   ├── ExampleCollection.php
    │   └── Aggregate/ExampleTranslation/
    │       └── ExampleTranslation{Definition,Entity,Collection}.php
    ├── Migration/Migration1730000000CreateExampleTable.php
    └── Resources/config/services.php
```

Attribute approach: only `Core/Content/Example/ExampleEntity.php` with `#[Entity('swag_example')]`, migration and `services.php`. The migration directory is fixed: `<Plugin-Namespace>\Migration` → `src/Migration` (`Framework/Bundle.php`).

## 3. Instructions

1. Define the entity name: `<vendor-prefix>_<name>` (snake_case, `swag_example`); properties lowerCamelCase, columns snake_case.
2. Write the definition (or attribute entity). Primary key: `IdField` + `PrimaryKey` + `Required`. Without `ApiAware`, a field is not readable/writable via the Admin/Store API.
3. Create the migration, either by hand (`MigrationStep`, timestamp = file name) or generated (install/activate the plugin with the definition beforehand):

```bash
bin/console dal:migration:create swag_example,swag_example_translation --bundle=SwagExample
```

   Argument `entities` = comma list; options `--bundle` (plugin name), `--namespace` (e.g. `V6_7`, only without bundle), `--package`. Without `--bundle` the file ends up in the core migration folder (core development only!). The command compares against the actual DB state (`MigrationQueryGenerator`), is `@internal`; always check the result (FK, collation, indexes). Assumption: if the table already exists and there is no delta, no file is created.
4. Register the definition services (all of them: main, translation, mapping definition).
5. Install the plugin and check the schema:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console database:migrate --all SwagExample    # for later migrations
bin/console cache:clear
bin/console dal:validate
```

6. Optional: `dal:create:entities` (schema entity classes to `<rootDir>/../schema/`, core development), `dal:create:hydrators` (hydrator for core definitions), `dal:refresh:index` (see T-28), `bin/console plugin:create SwagExample Swag --entities` (there is no `plugin:scaffold` command; `--entities` is a pure switch without a value, the entity names (PascalCase, comma-separated) are asked interactively, non-interactively nothing is created; `EntityGenerator` writes migration, definition, entity, collection and the tag `['entity' => '...']`, the attribute is not evaluated, harmless).

### 3.1 Associations (constructors from `Field/`)

| Field | Constructor |
|---|---|
| `FkField` | `(storageName, propertyName, referenceClass, referenceField = 'id')` |
| `ManyToOneAssociationField` | `(propertyName, storageName, referenceClass, referenceField = 'id', autoload = false)` |
| `OneToManyAssociationField` | `(propertyName, referenceClass, referenceField, localField = 'id')` |
| `OneToOneAssociationField` | `(propertyName, storageName, referenceField, referenceClass, autoload = true)` |
| `ManyToManyAssociationField` | `(propertyName, toManyDefinitionClass, mappingDefinition, mappingLocalColumn, mappingReferenceColumn, sourceColumn = 'id', referenceField = 'id')` |
| `TranslationsAssociationField` | `(referenceClass, referenceField, propertyName = 'translations', localField = 'id')`, sets `CascadeDelete` |
| `TranslatedField` | `(propertyName, useForSorting = false)` |
| `ReferenceVersionField` | `(definition, storageName = null)`, default column `<entity>_version_id` |

1:n/n:1:

```php
// ExampleDefinition
(new FkField('example_category_id', 'categoryId', ExampleCategoryDefinition::class))->addFlags(new ApiAware()),
(new ManyToOneAssociationField('category', 'example_category_id', ExampleCategoryDefinition::class, 'id', false))->addFlags(new ApiAware()),

// ExampleCategoryDefinition
(new OneToManyAssociationField('examples', ExampleDefinition::class, 'example_category_id'))
    ->addFlags(new ApiAware(), new SetNullOnDelete()),
```

n:m with mapping (table `swag_example_tag`, PK `(example_id, tag_id)`):

```php
class ExampleTagMappingDefinition extends MappingEntityDefinition
{
    final public const ENTITY_NAME = 'swag_example_tag';

    public function getEntityName(): string { return self::ENTITY_NAME; }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new FkField('example_id', 'exampleId', ExampleDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('tag_id', 'tagId', TagDefinition::class))->addFlags(new PrimaryKey(), new Required()),
            new ManyToOneAssociationField('example', 'example_id', ExampleDefinition::class, 'id'),
            new ManyToOneAssociationField('tag', 'tag_id', TagDefinition::class, 'id'),
        ]);
    }
}

// ExampleDefinition
(new ManyToManyAssociationField('tags', TagDefinition::class, ExampleTagMappingDefinition::class, 'example_id', 'tag_id'))
    ->addFlags(new ApiAware(), new CascadeDelete()),
```

`MappingEntityDefinition` provides no `defaultFields()` (no `created_at`/`updated_at`) and throws a `MappingEntityClassesException` on `getEntityClass()`/`getCollectionClass()`. Register the mapping definition as `shopware.entity.definition` as well.

Attribute approach: `#[ManyToMany(entity: 'tag', onDelete: OnDelete::CASCADE, api: true)] public ?array $tags`, `#[ManyToOne(entity: 'swag_example_category', onDelete: OnDelete::SET_NULL, api: true)]`, `#[OneToMany(entity: 'swag_example_item', ref: 'swag_example_id', onDelete: OnDelete::CASCADE, api: true)]`. Mapping name: alphabetically sorted pair `[entity, target]` joined with `_` (`AttributeEntityCompiler::mappingName()`, can be overridden via `mapping:`), columns `<entity>_id` and `<target>_id`.

### 3.2 Flags (selection, `Field/Flag/`)

| Flag | Meaning |
|---|---|
| `PrimaryKey`, `Required` | PK / required field |
| `ApiAware(...sources)` | visible/writable via API; without argument Admin + Store API, with `AdminApiSource::class` Admin only |
| `ApiCriteriaAware` | bypasses `ApiAware` for Criteria (special case price fields) |
| `Runtime(dependsOn)` | populated at runtime, not in DB; mandatory for non-association fields in an `EntityExtension` |
| `Computed` | computed by an indexer/externally, not writable via the DAL |
| `Inherited`, `ReverseInherited` | inheritance |
| `CascadeDelete`, `RestrictDelete`, `SetNullOnDelete` | delete behavior at DAL level (does not replace DB constraints) |
| `WriteProtected` | writable only with certain permissions/sources |
| `AllowHtml` | HTML allowed (otherwise sanitized); XSS risk |
| `SearchRanking` | weight in search (see [T-17](./17-listing-search-elasticsearch.md)) |
| `Extension` | value in `Entity::$extensions` |
| `Since`, `Deprecated` | metadata |

Further special flags (`Immutable`, `NoConstraint`, `IgnoreInOpenapiSchema`, `ResetOnClone`, `RuleAreas`, `Choice`, `AsArray`, `DoNotUseContext`, `IgnoreInUnusedMediaSearch`) in the source code. `->addFlags(...)` adds, `->setFlags(...)` replaces completely.

### 3.3 Translations

- Main definition: `TranslatedField('name')` (property name, not storage name) + `TranslationsAssociationField(TranslationDefinition::class, '<entity>_id')`.
- Translation definition: `EntityTranslationDefinition` + `getParentDefinitionClass()`; `getBaseFields()` provides the FK (`<entity>_id`), `language_id`, back associations and, for versioned parents, `ReferenceVersionField`. The translation entity needs the properties `<camelCaseEntityName>Id` and `<camelCaseEntityName>` (`swag_example` → `swagExampleId`, `swagExample`) and extends `TranslationEntity`. `dal:validate` requires a reverse side for the ManyToOne association `language` (`OneToManyAssociationField` with `language_id` on `LanguageDefinition`; `DefinitionValidator::validateManyToOne`): in plugins add it via an `EntityExtension` on `language` (template `templates/entity`, `LanguageExampleTranslationExtension`).
- Keep translated fields nullable in the translation definition; `Required` on the `TranslatedField` or on the translation definition makes `translations` in the main record a required field in the system language.
- Access: `$entity->getTranslated()['name']` (with fallback along the language chain); `getName()` only returns the value of the current language (possibly `null`). More in [T-18](./18-snippets-translations.md) and T-28.
- The docs show `getParentDefinitionClass()` as `public`, the code as `protected` (both are permitted).

### 3.4 Inheritance and versioning

Inheritance:

```php
public function isInheritanceAware(): bool { return true; }

new ParentFkField(self::class),
new ParentAssociationField(self::class, 'id'),
new ChildrenAssociationField(self::class),
(new StringField('description', 'description'))->addFlags(new Inherited()),
```

Migration: `parent_id BINARY(16) NULL`, inheritable columns nullable. Translations: `Inherited` on `TranslatedField` AND `TranslationsAssociationField`. Entity: add `parent`, `parentId`, `children`. Attribute approach: `#[Entity('x', parent: 'x', inheritanceAware: true)]`, `#[Inherited]`/`#[ReverseInherited]`. Inherited associations only load with `Criteria::addAssociation` + `Context::considerInheritance` (T-28).

Versioning:

```sql
ALTER TABLE `swag_example`
    ADD `version_id` BINARY(16) NOT NULL AFTER `id`,
    ADD PRIMARY KEY `id_version_id` (`id`, `version_id`),
    DROP INDEX `PRIMARY`;
```

```php
new VersionField(),                                    // PrimaryKey + Required automatically
new ReferenceVersionField(self::class, 'parent_version_id')
```

`isVersionAware()` checks the field `versionId`. In foreign tables/mappings `ReferenceVersionField(Target::class)` (default column `<entity>_version_id`) + `PrimaryKey`/`Required` in mappings (model `ProductCategoryDefinition`). Attributes `#[Version]`, `#[ReferenceVersion(entity: '...')]`. API: `EntityRepository::createVersion()`, `merge()`.

### 3.5 Entity specifics

- Properties at least `protected`; NO `readonly` (the hydrator sets values via reflection/`__set`).
- `EntityIdTrait` provides `$id` + `getId()/setId()`; `EntityCustomFieldsTrait` + `new CustomFields()` or `#[CustomFields(true)]` for custom fields.

## 4. Examples

### 4.1 Classic: entity with translation

`src/Migration/Migration1730000000CreateExampleTable.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000000CreateExampleTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_example` (
                `id` BINARY(16) NOT NULL,
                `technical_name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `uniq.swag_example.technical_name` UNIQUE (`technical_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_example_translation` (
                `swag_example_id` BINARY(16) NOT NULL,
                `language_id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`swag_example_id`, `language_id`),
                CONSTRAINT `fk.swag_example_translation.swag_example_id` FOREIGN KEY (`swag_example_id`)
                    REFERENCES `swag_example` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.swag_example_translation.language_id` FOREIGN KEY (`language_id`)
                    REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }
}
```

`src/Core/Content/Example/ExampleDefinition.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\BoolField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslationsAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationDefinition;

class ExampleDefinition extends EntityDefinition
{
    final public const ENTITY_NAME = 'swag_example';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ExampleEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ExampleCollection::class;
    }

    public function since(): ?string
    {
        return '1.0.0';
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new ApiAware(), new PrimaryKey(), new Required()),
            (new StringField('technical_name', 'technicalName'))->addFlags(new ApiAware(), new Required()),
            (new BoolField('active', 'active'))->addFlags(new ApiAware()),
            (new TranslatedField('name'))->addFlags(new ApiAware()),
            (new TranslationsAssociationField(ExampleTranslationDefinition::class, 'swag_example_id'))
                ->addFlags(new ApiAware(), new Required()),
        ]);
    }
}
```

`src/Core/Content/Example/ExampleEntity.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationCollection;

class ExampleEntity extends Entity
{
    use EntityIdTrait;

    protected string $technicalName;

    protected bool $active = true;

    protected ?string $name = null;

    protected ?ExampleTranslationCollection $translations = null;

    public function getTechnicalName(): string
    {
        return $this->technicalName;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getTranslations(): ?ExampleTranslationCollection
    {
        return $this->translations;
    }
}
```

`src/Core/Content/Example/ExampleCollection.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ExampleEntity>
 */
class ExampleCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'swag_example_collection';
    }

    protected function getExpectedClass(): string
    {
        return ExampleEntity::class;
    }
}
```

`src/Core/Content/Example/Aggregate/ExampleTranslation/ExampleTranslationDefinition.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Aggregate\ExampleTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityTranslationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use SwagExample\Core\Content\Example\ExampleDefinition;

class ExampleTranslationDefinition extends EntityTranslationDefinition
{
    final public const ENTITY_NAME = 'swag_example_translation';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ExampleTranslationEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ExampleTranslationCollection::class;
    }

    protected function getParentDefinitionClass(): string
    {
        return ExampleDefinition::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new StringField('name', 'name'))->addFlags(new ApiAware()),
        ]);
    }
}
```

`.../ExampleTranslation/ExampleTranslationEntity.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Aggregate\ExampleTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\TranslationEntity;
use SwagExample\Core\Content\Example\ExampleEntity;

class ExampleTranslationEntity extends TranslationEntity
{
    protected string $swagExampleId;

    protected ?string $name = null;

    protected ?ExampleEntity $swagExample = null;

    public function getSwagExampleId(): string
    {
        return $this->swagExampleId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getSwagExample(): ?ExampleEntity
    {
        return $this->swagExample;
    }
}
```

`.../ExampleTranslation/ExampleTranslationCollection.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Aggregate\ExampleTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ExampleTranslationEntity>
 */
class ExampleTranslationCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ExampleTranslationEntity::class;
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationDefinition;
use SwagExample\Core\Content\Example\ExampleDefinition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    // The tag is added via autoconfigure; adding it explicitly is harmless and documents the intent.
    $services->set(ExampleDefinition::class)->tag('shopware.entity.definition');
    $services->set(ExampleTranslationDefinition::class)->tag('shopware.entity.definition');
};
```

Usage (repository details T-28; service argument `new Reference('swag_example.repository')` or `service('swag_example.repository')`):

```php
$this->exampleRepository->create([[
    'id' => Uuid::randomHex(),
    'technicalName' => 'demo',
    'active' => true,
    'translations' => ['de-DE' => ['name' => 'Beispiel'], 'en-GB' => ['name' => 'Example']],
]], Context::createDefaultContext());
```

### 4.2 Attribute approach (since 6.6.3.0)

`src/Core/Content/Example/ExampleEntity.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Translations;
use Shopware\Core\Framework\DataAbstractionLayer\Entity as EntityStruct;
use Shopware\Core\Framework\Struct\ArrayEntity;

#[Entity('swag_example', since: '1.0.0')]
class ExampleEntity extends EntityStruct
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: true)]
    public string $id;

    #[Field(type: FieldType::STRING, api: true)]
    public string $technicalName;          // not nullable => automatically Required

    #[Field(type: FieldType::BOOL, api: true)]
    public bool $active = true;

    #[Field(type: FieldType::STRING, translated: true, api: true)]
    public ?string $name = null;           // translated fields must be nullable

    /** @var array<string, ArrayEntity>|null */
    #[Translations]
    public ?array $translations = null;
}
```

Registration: autoconfigure sets `shopware.entity` for classes with `#[Entity]`, provided they are registered as a service; explicitly `$services->set(ExampleEntity::class)->tag('shopware.entity');`. Migration as in 4.1. The result is `swag_example.definition`, `swag_example_translation.definition`, `swag_example.repository`, `swag_example_translation.repository`. `#[Entity]` parameters: `name`, `parent`, `since`, `collectionClass`, `hydratorClass`, `inheritanceAware`.

⚠ UNVERIFIED: examples were not run (`dal:validate`/`dal:migration:create` not executed). Autoconfigure takes effect (verified live) with `->defaults()->autoconfigure()` in the plugin `services.php`, not without `defaults()`; the example is therefore tagged explicitly.

## 5. Pitfalls

1. Table/column missing or mismatched: `dal:validate`, runtime error "Unknown column". The DAL never migrates.
2. Definition not registered as a service: `DefinitionNotFoundException`/repository missing. Also register every mapping and translation definition.
3. Constructor with arguments in the definition: `EntityCompilerPass` calls `new $class()`; container build error.
4. `parent::__construct()` in definitions: the base constructor is `@deprecated tag:v6.8.0`, calling it from a child class triggers a deprecation (`EntityDefinition.php` lines 62-78). Omit the constructor.
5. Property and storage name swapped: `StringField('technical_name', 'technicalName')` = (column, property); `TranslatedField('name')` property only.
6. `ApiAware` forgotten: field missing in Admin/Store API and admin lists (also for `translations`).
7. `Required` on the TranslatedField without a translation record in the system language: write error.
8. Nullable mismatch with attributes: the compiler sets `Required` if the property type is not nullable (`AttributeEntityCompiler::getFlags`); translated/inherited fields must be `?type`.
9. `readonly` properties in entities: hydration fails.
10. Mapping for versioned parents (product/category) without `ReferenceVersionField`: errors on write/load.
11. DAL delete flags instead of FK constraints: `CascadeDelete` only works via the DAL; add FK `ON DELETE` in the migration as well.
12. Migration placed incorrectly: must be in `src/Migration` with namespace `<Plugin>\Migration` (`Bundle::registerMigrationPath`).
13. Uninstall: only drop tables if `!$context->keepUserData()` (mapping/translation first), see [T-03](./03-plugin-lifecycle.md).
14. `dal:migration:create` without `--bundle` writes into the core migration folder.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Entities via attribute: not in 6.5; since 6.6.3.0, field classes directly since 6.6.9.0 (according to the docs, not verifiable in the 6.7 code). 6.7: `Attribute/Entity.php`, `AttributeEntityCompiler`; newer `FieldType`s among others `ENUM`, `DATE_INTERVAL`, `TIME_ZONE`, `EMAIL`, `PRICE` (examples `Content/MeasurementSystem/DataAbstractionLayer/*Entity.php`, since 6.7.1.0).
- Service file: `services.xml` was the standard; in 6.7 `services.php`, XML is deprecated (removal `tag:v6.8.0`, `Bundle::registerContainerFile`).
- Tag registration: formerly explicit `shopware.entity.definition`, now additionally autoconfigure.
- Definition constructor: `parent::__construct()` was common → deprecated in 6.7 (removed in 6.8).
- Field order `defaultFields()`/`defineFields()`: with feature flag `v6.8.0.0` defaults first, `defineFields()` may override `created_at`/`updated_at` (`EntityDefinition::getFields()` line 150).
- `Serialized` attribute: parameter `$serializer` becomes mandatory in 6.8 (`@deprecated tag:v6.8.0`).
- `RuleAreas::CATEGORY_AREA`, `LANDING_PAGE_AREA` deprecated, removal in 6.8.
- `MappingEntityDefinition` unchanged; `dal:migration:create` unchanged (core namespace default `V6_<minor>`).

## 6. Checklist

- [ ] Entity name with vendor prefix, property lowerCamelCase, column snake_case
- [ ] Definition, entity, collection (or `#[Entity]`) present; definition without constructor
- [ ] All definitions (including translation/mapping) registered or tagged
- [ ] Migration in `src/Migration` with `created_at`/`updated_at`, FK with `ON DELETE`
- [ ] `ApiAware` on all API-relevant fields including `translations`
- [ ] Translation entity with `<entity>Id`/`<entity>` and nullable text fields
- [ ] No `readonly` properties
- [ ] `bin/console dal:validate` clean
- [ ] Uninstall drops tables only if `!keepUserData()`

## 7. Sources

Docs (https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/): `add-custom-complex-data.html`, `add-data-associations.html`, `add-data-translations.html`, `entities-via-attributes.html`, `field-inheritance.html`, `using-flags.html`, `versioning-entities.html`.

Source code (`core/Framework/`): `DataAbstractionLayer/{EntityDefinition,Entity,EntityIdTrait,EntityCollection,TranslationEntity,EntityCustomFieldsTrait,MappingEntityDefinition,EntityTranslationDefinition}.php`, `DataAbstractionLayer/Field/*`, `Field/Flag/*`, `Attribute/*`, `AttributeEntityCompiler.php`, `DependencyInjection/CompilerPass/{AttributeEntityCompilerPass,EntityCompilerPass,AutoconfigureCompilerPass}.php`, `DataAbstractionLayer/Command/{CreateMigrationCommand,DataAbstractionLayerValidateCommand,CreateEntitiesCommand,CreateHydratorCommand,RefreshIndexCommand}.php`, `MigrationQueryGenerator.php`, `Bundle.php`, `Plugin/Command/Scaffolding/Generator/EntityGenerator.php`. Core examples: `Content/Product/Aggregate/ProductManufacturer/*`, `ProductCategory/ProductCategoryDefinition.php` (mapping).

### Gaps and uncertainties

- ⚠ UNVERIFIED: examples and CLI commands (`dal:migration:create`, `dal:validate`) not executed.
- ⚠ GAP: the docs require `['entity' => 'x']` on the tag; not supported by the code (the tag attribute is not read).
- Documented (verified live): autoconfigure in the plugin takes effect with `->defaults()->autoconfigure()` in `services.php`, not with a plain `$services->set(...)` without `defaults()`; hence explicitly tagged.
- ⚠ UNVERIFIED: attribute approach: FK column name for `#[ManyToOne]` and the schema of the generated translation/mapping tables only partially traced in the compiler; compare with `dal:validate` before use.
- ⚠ UNVERIFIED: version numbers 6.6.3.0/6.6.9.0 come from the docs, not verifiable in the 6.7 code.
- ⚠ UNVERIFIED: the docs' flags reference page (`/development/troubleshooting/dal-reference/flags-reference.html`) only linked, not fetched.
- ⚠ UNVERIFIED: "mapping table without timestamps" derived from `defaultFields() === []`; some core mappings still have `created_at` (not spot-checked).
