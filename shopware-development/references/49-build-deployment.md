# Build und Deployment (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift bei allem rund um den Build von Admin- und Storefront-JS, die Skripte `bin/build-*.sh` und `bin/watch-*.sh`, Webpack/Vite-Erweiterung, Deployment (Composer-Plugins, Deployment-Helper) und das Verpacken eines Plugins als ZIP.

**Antwort auf die offene Frage Webpack vs. Vite:** Der Admin-Build ist in 6.7.15.1 **Vite**, nicht Webpack (Admin hat keine `webpack.config.*` mehr; `build` = `VITE_MODE=production ts-node -T build.ts`). Die Storefront ist **hybrid**: Plugin-/Theme-JS weiter mit **Webpack**, `shopware.js` und Twig-Komponenten mit **Vite**. Die Doku-Seite "Extending Webpack" (Admin) ist veraltet und widerspricht dem Code.

Abgrenzung: Storefront-JS-Plugins [T-15](./15-storefront-js-plugins.md); Admin [T-24](./24-admin-module.md), [T-25](./25-admin-komponenten.md); Projektstruktur/Docker [T-01](./01-projektstruktur-dev-umgebung.md); Lifecycle-CLI [T-03](./03-plugin-lifecycle.md); Tests [T-47](./47-tests-jest.md); Lint [T-48](./48-statische-analyse.md).

## 2. Konzepte

- **Skripte** (`bin/*.sh`, Projektroot) sind dünne Orchestrierer: `bundle:dump` > `feature:dump` > npm-Install in Plugins > Build > `assets:install` (Storefront zusätzlich `theme:compile` > `cache:clear`).
- **Eingaben der Build-Tools:** `var/plugins.json` (von `bundle:dump`), `var/config_js_features.json` (von `feature:dump`), `var/theme-files.json` (von `theme:dump`, Hot-Mode).
- **Entry-Pflicht:** `src/Resources/app/{administration,storefront}/src/main.js|ts`; sonst `entryFilePath = null` und das Plugin wird nicht gebaut (`BundleConfigGenerator::getEntryFile`).
- **Storefront-Webpack-Erweiterung:** `<Plugin>/src/Resources/app/storefront/build/webpack.config.(ts|cts|js|cjs)` (Reihenfolge in `BundleConfigGenerator::getWebpackConfig()`); wird von der Storefront-`webpack.config.js` geladen und per `webpack-merge` gemergt.
- **Storefront-Vite:** `vite.shopware.config.mts` (Runtime `shopware.js`), `vite.components.config.mts` + `build/vite/build-components.js` für Twig-Komponenten unter `Resources/views/components/**` mit `.js/.ts/.scss/.css` (erkannt über `hasComponentAssets`).
- **Admin-Vite:** `build/plugins.vite.ts` baut je Extension (`root: extension.path`, Ausgabe `Resources/public/administration`, `manifest: true`, `base: /bundles/<technicalFolderName>/administration/`); eingebaut: Twig, Asset, Vue (Compat MODE 2), SVG, `ExternalsPlugin`, `OverrideComponentRegisterPlugin`, `vite-plugin-symfony`.
- **Deployment:** Build in CI (`shopware-cli project ci`), Rollout mit `vendor/bin/shopware-deployment-helper run` (Voraussetzung: `composer require shopware/deployment-helper`; in diesem Projekt weder in `composer.json` noch in `vendor/` vorhanden, Paketname laut Doku-Seite zum Deployment-Helper, ⚠ lokal nicht prüfbar). Plugins per Composer (`custom/plugins/*`, `custom/static-plugins/*` als path-Repositories mit Symlink; in diesem Projekt in der `composer.json` konfiguriert), Pakettyp `shopware-platform-plugin`.

```
<projektroot>/
├── bin/{build-js,build-administration,build-storefront,watch-administration,watch-storefront}.sh, functions.sh, console, ci
├── var/{plugins.json, config_js_features.json, theme-files.json}
├── custom/{plugins,static-plugins,apps}/SwagExample/
│   └── src/Resources/
│       ├── app/administration/{package.json, src/main.(js|ts)}
│       ├── app/storefront/{build/webpack.config.js, src/main.(js|ts)}
│       └── public/administration/            # Build-Ausgabe Admin (Vite, manifest.json)
└── vendor/shopware/
    ├── administration/Resources/app/administration/{build.ts, vite.config.mts, build/plugins.vite.ts}
    └── storefront/Resources/app/storefront/{webpack.config.js, vite.shopware.config.mts, vite.components.config.mts, build/}
```

