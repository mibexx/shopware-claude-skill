# Template: migration (MigrationStep)

Shopware 6.7.15.1. Eine Migration legt das Datenbankschema an. Die Vorlage erzeugt die Tabellen `swag_example` und `swag_example_translation` passend zur Vorlage ../entity/README.md (Das DAL legt nie selbst Tabellen an).

## Dateibaum

```
SwagExample/
└── src/
    └── Migration/
        └── Migration1730000000CreateSwagExampleTable.php
```

Das Verzeichnis `src/Migration` und der Namespace `<PluginNamespace>\Migration` sind fix (`Bundle::getMigrationNamespace()/getMigrationPath()`); der Pfad wird automatisch als Migration-Source registriert, eine `services.php`-Registrierung ist NICHT nötig. Wurzel-Gerüst: ../plugin/README.md

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `1730000000` (Dateiname, Klassenname, `getCreationTimestamp()`) | Unix-Timestamp, bestimmt die Reihenfolge | aktueller Timestamp (`date +%s`); alle drei Stellen identisch |
| `CreateSwagExampleTable` | Beschreibung im Klassennamen | sprechender Name |
| `SwagExample\Migration` | Namespace | `<PluginNamespace>\Migration` |
| `swag_example`, `swag_example_translation` | Tabellennamen (= Entity-Namen) | `<prefix>_<name>` |
| Spalten | `technical_name`, `active`, `name` | passend zur Entity-Definition |

## Regeln

- Dateiname = Klassenname = `Migration<Timestamp><Beschreibung>`; die Reihenfolge entscheidet allein `getCreationTimestamp()` (Werte `< 1` oder `>= 2147483647` sind unplausibel und werfen). Datei, Klasse und Namespace müssen übereinstimmen: jede `.php`-Datei in `src/Migration/` muss ladbar sein.
- Idempotent schreiben (`IF NOT EXISTS`, Hilfsmethoden `addColumn`, `dropColumnIfExists`, `dropTableIfExists`, `dropIndexIfExists`, `dropForeignKeyIfExists`; Prüfungen `columnExists($connection, $table, $column)` und `indexExists($connection, $table, $index)`; alle `protected` in `MigrationStep`, Aufruf per `$this->`. `TableHelper` ist `@internal`/`@final` und nicht für Plugins gedacht).
- Veröffentlichte Migrationen nie ändern, stattdessen eine neue anlegen. Kein Rollback, keine umschließende Transaktion.
- Rückwärtskompatibel (Expand/Contract): Neues in `update()`, Destruktives (DROP, Rename) erst später in `updateDestructive()`. `plugin:install` und `plugin:update` führen automatisch NUR `update()` aus (bei `plugin:activate` nur mit `setAutoMigrate(true)` in `activate()`), `updateDestructive()` nie.
- Kurz halten (Guideline: unter 10 Sekunden).
- Fremdschlüssel-`ON DELETE` zusätzlich zu den DAL-Flags setzen (`CascadeDelete` wirkt nur beim Löschen über das DAL).
- Mapping-Tabellen (n:m) haben keine DAL-Timestamps; siehe ../entity/README.md.

## Einbindung / CLI

```bash
# Skelett erzeugen (Template enthaelt update(), aber kein updateDestructive())
bin/console database:create-migration -p SwagExample --name CreateSwagExampleTable

# oder aus dem Entity-Schema generieren (Plugin muss aktiv sein; ohne --bundle wuerde in den CORE geschrieben!)
bin/console dal:migration:create swag_example,swag_example_translation --bundle=SwagExample

# ausfuehren
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample   # laeuft automatisch bei install/update
bin/console database:migrate SwagExample --all
bin/console database:migrate-destructive SwagExample --all                         # nur manuell
bin/console dal:validate                                                           # Schema gegen Definitionen pruefen
```

Aufräumen beim Deinstallieren: `Plugin::uninstall()` (Vorlage in ../plugin/SwagExample/src/SwagExample.php) löscht die Tabellen nur bei `!keepUserData()`. Ohne `keepUserData` entfernt der Core die Zeilen aus der Tabelle `migration`, eine Neuinstallation führt die Migrationen erneut aus. Automatik pro Lifecycle-Schritt abschaltbar mit `InstallContext::setAutoMigrate(false)` und `getMigrationCollection()->migrateInPlace()`.

## Verweise

- ../../references/04-datenbank-migrationen.md
- ../../references/03-plugin-lifecycle.md
- ../../references/27-dal-entities.md
- Vorlagen: ../plugin/README.md, ../entity/README.md

## Hinweise und Lücken

- Deprecation: `MigrationStep::indexExists()` (`#[ExceptionChange(v6.8.0)]`); mit `v6.8.0.0` gibt er `false` statt `TableNotFoundException`. Weiter `$this->indexExists()` nutzen; `TableHelper` (`@internal`, `@final`) nicht direkt aufrufen.
- Geprüft: Methodensignaturen gegen `core/Framework/Migration/MigrationStep.php`; SQL-Muster aus dem Scaffold-Stub und der Recherche.
- ⚠ UNGEPRÜFT: Das SQL wurde nicht gegen eine Datenbank ausgeführt (kein PHP/DB lokal).
- ⚠ UNGEPRÜFT: Ob `dal:migration:create` bei bereits angelegter Tabelle keine Datei erzeugt (Annahme, Delta zur DB); Ergebnis des `@internal`-Befehls immer prüfen.
- ⚠ LÜCKE: Versionierte Entities (`version_id`-Primärschlüssel, `ReferenceVersionField`) und Vererbung (`parent_id`) sind nur als Hinweis in ../entity/README.md, nicht als Migration enthalten.
