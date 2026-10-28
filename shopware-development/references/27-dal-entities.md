# Eigene Entities im DAL: Definition, Entity, Collection, Associations (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eine neue Datenbank-Entity im Plugin entstehen soll: klassisch mit `EntityDefinition`/`Entity`/`EntityCollection` oder per PHP-Attribut `#[Entity]`, inklusive Übersetzungen, Associations (1:n, n:1, n:m), Flags, Vererbung und Versionierung.

Abgrenzung: Lesen/Schreiben/Indexer → [T-28](./28-dal-lesen-schreiben.md). Bestehende Entities erweitern, Custom Fields → [T-29](./29-entity-extensions-custom-fields.md). Migrationen → [T-04](./04-datenbank-migrationen.md). Uninstall/Tabellen löschen → [T-03](./03-plugin-lifecycle.md). Services → [T-05](./05-services-registrieren.md). Admin-Seite dazu → [T-24](./24-admin-module.md), [T-26](./26-admin-datenhandling.md). Gerüst: [../templates/entity/README.md](../templates/entity/README.md), [../templates/migration/README.md](../templates/migration/README.md).

## 2. Konzepte

- Klassisch: drei PHP-Klassen (`EntityDefinition`, `Entity`, `EntityCollection`) plus eigene Migration. Das DAL legt KEINE Tabellen an; `bin/console dal:validate` prüft Schema gegen Definition.
- **Registrierung**: Tag `shopware.entity.definition`. In 6.7 setzt Autoconfigure ihn automatisch für Services, die `EntityDefinition` erweitern (`AutoconfigureCompilerPass`, `registerForAutoconfiguration(EntityDefinition::class)`). Das Tag-Attribut `entity="..."` wird vom `EntityCompilerPass` NICHT ausgewertet; der Name kommt per `new $class()->getEntityName()` (`EntityCompilerPass` Z. 63-65: `$instance = new $class()`, `$instance->getEntityName()`). Folge: argumentloser Konstruktor in der Definition.
- `EntityCompilerPass` erzeugt Service `<entity_name>.repository` (öffentlich, `EntityRepository`).
- **Attribut-Entities** (`#[Entity('name')]`, Tag `shopware.entity`, ebenfalls per Autoconfigure): Definition, Translation-, Mapping-Definition und Repository werden zur Compile-Zeit generiert (`AttributeEntityCompiler`, `AttributeEntityCompilerPass`). Kein Getter/Setter nötig. Die Migration schreibt man trotzdem selbst (oder `dal:migration:create`).
- `created_at`/`updated_at` kommen aus `EntityDefinition::defaultFields()` (`CreatedAtField`, `UpdatedAtField`); die Spalten müssen in der Migration existieren.
- Übersetzungen: `*_translation`-Tabelle + `EntityTranslationDefinition` + `TranslatedField` + `TranslationsAssociationField`. Vererbung: `Inherited` + `ParentFkField`/`ParentAssociationField`/`ChildrenAssociationField` + `isInheritanceAware()`. Versionierung: `VersionField` (+ `ReferenceVersionField` in Fremd-/Mappingtabellen).
- Entscheidungshilfe: klassisch für volle Kontrolle (Hydrator, Extensions, Vererbung, eigene Felder); Attribut-Weg für schlanke Entities.
- CLI-Namen: `dal:migration:create`, `dal:create:entities`, `dal:create:hydrators`, `dal:validate`, `dal:refresh:index` (NICHT `dal:create:*` für Migrationen).

Verzeichnisbaum (Plugin `SwagExample`, Namespace `SwagExample`):

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

Attribut-Weg: nur `Core/Content/Example/ExampleEntity.php` mit `#[Entity('swag_example')]`, Migration und `services.php`. Migrationsverzeichnis fix `<Plugin-Namespace>\Migration` → `src/Migration` (`Framework/Bundle.php`).

## 3. Anleitung

