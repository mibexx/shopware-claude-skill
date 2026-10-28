# Projektstruktur und Entwicklungsumgebung (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn man sich in einem Shopware-6.7-Projekt orientieren muss: wo eigener Code liegt, welche Konfigurationsdateien und Umgebungsvariablen gelten und wie die Docker-Umgebung bedient wird. Sie ist der Einstieg vor jeder Plugin-Arbeit.

Abgrenzung: Aufbau eines einzelnen Plugins und `composer.json` in [T-02 Plugin-Aufbau](./02-plugin-aufbau.md); Lifecycle-Befehle in [T-03 Plugin-Lifecycle](./03-plugin-lifecycle.md); Build-Skripte (`bin/build-*.sh`, `bin/watch-*.sh`) in [T-49 Build und Deployment](./49-build-deployment.md).

## 2. Konzepte

- Shopware 6.7 ist ein **Symfony-Flex-Projekt** (`shopware/production`, `composer.json` Typ `project`). Core, Storefront, Administration und Elasticsearch liegen als Composer-Pakete in `vendor/shopware/*`. **Eigener Code** gehört nach `custom/plugins`, `custom/static-plugins`, `custom/apps` oder (projektspezifisch) nach `src/` (Namespace `App\`). Nie in `vendor/shopware` ändern (wird bei `composer install/update` überschrieben).
- Kernel: `Shopware\Core\Kernel` (nicht `App\Kernel`), gebaut über `KernelFactory::create()` (Einstieg `public/index.php`, `bin/console`). Bundles in `config/bundles.php`; Plugins kommen zusätzlich über einen `KernelPluginLoader` (Standard `DbalKernelPluginLoader`, liest Tabelle `plugin`).
- Konfiguration: `config/packages/*.yaml` (+ `config/packages/<env>/**`), `config/services.yaml`, `config/routes.yaml`. Shopware-Defaults (`shopware.yaml`, `feature.yaml` ...) liegen im Bundle `core/Framework/Resources/config/packages/`, nicht im Projekt.
- Umgebung: Rangfolge laut `debug:dotenv` (absteigend) `.env.local.php`, `.env.dev.local`, `.env.dev`, `.env.local`, `.env`. Echte Prozess-Variablen (z. B. aus `compose.override.yaml`) schlagen alle Dateien.
- Docker-Dienste: `web` (nginx + PHP 8.4, Image `ghcr.io/shopware/docker-base:8.4-nginx`), `database` (MariaDB 11.8), `mailer` (Mailpit), optional `opensearch` (Profil `search`).

### Verzeichnisbaum (Projektwurzel, `kernel.project_dir`)

```text
shopware/
├── .env  .env.local  .env.dev        # Defaults (APP_ENV=prod) / lokale Overrides (gitignored) / env-spezifisch
├── composer.json  composer.lock  symfony.lock
├── compose.yaml                      # Basis: database, opensearch (Profil search)
├── compose.override.yaml             # PROJEKTSPEZIFISCH: web, mailer, Ports, Env-Variablen
├── docker/Dockerfile                 # PROJEKTSPEZIFISCH
├── bin/{console,ci,build-*.sh,watch-*.sh}
├── config/
│   ├── bundles.php  services.yaml  routes.yaml  routes/mcp.yaml
│   ├── packages/{http_discovery,lock,mcp}.yaml  und <env>/...
│   └── jwt/                          # per system:generate-jwt-secret, gitignored
├── custom/{plugins,static-plugins,apps}/
├── src/Controller/                   # Projektcode, Namespace App\
├── files/                            # privates Filesystem (u. a. theme-config/)
├── public/                           # Webroot; bundles/ media/ thumbnail/ theme/ sitemap/ sind generiert
├── var/                              # generiert: cache/<env>_h<hash>/, log/<env>.log, plugins.json
├── install.lock                      # verhindert den Web-Installer
└── vendor/shopware/{core,storefront,administration,elasticsearch}/
```

### Entscheidungshilfe: Wo liegt mein Code?

| Ablage | Wann | Laden/Lifecycle |
|---|---|---|
| `custom/plugins/<Name>` | klassisches Plugin, Admin-/CLI-Lifecycle | `DbalKernelPluginLoader` über Tabelle `plugin`; `plugin:refresh/install/activate` |
| `custom/static-plugins/<Name>` (`plugin:create --static`) | projektinternes Plugin, im Projekt-Repo versioniert | Composer-Path-Repository (`repositories[type=path,url=custom/static-plugins/*]`, `symlink: true`), i. d. R. `composer require vendor/name` |
| `custom/apps/<Name>` mit `manifest.xml` | App-System | `app:refresh`, siehe [T-10 App-System](./10-app-system-abgrenzung.md) |
| `src/` (`App\`) | reiner Projektcode | `config/services.yaml` autowired automatisch; Bundles in `config/bundles.php` |

### Wichtige Umgebungsvariablen

| Variable | Zweck |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `dev`/`prod`/`test`; `.env` Default `prod`; steuert Debug und `config/packages/<env>/` |
| `APP_URL` | öffentliche Shop-URL (Port muss zum Port-Mapping passen) |
| `APP_SECRET`, `INSTANCE_ID` | Symfony-Secret / Shop-Instanz-ID |
| `DATABASE_URL` | DB-DSN (`Adapter\Database\MySQLFactory`; optional `DATABASE_SSL_*`, `DATABASE_REPLICA_*`) |
| `MAILER_DSN`, `LOCK_DSN` | Mailer / Symfony-Lock |
| `MESSENGER_TRANSPORT_DSN` (+ `_FAILURE_DSN`, `_LOW_PRIORITY_DSN`) | Queue-Transporte |
| `BLUE_GREEN_DEPLOYMENT`, `SHOPWARE_CDN_STRATEGY_DEFAULT` | `shopware.deployment.blue_green`, `shopware.cdn.strategy` |
| `COMPOSER_PLUGIN_LOADER` | `ComposerPluginLoader` statt DB-Loader (nur Composer-Plugins) |
| `SHOPWARE_SKIP_WEBINSTALLER` | Web-Installer (`/installer`) überspringen |
| `OPENSEARCH_URL`, `SHOPWARE_ES_*` | Elasticsearch/OpenSearch |
| `PROXY_URL`, `SHOPWARE_HTTP_CACHE_ENABLED`, `SHOPWARE_HTTP_DEFAULT_TTL` | HTTP-Cache |
| `APP_CACHE_DIR`, `APP_BUILD_DIR`, `APP_LOG_DIR`, `SHOPWARE_CACHE_ID`, `PROJECT_ROOT` | Kernel-Pfade (`Kernel::getCacheDir()/getBuildDir()/getLogDir()`, `KernelFactory`) |

## 3. Anleitung

1. **Start/Stopp** (aus der Projektwurzel):
   ```bash
   docker compose up -d
   docker compose ps
   docker compose logs -f web
   docker compose stop
   docker compose down        # Volume db-data bleibt
   docker compose down -v     # löscht die DB!
   docker compose --profile search up -d   # mit OpenSearch
   ```
2. **URLs** (Projekt-Setup laut `compose.override.yaml`, projektspezifisch): Storefront `http://localhost:8100`, Admin `/admin` (admin/shopware), Mailpit `http://localhost:8025`, MariaDB-Host-Port `3307`. Doku-Standard wäre Port 8000.
3. **Konsole immer im Container**: `docker compose exec web php bin/console <cmd>`. Wichtige Gruppen: `plugin:*`, `app:*`, `cache:clear` / `cache:clear:all` / `cache:clear:http`, `assets:install`, `theme:*`, `database:migrate`, `dal:refresh:index`, `bundle:dump`, `feature:list|enable|disable|dump`, `debug:config|container|router|dotenv|event-dispatcher|autowiring|twig`, `administration:*`.
4. **Composer im Container**: `docker compose exec web composer require ...`. Das Post-install-Script `assets:install` läuft automatisch (`scripts.auto-scripts`).
5. **Konfiguration prüfen** statt raten: `bin/console debug:config shopware <bereich>` (z. B. `filesystem`), `bin/console debug:dotenv`. Eigene Overrides der Shopware-Defaults als `config/packages/shopware.yaml` im Projekt (existiert in der Standardinstallation nicht).
6. **Nach Änderungen**: Config/Plugin -> `cache:clear`; Theme/Twig -> `theme:compile`; Admin-/Storefront-JS braucht Build ([T-49](./49-build-deployment.md)); `assets:install` kopiert nach `public/bundles`.

## 4. Beispiele

Plugin per CLI anlegen und aktivieren (Details und Pflichtfelder in [T-02](./02-plugin-aufbau.md); Gerüst: [../templates/plugin/README.md](../templates/plugin/README.md)):

```bash
cd /pfad/zur/projektwurzel
docker compose up -d
docker compose exec web php bin/console plugin:create SwagExample Swag --no-scaffold
docker compose exec web php bin/console plugin:refresh
docker compose exec web php bin/console plugin:install --activate SwagExample
docker compose exec web php bin/console plugin:list
docker compose exec web php bin/console cache:clear
```

`custom/plugins/SwagExample/composer.json` (Minimal, `extra.label` ist Pflicht, sonst ungültig laut `PluginFinder::isPluginComposerValid`):

```json
{
    "name": "swag/example",
    "description": "Example plugin",
    "type": "shopware-platform-plugin",
    "version": "1.0.0",
    "license": "MIT",
    "require": { "shopware/core": "~6.7.0" },
    "extra": {
        "shopware-plugin-class": "SwagExample\\SwagExample",
        "label": { "de-DE": "Beispiel-Plugin", "en-GB": "Example plugin" }
    },
    "autoload": { "psr-4": { "SwagExample\\": "src/" } }
}
```

`custom/plugins/SwagExample/src/SwagExample.php`:

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
}
```

## 5. Fallstricke

- **Plugin nicht sichtbar**: `plugin:refresh` vergessen (Tabelle `plugin` wird nur dort aktualisiert) oder `type`/`extra.shopware-plugin-class`/`extra.label` fehlt (`PluginFinder`; beim `ComposerPluginLoader` Meldung „Skipped package ... invalid shopware-plugin-class").
- **Befehle auf dem Host statt im Container**: DB-Host `database` und PHP 8.4 existieren nur im Container; auf dem Host gilt `DATABASE_URL` aus `.env` (`localhost`) und scheitert. Gleiches gilt für Composer.
- **`APP_ENV` aus `.env` angenommen**: Prozess-Env schlägt `.env` (dort steht `prod`, im Container `dev`). `debug:dotenv` zeigt beides.
- **`APP_URL` falsch (Port)**: führt zu falschen Asset-/Admin-URLs.
- **Generierte Verzeichnisse committen**: `public/bundles|media|theme|thumbnail|sitemap`, `files/*`, `var/*`, `.env.local` sind laut `.gitignore` ausgeschlossen.
- **Code in `vendor/shopware` ändern**: geht beim nächsten Update verloren. Stattdessen Plugin, [Dekoration](./06-service-dekoration.md) oder [Event-Subscriber](./07-events-subscriber.md).
- **XML-Projektkonfiguration**: deprecated, YAML oder PHP verwenden.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- XML-Konfiguration (`config/packages/**/*.xml`, `config/services.xml`, `config/services_<env>.xml`, `config/routes/**/*.xml`, `config/routes.xml`) löst in 6.7 eine Deprecation aus („will not be loaded in v6.8.0.0", `Kernel::configureContainer/configureRoutes`); gleiches für XML in Bundle-`Resources/config/packages` (`Bundle.php`).
- `Symfony\UX\TwigComponent\TwigComponentBundle` muss in `config/bundles.php` stehen (sonst Deprecation, Auto-Registrierung nur bis 6.8).
- Neue Bundles in 6.7.15.1: `Symfony\AI\McpBundle\McpBundle`, `Pentatrion\ViteBundle\PentatrionViteBundle`, `Shopware\Core\Service\Service`, `Shopware\Core\Profiling\Profiling`; dazu `config/packages/mcp.yaml`, `config/routes/mcp.yaml` (siehe [T-44 MCP-Server](./44-mcp-server.md)).
- Feature-Flags `v6.5.0.0`, `v6.6.0.0`, `v6.7.0.0` sind `default: true, toggleable: false` (altes Verhalten nicht mehr abschaltbar); `v6.8.0.0` ist `default: false` (Opt-in-Vorschau).
- `COMPOSER_PLUGIN_LOADER` wird von `public/index.php` und `bin/console` unterstützt; `system:install` nutzt zwingend den `StaticKernelPluginLoader`.
- Docker-Standard laut Doku: Shopware CLI (`shopware-cli project dev`) bzw. Compose-Setup.

## 6. Checkliste

- [ ] Alle `bin/console`- und Composer-Befehle laufen im Container (`docker compose exec web ...`).
- [ ] Eigener Code liegt in `custom/plugins`, `custom/static-plugins` oder `src/`, nie in `vendor/shopware`.
- [ ] `composer.json` hat `type`, `extra.shopware-plugin-class`, `extra.label`, `autoload.psr-4`.
- [ ] Nach neuem/geändertem Plugin: `plugin:refresh`, bei Bedarf `plugin:install --activate`, `cache:clear`.
- [ ] `APP_URL` passt zum Port-Mapping.
- [ ] Konfig-Schlüssel vor Override mit `debug:config shopware <bereich>` geprüft.
- [ ] Neue Konfiguration/Services in YAML oder PHP, nicht XML.
- [ ] Generierte Verzeichnisse (`var`, `public/bundles`, `files`) nicht im Repository.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/bundle.html (Plugin -> `Shopware\Bundle` -> Symfony-Bundle; Bundles in `src/` und `config/bundles.php`; Migrationen nicht automatisch)
- https://developer.shopware.com/docs/guides/installation/ (Shopware CLI + Docker, `shopware-cli project dev`, Login admin/shopware; enthält keinen Verzeichnisbaum)

Quellcode (relativ zu `vendor/shopware/`):
- `core/Kernel.php` (`Shopware\Core\Kernel`: `registerBundles`, `configureContainer`, `configureRoutes`, `getKernelParameters`, `getCacheDir`, `getLogDir`)
- `core/Framework/Adapter/Kernel/KernelFactory.php`, `core/Framework/Framework.php`, `core/Framework/Bundle.php`
- `core/Framework/Resources/config/packages/{shopware,feature,framework}.yaml`
- `core/Framework/Plugin/KernelPluginLoader/*`, `core/Framework/Plugin/Util/PluginFinder.php` (`COMPOSER_TYPE`), `core/Framework/Plugin/PluginService.php`, `core/Framework/Plugin/Command/PluginCreateCommand.php` (`--static`, `--no-scaffold`)
- `core/DevOps/Environment/EnvironmentHelper.php`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: `config/packages/shopware.yaml` existiert in der Installation nicht; die Override-Möglichkeit folgt aus `Kernel::configureContainer` (Glob `config/packages/*`), nicht aus einem Testlauf.
- ⚠ UNGEPRÜFT: Static-Plugins per `composer require` einbinden ist aus dem Path-Repository und `PluginFinder::enrichWithVendorPlugins` abgeleitet, nicht ausgeführt. `plugin:create`/`plugin:install` wurden nicht ausgeführt (Beispiele aus Stub und Code).
- ⚠ LÜCKE: `compose.override.yaml`, `docker/Dockerfile`, Port 8100 und der `web`-Dienst sind projektspezifisch, nicht gegen das offizielle Rezept `shopware/production` geprüft.
- ⚠ LÜCKE: Wirkung von `APP_URL_CHECK_DISABLED` nicht im Code verifiziert.
- ⚠ LÜCKE: Ob Plugin-`services.xml` in 6.8 weiter geladen wird, ist nicht belegt (siehe [T-05](./05-services-registrieren.md)); Unterschiede zu 6.5/6.6-Rezepten nicht gegen alte Rezepte geprüft.
- ⚠ LÜCKE: Webpack vs. Vite (Vite-Bundle ist registriert) gehört zu [T-49](./49-build-deployment.md); Doku-Seite `/docs/concepts/` nicht abgerufen.
