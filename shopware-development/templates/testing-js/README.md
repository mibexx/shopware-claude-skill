# Template: JS tests (Jest) for administration and storefront (SwagExample)

Purpose: Jest scaffold for a plugin: administration component test (Vue 3, `@vue/test-utils`) and storefront JS plugin test (PluginManager plugin). Shopware 6.7.15.1. Background: [T-47](../../references/47-tests-jest.md). PHP tests: [T-46](../../references/46-tests-phpunit.md), PHPUnit/quality scaffolds: [testing](../testing/README.md), [quality](../quality/README.md); lint/static analysis: [T-48](../../references/48-static-analysis.md); admin components: [T-25](../../references/25-admin-components.md), example module: [admin-module](../admin-module/README.md); storefront JS plugins: [T-15](../../references/15-storefront-js-plugins.md), test subject: [storefront-js-plugin](../storefront-js-plugin/README.md); build: [T-49](../../references/49-build-deployment.md).

Not covered: Playwright/Acceptance Test Suite (E2E) and Vitest (only for the storefront component system, see T-47).

## Tested in practice (as of this writing)

Both scaffolds were rebuilt in a temporary directory outside the project (layout `vendor/shopware/{administration,storefront}` + `custom/plugins/SwagExample`) and executed:

| Variant | Result |
|---|---|
| Storefront, host Node v26.10.0 / npm 11.19.1 | 1 test green |
| Storefront, container `node:22` (Node 22.23.4, npm 10.9.9) | 1 test green |
| Administration, container `node:22` | 2 tests green |
| Administration, host Node v26.10.0 | 2 tests green (v26 is outside the admin `engines`, see below) |
| Administration with `ADMIN_PATH` pointing to a copy of the admin sources | 2 tests green |

Not checked: a run directly against the real files under `vendor/shopware/...` and against a real plugin in `custom/plugins/` (deliberately nothing was installed/changed there); Windows/Linux host; `--watch`; coverage.

## File tree

```
SwagExample/src/Resources/app/
├── administration/
│   ├── package.json                 # scripts + devDependencies
│   ├── babel.config.json            # MANDATORY (without it: "Cannot use import statement")
│   ├── jest.config.js               # preset + mapper additions
│   └── src/component/swag-example-counter/
│       ├── index.js
│       ├── swag-example-counter.html.twig
│       └── swag-example-counter.spec.js   # test next to the code (*.spec.js)
└── storefront/
    ├── package.json
    ├── babel.config.js              # MANDATORY, as babel.config.js (not .babelrc.js)
    ├── jest.config.js
    ├── jest.init.js                 # PluginManager mock + window.PluginBaseClass
    └── test/swag-example/swag-example.plugin.test.js   # *.test.js under test/
```

The storefront test imports `plugin/swag-example/swag-example.plugin`. For this, place the plugin from [storefront-js-plugin](../storefront-js-plugin/README.md) at `storefront/src/swag-example/swag-example.plugin.js` (this template does not duplicate it). The admin example component belongs only to this scaffold, not to the admin-module template.

## Prerequisite: Node in the project Docker setup

**The `web` container of this project has no Node and no npm** (checked: `docker compose exec -T web sh -c 'which node npm'` returns nothing, `node: not found`; image `shopware-web:6.7.15.1` from `ghcr.io/shopware/docker-base:8.4-nginx`). JS tests therefore do not run via `docker compose exec web ...`. Two checked ways:

**(i) Node on the host.** The project directory is mounted via a volume (`./:/var/www/html`, `compose.override.yaml`), so the plugin files under `custom/plugins/` live on the host. Run there:

```bash
cd custom/plugins/SwagExample/src/Resources/app/storefront
npm install        # once
npm run unit
```

**(ii) Separate Node container** (mount the project root, because the configs point to `vendor/` via relative paths):