1. Entity-Namen festlegen: `<vendor-prefix>_<name>` (snake_case, `swag_example`); Properties lowerCamelCase, Spalten snake_case.
2. Definition schreiben (oder Attribut-Entity). Primärschlüssel: `IdField` + `PrimaryKey` + `Required`. Ohne `ApiAware` ist ein Feld nicht über Admin-/Store-API les-/schreibbar.
3. Migration anlegen, entweder von Hand (`MigrationStep`, Timestamp = Dateiname) oder generiert (Plugin mit Definition vorher installieren/aktivieren):

```bash
bin/console dal:migration:create swag_example,swag_example_translation --bundle=SwagExample
```

   Argument `entities` = Kommaliste; Optionen `--bundle` (Plugin-Name), `--namespace` (z. B. `V6_7`, nur ohne Bundle), `--package`. Ohne `--bundle` landet die Datei im Core-Migrationsordner (nur Core-Entwicklung!). Der Befehl vergleicht mit dem DB-Ist-Zustand (`MigrationQueryGenerator`), ist `@internal`; Ergebnis immer prüfen (FK, Collation, Indizes). Annahme: Bei bereits angelegter Tabelle ohne Delta entsteht keine Datei.
4. Definition-Services registrieren (alle: Haupt-, Translation-, Mapping-Definition).
5. Plugin installieren und Schema prüfen:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console database:migrate --all SwagExample    # bei späteren Migrationen
bin/console cache:clear
bin/console dal:validate
```

6. Optional: `dal:create:entities` (Schema-Entity-Klassen nach `<rootDir>/../schema/`, Core-Entwicklung), `dal:create:hydrators` (Hydrator für Core-Definitionen), `dal:refresh:index` (siehe T-28), `bin/console plugin:create SwagExample Swag --entities` (kein Befehl `plugin:scaffold`; `--entities` ist ein reiner Schalter ohne Wert, die Entity-Namen PascalCase/kommagetrennt werden interaktiv abgefragt, nicht-interaktiv entsteht nichts; `EntityGenerator` schreibt Migration, Definition, Entity, Collection und den Tag `['entity' => '...']`, das Attribut wird nicht ausgewertet, harmlos).

### 3.1 Associations (Konstruktoren aus `Field/`)

| Feld | Konstruktor |
|---|---|
| `FkField` | `(storageName, propertyName, referenceClass, referenceField = 'id')` |
| `ManyToOneAssociationField` | `(propertyName, storageName, referenceClass, referenceField = 'id', autoload = false)` |
| `OneToManyAssociationField` | `(propertyName, referenceClass, referenceField, localField = 'id')` |
| `OneToOneAssociationField` | `(propertyName, storageName, referenceField, referenceClass, autoload = true)` |
| `ManyToManyAssociationField` | `(propertyName, toManyDefinitionClass, mappingDefinition, mappingLocalColumn, mappingReferenceColumn, sourceColumn = 'id', referenceField = 'id')` |
| `TranslationsAssociationField` | `(referenceClass, referenceField, propertyName = 'translations', localField = 'id')`, setzt `CascadeDelete` |
| `TranslatedField` | `(propertyName, useForSorting = false)` |
| `ReferenceVersionField` | `(definition, storageName = null)`, Default-Spalte `<entity>_version_id` |

1:n/n:1:

```php
// ExampleDefinition
(new FkField('example_category_id', 'categoryId', ExampleCategoryDefinition::class))->addFlags(new ApiAware()),
(new ManyToOneAssociationField('category', 'example_category_id', ExampleCategoryDefinition::class, 'id', false))->addFlags(new ApiAware()),

// ExampleCategoryDefinition
(new OneToManyAssociationField('examples', ExampleDefinition::class, 'example_category_id'))
    ->addFlags(new ApiAware(), new SetNullOnDelete()),
