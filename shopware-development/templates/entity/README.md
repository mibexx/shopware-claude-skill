# Template: entity (DAL entity with translation)

Shopware 6.7.15.1. Classic approach: `EntityDefinition` + `Entity` + `EntityCollection` plus translation definition/entity/collection for the translatable field `name`. The matching schema is provided by ../migration/README.md (tables `swag_example`, `swag_example_translation`); the DAL does not create tables.

## File tree

```
SwagExample/
└── src/
    ├── Core/Content/Example/
    │   ├── ExampleDefinition.php
    │   ├── ExampleEntity.php
    │   ├── ExampleCollection.php
    │   └── Aggregate/ExampleTranslation/
    │       ├── ExampleTranslationDefinition.php
    │       ├── ExampleTranslationEntity.php
    │       └── ExampleTranslationCollection.php
    └── Extension/System/Language/
        └── LanguageExampleTranslationExtension.php   # reverse side for `dal:validate`
```

Root scaffold: ../plugin/README.md. The registration is not in a file of this template, but in the excerpt below.

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample\Core\Content\Example` | Namespace/folder | `<Plugin-NS>\Core\Content\<Name>` |
| `Example*` (classes) | Name of the entity | your own name (PascalCase) |
| `swag_example` | Entity/table name (`ENTITY_NAME`) | `<prefix>_<name>`, snake_case |
| `swag_example_translation` | Translation entity/table | `<entity>_translation` |
| `swag_example_id` | FK column in the translation table | `<entity>_id` |
| `swagExampleId`, `swagExample` | Properties of the translation entity (derived from the entity name in lowerCamelCase) | derive accordingly |
| `technical_name`/`technicalName`, `active`, `name` | Example fields | your own fields |

## Registration (excerpt for `src/Resources/config/services.php`)

```php
use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationDefinition;
use SwagExample\Core\Content\Example\ExampleDefinition;

// The tag is added automatically for services with autoconfigure; without defaults() set it explicitly:
$services->set(ExampleDefinition::class)->tag('shopware.entity.definition');
$services->set(ExampleTranslationDefinition::class)->tag('shopware.entity.definition');

// Reverse side of ExampleTranslationDefinition::language (without autoconfigure set the tag explicitly):
$services->set(\SwagExample\Extension\System\Language\LanguageExampleTranslationExtension::class)
    ->tag('shopware.entity.extension');
```

The `EntityCompilerPass` creates the public services `swag_example.repository` and `swag_example_translation.repository` from this and reads the entity name via `getEntityName()` (new instance, hence an argument-less constructor). A tag attribute `['entity' => '...']` is not evaluated. Every definition (including mapping and translation definitions) must be registered.

Inject the repository (explicitly):

```php
$services->set(\SwagExample\Service\ExampleService::class)
    ->args([service('swag_example.repository')]);
```

```php
$this->exampleRepository->create([[
    'id' => Uuid::randomHex(),
    'technicalName' => 'demo',
    'active' => true,
    'translations' => ['de-DE' => ['name' => 'Beispiel'], 'en-GB' => ['name' => 'Example']],
]], Context::createDefaultContext());
```

## Integration / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample   # migration runs along
bin/console cache:clear
bin/console dal:validate                            # check definition against schema (with the language extension, no reverse finding)
```

Afterwards the entity is reachable via the Admin API (only `ApiAware` fields) and the repository. Removing the plugin cleans up tables only via `uninstall()` (template in ../plugin).

## Rules and pitfalls

- Column first, property second: `StringField('technical_name', 'technicalName')` = (storage name, property). `TranslatedField('name')` takes only the property name.
- Without `ApiAware` a field is not visible via the Admin/Store API (not even `translations`).
- Properties `protected`, not `readonly`. Translated fields are `nullable` in the entity/translation entity.
- Do not call `parent::__construct()` in definitions (the base constructor is deprecated, removal in 6.8.0).
- `CascadeDelete`/`SetNullOnDelete` only take effect on DAL deletes; additionally FK `ON DELETE` in the migration.
- `Required` on `translations` requires a record in the system language on creation.
- Accessing translations: `$entity->getTranslated()['name']` (with language chain), `getName()` current language only.

## Extensions (not included in the template)

Associations (constructors): `FkField(storage, property, ReferenceDefinition::class, 'id')`, `ManyToOneAssociationField(property, storage, Reference::class, 'id', false)`, `OneToManyAssociationField(property, Reference::class, referenceField)`, `ManyToManyAssociationField(property, Target::class, Mapping::class, localColumn, referenceColumn)` with `MappingEntityDefinition` (without timestamps, also register as `shopware.entity.definition`). Inheritance: `isInheritanceAware()`, `ParentFkField`, `ParentAssociationField`, `ChildrenAssociationField`, flag `Inherited`. Versioning: `VersionField` and `ReferenceVersionField`; migration with primary key `(id, version_id)`. Custom fields: `EntityCustomFieldsTrait` plus a `CustomFields` field.

### Alternative: entity via PHP attribute (since 6.6.3.0 according to the docs)

Instead of the three classes, one class is enough; definition, translation definition and repository are generated at compile time. The migration is still required. Do not use at the same time as the classic approach for the same entity name.

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

Registration: `$services->set(\SwagExample\Core\Content\Example\ExampleEntity::class)->tag('shopware.entity');`

## References

- ../../references/27-dal-entities.md
- ../../references/28-dal-reading-writing.md
- ../../references/29-entity-extensions-custom-fields.md
- ../../references/04-database-migrations.md
- ../../references/05-registering-services.md
- Templates: ../plugin/README.md, ../migration/README.md

## Notes and gaps

- Checked: classes, namespaces, field and flag classes, constructors against `core/Framework/DataAbstractionLayer/` (6.7.15.1); `EntityTranslationDefinition::getBaseFields()` confirms the property names `swagExampleId`/`swagExample`. Without the `#[Package]` attribute (core-internal, not needed for plugins).
- `DefinitionValidator::validateManyToOne()` (lines 759-777) requires, for the translation's `language` association, a `OneToManyAssociationField` on `LanguageDefinition` with `localField` `id` and the translation definition as reference; core definitions carry it directly in `LanguageDefinition` (lines 145-188). Plugins add it via an `EntityExtension` (`EntityDefinition::getFields()` adds extension associations with the flag `Extension` to the field set, `validateOneToMany` skips them). This has been traced in the code, but not executed.
- Verified live (review rounds 1–3, Shopware 6.7.15.1): `dal:validate` reports "No errors found", writing and reading (incl. translation) work.
- ⚠ UNVERIFIED: Attribute approach: FK column names for `#[ManyToOne]` and the exact schema of generated translation/mapping tables were only partially traced in the compiler; cross-check with `dal:validate`.
- Autoconfigure only takes effect in plugins with `->defaults()->autowire()->autoconfigure()` (verified live, see plugin template variant B); without `defaults()` it is not active, hence the explicit tag.
- ⚠ GAP: EntityExtension/BulkEntityExtension (extending core entities), indexers, custom field sets (`custom-fields.xml`), ACL privileges and an admin module for the entity are not part of this template.
- ⚠ GAP: Mapping definition and versioning are only documented as text excerpts, not as files.
