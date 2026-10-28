# Build and deployment (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies to everything around building admin and storefront JS, the scripts `bin/build-*.sh` and `bin/watch-*.sh`, Webpack/Vite extension, deployment (Composer plugins, Deployment Helper) and packaging a plugin as a ZIP.

**Answer to the open question Webpack vs. Vite:** in 6.7.15.1 the admin build is **Vite**, not Webpack (the admin no longer has a `webpack.config.*`; `build` = `VITE_MODE=production ts-node -T build.ts`). The storefront is **hybrid**: plugin/theme JS still with **Webpack**, `shopware.js` and Twig components with **Vite**. The docs page "Extending Webpack" (admin) is outdated and contradicts the code.

Scope: storefront JS plugins [T-15](./15-storefront-js-plugins.md); admin [T-24](./24-admin-modules.md), [T-25](./25-admin-components.md); project structure/Docker [T-01](./01-project-structure-dev-environment.md); lifecycle CLI [T-03](./03-plugin-lifecycle.md); tests [T-47](./47-tests-jest.md); lint [T-48](./48-static-analysis.md).

## 2. Concepts

- **Scripts** (`bin/*.sh`, project root) are thin orchestrators: `bundle:dump` > `feature:dump` > npm install in plugins > build > `assets:install` (storefront additionally `theme:compile` > `cache:clear`).
- **Inputs of the build tools:** `var/plugins.json` (from `bundle:dump`), `var/config_js_features.json` (from `feature:dump`), `var/theme-files.json` (from `theme:dump`, hot mode).
- **Entry is mandatory:** `src/Resources/app/{administration,storefront}/src/main.js|ts`; otherwise `entryFilePath = null` and the plugin is not built (`BundleConfigGenerator::getEntryFile`).
- **Storefront Webpack extension:** `<Plugin>/src/Resources/app/storefront/build/webpack.config.(ts|cts|js|cjs)` (order in `BundleConfigGenerator::getWebpackConfig()`); loaded by the storefront `webpack.config.js` and merged via `webpack-merge`.
- **Storefront Vite:** `vite.shopware.config.mts` (runtime `shopware.js`), `vite.components.config.mts` + `build/vite/build-components.js` for Twig components under `Resources/views/components/**` with `.js/.ts/.scss/.css` (detected via `hasComponentAssets`).
- **Admin Vite:** `build/plugins.vite.ts` builds per extension (`root: extension.path`, output `Resources/public/administration`, `manifest: true`, `base: /bundles/<technicalFolderName>/administration/`); built in: Twig, Asset, Vue (compat MODE 2), SVG, `ExternalsPlugin`, `OverrideComponentRegisterPlugin`, `vite-plugin-symfony`.
- **Deployment:** build in CI (`shopware-cli project ci`), rollout with `vendor/bin/shopware-deployment-helper run` (prerequisite: `composer require shopware/deployment-helper`; in this project present neither in `composer.json` nor in `vendor/`, package name according to the docs page on the Deployment Helper, ⚠ cannot be verified locally). Plugins via Composer (`custom/plugins/*`, `custom/static-plugins/*` as path repositories with symlink; configured in `composer.json` in this project), package type `shopware-platform-plugin`.

```
<project-root>/
├── bin/{build-js,build-administration,build-storefront,watch-administration,watch-storefront}.sh, functions.sh, console, ci
├── var/{plugins.json, config_js_features.json, theme-files.json}
├── custom/{plugins,static-plugins,apps}/SwagExample/
│   └── src/Resources/
│       ├── app/administration/{package.json, src/main.(js|ts)}
│       ├── app/storefront/{build/webpack.config.js, src/main.(js|ts)}
│       └── public/administration/            # admin build output (Vite, manifest.json)
└── vendor/shopware/
    ├── administration/Resources/app/administration/{build.ts, vite.config.mts, build/plugins.vite.ts}
    └── storefront/Resources/app/storefront/{webpack.config.js, vite.shopware.config.mts, vite.components.config.mts, build/}
```

Storefront plugin output (derived from `webpack.config.js`): `Resources/app/storefront/dist/storefront/js/<technicalName>/<technicalName>.js`. ⚠ UNVERIFIED: not confirmed by a build.

## 3. Instructions

### 3.1 What the scripts do (verified against the code)