```

n:m mit Mapping (Tabelle `swag_example_tag`, PK `(example_id, tag_id)`):

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

`MappingEntityDefinition` liefert keine `defaultFields()` (kein `created_at`/`updated_at`) und wirft bei `getEntityClass()`/`getCollectionClass()` eine `MappingEntityClassesException`. Mapping-Definition ebenfalls als `shopware.entity.definition` registrieren.

Attribut-Weg: `#[ManyToMany(entity: 'tag', onDelete: OnDelete::CASCADE, api: true)] public ?array $tags`, `#[ManyToOne(entity: 'swag_example_category', onDelete: OnDelete::SET_NULL, api: true)]`, `#[OneToMany(entity: 'swag_example_item', ref: 'swag_example_id', onDelete: OnDelete::CASCADE, api: true)]`. Mapping-Name: alphabetisch sortiertes Paar `[entity, ziel]` mit `_` (`AttributeEntityCompiler::mappingName()`, überschreibbar per `mapping:`), Spalten `<entity>_id` und `<ziel>_id`.

### 3.2 Flags (Auswahl, `Field/Flag/`)

| Flag | Bedeutung |
|---|---|
| `PrimaryKey`, `Required` | PK / Pflichtfeld |
| `ApiAware(...sources)` | über API sichtbar/schreibbar; ohne Argument Admin- + Store-API, mit `AdminApiSource::class` nur Admin |
| `ApiCriteriaAware` | umgeht `ApiAware` für Criteria (Sonderfall Preisfelder) |
| `Runtime(dependsOn)` | zur Laufzeit befüllt, nicht in DB; Pflicht für Nicht-Assoziations-Felder in `EntityExtension` |
| `Computed` | per Indexer/extern berechnet, nicht über DAL schreibbar |
| `Inherited`, `ReverseInherited` | Vererbung |
| `CascadeDelete`, `RestrictDelete`, `SetNullOnDelete` | Löschverhalten auf DAL-Ebene (ersetzt keine DB-Constraints) |
| `WriteProtected` | nur mit bestimmten Rechten/Quellen schreibbar |
| `AllowHtml` | HTML erlaubt (sonst Sanitizing); XSS-Risiko |
| `SearchRanking` | Gewicht in der Suche (siehe [T-17](./17-listing-suche-elasticsearch.md)) |
| `Extension` | Wert in `Entity::$extensions` |
| `Since`, `Deprecated` | Metadaten |

Weitere Spezialflags (`Immutable`, `NoConstraint`, `IgnoreInOpenapiSchema`, `ResetOnClone`, `RuleAreas`, `Choice`, `AsArray`, `DoNotUseContext`, `IgnoreInUnusedMediaSearch`) im Quellcode. `->addFlags(...)` fügt hinzu, `->setFlags(...)` ersetzt komplett.

### 3.3 Übersetzungen

- Hauptdefinition: `TranslatedField('name')` (Property-Name, nicht Storage-Name) + `TranslationsAssociationField(TranslationDefinition::class, '<entity>_id')`.
- Translation-Definition: `EntityTranslationDefinition` + `getParentDefinitionClass()`; `getBaseFields()` liefert FK (`<entity>_id`), `language_id`, Rückassoziationen und bei versionierten Eltern `ReferenceVersionField`. Translation-Entity braucht Properties `<camelCaseEntityName>Id` und `<camelCaseEntityName>` (`swag_example` → `swagExampleId`, `swagExample`) und erbt `TranslationEntity`. `dal:validate` verlangt zur ManyToOne-Assoziation `language` eine Rückseite (`OneToManyAssociationField` mit `language_id` auf `LanguageDefinition`; `DefinitionValidator::validateManyToOne`): in Plugins per `EntityExtension` auf `language` ergänzen (Vorlage `templates/entity`, `LanguageExampleTranslationExtension`).
- Übersetzte Felder in der Translation-Definition nullable halten; `Required` am `TranslatedField` bzw. an der Translation-Definition macht `translations` im Hauptdatensatz zum Pflichtfeld in der Systemsprache.
- Zugriff: `$entity->getTranslated()['name']` (mit Fallback über Sprachkette); `getName()` liefert nur den Wert der aktuellen Sprache (ggf. `null`). Mehr in [T-18](./18-snippets-uebersetzungen.md) und T-28.
- Doku zeigt `getParentDefinitionClass()` als `public`, im Code `protected` (beides zulässig).

