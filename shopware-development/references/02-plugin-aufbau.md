# Plugin-Aufbau, composer.json und Abhängigkeiten (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift beim Anlegen eines neuen Plugins: Pflichtfelder der `composer.json`, Plugin-Klasse, Verzeichnisstruktur, Composer-/npm-Abhängigkeiten und Abhängigkeiten zwischen Plugins.

Abgrenzung: Einordnung ins Projekt in [T-01 Projektstruktur](./01-projektstruktur-dev-umgebung.md); Install/Update/Uninstall-Hooks in [T-03 Plugin-Lifecycle](./03-plugin-lifecycle.md); Service-Registrierung in [T-05](./05-services-registrieren.md); Build-Details in [T-49](./49-build-deployment.md). Gerüst: [../templates/plugin/README.md](../templates/plugin/README.md).

## 2. Konzepte

- Ein Plugin ist ein Composer-Paket mit `"type": "shopware-platform-plugin"` (`PluginFinder::COMPOSER_TYPE`).
- **Pflichtfelder laut Code** (`PluginFinder::isShopwarePluginType()`, `isPluginComposerValid()`, `PluginService::refreshPlugins()`):
  1. `type` = `shopware-platform-plugin`
  2. `extra.shopware-plugin-class` (FQCN, nicht leer)
  3. `extra.label` (nicht leer, Objekt je Locale, z. B. `de-DE`, `en-GB`). Die Doku nennt es nicht zwingend, es gilt der Code.
  4. `autoload.psr-4` oder `psr-0` (sonst Fehler „Neither a PSR-4 nor PSR-0 autoload information is given.")
  5. Die composer.json muss die Composer-Validierung bestehen (`PackageProvider`, `ConfigValidator`): Fehler brechen ab, Warnungen werden ausgegeben. Der Scaffold-Stub enthält `name`, `description`, `version`, `license`, `require.shopware/core`.
- Optionale `extra`-Felder (`PluginService`): `description`, `manufacturerLink`, `supportLink` (je Locale-Objekt), `copyright`, `plugin-icon` (Default `src/Resources/config/plugin.png`).
- Plugin-Klasse: `abstract class Plugin extends Bundle` (`core/Framework/Plugin.php`), Bundle erweitert Symfony-`Bundle`. Der Konstruktor ist `final` und `@internal`: nicht überschreiben, nichts darüber injizieren.
- `executeComposerCommands(): bool` (Default `false`): bei `true` führt Shopware bei Install/Update `composer require <name>:<version>` und bei Uninstall `composer remove` aus.

### Verzeichnisbaum

```text
custom/plugins/SwagExample/               (oder custom/static-plugins/...)
├── composer.json                         # Pflicht
├── src/
│   ├── SwagExample.php                   # Plugin-Klasse (extends Plugin)
│   ├── Resources/
│   │   ├── config/{services.php,routes.php,config.xml,plugin.png}
│   │   ├── views/                        # Twig (storefront/...)
│   │   └── app/{administration,storefront}/   # src/main.ts|js, optional package.json, build/webpack.config.js
│   └── Migration/                        # Namespace <PluginNS>\Migration
├── tests/                                # autoload-dev: <NS>\Tests\
└── vendor/                               # nur bei Zip-Auslieferung mit Composer-Abhängigkeiten
```

Aus `Bundle`: `build(ContainerBuilder)` (lädt `Resources/config/services.*`, registriert Migrationspfad, Filesystem `private`/`public`), `getMigrationNamespace()`/`getMigrationPath()`, `getTemplatePriority()` (Default 0), `getAdditionalBundles()`, `getAdminBaseUrl()`. `getPath()` (Symfony) = Verzeichnis der Plugin-Klasse, i. d. R. `src/`.

## 3. Anleitung

1. **Anlegen**: `bin/console plugin:create SwagExample Swag` (Argumente `plugin-name`, `plugin-namespace`, beide PascalCase; ohne Argumente und nicht-interaktiv Fehler). Optionen `--static` (-> `custom/static-plugins/<Name>`), `--no-scaffold` (nur Pflichtdateien) sowie Generator-Optionen (Command, Config, Entity, EventSubscriber, ScheduledTask, StoreApiRoute, StorefrontController, AdminModule, JavascriptPlugin, CustomFieldset, Tests; siehe `Scaffolding/Generator/`). Existiert das Verzeichnis, bricht der Befehl ab; bei Fehlern wird es wieder gelöscht. Der Composer-Name wird aus Namespace/Name in kebab-case gebildet (`ComposerGenerator`).
2. **composer.json prüfen** (Pflichtfelder oben). Die Doku empfiehlt den Static-Plugin-Ansatz (Plugin im Projekt über Composer).
3. **Erkennen**: `bin/console plugin:refresh` (`-s` unterdrückt die Liste). `PluginFinder` durchsucht `custom/plugins` (Tiefe 0), alle Composer-Pakete vom Typ `shopware-platform-plugin` und das Root-Paket.
4. **Lifecycle**: `plugin:install [-a|--activate] [--reinstall]`, `plugin:activate`, `plugin:deactivate`, `plugin:uninstall [--keep-user-data]`, `plugin:update`, `plugin:update:all`. Gemeinsame Optionen `-r/--refresh`, `-c/--clearCache`, `--skip-asset-build` (Details [T-03](./03-plugin-lifecycle.md)).
5. **Build-Config**: `bin/console bundle:dump` schreibt `var/plugins.json` (Argument `dumpFilePath`); die Build-Skripte lesen sie.
6. **Wird `build()` überschrieben**, immer `parent::build($container)` aufrufen, sonst werden Services/Migrationen nicht registriert.
7. **Composer-Abhängigkeiten (Drittbibliotheken)**:
   - Composer-/Static-Plugin: normales `require` in der Plugin-composer.json.
   - Zip-Plugin (Store): `vendor/` mitliefern (Doku: max. 5 MB Bundle) **oder** `executeComposerCommands(): true` überschreiben. Code (`PluginLifecycleService::executeComposerRequireWhenNeeded`, `CommandExecutor`): `composer require <name>:<version> --update-with-dependencies --no-scripts` im Projektverzeichnis; übersprungen bei `shopware.deployment.cluster_setup` oder wenn die installierte Version (`InstalledVersions`) der Plugin-Version entspricht. Uninstall -> `composer remove`; danach `opcache_reset()` und `refreshPlugins`.
   - Doku-Tipp: `shopware/core` beim `composer require` im Plugin temporär aus `require` entfernen. Private Pakete in `<plugin>/packages/` ablegen und in der composer.json referenzieren (Doku).
8. **npm-Abhängigkeiten**: `npm init -y` in `src/Resources/app/administration/` bzw. `.../storefront/`, dann `npm install <paket>`. `bin/build-storefront.sh` liest `var/plugins.json` und führt je Plugin `npm install --prefer-offline` aus, wenn `package.json` existiert und `node_modules` fehlt. Administration (6.7, laut Doku): Vite löst Pakete aus plugin-eigenem `node_modules`; Storefront: Webpack, ggf. `src/Resources/app/storefront/build/webpack.config.js`.
9. **Plugin-Abhängigkeiten** über `require`/`conflict` (Beispiel unten). `RequirementsValidator::validateRequirements()` läuft bei Install/Update/Activate (bei Composer-verwalteten Plugins nur bei Activate) und prüft Composer-Pakete, installierte/aktive Plugins, `replace` und mitgelieferte `vendor`-Pakete; Verstöße sammelt `RequirementExceptionStack`. `resolveActiveDependants()` verhindert Deaktivieren/Deinstallieren eines Plugins, von dem aktive Plugins abhängen. Maßgeblich ist der Composer-Paketname, nicht Label oder Klasse.

## 4. Beispiele

`custom/plugins/SwagExample/composer.json`

```json
{
    "name": "swag/example",
    "description": "Example plugin",
    "type": "shopware-platform-plugin",
    "version": "1.0.0",
    "license": "MIT",
    "require": {
        "shopware/core": "~6.7.0"
    },
    "extra": {
        "shopware-plugin-class": "SwagExample\\SwagExample",
        "label": {
            "de-DE": "Beispiel-Plugin",
            "en-GB": "Example plugin"
        },
        "description": {
            "de-DE": "Minimales Beispiel-Plugin",
            "en-GB": "Minimal example plugin"
        }
    },
    "autoload": {
        "psr-4": {
            "SwagExample\\": "src/"
        }
    }
}
```

`src/SwagExample.php`

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
}
```

`src/Resources/config/services.php` (optional; `Bundle` lädt `Resources/config/services.*`; Autowiring ist nicht Default, siehe [T-05](./05-services-registrieren.md))

```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();
};
```

Starten:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample
```

