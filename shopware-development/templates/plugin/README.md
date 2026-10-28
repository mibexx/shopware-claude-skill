# Template: plugin (Wurzel-Vorlage)

Shopware 6.7.15.1. Gerüst eines Plugins: `composer.json`, Plugin-Klasse mit allen Lifecycle-Methoden, Service-Registrierung per `services.php` (Standard) sowie `services.xml.dist` als deprecated Alternative. Alle anderen Vorlagen-Verzeichnisse (`config`, `migration`, `entity`, `event-subscriber`, `decorator`, ...) enthalten nur ihre eigenen Dateien in derselben Struktur (`SwagExample/src/...`) und werden in dieses Gerüst hineinkopiert.

## Dateibaum

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                      # Plugin-Klasse (extends Shopware\Core\Framework\Plugin)
    └── Resources/
        └── config/
            ├── services.php                 # Services (Standard in 6.7)
            └── services.xml.dist            # Alternative (deprecated), siehe unten
```

Weitere übliche Orte (von anderen Vorlagen befüllt): `src/Migration/` (Namespace `SwagExample\Migration`, wird automatisch registriert), `src/Resources/config/config.xml`, `src/Resources/app/administration|storefront/`, `src/Resources/views/`, `tests/` (autoload-dev `SwagExample\Tests\`). Das Plugin-Icon liegt standardmäßig unter `src/Resources/config/plugin.png` (Binärdatei, nicht Teil dieser Vorlage).

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample` (Verzeichnis, Klasse, Bundle-Name) | Plugin-Name, PascalCase, Klassenname = Dateiname | z. B. `AcmeShop` |
| `SwagExample\` (Namespace) | PSR-4-Namespace (`composer.json` `autoload.psr-4` und `extra.shopware-plugin-class`) | z. B. `Acme\Shop\` |
| `swag/example` | Composer-Paketname (maßgeblich für Abhängigkeiten) | `vendor/paket` |
| `swag_example` | Tabellen-/Entity-Name (in `uninstall()`) | `<prefix>_<name>` |
| `extra.label` / `extra.description` | Pflicht bzw. optional, je Locale (`de-DE`, `en-GB`) | eigene Texte |
| `~6.7.0` | Constraint auf `shopware/core`, nicht auf Patch-Stand pinnen | beibehalten |

## composer.json: Pflichtfelder (laut `PluginFinder`)

`type = shopware-platform-plugin`, `extra.shopware-plugin-class` (FQCN), `extra.label` (nicht leer), `autoload.psr-4` oder `psr-0`. Fehlt etwas davon, taucht das Plugin nicht in `plugin:list` auf. Optional: `extra.description`, `manufacturerLink`, `supportLink`, `copyright`, `plugin-icon`. Abhängigkeiten zu anderen Plugins: normales `require`/`conflict` mit dem Composer-Namen.

## Lifecycle (Reihenfolge)

- Install: Pre-Event, Systemconfig-Defaults aus `config.xml`, `install()`, Migrationen, Custom Fields, `installedAt`, `postInstall()`, Post-Event.
- Update: `update()`, Migrationen, Custom Fields, DB-Version, `postUpdate()`.
- Activate / Deactivate: Kernel wird neu gebootet, `activate()` läuft auf der neuen Instanz (Plugin-Services verfügbar). Migrationen laufen bei Activate nur, wenn `activate()` selbst `setAutoMigrate(true)` setzt (Core setzt es vorher auf false).
- Uninstall: `uninstall()` läuft immer, auch mit `keepUserData()`. Der Core löscht Migrationseinträge, Systemconfig und Custom Fields (ohne `keepUserData`), aber NICHT eigene Tabellen/Spalten: das erledigt `uninstall()` (Vorlage enthält den `keepUserData`-Guard und `DROP TABLE IF EXISTS`; Zeilen entfernen, wenn es keine Tabellen gibt).
- In `install()` sind eigene Plugin-Services nicht verfügbar (Container wird erst bei Activate neu gebaut). Der Konstruktor der Plugin-Klasse ist `final`; keine Konstruktor-Injection.
- Schemaänderungen immer per Migration (siehe ../migration/README.md), nicht in `install()`/`update()`.

## Services registrieren

Geladen wird per Glob `Resources/config/services.*` (XML, YAML, PHP). XML ist in 6.7 deprecated (`Bundle::registerContainerFile`, Entfernung 6.8.0.0, löst eine Deprecation aus). Daher: `services.php` verwenden. `services.xml.dist` hat absichtlich eine Endung, die nicht geladen wird; wer XML braucht, benennt sie um und löscht `services.php` (sonst werden beide geladen).

Ausschnitt `src/Resources/config/services.php`, Variante A (explizit, Core-Stil, in den Vorlagen verwendet):

```php
$services->set(\SwagExample\Service\MyService::class)
    ->args([service(\Shopware\Core\System\SystemConfig\SystemConfigService::class)]);