Storefront-Plugin-Ausgabe (aus `webpack.config.js` abgeleitet): `Resources/app/storefront/dist/storefront/js/<technicalName>/<technicalName>.js`. ⚠ UNGEPRÜFT: nicht durch Build bestätigt.

## 3. Anleitung

### 3.1 Was die Skripte tun (am Code belegt)

| Skript | Ablauf |
|---|---|
| `bin/build-js.sh` | `set -euo pipefail`; ruft `build-administration.sh`, dann `build-storefront.sh` (falls vorhanden). |
| `bin/build-administration.sh` | Lädt `.env*` (`functions.sh::load_dotenv`). `bundle:dump` (Skip `SHOPWARE_SKIP_BUNDLE_DUMP`), `feature:dump`. Mit `jq`: je Eintrag aus `var/plugins.json` `npm install --omit=dev` im Admin-Pfad, falls `package.json` da und `node_modules` fehlt (Skip pro Plugin `SKIP_<TECHNICAL_NAME_UPPER_SNAKE>`; zusätzlich `npm ci --omit=dev` in `basePath` und `basePath/..`). Dann `npm install --omit=dev` im Admin-Bundle, Entity-Schema-Dump (`framework:schema -s entity-schema`, Skip `SHOPWARE_SKIP_ENTITY_SCHEMA_DUMP`), `npm run build`, `assets:install` (Skip `SHOPWARE_SKIP_ASSET_COPY`). In CI (`CI` gesetzt) `bin/ci` statt `bin/console`. |
| `bin/build-storefront.sh` | `bundle:dump`, `feature:dump`, Plugin-npm-Installs (im Plugin-Ordner `app/storefront` `npm install --prefer-offline` **ohne** `--omit=dev`, nur wenn `node_modules` fehlt; zusätzlich `npm ci --omit=dev` in `basePath` und `basePath/..`, sofern dort eine `package.json` liegt), `npm install --prefer-offline --omit=dev` im Storefront-Bundle (der Storefront-Build läuft trotzdem, weil `webpack`, `vite` u. a. dort unter `dependencies` stehen), `node copy-to-vendor.js`, `npm run production`, `assets:install`, `theme:compile --active-only` (Skip `SHOPWARE_SKIP_THEME_COMPILE`), `cache:clear` (außer mit `--keep-cache`). |
| `bin/watch-administration.sh` | `bundle:dump`, `feature:dump`, Plugin-npm-Install, Entity-Schema-Dump, `npm run dev` (Vite-Dev-Server pro Extension). Env: `HOST`, `VITE_HOST`, `ADMIN_PORT`, `APP_URL`, `SHOPWARE_ADMIN_SKIP_SOURCEMAP_GENERATION`, `DISABLE_DEVSERVER_OPEN`, `DDEV_PRIMARY_URL`; proxyt `/api` an `APP_URL`. |
| `bin/watch-storefront.sh` | `bundle:dump`, `feature:dump`, `theme:compile --active-only`, `theme:dump` (optionales Argument = technischer Theme-Name), Plugin-npm-Install, `npm run-script hot-proxy`. Env: `APP_URL`, `PROXY_URL`, `STOREFRONT_ASSETS_PORT` (9999), `STOREFRONT_PROXY_PORT` (9998), `STOREFRONT_HTTPS_KEY_FILE`, `STOREFRONT_HTTPS_CERTIFICATE_FILE`, `STOREFRONT_SKIP_SSL_CERT`. |

Weitere Skript-Details (am Code belegt):