### 3.4 Vererbung und Versionierung

Vererbung:

```php
public function isInheritanceAware(): bool { return true; }

new ParentFkField(self::class),
new ParentAssociationField(self::class, 'id'),
new ChildrenAssociationField(self::class),
(new StringField('description', 'description'))->addFlags(new Inherited()),
```

Migration: `parent_id BINARY(16) NULL`, vererbbare Spalten nullable. Übersetzungen: `Inherited` an `TranslatedField` UND `TranslationsAssociationField`. Entity: `parent`, `parentId`, `children` ergänzen. Attribut-Weg: `#[Entity('x', parent: 'x', inheritanceAware: true)]`, `#[Inherited]`/`#[ReverseInherited]`. Inherited-Associations laden nur mit `Criteria::addAssociation` + `Context::considerInheritance` (T-28).

Versionierung:

```sql
ALTER TABLE `swag_example`
    ADD `version_id` BINARY(16) NOT NULL AFTER `id`,
    ADD PRIMARY KEY `id_version_id` (`id`, `version_id`),
    DROP INDEX `PRIMARY`;
```

```php
new VersionField(),                                    // PrimaryKey + Required automatisch
new ReferenceVersionField(self::class, 'parent_version_id')
```

`isVersionAware()` prüft das Feld `versionId`. In Fremdtabellen/Mappings `ReferenceVersionField(Ziel::class)` (Default-Spalte `<entity>_version_id`) + `PrimaryKey`/`Required` in Mappings (Vorbild `ProductCategoryDefinition`). Attribute `#[Version]`, `#[ReferenceVersion(entity: '...')]`. API: `EntityRepository::createVersion()`, `merge()`.

### 3.5 Entity-Besonderheiten

- Properties mindestens `protected`; KEIN `readonly` (Hydrator setzt per Reflection/`__set`).
- `EntityIdTrait` liefert `$id` + `getId()/setId()`; `EntityCustomFieldsTrait` + `new CustomFields()` bzw. `#[CustomFields(true)]` für Custom Fields.

## 4. Beispiele

### 4.1 Klassisch: Entity mit Übersetzung

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

    // Der Tag wird per Autoconfigure ergänzt; explizit ist er harmlos und dokumentiert die Absicht.
    $services->set(ExampleDefinition::class)->tag('shopware.entity.definition');
    $services->set(ExampleTranslationDefinition::class)->tag('shopware.entity.definition');
};
```

Nutzung (Repository-Details T-28; Service-Argument `new Reference('swag_example.repository')` bzw. `service('swag_example.repository')`):

```php
$this->exampleRepository->create([[
    'id' => Uuid::randomHex(),
    'technicalName' => 'demo',
    'active' => true,
    'translations' => ['de-DE' => ['name' => 'Beispiel'], 'en-GB' => ['name' => 'Example']],
]], Context::createDefaultContext());
```

### 4.2 Attributweg (ab 6.6.3.0)

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
    public string $technicalName;          // nicht nullable => automatisch Required

    #[Field(type: FieldType::BOOL, api: true)]
    public bool $active = true;

    #[Field(type: FieldType::STRING, translated: true, api: true)]
    public ?string $name = null;           // übersetzte Felder müssen nullable sein

    /** @var array<string, ArrayEntity>|null */
    #[Translations]
    public ?array $translations = null;
}
```

Registrierung: Autoconfigure setzt `shopware.entity` für Klassen mit `#[Entity]`, sofern als Service registriert; explizit `$services->set(ExampleEntity::class)->tag('shopware.entity');`. Migration wie in 4.1. Es entstehen `swag_example.definition`, `swag_example_translation.definition`, `swag_example.repository`, `swag_example_translation.repository`. `#[Entity]`-Parameter: `name`, `parent`, `since`, `collectionClass`, `hydratorClass`, `inheritanceAware`.