```bash
docker run --rm -v "$PWD":/app \
  -w /app/custom/plugins/SwagExample/src/Resources/app/storefront \
  node:22 sh -c 'npm install --no-audit --no-fund && npm run unit'
```

With Docker Desktop on macOS, `docker` may not be in the PATH: `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`.

Node version (established in the core `package.json` files): storefront `engines.node: ^20.19.0 || >=22.12.0`, `engines.npm: >=11.8.0`; administration `engines.node: ^20.0.0 || ... || ^25.0.0`, `engines.npm: >=10.0.0`. Recommendation: Node 22 (common intersection). The host has Node 26, which the admin `engines` do not cover (ran anyway). The `node:22` container has npm 10.9.9, below the storefront requirement `>=11.8.0` (ran anyway; the core itself simply does not enforce this for plugins). ⚠ UNVERIFIED: behavior with `engine-strict`.

Important: `node_modules` is platform-dependent (host macOS vs. container Linux). Use only one way per directory or delete `node_modules` before switching.

## Storefront (Jest)

Setup: copy the files from `storefront/` into the plugin, `npm install`, check the path in `moduleNameMapper` (`^src/`) (7x `..` up to the project root with the layout `custom/plugins/<Plugin>/src/Resources/app/storefront`; adjust for a plugin in `custom/static-plugins` or a symlink). Run: `npm run unit`.

Established by the run:
- `modulePaths: ['<rootDir>/node_modules']` is necessary, otherwise the core code under `vendor/` does not find its packages (`deepmerge`). The core code has no `node_modules` of its own (`vendor/shopware/storefront/.../storefront` has none). Core imports from `src/` only load what is installed in the plugin; `core-js` and `deepmerge` are enough for the `Plugin` base class and `$emitter`. If your code imports further core modules with packages, add those in the plugin.
- `babel.config.js` in the plugin folder (root config) is needed; a `.babelrc.js` there did not apply to files under `vendor/` (test failed with a parse error).
- `babel-jest` does not need to be installed separately (comes with Jest).
- The example plugin reads `window.PluginBaseClass`; in the browser `plugin.manager.js` sets it. In `jest.init.js` it is therefore set from `src/plugin-system/plugin.class`. Plugins that import `PluginBaseClass` directly do not need this.

At folder level, `bin/build-storefront.sh` evaluates a `package.json` in `.../app/storefront` and runs `npm install` there if no `node_modules` exists yet (including `devDependencies`, i.e. Jest). ⚠ UNVERIFIED: effect of `babel.config.js`/`jest.config.js` in the plugin folder on the storefront build (the core uses `swc-loader`); run `bin/build-storefront.sh` before shipping and do not ship `node_modules`.

## Administration (Jest + preset `@shopware-ag/jest-preset-sw6-admin` 6.0.6)

The core `test/` directory (setup, helpers, `wrapTestComponent`) is not in the vendor package `administration`. The preset partially replaces it: `Shopware` global, mixins, directives, Twig transformer (dependencies of preset 6.0.6: `@babel/core`, `@babel/preset-typescript`, `babel-jest ^27`, `babel-plugin-shopware-vite-meta-glob`).

Prerequisite: the administration sources **with an installed `node_modules`** (the preset loads `src/core/shopware.ts` and `node_modules/vue` from there). In the project this is not the case (`vendor/shopware/administration/.../administration` has no `node_modules`). What was checked is `npm ci --ignore-scripts` on a copy (`src`, `package.json`, `package-lock.json`, `patches`, `scripts`), around 1 GB, ~2000 packages, in the container `node:22` and on the host:

```bash
cp -R vendor/shopware/administration/Resources/app/administration /path/admin-copy   # outside the project
cd /path/admin-copy && npm ci --ignore-scripts
cd custom/plugins/SwagExample/src/Resources/app/administration
npm install
ADMIN_PATH=/path/admin-copy npm run unit
```