- **`bin/functions.sh`** (`load_dotenv <datei>`): wird von `build-administration.sh`, `watch-administration.sh` und `watch-storefront.sh` gesourct (nicht von `build-storefront.sh`/`build-js.sh`). `LOAD_DOTENV=0` überspringt das Laden. Reihenfolge: `<datei>` (sonst `<datei>.dist`), dann `<datei>.local`, `<datei>.$APP_ENV` (Default `dev`), `<datei>.$APP_ENV.local`; spätere überschreiben frühere. Die Skripte sichern vorher die gesetzte Umgebung (`declare -p -x`) und stellen sie danach wieder her: **bereits exportierte Variablen haben Vorrang vor `.env`**. `ENV_FILE` Default `$PROJECT_ROOT/.env`, `PROJECT_ROOT` Default Elternordner von `bin/`.
- **Pfade:** Gibt es `vendor/shopware/platform`, liegen `ADMIN_ROOT`/`STOREFRONT_ROOT` unter `vendor/shopware/platform/src/{Administration,Storefront}`, sonst unter `vendor/shopware/{administration,storefront}`; per Env überschreibbar. `build-administration.sh`, `build-storefront.sh` und `watch-storefront.sh` setzen `NPM_CONFIG_FUND/AUDIT/UPDATE_NOTIFIER=false`; die beiden Build-Skripte zusätzlich `PUPPETEER_SKIP_(CHROMIUM_)DOWNLOAD=true`.
- **`build-js.sh`** führt die beiden Build-Skripte nur aus, wenn sie existieren (`-e`), und bricht bei Fehlern ab (`set -euo pipefail`).
- **`build-administration.sh`:** `feature:dump || true` (Fehler ignoriert); Plugin-Installs mit `npm install --omit=dev --no-audit --prefer-offline`, `name != administration`; der Schema-Dump läuft nur, wenn `entity-schema-converter.ts` existiert, mit `bin/console -e prod framework:schema -s entity-schema` nach `test/_mocks_/entity-schema.json` plus `npm run convert-entity-schema`; Admin-Bundle `npm install --prefer-offline --omit=dev`.
- **`build-storefront.sh`:** zusätzlich Skip `SHOPWARE_SKIP_FEATURE_DUMP`; Reihenfolge `assets:install` vor `theme:compile`; `--keep-cache` muss erstes Argument sein. Kein `load_dotenv`.
- **`watch-administration.sh`:** immer `bin/console` (kein `bin/ci`); `HOST` Default `localhost`; Plugin-Installs `npm install --omit=dev` (ohne `basePath`-`npm ci`); Admin-Bundle `npm install --prefer-offline` (**mit** devDependencies, nötig für Vite-Dev-Server); `npm run dev`.
- **`watch-storefront.sh`:** installiert das Storefront-Bundle nur, wenn `node_modules/webpack-dev-server` fehlt (`npm install --prefer-offline`, mit devDependencies); Reihenfolge `bundle:dump`, `feature:dump`, `theme:compile --active-only`, `theme:dump [--theme-name=<arg>]`; Plugin-Installs plain `npm install`; immer `bin/console`.
- **Plugin-Skip:** `SKIP_<TECHNICAL_NAME>` (Name in Großbuchstaben, Bindestriche zu `_`) überspringt das Plugin in allen vier Skripten; Quelle der Plugin-Liste ist `var/plugins.json` (Voraussetzung `bundle:dump`).

Voraussetzungen: `node`, `npm`, `bin/console` (PHP), optional `jq` (ohne `jq` werden Plugin-Abhängigkeiten nicht installiert, nur Hinweis). Scripts im Storefront-`package.json`: `production` (`webpack … && build:shopware && build:components`), `build:shopware`, `build:components`, `dev:server`, `development`, `hot-proxy`, `unit`, `unit:components`, `lint`.

### 3.2 Im Docker-Setup dieses Projekts

`compose.override.yaml`: Service `web` (Image `shopware-web:6.7.15.1`, Basis `ghcr.io/shopware/docker-base:8.4-nginx`, Projekt nach `/var/www/html`), Port `8100:8000`, `APP_URL=http://localhost:8100`; gemappt nur 8100 (web), 1025/8025 (mailer), 3307 (db).

```bash
docker compose exec web bin/build-js.sh                  # Admin + Storefront
docker compose exec web bin/build-storefront.sh --keep-cache
docker compose exec web bin/console bundle:dump
docker compose exec web bin/console feature:dump
```

