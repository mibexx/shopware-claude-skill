# Template: entity (DAL-Entity mit Übersetzung)

Shopware 6.7.15.1. Klassischer Weg: `EntityDefinition` + `Entity` + `EntityCollection` plus Translation-Definition/-Entity/-Collection für das übersetzbare Feld `name`. Das passende Schema liefert ../migration/README.md (Tabellen `swag_example`, `swag_example_translation`); das DAL legt keine Tabellen an.

## Dateibaum

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
        └── LanguageExampleTranslationExtension.php   # Rückseite für `dal:validate`
```

Wurzel-Gerüst: ../plugin/README.md. Die Registrierung steht nicht in einer Datei dieser Vorlage, sondern im Ausschnitt unten.

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample\Core\Content\Example` | Namespace/Ordner | `<Plugin-NS>\Core\Content\<Name>` |
| `Example*` (Klassen) | Name der Entity | eigener Name (PascalCase) |
| `swag_example` | Entity-/Tabellenname (`ENTITY_NAME`) | `<prefix>_<name>`, snake_case |
| `swag_example_translation` | Translation-Entity/Tabelle | `<entity>_translation` |
| `swag_example_id` | FK-Spalte in der Translation-Tabelle | `<entity>_id` |
| `swagExampleId`, `swagExample` | Properties der Translation-Entity (aus dem Entity-Namen in lowerCamelCase abgeleitet) | entsprechend ableiten |
| `technical_name`/`technicalName`, `active`, `name` | Beispielfelder | eigene Felder |

## Registrierung (Ausschnitt für `src/Resources/config/services.php`)

```php
use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationDefinition;
use SwagExample\Core\Content\Example\ExampleDefinition;

// Tag wird bei Services mit autoconfigure automatisch ergaenzt; ohne defaults() explizit setzen:
$services->set(ExampleDefinition::class)->tag('shopware.entity.definition');
$services->set(ExampleTranslationDefinition::class)->tag('shopware.entity.definition');

// Rueckseite zu ExampleTranslationDefinition::language (ohne Autoconfigure Tag explizit setzen):
$services->set(\SwagExample\Extension\System\Language\LanguageExampleTranslationExtension::class)
    ->tag('shopware.entity.extension');
```

Der `EntityCompilerPass` erzeugt daraus die öffentlichen Services `swag_example.repository` und `swag_example_translation.repository` und liest den Entity-Namen über `getEntityName()` (neue Instanz, daher argumentloser Konstruktor). Ein Tag-Attribut `['entity' => '...']` wird nicht ausgewertet. Jede Definition (auch Mapping- und Translation-Definitionen) muss registriert sein.