| Script | Flow |
|---|---|
| `bin/build-js.sh` | `set -euo pipefail`; calls `build-administration.sh`, then `build-storefront.sh` (if present). |
| `bin/build-administration.sh` | Loads `.env*` (`functions.sh::load_dotenv`). `bundle:dump` (skip `SHOPWARE_SKIP_BUNDLE_DUMP`), `feature:dump`. With `jq`: for each entry from `var/plugins.json`, `npm install --omit=dev` in the admin path if `package.json` exists and `node_modules` is missing (skip per plugin `SKIP_<TECHNICAL_NAME_UPPER_SNAKE>`; additionally `npm ci --omit=dev` in `basePath` and `basePath/..`). Then `npm install --omit=dev` in the admin bundle, entity schema dump (`framework:schema -s entity-schema`, skip `SHOPWARE_SKIP_ENTITY_SCHEMA_DUMP`), `npm run build`, `assets:install` (skip `SHOPWARE_SKIP_ASSET_COPY`). In CI (`CI` set) `bin/ci` instead of `bin/console`. |
| `bin/build-storefront.sh` | `bundle:dump`, `feature:dump`, plugin npm installs (in the plugin folder `app/storefront` `npm install --prefer-offline` **without** `--omit=dev`, only if `node_modules` is missing; additionally `npm ci --omit=dev` in `basePath` and `basePath/..` if a `package.json` exists there), `npm install --prefer-offline --omit=dev` in the storefront bundle (the storefront build still works because `webpack`, `vite` and others are listed under `dependencies` there), `node copy-to-vendor.js`, `npm run production`, `assets:install`, `theme:compile --active-only` (skip `SHOPWARE_SKIP_THEME_COMPILE`), `cache:clear` (except with `--keep-cache`). |
| `bin/watch-administration.sh` | `bundle:dump`, `feature:dump`, plugin npm install, entity schema dump, `npm run dev` (Vite dev server per extension). Env: `HOST`, `VITE_HOST`, `ADMIN_PORT`, `APP_URL`, `SHOPWARE_ADMIN_SKIP_SOURCEMAP_GENERATION`, `DISABLE_DEVSERVER_OPEN`, `DDEV_PRIMARY_URL`; proxies `/api` to `APP_URL`. |
| `bin/watch-storefront.sh` | `bundle:dump`, `feature:dump`, `theme:compile --active-only`, `theme:dump` (optional argument = technical theme name), plugin npm install, `npm run-script hot-proxy`. Env: `APP_URL`, `PROXY_URL`, `STOREFRONT_ASSETS_PORT` (9999), `STOREFRONT_PROXY_PORT` (9998), `STOREFRONT_HTTPS_KEY_FILE`, `STOREFRONT_HTTPS_CERTIFICATE_FILE`, `STOREFRONT_SKIP_SSL_CERT`. |

Further script details (verified against the code):

- **`bin/functions.sh`** (`load_dotenv <file>`): sourced by `build-administration.sh`, `watch-administration.sh` and `watch-storefront.sh` (not by `build-storefront.sh`/`build-js.sh`). `LOAD_DOTENV=0` skips loading. Order: `<file>` (otherwise `<file>.dist`), then `<file>.local`, `<file>.$APP_ENV` (default `dev`), `<file>.$APP_ENV.local`; later ones override earlier ones. The scripts save the current environment beforehand (`declare -p -x`) and restore it afterwards: **already exported variables take precedence over `.env`**. `ENV_FILE` defaults to `$PROJECT_ROOT/.env`, `PROJECT_ROOT` defaults to the parent folder of `bin/`.
- **Paths:** if `vendor/shopware/platform` exists, `ADMIN_ROOT`/`STOREFRONT_ROOT` are under `vendor/shopware/platform/src/{Administration,Storefront}`, otherwise under `vendor/shopware/{administration,storefront}`; can be overridden via env. `build-administration.sh`, `build-storefront.sh` and `watch-storefront.sh` set `NPM_CONFIG_FUND/AUDIT/UPDATE_NOTIFIER=false`; the two build scripts additionally set `PUPPETEER_SKIP_(CHROMIUM_)DOWNLOAD=true`.
- **`build-js.sh`** runs the two build scripts only if they exist (`-e`) and aborts on errors (`set -euo pipefail`).
- **`build-administration.sh`:** `feature:dump || true` (errors ignored); plugin installs with `npm install --omit=dev --no-audit --prefer-offline`, `name != administration`; the schema dump only runs if `entity-schema-converter.ts` exists, with `bin/console -e prod framework:schema -s entity-schema` to `test/_mocks_/entity-schema.json` plus `npm run convert-entity-schema`; admin bundle `npm install --prefer-offline --omit=dev`.
- **`build-storefront.sh`:** additionally skip `SHOPWARE_SKIP_FEATURE_DUMP`; order `assets:install` before `theme:compile`; `--keep-cache` must be the first argument. No `load_dotenv`.
- **`watch-administration.sh`:** always `bin/console` (no `bin/ci`); `HOST` defaults to `localhost`; plugin installs `npm install --omit=dev` (without the `basePath` `npm ci`); admin bundle `npm install --prefer-offline` (**with** devDependencies, required for the Vite dev server); `npm run dev`.
- **`watch-storefront.sh`:** installs the storefront bundle only if `node_modules/webpack-dev-server` is missing (`npm install --prefer-offline`, with devDependencies); order `bundle:dump`, `feature:dump`, `theme:compile --active-only`, `theme:dump [--theme-name=<arg>]`; plugin installs plain `npm install`; always `bin/console`.
- **Plugin skip:** `SKIP_<TECHNICAL_NAME>` (name in uppercase, hyphens to `_`) skips the plugin in all four scripts; the source of the plugin list is `var/plugins.json` (prerequisite `bundle:dump`).