Watcher im Container brauchen Port-Mappings (Storefront 9998/9999; Admin der Port aus `ADMIN_PORT`); sie fehlen in der Override-Datei und müssten ergänzt werden (Annahme, nicht getestet). `plugins.vite.ts` bindet Extension-Server an `127.0.0.1`; `isInsideDockerContainer()` verhindert das Browser-Öffnen.

### 3.3 Webpack/Vite erweitern

1. **Storefront (Webpack):** `build/webpack.config.js` im Plugin anlegen (Beispiel Abschnitt 4), dann `bin/console bundle:dump` und Build.
2. **Storefront-Komponenten (Vite):** Nur-Komponenten-Rebuild (aus `build/README.md`): `npm run build:components`, dann `assets:install`, `theme:compile --sync`. Dev-Server: `npm run dev:server` (Vite, Port 5175, Env `STOREFRONT_VITE_PORT|HOST|ORIGIN`). Die README nennt `composer build:js:storefront`/`composer storefront:dev-server`; diese Composer-Scripts fehlen im Projekt-`composer.json` (nur Platform-Monorepo).
3. **Admin (Vite):** Für Plugins ist im Code **keine** Hook-Datei für eine Vite-Erweiterung belegt. Nur-Extensions-Build: `SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1`; Sourcemaps: `GENERATE_SOURCEMAPS=true` bzw. `SHOPWARE_ADMIN_SKIP_SOURCEMAP_GENERATION=1`. Admin-Extension-Tooling (⚠ experimentell/`@internal`, `@experimental stableVersion:v6.8.0`): `npm run extension-tooling:setup|check` (siehe [T-48](./48-statische-analyse.md)).

### 3.4 Deployment

```bash
# Build-Phase (CI)
shopware-cli project ci .     # composer install --no-dev, fehlende Extension-Assets bauen, node_modules/Sourcen entfernen, Snippets mergen, SBOM
# Alternative ohne CLI
composer install --no-dev -o && bin/build-js.sh

# Server-Phase (einmalig vorher: composer require shopware/deployment-helper; fehlt im Projekt, ⚠ Paketname nur laut Doku)
vendor/bin/shopware-deployment-helper run [--skip-theme-compile] [--skip-assets-install]
```

Frisch: Schema, Admin-User, Storefront-Sales-Channel, Extensions installieren/aktivieren, Hooks. Update: Migrationen, Extension-Refresh, Theme-Compile, One-Time-Tasks, Hooks. Manuell: `bin/console system:update:finish`, `plugin:refresh`, `plugin:install --activate <Name>` bzw. `plugin:update <Name>`, `assets:install`, `theme:compile` (Optionen `--active-only`, `--only`, `--skip`, `--only-themes`, `--skip-themes`, `--sync`, `--keep-assets`), `cache:clear`. Composer-Plugins aus Packagist/`packages.shopware.com` (Env `SHOPWARE_PACKAGES_TOKEN`/`COMPOSER_AUTH`) oder lokalen Pfaden.

### 3.5 Plugin als ZIP

```bash
shopware-cli extension zip custom/plugins/SwagExample                    # Standard: letzter Git-Tag
shopware-cli extension zip custom/plugins/SwagExample --disable-git      # aktueller Stand
shopware-cli extension zip custom/plugins/SwagExample --git-commit <ref> --release
shopware-cli extension build custom/plugins/SwagExample                  # nur Assets
bin/console plugin:zip-import <zip> [--no-refresh] [--delete]            # ZIP einspielen
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
```

Konfiguration `.config/shopware-extension.yml` (Doku): `build.zip.assets.enable_es_build_for_admin|storefront`, `build.zip.pack.excludes.paths`, `build.zip.checksum.ignore`, `build.extraBundles`, `changelog`. ⚠ LÜCKE: Die Doku-Seitenzusammenfassung nennt auch `extension package` (siehe [T-48](./48-statische-analyse.md)); `shopware-cli` ist lokal nicht installiert, Befehlsnamen/Flags nicht ausgeführt.

## 4. Beispiele

`custom/plugins/SwagExample/src/Resources/app/storefront/build/webpack.config.js` (die Funktion erhält `{ basePath, env, config, name, technicalName, technicalFolderName, plugin }` und liefert ein Objekt, das über Core- und Plugin-Basis-Config gemergt wird)