$services->set(\SwagExample\Subscriber\MySubscriber::class)
    ->tag('kernel.event_subscriber');
```

Variante B (Autowire + Autoconfigure, `src` scannen). Live bestätigt: Plugin installiert und aktiviert, Subscriber und Decorator funktionieren; Autoconfigure greift nur mit `->defaults()->autowire()->autoconfigure()`:

```php
$services->defaults()->autowire()->autoconfigure();

$services->load('SwagExample\\', __DIR__ . '/../../')
    ->exclude([__DIR__ . '/../../Resources', __DIR__ . '/../../Migration', __DIR__ . '/../../SwagExample.php']);

// skalare Argumente lassen sich nicht autowiren
$services->set(\SwagExample\Service\MyService::class)->arg('$greeting', 'Hello');
```

Es gibt keinen Autowiring-Default: ohne `defaults()->autowire()` müssen Argumente per `->args([...])` und Tags per `->tag(...)` angegeben werden. Controller, Routen und per `$container->get()` abgerufene Services brauchen `->public()`. Details und Tag-Übersicht: ../../references/05-services-registrieren.md.

## Einbindung / CLI

```bash
# Gerüst nach custom/plugins/ kopieren (oder custom/static-plugins/) und Platzhalter ersetzen
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample
bin/console cache:clear

# Alternativ ein Gerüst vom Core erzeugen lassen (legt services.php inkrementell an)
bin/console plugin:create SwagExample SwagExample

bin/console plugin:update SwagExample -c          # nach Versionserhöhung in composer.json + plugin:refresh
bin/console plugin:deactivate SwagExample
bin/console plugin:uninstall SwagExample --keep-user-data
bin/console plugin:uninstall SwagExample          # löscht Daten (Aufräumcode in uninstall())
```

Prüfen: `bin/console debug:container <ServiceId>`, `bin/console lint:container` (Symfony-Standardbefehle).

## Optionale Plugin-Methoden (in der Vorlage auskommentiert)

`executeComposerCommands(): bool` (Default `false`, führt `composer require/remove` aus; wirkungslos bei `shopware.deployment.cluster_setup`), `rebuildContainer(): bool` (Default `true`, nur in der CLI wirksam), `enrichPrivileges(): array`, `configureRoutes(...)`. Wird `build()` überschrieben, immer `parent::build($container)` aufrufen.

## Verweise

- ../../references/02-plugin-aufbau.md
- ../../references/03-plugin-lifecycle.md
- ../../references/05-services-registrieren.md
- ../../references/01-projektstruktur-dev-umgebung.md
- ../../references/49-build-deployment.md
- Passende Vorlagen: ../config/README.md, ../migration/README.md, ../entity/README.md, ../event-subscriber/README.md, ../decorator/README.md, ../theme/README.md

## Hinweise und Lücken

- Deprecations (6.7, Entfernung 6.8.0.0): `services.xml`, `routes.xml` und Package-Konfig als XML; `plugin:list --json` (stattdessen `--format json`). `config.xml` (Plugin-Konfiguration) ist davon nach aktuellem Wissen nicht betroffen (Annahme).
- Live bestätigt (Review-Runden 1–3): Vorlage installiert, aktiviert und deinstalliert (mit und ohne `--keep-user-data`) in Shopware 6.7.15.1. `composer.json` ist als JSON geprüft; Signaturen der Lifecycle-Methoden und Contexts gegen `core/Framework/Plugin.php` geprüft.
- ⚠ LÜCKE: `plugin.png` (Icon) ist nicht enthalten. Die Plugin-Klasse nutzt `$this->container` (Symfony-`Bundle`); in `install()` nur Core-Services daraus holen.
- ⚠ LÜCKE: Das Verhalten eines Plugin-eigenen Subscribers auf `Plugin*Event` vor der Aktivierung ist nicht belegt (Container enthält Plugin-Services erst nach Aktivierung).
- ⚠ LÜCKE: Zip-Plugins mit Drittbibliotheken (`vendor/` mitliefern, Store-Limit) sind nur in der Referenz beschrieben, nicht als Vorlage.