Prerequisites: `node`, `npm`, `bin/console` (PHP), optionally `jq` (without `jq`, plugin dependencies are not installed, only a notice). Scripts in the storefront `package.json`: `production` (`webpack … && build:shopware && build:components`), `build:shopware`, `build:components`, `dev:server`, `development`, `hot-proxy`, `unit`, `unit:components`, `lint`.

### 3.2 In this project's Docker setup

`compose.override.yaml`: service `web` (image `shopware-web:6.7.15.1`, base `ghcr.io/shopware/docker-base:8.4-nginx`, project at `/var/www/html`), port `8100:8000`, `APP_URL=http://localhost:8100`; only 8100 (web), 1025/8025 (mailer), 3307 (db) are mapped.

```bash
docker compose exec web bin/build-js.sh                  # admin + storefront
docker compose exec web bin/build-storefront.sh --keep-cache
docker compose exec web bin/console bundle:dump
docker compose exec web bin/console feature:dump
```

Watchers in the container need port mappings (storefront 9998/9999; admin the port from `ADMIN_PORT`); they are missing in the override file and would have to be added (assumption, not tested). `plugins.vite.ts` binds extension servers to `127.0.0.1`; `isInsideDockerContainer()` prevents opening the browser.

### 3.3 Extending Webpack/Vite

1. **Storefront (Webpack):** create `build/webpack.config.js` in the plugin (example in section 4), then `bin/console bundle:dump` and build.
2. **Storefront components (Vite):** components-only rebuild (from `build/README.md`): `npm run build:components`, then `assets:install`, `theme:compile --sync`. Dev server: `npm run dev:server` (Vite, port 5175, env `STOREFRONT_VITE_PORT|HOST|ORIGIN`). The README names `composer build:js:storefront`/`composer storefront:dev-server`; these Composer scripts are missing from the project `composer.json` (platform monorepo only).
3. **Admin (Vite):** for plugins, **no** hook file for a Vite extension is documented in the code. Extensions-only build: `SHOPWARE_ADMIN_BUILD_ONLY_EXTENSIONS=1`; sourcemaps: `GENERATE_SOURCEMAPS=true` or `SHOPWARE_ADMIN_SKIP_SOURCEMAP_GENERATION=1`. Admin extension tooling (⚠ experimental/`@internal`, `@experimental stableVersion:v6.8.0`): `npm run extension-tooling:setup|check` (see [T-48](./48-static-analysis.md)).

### 3.4 Deployment

```bash
# Build phase (CI)
shopware-cli project ci .     # composer install --no-dev, build missing extension assets, remove node_modules/sources, merge snippets, SBOM
# Alternative without CLI
composer install --no-dev -o && bin/build-js.sh

# Server phase (once beforehand: composer require shopware/deployment-helper; missing in the project, ⚠ package name only according to the docs)
vendor/bin/shopware-deployment-helper run [--skip-theme-compile] [--skip-assets-install]
```