```js
const path = require('path');

module.exports = ({ basePath }) => {
    return {
        resolve: {
            alias: {
                SwagExample: path.resolve(basePath, 'Resources/app/storefront/src'),
            },
        },
    };
};
```

`src/Resources/app/storefront/src/main.js` (muss `main.js|ts` heißen)

```js
import ExamplePlugin from './example-plugin/example-plugin.plugin';

window.PluginManager.register('ExamplePlugin', ExamplePlugin, '[data-example-plugin]');
```

`.config/shopware-project.yml` (Deployment-Helper, laut Doku; Voraussetzung `composer require shopware/deployment-helper`; ⚠ LÜCKE: Dateiname widersprüchlich, in der Übersicht ist auch `deployment.yaml` erwähnt, Schlüssel von der Konfigurationsseite)

```yaml
deployment:
  hooks:
    post-update: |
      ./bin/console cache:clear
  extension-management:
    enabled: true
    overrides:
      SwagExample:
        state: ignore
  maintenance:
    enabled: true
  cache:
    always_clear: true
```

Admin-Entry: `src/Resources/app/administration/src/main.js` (Vite-Aliasse `~scss/...`, `src/...` vordefiniert).

## 5. Fallstricke

- Admin-Doku folgen und `build/webpack.config.js` für das Admin anlegen: wird in 6.7.15.1 nicht ausgewertet (`BundleConfigGenerator` schreibt den Pfad nur in `var/plugins.json`, `plugins.vite.ts` liest ihn nicht).
- Entry nicht `main.js|ts`: Plugin wird nicht gebaut.
- `var/plugins.json` fehlt/veraltet: Webpack bricht ab ("Try bin/console bundle:dump"); nach neuem/aktiviertem Plugin immer `bundle:dump`.
- `var/config_js_features.json` fehlt: alle JS-Features deaktiviert (Warnung im Webpack-Log), `feature:dump`.
- `jq` fehlt: Plugin-npm-Abhängigkeiten werden nicht installiert.
- Build ohne `assets:install`/`theme:compile`/`cache:clear`: alte Dateien sichtbar (`build-storefront.sh` macht alles, außer mit `SHOPWARE_SKIP_*`/`--keep-cache`).
- Watcher im Docker ohne Port-Mapping: Dev-Server nicht erreichbar.
- Parallele Vite-Plugin-Builds: `emptyOutDir` leert `Resources/public/administration`; `sw-plugin-dev.json` liegt bewusst außerhalb.
- `--omit=dev` gilt nicht überall: `build-administration.sh` installiert Plugin-Abhängigkeiten im Admin-Ordner mit `--omit=dev` (Build-Tools des Plugins gehören dort in `dependencies`); `build-storefront.sh` installiert im Plugin-Ordner `app/storefront` **ohne** `--omit=dev` (`devDependencies` werden installiert), nutzt `--omit=dev` aber für das Storefront-Bundle und für `npm ci` in `basePath`/`basePath/..`. Eine `package.json` direkt im Plugin-Root wird also ohne `devDependencies` installiert.
- Build-Artefakte (`Resources/public/administration`, `app/storefront/dist`) nicht im ZIP/Deployment: Shop ohne Plugin-JS; `shopware-cli extension zip` baut sie, ein reines `git archive` nicht.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Admin: Webpack (6.5/6.6) → Vite (6.7, Code belegt; Umstellungszeitpunkt nicht belegt; Doku bleibt Webpack, Widerspruch, Code gilt). Admin-Watcher nutzt Vite-Dev-Server pro Extension.
- Storefront: Webpack bleibt; neu in 6.7.15.1 ist die Vite-Pipeline für `shopware.js` und Twig-Komponenten.
- `ThemeCompileCommand`: `--no-cleanup` deprecated (`@deprecated tag:v6.8.0`), Aufräumen macht der Scheduled Task `theme.delete_files`; `--sync` vorhanden.
- `feature:dump` hat Alias `administration:dump:features`.
- Deployment-Helper und `shopware-cli project ci` ersetzen ältere Abläufe (`bin/build-js.sh` auf dem Server); Vergleich mit 6.5/6.6 nicht im Code belegt. CLI-Validierung: Node 20+, PHP 8.2+ (Doku).