⚠ GAP: `npm ci` directly in `vendor/` (default path of `jest.config.js`) was deliberately not executed; `vendor/` is managed by Composer, a reinstall overwrites it. A copy with `ADMIN_PATH` is the checked way. `--ignore-scripts` skips `preinstall` (validation) and `postinstall` (`patch-package`); for tests this was sufficient.

Established by the run (not from the preset README, which only mentions `adminPath` and is outdated):
- The preset must be supplemented for the 6.7 admin: `ADMIN_PATH` as an environment variable (the preset maps `^src(.*)$` to it), mappers for `lodash-es/*`, `@shopware-ag/meteor-admin-sdk/es`, `admin-extension-sdk/es`, `meteor-component-library`, `@vue/test-utils`, `vue` and `transformIgnorePatterns` for `@shopware-ag/meteor-*` (modeled on the core's `_jest.config.ts`). Without them the setup aborts with "Cannot use import statement" or "Could not locate module".
- `@vue/test-utils` and `vue` are mapped from the admin `node_modules` (one Vue instance); do not install them separately in the plugin.
- `babel.config.json` with `@babel/preset-env` is mandatory.
- Only the test pattern `Shopware.Component.register` + `Shopware.Component.build(...)` + `mount` is checked. `wrapTestComponent`, the `flushPromises` global, `global.repositoryFactoryMock`, `activeAclRoles`, `activeFeatureFlags` (T-47 4.3) are core `test/` helpers and are NOT checked with the preset (⚠ GAP); `flushPromises` can be imported from `@vue/test-utils`.

Stubs and mocks (Vue Test Utils 2): `mount(component, { props, global: { stubs: { 'sw-icon': true }, mocks: { $t: (k) => k }, provide: { repositoryFactory: ... } } })`. ⚠ UNVERIFIED: `stubs`/`mocks`/`provide` are VTU 2 API and only entered empty in the example; not executed with real Shopware components (e.g. `sw-page`, `mt-button`). `Shopware.Component.build` resolves Twig blocks/inheritance, but does not register child components: unknown `sw-*` tags are rendered as custom elements (warning) or must be stubbed.

Additionally: `admin:unit` from the platform repo does not exist in the project (no `composer run admin:unit`); see T-47.

## Pitfalls

- The `web` container has no Node (see above).
- Storefront tests are named `*.test.js` under `test/`, admin tests `*.spec.js|ts` under `src/`.
- `.babelrc.js` in the plugin is not enough for core code under `vendor/`; use `babel.config.js` or `babel.config.json` respectively.
- Admin: do not let core specs (`src/**/*.spec.js` under `ADMIN_PATH`) run along: `testMatch` stays on `<rootDir>` (plugin).
- Preset 6.0.6 depends on `babel-jest ^27`, the plugin uses Jest 30 (ran, but mixed versions; ⚠ UNVERIFIED in the long run/across updates).
- Do not commit/ship the plugin's `node_modules`; `bin/build-administration.sh` installs for plugins with a `package.json` via `npm install --omit=dev`, `bin/build-storefront.sh` without `--omit=dev`.
- `TZ=UTC` is set in the admin config; do not make tests depend on the local time zone.
- Linux host: files from `docker run` may be owned by root (⚠ UNVERIFIED; not noticed under Docker Desktop macOS). If needed add `--user "$(id -u):$(id -g)"`.

## Gaps

- ⚠ GAP: Long-term suitability of the preset: according to its `CHANGELOG` the preset is designed for Vue 3/Vite meta glob, but the setup lacks 6.7 mappers; the configuration above is derived from the core `_jest.config.ts` and only checked with a minimal component.
- ⚠ GAP: Run against the real `vendor/` and a real plugin in `custom/plugins/` (see above).
- ⚠ GAP: Vitest tests (storefront component system) and Playwright/Acceptance Test Suite: no instructions here.
- ⚠ GAP: Integration into CI/release scripts (the project's `composer.json` contains no JS test scripts).