Fresh install: schema, admin user, storefront sales channel, install/activate extensions, hooks. Update: migrations, extension refresh, theme compile, one-time tasks, hooks. Manually: `bin/console system:update:finish`, `plugin:refresh`, `plugin:install --activate <Name>` or `plugin:update <Name>`, `assets:install`, `theme:compile` (options `--active-only`, `--only`, `--skip`, `--only-themes`, `--skip-themes`, `--sync`, `--keep-assets`), `cache:clear`. Composer plugins from Packagist/`packages.shopware.com` (env `SHOPWARE_PACKAGES_TOKEN`/`COMPOSER_AUTH`) or local paths.

### 3.5 Plugin as ZIP

```bash
shopware-cli extension zip custom/plugins/SwagExample                    # default: latest Git tag
shopware-cli extension zip custom/plugins/SwagExample --disable-git      # current state
shopware-cli extension zip custom/plugins/SwagExample --git-commit <ref> --release
shopware-cli extension build custom/plugins/SwagExample                  # assets only
bin/console plugin:zip-import <zip> [--no-refresh] [--delete]            # import ZIP
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
```

Configuration `.config/shopware-extension.yml` (docs): `build.zip.assets.enable_es_build_for_admin|storefront`, `build.zip.pack.excludes.paths`, `build.zip.checksum.ignore`, `build.extraBundles`, `changelog`. ⚠ GAP: the docs page summary also names `extension package` (see [T-48](./48-static-analysis.md)); `shopware-cli` is not installed locally, command names/flags not executed.

## 4. Examples

`custom/plugins/SwagExample/src/Resources/app/storefront/build/webpack.config.js` (the function receives `{ basePath, env, config, name, technicalName, technicalFolderName, plugin }` and returns an object that is merged over the core and plugin base config)

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

`src/Resources/app/storefront/src/main.js` (must be named `main.js|ts`)

```js
import ExamplePlugin from './example-plugin/example-plugin.plugin';

window.PluginManager.register('ExamplePlugin', ExamplePlugin, '[data-example-plugin]');
```

`.config/shopware-project.yml` (Deployment Helper, according to the docs; prerequisite `composer require shopware/deployment-helper`; ⚠ GAP: file name contradictory, the overview also mentions `deployment.yaml`; keys from the configuration page)

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

Admin entry: `src/Resources/app/administration/src/main.js` (Vite aliases `~scss/...`, `src/...` predefined).

## 5. Pitfalls