## 6. Checkliste

- [ ] Entry `main.js|ts` vorhanden (Admin und/oder Storefront)
- [ ] Storefront-Webpack-Erweiterung nur unter `app/storefront/build/webpack.config.js`; keine Admin-Webpack-Config
- [ ] Plugin-Build-Tools in `dependencies`, wo `--omit=dev` greift (Admin-Plugin-Ordner, `basePath`, `basePath/..`); im Storefront-Plugin-Ordner genügen `devDependencies`
- [ ] `bundle:dump` und `feature:dump` vor jedem Build (Skripte tun es selbst)
- [ ] Nach Build: `assets:install`, `theme:compile`, `cache:clear`
- [ ] Watcher im Docker: Ports gemappt oder lokal auf dem Host
- [ ] CI: `shopware-cli project ci` oder `composer install --no-dev -o` + `bin/build-js.sh`
- [ ] Server: `composer require shopware/deployment-helper` erledigt, dann `shopware-deployment-helper run`
- [ ] ZIP mit `shopware-cli extension zip`, Artefakte enthalten, `extension validate` ausgeführt

## 7. Quellen

Doku: https://developer.shopware.com/docs/guides/plugins/plugins/administration/advanced-configuration/extending-webpack.html (Admin = Webpack, veraltet gegenüber Code); https://developer.shopware.com/docs/guides/hosting/installation-updates/deployments/deployment-helper.html (+ Unterseiten `configuration`, `environment`, `one-time-tasks`, `troubleshooting`); https://developer.shopware.com/docs/products/cli/ (Index; Pfade `/docs/products/tools/cli/extension-commands/build.html`, `/docs/products/tools/cli/project-commands/build.html`, `/docs/products/tools/cli/installation.html`).

Quellcode/Projekt: `bin/{build-js,build-administration,build-storefront,watch-administration,watch-storefront}.sh`, `bin/functions.sh`, `compose.yaml`, `compose.override.yaml`, `composer.json`; unter `vendor/shopware/`: `core/Framework/Plugin/BundleConfigGenerator.php` (`getConfig`, `getEntryFile`, `getWebpackConfig`, `hasStorefrontComponentAssets`, `isTheme`), `core/Framework/Plugin/Command/{BundleDumpCommand,PluginZipImportCommand,PluginRefreshCommand}.php`, `core/Framework/Adapter/Asset/AssetInstallCommand.php`, `core/Framework/Feature/Command/FeatureDumpCommand.php`, `storefront/Theme/Command/{ThemeCompileCommand,ThemeDumpCommand}.php`, `storefront/Resources/app/storefront/{package.json,webpack.config.js,vite.shopware.config.mts,vite.components.config.mts,build/README.md,build/start-hot-reload.js,build/vite/}`, `administration/Resources/app/administration/{package.json,build.ts,vite.config.mts,build/plugins.vite.ts,build/vite-plugins/,extension-tooling/README.md}`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: `shopware-cli` lokal nicht installiert; `extension zip` (Auftrag) vs. `extension package` (Doku) nicht verifiziert; Flags nicht ausgeführt.
- ⚠ UNGEPRÜFT: Skripte nicht im `web`-Container ausgeführt (kein Docker); Node/jq im Image `docker-base:8.4-nginx` unbelegt.
- ⚠ LÜCKE: Port-Default von `ADMIN_PORT`/`getViteServerPorts()` nicht ausgelesen; Vite-Erweiterung für Admin-Plugins im Code nicht belegt (Annahme: nicht vorgesehen).
- ⚠ UNGEPRÜFT: genauer Pfad der Storefront-Plugin-Ausgabe; `.gitignore`-Inhalt zu `custom/plugins`; Composer-Scripts `build:js:*` nur aus README, im Projekt nicht vorhanden.
- ⚠ LÜCKE: `shopware/deployment-helper` ist im Projekt nicht installiert; Paketname/Version nur aus der Doku, nicht gegen Packagist geprüft.
- ⚠ LÜCKE: Deployment-Helper-Konfig-Dateiname (`.config/shopware-project.yml` vs. `deployment.yaml`) in der Doku widersprüchlich.