Composer-Pakete per Shopware installieren lassen (Zip-Plugin ohne `vendor/`):

```php
// src/SwagExample.php
public function executeComposerCommands(): bool
{
    return true;
}
```

Plugin-Abhängigkeiten in `composer.json`:

```json
"require": {
    "shopware/core": "~6.7.0",
    "swag/other-plugin": "^1.0"
},
"conflict": {
    "swag/legacy-plugin": "<2.0"
}
```

## 5. Fallstricke

- `extra.label` fehlt oder `type` falsch -> Plugin nicht in `plugin:list`; Meldung „invalid "type" ..., or missing extra.label property" (`PluginFinder::addError`).
- Kein `autoload.psr-4`/`psr-0` -> `refreshPlugins`-Fehler. Für nicht Composer-verwaltete Plugins registriert `KernelPluginLoader::registerPluginNamespaces` Namespaces zur Laufzeit; Pfade müssen unter dem Projektverzeichnis liegen.
- `shopware-plugin-class` falsch/Klasse nicht ladbar -> `ComposerPluginLoader` überspringt das Paket („Skipped package ... due invalid "shopware-plugin-class" config"); der `KernelPluginLoader` ignoriert die Klasse ohne Meldung (`class_exists`).
- Plugin-Klasse erbt nicht von `Shopware\Core\Framework\Plugin` -> `PluginException::kernelPluginLoaderError`. Konstruktor überschreiben ist nicht möglich (`final`).
- composer.json geändert, aber kein `plugin:refresh`/Cache-Clear (`-c`) -> Änderungen werden nicht übernommen.
- `removeMigrations()` wirft für Namespaces, die mit `Shopware` beginnen (außer `Shopware\Commercial`).
- Zip-Plugin mit Drittbibliothek ohne `vendor/` und ohne `executeComposerCommands()` -> Klassen fehlen.
- `executeComposerCommands()` ist im Cluster-Setup (`shopware.deployment.cluster_setup`) wirkungslos -> Abhängigkeiten vorab per Composer installieren.
- Anti-Pattern: `shopware/core` auf Patch-Stand pinnen (`6.7.15.1`); besser `~6.7.0` und Plugin-Abhängigkeiten nicht exakt pinnen.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Scaffold-Stub nutzt `"shopware/core": "~6.7.0"` und erzeugt `services.php`/`routes.php` (PHP) statt XML. XML-Services/-Routen (`services.xml`, `routes.xml`, `routes_overwrite.xml`, Package-Config) sind in 6.7 deprecated (`Bundle.php`, `@deprecated tag:v6.8.0`) und werden ab 6.8 nicht mehr geladen. `config.xml` (Plugin-Konfiguration) ist davon laut Annahme nicht betroffen.
- Administration-Build in 6.7 mit Vite (Doku), Storefront weiter Webpack (Doku); im Code liegen zusätzlich `vite.*.config.mts` und `vitest.config.mts` in der Storefront -> [T-49](./49-build-deployment.md).
- `Plugin::executeComposerCommands()` und `CommandExecutor` sind in 6.7 unverändert vorhanden.

## 6. Checkliste

- [ ] `type` = `shopware-platform-plugin`, `extra.shopware-plugin-class` zeigt auf eine ladbare Klasse.
- [ ] `extra.label` für `de-DE` und `en-GB` gesetzt.
- [ ] `autoload.psr-4` passt zum Namespace der Plugin-Klasse.
- [ ] `require.shopware/core` als `~6.7.0`, keine exakten Patch-Pins.
- [ ] Services als `services.php` (PHP), nicht XML.
- [ ] Wurde `build()` überschrieben: `parent::build($container)` aufgerufen.
- [ ] Zip-Plugin: `vendor/` enthalten oder `executeComposerCommands()` bewusst gesetzt.
- [ ] Plugin-Abhängigkeiten per Composer-Paketnamen in `require`/`conflict`.
- [ ] `plugin:refresh` nach jeder composer.json-Änderung.

## 7. Quellen

Doku (abgerufen, zusammengefasst):
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-base-guide.html
- https://developer.shopware.com/docs/guides/plugins/plugins/dependencies/add-plugin-dependencies.html
- https://developer.shopware.com/docs/guides/plugins/plugins/dependencies/using-composer-dependencies.html
- https://developer.shopware.com/docs/guides/plugins/plugins/dependencies/using-npm-dependencies.html
- https://developer.shopware.com/docs/guides/plugins/plugins/creating-plugins.html (nicht abgerufen, ungeprüft)

Quellcode (relativ zu `vendor/shopware/`): `core/Framework/Plugin.php`, `core/Framework/Bundle.php`, `core/Framework/Plugin/Util/PluginFinder.php`, `core/Framework/Plugin/PluginService.php`, `core/Framework/Plugin/Composer/{PackageProvider,CommandExecutor}.php`, `core/Framework/Plugin/PluginLifecycleService.php` (`executeComposerRequireWhenNeeded`), `core/Framework/Plugin/KernelPluginLoader/*`, `core/Framework/Plugin/Requirement/{RequirementsValidator,RequirementExceptionStack}.php`, `core/Framework/Plugin/Command/{PluginCreateCommand,PluginRefreshCommand,BundleDumpCommand}.php`, `core/Framework/Plugin/Command/Scaffolding/` (`stubs/composer.stub`, `stubs/plugin-class.stub`), Projekt: `bin/build-storefront.sh`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: `plugin:create` wurde nicht ausgeführt; der abgeleitete Composer-Name ist eine Code-Ableitung.
- ⚠ UNGEPRÜFT: Doku-Angaben zu Store-Größenlimit (5 MB) und Build-Befehlen (`composer build:js:admin`, `shopware-cli`) stammen nur aus der Doku; `creating-plugins.html` nicht abgerufen.
- ⚠ LÜCKE: Doku sagt, `executeComposerCommands` greife bei „Aktivierung"; der Code führt es bei Install und Update aus (Code gilt).
- ⚠ UNGEPRÜFT: Dass `config.xml` nicht von der XML-Deprecation betroffen ist, ist Annahme.
- ⚠ UNGEPRÜFT: Vergleich mit 6.5/6.6 beruht auf Erinnerung, nicht auf altem Code; `DbalKernelPluginLoader`/`StaticKernelPluginLoader` nur oberflächlich gelesen.