⚠ UNGEPRÜFT: Beispiele wurden nicht ausgeführt (`dal:validate`/`dal:migration:create` nicht gelaufen). Autoconfigure greift (live bestätigt) mit `->defaults()->autoconfigure()` in der Plugin-`services.php`, ohne `defaults()` nicht; im Beispiel daher explizit getaggt.

## 5. Fallstricke

1. Tabelle/Spalte fehlt oder passt nicht: `dal:validate`, Laufzeitfehler "Unknown column". Das DAL migriert nie.
2. Definition nicht als Service registriert: `DefinitionNotFoundException`/Repository fehlt. Auch jede Mapping- und Translation-Definition registrieren.
3. Konstruktor mit Argumenten in der Definition: `EntityCompilerPass` ruft `new $class()`; Container-Build-Fehler.
4. `parent::__construct()` in Definitionen: Basiskonstruktor ist `@deprecated tag:v6.8.0`, Aufruf aus Kindklasse löst Deprecation aus (`EntityDefinition.php` Z. 62-78). Konstruktor weglassen.
5. Property- vs. Storage-Name vertauscht: `StringField('technical_name', 'technicalName')` = (Spalte, Property); `TranslatedField('name')` nur Property.
6. `ApiAware` vergessen: Feld fehlt in Admin-/Store-API und Admin-Listen (auch bei `translations`).
7. `Required` am TranslatedField ohne Translation-Datensatz in der Systemsprache: Schreibfehler.
8. Nullable-Mismatch bei Attributen: Compiler setzt `Required`, wenn der Property-Typ nicht nullable ist (`AttributeEntityCompiler::getFlags`); Translated-/vererbte Felder müssen `?type` sein.
9. `readonly`-Properties in Entities: Hydrierung schlägt fehl.
10. Mapping bei versionierten Eltern (Produkt/Kategorie) ohne `ReferenceVersionField`: Fehler beim Schreiben/Laden.
11. DAL-Löschflags statt FK-Constraints: `CascadeDelete` wirkt nur über das DAL; FK-`ON DELETE` zusätzlich in der Migration.
12. Migration falsch abgelegt: muss in `src/Migration` mit Namespace `<Plugin>\Migration` liegen (`Bundle::registerMigrationPath`).
13. Uninstall: Tabellen nur bei `!$context->keepUserData()` löschen (Mapping/Translation zuerst), siehe [T-03](./03-plugin-lifecycle.md).
14. `dal:migration:create` ohne `--bundle` schreibt in den Core-Migrationsordner.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Entities per Attribut: nicht in 6.5; ab 6.6.3.0, Feldklassen direkt ab 6.6.9.0 (laut Doku, im 6.7-Code nicht rückprüfbar). 6.7: `Attribute/Entity.php`, `AttributeEntityCompiler`; neuere `FieldType` u. a. `ENUM`, `DATE_INTERVAL`, `TIME_ZONE`, `EMAIL`, `PRICE` (Beispiele `Content/MeasurementSystem/DataAbstractionLayer/*Entity.php`, since 6.7.1.0).
- Service-Datei: `services.xml` war Standard; in 6.7 `services.php`, XML ist deprecated (Entfernung `tag:v6.8.0`, `Bundle::registerContainerFile`).
- Tag-Registrierung: früher explizit `shopware.entity.definition`, jetzt zusätzlich Autoconfigure.
- Definition-Konstruktor: `parent::__construct()` üblich → in 6.7 deprecated (entfällt 6.8).
- Feldreihenfolge `defaultFields()`/`defineFields()`: Mit Feature-Flag `v6.8.0.0` zuerst Default, `defineFields()` darf `created_at`/`updated_at` überschreiben (`EntityDefinition::getFields()` Z. 150).
- `Serialized`-Attribut: Parameter `$serializer` wird in 6.8 Pflicht (`@deprecated tag:v6.8.0`).
- `RuleAreas::CATEGORY_AREA`, `LANDING_PAGE_AREA` deprecated, Entfernung 6.8.
- `MappingEntityDefinition` unverändert; `dal:migration:create` unverändert (Core-Namensraum-Default `V6_<minor>`).