- Following the admin docs and creating `build/webpack.config.js` for the admin: not evaluated in 6.7.15.1 (`BundleConfigGenerator` only writes the path to `var/plugins.json`, `plugins.vite.ts` does not read it).
- Entry not named `main.js|ts`: the plugin is not built.
- `var/plugins.json` missing/outdated: Webpack aborts ("Try bin/console bundle:dump"); always run `bundle:dump` after a new/activated plugin.
- `var/config_js_features.json` missing: all JS features disabled (warning in the Webpack log); run `feature:dump`.
- `jq` missing: plugin npm dependencies are not installed.
- Build without `assets:install`/`theme:compile`/`cache:clear`: old files visible (`build-storefront.sh` does everything, except with `SHOPWARE_SKIP_*`/`--keep-cache`).
- Watchers in Docker without port mapping: dev server not reachable.
- Parallel Vite plugin builds: `emptyOutDir` empties `Resources/public/administration`; `sw-plugin-dev.json` deliberately lives outside it.
- `--omit=dev` does not apply everywhere: `build-administration.sh` installs plugin dependencies in the admin folder with `--omit=dev` (the plugin's build tools belong in `dependencies` there); `build-storefront.sh` installs in the plugin folder `app/storefront` **without** `--omit=dev` (`devDependencies` are installed), but uses `--omit=dev` for the storefront bundle and for `npm ci` in `basePath`/`basePath/..`. A `package.json` directly in the plugin root is therefore installed without `devDependencies`.
- Build artifacts (`Resources/public/administration`, `app/storefront/dist`) not in the ZIP/deployment: shop without plugin JS; `shopware-cli extension zip` builds them, a plain `git archive` does not.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Admin: Webpack (6.5/6.6) → Vite (6.7, documented in code; switchover point not documented; the docs remain on Webpack, contradiction, the code applies). The admin watcher uses a Vite dev server per extension.
- Storefront: Webpack remains; new in 6.7.15.1 is the Vite pipeline for `shopware.js` and Twig components.
- `ThemeCompileCommand`: `--no-cleanup` deprecated (`@deprecated tag:v6.8.0`), cleanup is done by the scheduled task `theme.delete_files`; `--sync` available.
- `feature:dump` has the alias `administration:dump:features`.
- Deployment Helper and `shopware-cli project ci` replace older workflows (`bin/build-js.sh` on the server); comparison with 6.5/6.6 not documented in the code. CLI validation: Node 20+, PHP 8.2+ (docs).

## 6. Checklist

- [ ] Entry `main.js|ts` present (admin and/or storefront)
- [ ] Storefront Webpack extension only under `app/storefront/build/webpack.config.js`; no admin Webpack config
- [ ] Plugin build tools in `dependencies` where `--omit=dev` applies (admin plugin folder, `basePath`, `basePath/..`); in the storefront plugin folder `devDependencies` are sufficient
- [ ] `bundle:dump` and `feature:dump` before every build (the scripts do it themselves)
- [ ] After the build: `assets:install`, `theme:compile`, `cache:clear`
- [ ] Watchers in Docker: ports mapped, or run locally on the host
- [ ] CI: `shopware-cli project ci` or `composer install --no-dev -o` + `bin/build-js.sh`
- [ ] Server: `composer require shopware/deployment-helper` done, then `shopware-deployment-helper run`
- [ ] ZIP with `shopware-cli extension zip`, artifacts included, `extension validate` executed

## 7. Sources

Docs: https://developer.shopware.com/docs/guides/plugins/plugins/administration/advanced-configuration/extending-webpack.html (admin = Webpack, outdated compared to the code); https://developer.shopware.com/docs/guides/hosting/installation-updates/deployments/deployment-helper.html (+ subpages `configuration`, `environment`, `one-time-tasks`, `troubleshooting`); https://developer.shopware.com/docs/products/cli/ (index; paths `/docs/products/tools/cli/extension-commands/build.html`, `/docs/products/tools/cli/project-commands/build.html`, `/docs/products/tools/cli/installation.html`).

Source code/project: `bin/{build-js,build-administration,build-storefront,watch-administration,watch-storefront}.sh`, `bin/functions.sh`, `compose.yaml`, `compose.override.yaml`, `composer.json`; under `vendor/shopware/`: `core/Framework/Plugin/BundleConfigGenerator.php` (`getConfig`, `getEntryFile`, `getWebpackConfig`, `hasStorefrontComponentAssets`, `isTheme`), `core/Framework/Plugin/Command/{BundleDumpCommand,PluginZipImportCommand,PluginRefreshCommand}.php`, `core/Framework/Adapter/Asset/AssetInstallCommand.php`, `core/Framework/Feature/Command/FeatureDumpCommand.php`, `storefront/Theme/Command/{ThemeCompileCommand,ThemeDumpCommand}.php`, `storefront/Resources/app/storefront/{package.json,webpack.config.js,vite.shopware.config.mts,vite.components.config.mts,build/README.md,build/start-hot-reload.js,build/vite/}`, `administration/Resources/app/administration/{package.json,build.ts,vite.config.mts,build/plugins.vite.ts,build/vite-plugins/,extension-tooling/README.md}`.

### Gaps and uncertainties

- ⚠ GAP: `shopware-cli` not installed locally; `extension zip` (assignment) vs. `extension package` (docs) not verified; flags not executed.
- ⚠ UNVERIFIED: scripts not executed in the `web` container (no Docker); Node/jq in the image `docker-base:8.4-nginx` undocumented.
- ⚠ GAP: default port of `ADMIN_PORT`/`getViteServerPorts()` not read out; Vite extension for admin plugins not documented in the code (assumption: not intended).
- ⚠ UNVERIFIED: exact path of the storefront plugin output; `.gitignore` content regarding `custom/plugins`; Composer scripts `build:js:*` only from the README, not present in the project.
- ⚠ GAP: `shopware/deployment-helper` is not installed in the project; package name/version only from the docs, not checked against Packagist.
- ⚠ GAP: Deployment Helper config file name (`.config/shopware-project.yml` vs. `deployment.yaml`) contradictory in the docs.