Repository injizieren (explizit):

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

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample   # Migration laeuft mit
bin/console cache:clear
bin/console dal:validate                            # Definition gegen Schema pruefen (mit der Language-Extension ohne Reverse-Befund)
```

Danach ist die Entity über die Admin-API (nur `ApiAware`-Felder) und das Repository erreichbar. Löschen des Plugins räumt Tabellen nur über `uninstall()` auf (Vorlage in ../plugin).

## Regeln und Fallstricke

- Spalte zuerst, Property danach: `StringField('technical_name', 'technicalName')` = (Storage-Name, Property). `TranslatedField('name')` nimmt nur den Property-Namen.
- Ohne `ApiAware` ist ein Feld nicht über Admin-/Store-API sichtbar (auch nicht `translations`).
- Properties `protected`, nicht `readonly`. Übersetzte Felder sind in Entity/Translation-Entity `nullable`.
- `parent::__construct()` in Definitionen nicht aufrufen (Basis-Konstruktor ist deprecated, Entfernung 6.8.0).
- `CascadeDelete`/`SetNullOnDelete` wirken nur beim DAL-Löschen; FK-`ON DELETE` zusätzlich in der Migration.
- `Required` an `translations` verlangt beim Anlegen einen Datensatz in der Systemsprache.
- Zugriff auf Übersetzungen: `$entity->getTranslated()['name']` (mit Sprachkette), `getName()` nur aktuelle Sprache.

## Erweiterungen (nicht in der Vorlage enthalten)

Assoziationen (Konstruktoren): `FkField(storage, property, ReferenceDefinition::class, 'id')`, `ManyToOneAssociationField(property, storage, Reference::class, 'id', false)`, `OneToManyAssociationField(property, Reference::class, referenceField)`, `ManyToManyAssociationField(property, Target::class, Mapping::class, localColumn, referenceColumn)` mit `MappingEntityDefinition` (ohne Timestamps, ebenfalls als `shopware.entity.definition` registrieren). Vererbung: `isInheritanceAware()`, `ParentFkField`, `ParentAssociationField`, `ChildrenAssociationField`, Flag `Inherited`. Versionierung: `VersionField` und `ReferenceVersionField`; Migration mit Primärschlüssel `(id, version_id)`. Custom Fields: `EntityCustomFieldsTrait` plus `CustomFields`-Feld.

### Alternative: Entity per PHP-Attribut (seit 6.6.3.0 laut Doku)

Statt der drei Klassen genügt eine Klasse; Definition, Translation-Definition und Repository werden zur Compile-Zeit erzeugt. Die Migration bleibt nötig. Nicht gleichzeitig mit dem klassischen Weg für denselben Entity-Namen verwenden.

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
    public ?string $name = null;           // uebersetzte Felder muessen nullable sein

    /** @var array<string, ArrayEntity>|null */
    #[Translations]
    public ?array $translations = null;
}
```

Registrierung: `$services->set(\SwagExample\Core\Content\Example\ExampleEntity::class)->tag('shopware.entity');`

## Verweise

- ../../references/27-dal-entities.md
- ../../references/28-dal-lesen-schreiben.md
- ../../references/29-entity-extensions-custom-fields.md
- ../../references/04-datenbank-migrationen.md
- ../../references/05-services-registrieren.md
- Vorlagen: ../plugin/README.md, ../migration/README.md

## Hinweise und Lücken

- Geprüft: Klassen, Namespaces, Feld- und Flag-Klassen, Konstruktoren gegen `core/Framework/DataAbstractionLayer/` (6.7.15.1); `EntityTranslationDefinition::getBaseFields()` bestätigt die Property-Namen `swagExampleId`/`swagExample`. Ohne `#[Package]`-Attribut (Core-intern, für Plugins nicht nötig).
- `DefinitionValidator::validateManyToOne()` (Z. 759-777) verlangt für die `language`-Assoziation der Translation eine `OneToManyAssociationField` auf `LanguageDefinition` mit `localField` `id` und der Translation-Definition als Referenz; Core-Definitionen tragen sie direkt in `LanguageDefinition` (Z. 145-188). Plugins ergänzen sie per `EntityExtension` (`EntityDefinition::getFields()` hängt Extension-Assoziationen mit Flag `Extension` ins Feldset, `validateOneToMany` überspringt sie). Das ist am Code nachvollzogen, aber nicht ausgeführt.
- Live bestätigt (Review-Runden 1–3, Shopware 6.7.15.1): `dal:validate` meldet „No errors found“, Schreiben und Lesen (inkl. Translation) funktionieren.
- ⚠ UNGEPRÜFT: Attributweg: FK-Spaltennamen bei `#[ManyToOne]` und das genaue Schema generierter Translation-/Mapping-Tabellen wurden nur teilweise im Compiler nachvollzogen; mit `dal:validate` abgleichen.
- Autoconfigure greift in Plugins nur mit `->defaults()->autowire()->autoconfigure()` (live bestätigt, siehe Plugin-Vorlage Variante B); ohne `defaults()` bleibt es nicht aktiv, daher der explizite Tag.
- ⚠ LÜCKE: EntityExtension/BulkEntityExtension (Core-Entities erweitern), Indexer, Custom-Field-Sets (`custom-fields.xml`), ACL-Privilegien und Admin-Modul für die Entity sind nicht Teil dieser Vorlage.
- ⚠ LÜCKE: Mapping-Definition und Versionierung sind nur als Textausschnitte dokumentiert, nicht als Dateien.