## 6. Checkliste

- [ ] Entity-Name mit Vendor-Präfix, Property lowerCamelCase, Spalte snake_case
- [ ] Definition, Entity, Collection (bzw. `#[Entity]`) vorhanden; Definition ohne Konstruktor
- [ ] Alle Definitionen (auch Translation/Mapping) registriert bzw. getaggt
- [ ] Migration in `src/Migration` mit `created_at`/`updated_at`, FK mit `ON DELETE`
- [ ] `ApiAware` an allen API-relevanten Feldern inkl. `translations`
- [ ] Translation-Entity mit `<entity>Id`/`<entity>` und nullable Textfeldern
- [ ] Keine `readonly`-Properties
- [ ] `bin/console dal:validate` sauber
- [ ] Uninstall löscht Tabellen nur bei `!keepUserData()`

## 7. Quellen

Doku (https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/): `add-custom-complex-data.html`, `add-data-associations.html`, `add-data-translations.html`, `entities-via-attributes.html`, `field-inheritance.html`, `using-flags.html`, `versioning-entities.html`.

Quellcode (`core/Framework/`): `DataAbstractionLayer/{EntityDefinition,Entity,EntityIdTrait,EntityCollection,TranslationEntity,EntityCustomFieldsTrait,MappingEntityDefinition,EntityTranslationDefinition}.php`, `DataAbstractionLayer/Field/*`, `Field/Flag/*`, `Attribute/*`, `AttributeEntityCompiler.php`, `DependencyInjection/CompilerPass/{AttributeEntityCompilerPass,EntityCompilerPass,AutoconfigureCompilerPass}.php`, `DataAbstractionLayer/Command/{CreateMigrationCommand,DataAbstractionLayerValidateCommand,CreateEntitiesCommand,CreateHydratorCommand,RefreshIndexCommand}.php`, `MigrationQueryGenerator.php`, `Bundle.php`, `Plugin/Command/Scaffolding/Generator/EntityGenerator.php`. Core-Beispiele: `Content/Product/Aggregate/ProductManufacturer/*`, `ProductCategory/ProductCategoryDefinition.php` (Mapping).

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele und CLI-Befehle (`dal:migration:create`, `dal:validate`) nicht ausgeführt.
- ⚠ LÜCKE: Doku verlangt `['entity' => 'x']` am Tag; im Code unbelegt (Tag-Attribut wird nicht gelesen).
- Belegt (live bestätigt): Autoconfigure im Plugin greift mit `->defaults()->autoconfigure()` in `services.php`, bei reinem `$services->set(...)` ohne `defaults()` nicht; deshalb explizit getaggt.
- ⚠ UNGEPRÜFT: Attributweg: FK-Spaltenname bei `#[ManyToOne]` und Schema der generierten Translation-/Mapping-Tabellen nur teilweise im Compiler nachvollzogen; vor Einsatz mit `dal:validate` abgleichen.
- ⚠ UNGEPRÜFT: Versionsangaben 6.6.3.0/6.6.9.0 stammen aus der Doku, im 6.7-Code nicht prüfbar.
- ⚠ UNGEPRÜFT: Flags-Referenz-Seite der Doku (`/development/troubleshooting/dal-reference/flags-reference.html`) nur verlinkt, nicht abgerufen.
- ⚠ UNGEPRÜFT: "Mapping-Tabelle ohne Timestamps" aus `defaultFields() === []` abgeleitet; Core-Mappings haben teils trotzdem `created_at` (nicht stichprobenhaft geprüft).
