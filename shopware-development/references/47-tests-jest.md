# JS tests: Jest and Vitest for Administration and Storefront (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin's or theme's JavaScript/TypeScript is tested: admin components (Vue 3), storefront JS plugins, new storefront components. It answers the open question "Jest or Vitest in the storefront?" (see section 2).

Scope: PHP tests [T-46](./46-tests-phpunit.md); lint/static analysis [T-48](./48-static-analysis.md); build scripts [T-49](./49-build-deployment.md); storefront JS plugins [T-15](./15-storefront-js-plugins.md); admin components [T-25](./25-admin-components.md).

## 2. Concepts

**Research answer (code finding 6.7.15.1):**
- **Administration: Jest is authoritative** (Jest 30.2.0, `@vue/test-utils` 2.4.6, Vue 3.5.22, transformer `@swc/jest` plus custom ones for `.twig`/`.vue`). Tests `*.spec.js|ts` next to the code. Config: `jest.config.js` (jiti wrapper) loads `_jest.config.ts`. Scripts `unit`, `unit-watch`, `unit-setup`.
- **Storefront: two runners side by side, not a replacement.**
  - **Jest** (30.1.3, `jsdom`, `babel-jest`) for the classic JS plugins (`src/**`, tests `test/**/*.test.js`): `npm run unit`. **For plugin JS (PluginManager plugins), Jest applies.**
  - **Vitest** (4.1.11, `happy-dom`) only for the new component system (`src/component-system/**/*.test.ts`, `build/vite/**/*.test.*`) and the Twig UX components (`Resources/views/components/**/*.test.{js,ts}`): `npm run unit:components`.
- **Running in the project Docker setup:** the `web` container (image `shopware-web:6.7.15.1`, base `ghcr.io/shopware/docker-base:8.4-nginx`) has **no Node/npm** (verified: `docker compose exec -T web sh -c 'which node npm; node -v'` returns `node: not found`). JS tests therefore run (i) with Node on the host (the project lives on the host via the volume `./:/var/www/html`, plugin files under `custom/plugins/`) or (ii) in a separate `node:22` container. Details in section 3.0.
- **Vendor boundary:** the `administration` package contains neither `test/` nor `node_modules`. The core `_jest.config.ts` and helpers (`wrapTestComponent`, mocks) cannot be used directly from `vendor/`; a plugin needs its own setup or a platform checkout.
- **E2E (Playwright, Acceptance Test Suite):** scope delimitation only. In the installed code there is only a TODO in the admin docs, no Playwright dependency in the `package.json` files.

Directories (core, for orientation):

```
administration/Resources/app/administration/
├── jest.config.js, _jest.config.ts, package.json
├── src/**/<name>.spec.js|ts            # co-location
│   └── <name>.spec/*.spec.ts           # split for tests > 500 lines
└── test/ (_setup, _helper_, _mocks_, transformer)   # NOT in the vendor package
storefront/Resources/app/storefront/
├── jest.config.js, jest.init.js        # Jest (plugins)
├── vitest.config.mts, __mocks__/shopware.ts   # Vitest (component system)
├── test/<area>/*.test.js
└── src/component-system/*.test.ts
storefront/Resources/views/components/**/*.test.{js,ts}   # Vitest
```

Plugin structure (config/setup verified by a run, template: [templates/testing-js](../templates/testing-js/README.md)):

```
custom/plugins/SwagExample/src/Resources/app/
├── administration/{package.json, babel.config.json, jest.config.js, src/.../my-comp/my-comp.spec.js}
└── storefront/{package.json, babel.config.js, jest.config.js, jest.init.js, src/my-plugin/my-plugin.plugin.js, test/my-plugin/my-plugin.plugin.test.js}
```

## 3. Instructions

### 3.0 Running in this project's Docker setup

Both ways were verified in practice with the scaffold [templates/testing-js](../templates/testing-js/README.md) (temporary directory outside the project, layout `vendor/shopware/*` plus `custom/plugins/SwagExample`).

**Node versions (documented):** storefront `engines`: `node ^20.19.0 || >=22.12.0`, `npm >=11.8.0`. Administration `engines`: `node ^20.0.0 || ... || ^25.0.0`, `npm >=10.0.0`. Recommendation: Node 22. The host has Node v26.10.0 / npm 11.19.1 (the admin `engines` does not cover 26; tests ran anyway). The `node:22` container has npm 10.9.9 (below the storefront requirement; ran anyway).

**(i) Node on the host** (verified, storefront and admin green):

```bash
cd custom/plugins/SwagExample/src/Resources/app/storefront
npm install && npm run unit
```

**(ii) Separate Node container** (verified, storefront and admin green; mount the project root, because the configs point to `vendor/` via relative paths):

```bash
export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin   # macOS Docker Desktop, if docker is not in PATH
docker run --rm -v "$PWD":/app -w /app/custom/plugins/SwagExample/src/Resources/app/storefront \
  node:22 sh -c 'npm install --no-audit --no-fund && npm run unit'
```

`node_modules` is platform-dependent (macOS vs. Linux container): use only one way per folder. ⚠ UNVERIFIED: file ownership (root) with `docker run` on a Linux host; `engine-strict`; `--watch`.

**No way via `docker compose exec web`.** ⚠ GAP: a Node service in `compose.override.yaml` would be conceivable, but was not created/verified.

1. **Admin in the platform checkout:**

```bash
composer run admin:unit              # all tests (coverage only with --ci)
composer run admin:unit:watch
cd src/Administration/Resources/app/administration
npm run unit-setup                   # once: generates test/_helper_/componentWrapper/component-imports.js
npx jest --collectCoverage=false src/core/factory/http.factory.spec.js
```

   Without `unit-setup`, `_jest.config.ts` aborts with "Run `npm run unit-setup`". `composer run admin:unit` only exists in the platform repo, not in the project `composer.json`.
2. **Admin in the plugin (verified against the package and by a run, preset 6.0.6):**
   - Preset content (`jest-preset.js`, `@tool/setup-env-for-shopware.js`): `testEnvironment: 'jsdom'`, `testMatch` `<rootDir>/src/**/*.spec.js|ts`, transform `babel-jest` (preset `@babel/preset-typescript`, plugin `shopware-vite-meta-glob`) plus Twig-to-Vue transformer, setup loads `<adminPath>/src/core/shopware.ts` and `<adminPath>/node_modules/vue/dist/vue.cjs.js`. The preset therefore needs the **Administration sources with installed `node_modules`**. In the project, `vendor/shopware/administration/Resources/app/administration` has no `node_modules` (and no `test/`).
   - Verified way: copy of the admin sources (`src`, `package.json`, `package-lock.json`, `patches`, `scripts`) outside the project, there `npm ci --ignore-scripts` (~2000 packages, ~1 GB, `node:22` container and host); in the plugin `npm install` with `jest 30.2.0`, `jest-environment-jsdom 30.2.0`, `@shopware-ag/jest-preset-sw6-admin 6.0.6`, `@babel/preset-env 7.29.5`, plus `babel.config.json` (`{"presets":["@babel/preset-env"]}`, mandatory) and `jest.config.js` from section 4.3; invocation `ADMIN_PATH=<copy> npm run unit`.
   - ⚠ GAP: `npm ci` directly in `vendor/` (default path) not executed (Composer-managed, 1 GB).
   - Preset 6.0.6 alone is not sufficient for 6.7.15.1: mappers for `lodash-es/*`, `@shopware-ag/meteor-admin-sdk/es`, `admin-extension-sdk/es`, `meteor-component-library`, `@vue/test-utils`, `vue` and `transformIgnorePatterns` must be added (taken from `_jest.config.ts`); without them the setup aborts (see 4.3).
3. **Storefront Jest (verified by a run):** in the plugin, create dev dependencies, `babel.config.js`, `jest.config.js` and `jest.init.js` (PluginManager mock) (section 4.1; versions from `storefront/Resources/app/storefront/package.json`: `jest` 30.1.3, `jest-environment-jsdom` 30.2.0, `@babel/core` 7.29.7, `@babel/preset-env` 7.23.7, `@babel/preset-typescript` 7.23.3, `@babel/plugin-proposal-decorators` 7.23.7, `core-js` 3.40.0, `deepmerge` 4.3.1). `babel-jest` is not needed separately (verified). Tests under `test/**/*.test.js`; invocation `npm run unit` in the plugin storefront folder (section 3.0); in the platform checkout `composer run storefront:unit` or `npm run unit` / `npm run unit-watch` in the storefront folder.
4. **Storefront Vitest:** `npm run unit:components`, `unit:components:watch`, `unit:components:coverage`. `vitest.config.mts` reads `var/plugins.json` (after `bundle:dump`), thereby also finds plugin-owned `Resources/views/components` and collects `*.test.js|ts` next to the component (without `plugins.json` only core). Do not set your own globs on `views/components` (the config uses `followSymbolicLinks: false`).
5. **Feature flags in admin tests:** `global.activeFeatureFlags`; ⚠ GAP: ADR `adr/2026-08-06-administration-jest-feature-flag-helpers.md` only referenced, not present in the vendor package.
6. **Large specs** (> 500 lines): split into `<name>.spec/<scenario>.spec.ts` (`testMatch` includes `src/**/*.spec/*.spec.ts`).

## 4. Examples

### 4.1 Storefront JS plugin (Jest), derived from the core setup

`package.json` addition in the plugin (`src/Resources/app/storefront/`; versions taken from core, otherwise Babel/Jest collide with the core code)

```json
{
  "scripts": { "unit": "jest --config jest.config.js --ci" },
  "devDependencies": {
    "@babel/core": "7.29.7",
    "@babel/preset-env": "7.23.7",
    "@babel/preset-typescript": "7.23.3",
    "@babel/plugin-proposal-decorators": "7.23.7",
    "jest": "30.1.3",
    "jest-environment-jsdom": "30.2.0",
    "core-js": "3.40.0"
  }
}
```

`src/Resources/app/storefront/babel.config.js` (content of the core template `storefront/Resources/app/storefront/.babelrc.js`, but as **`babel.config.js`**: verified that a `.babelrc.js` in the plugin does not apply to core files under `vendor/` and fails with a parse error)

```js
module.exports = {
    presets: [
        ['@babel/preset-env', { useBuiltIns: 'entry', corejs: '3.34.0', bugfixes: true }],
        '@babel/preset-typescript',
    ],
    plugins: [['@babel/plugin-proposal-decorators', { version: '2023-01' }]],
};
```

Verified (run with host Node 26 and `node:22`): with `modulePaths` pointing to the plugin `node_modules` and `babel.config.js`, the alias `src/` loads the core sources under `vendor/` (without `node_modules` there); `core-js` and `deepmerge` in the plugin are sufficient for the `Plugin` base class and `$emitter`. If your code imports further core modules with third-party packages (e.g. `bootstrap`), add them to the plugin. ⚠ UNVERIFIED: `bootstrap` import as in the core `jest.init.js`, core plugins with many dependencies.

`src/Resources/app/storefront/jest.config.js`

```js
const { join } = require('path');

module.exports = {
    testEnvironment: 'jsdom',
    clearMocks: true,
    resetMocks: true,
    restoreMocks: true,
    rootDir: __dirname,
    moduleFileExtensions: ['js', 'ts', 'html'],
    // required: otherwise core code under vendor/ does not find its packages (deepmerge)
    modulePaths: ['<rootDir>/node_modules'],
    moduleNameMapper: {
        // adjust the path to the project layout (7x '..' up to the project root for custom/plugins/<Plugin>)!
        '^src/(.*)$': '<rootDir>/../../../../../../../vendor/shopware/storefront/Resources/app/storefront/src/$1',
        '^plugin/(.*)$': '<rootDir>/src/$1',
    },
    testMatch: ['**/test/**/*.test.js'],
    transform: { '^.+\\.(t|j)s$': 'babel-jest' },
    setupFilesAfterEnv: [join(__dirname, 'jest.init.js')],
};
```

`src/Resources/app/storefront/jest.init.js` (shortened from the core template; without the mock the `Plugin` base class throws)

```js
global.PluginManager = {
    getPluginInstancesFromElement: () => new Map(),
    getPluginInstanceFromElement: () => ({}),
    getPlugin: () => ({ get: () => [] }),
    getPluginInstances: () => new Map(),
    initializePlugins: jest.fn(() => Promise.resolve()),
    initializePluginsInParentElement: jest.fn(() => Promise.resolve()),
};

// only needed if the plugin uses window.PluginBaseClass (in the browser, plugin.manager.js sets it):
window.PluginBaseClass = require('src/plugin-system/plugin.class').default;
```

`src/Resources/app/storefront/test/my-plugin/my-plugin.plugin.test.js`

```js
import MyPlugin from 'plugin/my-plugin/my-plugin.plugin';

describe('MyPlugin', () => {
    let plugin;

    beforeEach(() => {
        document.body.innerHTML = '<button id="btn" data-my-plugin="true"></button>';
        plugin = new MyPlugin(document.getElementById('btn'));
    });

    afterEach(() => { plugin = undefined; });

    test('is instantiated', () => {
        expect(plugin).toBeInstanceOf(MyPlugin);
    });
});
```

The mock in `jest.init.js` is shortened; the core `jest.init.js` additionally sets `global.bootstrap = bootstrap` (import `bootstrap`), `global.router = {}` and an `unhandledRejection` logger. Best model: `storefront/Resources/app/storefront/test/plugin/header/account-guest-abort-button.plugin.test.js` (`jest.spyOn(Prototype, '_assignLocation')`, `plugin.$emitter.subscribe(...)`).

### 4.2 Storefront component (Vitest, component system only)

Note: `ShopwareComponent` (`component-system/component.ts`) is `@internal` (no BC guarantee); the example shows the core testing methodology, not a stable plugin API. Plugin code should not rely on it.

Shortened from `src/component-system/component.test.ts`; the alias `shopware` points to `__mocks__/shopware.ts` (`ShopwareComponent`, `Shopware.emit/on/…` as `vi.fn()`).

```ts
import { describe, expect, it } from 'vitest';
import ShopwareComponent from './component';

describe('MyComponent', () => {
    it('reads options from data-component-options', () => {
        const el = document.createElement('div');
        el.setAttribute('data-component-options', JSON.stringify({ a: 1 }));
        // constructor: (el, options, name)
        const c = new (class extends ShopwareComponent {})(el, {}, 'Sw:Test');
        expect(c.options).toMatchObject({ a: 1 });
    });
});
```

⚠ UNVERIFIED: not executed; constructor signature derived from `component.test.ts`.

### 4.3 Administration component (Vue 3, Vue Test Utils 2)

Pattern based on `technical-docs/07-testing/01-overview.md`. `wrapTestComponent`, `flushPromises`, `global.repositoryFactoryMock`, `global.activeAclRoles`, `global.activeFeatureFlags` come from `test/_setup/*` and `test/_helper_/*` of the platform repo (not in the vendor package).

```js
import { mount } from '@vue/test-utils';

async function createWrapper(options = {}) {
    return mount(await wrapTestComponent('sw-your-component', { sync: true }), {
        props: {},
        global: { stubs: { 'sw-icon': true } },
        ...options,
    });
}

describe('src/module/my-module/component/my-comp', () => {
    let wrapper;

    beforeEach(async () => {
        wrapper = await createWrapper();
        await flushPromises();
    });

    afterEach(() => wrapper?.unmount());

    it('renders', () => {
        expect(wrapper.vm).toBeTruthy();
    });
});
```

Preset variant for a plugin (**verified** with preset 6.0.6, Jest 30.2.0, admin sources 6.7.15.1; component with `Shopware.Component.register`, test with `Shopware.Component.build` + `mount`, 2 tests green on host Node 26 and `node:22`). The preset's README (only `adminPath`, `@babel/plugin-proposal-class-properties`) is incomplete for 6.7; the additions come from the core `_jest.config.ts`:

```js
// src/Resources/app/administration/jest.config.js (shortened, complete: templates/testing-js)
const path = require('path');
const adminPath = process.env.ADMIN_PATH
    || path.resolve(__dirname, '../../../../../../../vendor/shopware/administration/Resources/app/administration');
process.env.ADMIN_PATH = adminPath;   // the preset maps "src/..." to it
process.env.TZ = process.env.TZ || 'UTC';

module.exports = {
    preset: '@shopware-ag/jest-preset-sw6-admin',
    rootDir: __dirname,
    globals: { adminPath },
    testEnvironment: 'jsdom',
    collectCoverage: false,
    moduleNameMapper: {
        '^vue$': path.join(adminPath, 'node_modules/vue/dist/vue.cjs.js'),
        '^@vue/test-utils$': `${adminPath}/node_modules/@vue/test-utils`,
        '^@shopware-ag/meteor-admin-sdk/es/(.*)': `${adminPath}/node_modules/@shopware-ag/meteor-admin-sdk/umd/$1`,
        '^@shopware-ag/admin-extension-sdk/es/(.*)': `${adminPath}/node_modules/@shopware-ag/admin-extension-sdk/umd/$1`,
        '^@shopware-ag/meteor-component-library$': `${adminPath}/node_modules/@shopware-ag/meteor-component-library/dist/common/index.js`,
        '^@shopware-ag/meteor-component-library/dist/esm/(.*)$': `${adminPath}/node_modules/@shopware-ag/meteor-component-library/dist/common/$1`,
        '^lodash-es/(.*)$': 'lodash/$1',
        '^lodash-es$': 'lodash',
        // additionally: css/scss mock and '^src(.*)$' (see template)
    },
    transformIgnorePatterns: ['/node_modules/(?!(@shopware-ag/meteor-component-library|@shopware-ag/meteor-icon-kit|uuidv7)/)'],
    testMatch: ['<rootDir>/src/**/*.spec.js', '<rootDir>/src/**/*.spec.ts'],
};
```

Additionally `babel.config.json` (`{"presets":["@babel/preset-env"]}`); without it: "Cannot use import statement outside a module". `@vue/test-utils` does not need to be installed in the plugin (mapper to the admin `node_modules`). No `wrapTestComponent`/`repositoryFactoryMock`/`activeAclRoles` (core `test/` helpers; with the preset ⚠ GAP, not verified). Stubs/mocks (`global.stubs`, `global.mocks`, `global.provide`) are VTU 2 API; with real Shopware components (`sw-page`, `mt-button`) in the plugin ⚠ UNVERIFIED.

## 5. Pitfalls

- The docs page `jest-admin.html` shows Vue 2 patterns (`wrapper.destroy()`, `createLocalVue`, `shallowMount(Shopware.Component.build(...))`). In 6.7 (Vue 3): `wrapper.unmount()`, `global.stubs`, `await wrapTestComponent(name, { sync: true })`. The core overview file also still uses `destroy()`; it does not exist in VTU 2.
- Storefront Jest files must be named `**/test/**/*.test.js`; `*.spec.js` files are not found there. Admin, by contrast, only `*.spec.js|ts` under `src/`. The docs show `.spec.js` in the tree and `.test.js` in the text; the code (`testMatch`) decides.
- Storefront plugin without `PluginManager` mock: the `Plugin` base class throws.
- Do not write Jest tests for plugin JS in the Vitest config or vice versa (`jest.fn` vs. `vi.fn`).
- `@shopware-ag/meteor-*` are ES modules and must be excluded in `transformIgnorePatterns` (core does this; replicate it in the plugin setup).
- **The `web` container has no Node:** never run JS tests via `docker compose exec web`; use host Node or `docker run node:22` (3.0).
- `.babelrc.js` in the plugin does not apply to core code under `vendor/`; use `babel.config.js` (storefront) or `babel.config.json` (admin). Without `modulePaths`, core code in the storefront alias does not find its packages.
- Preset 6.0.6 needs an admin `node_modules` (setup loads `<adminPath>/node_modules/vue`); `vendor/shopware/administration` has none.
- The plugin `package.json` in `.../app/administration` or `.../app/storefront` is also installed by `bin/build-administration.sh` (`npm install --omit=dev`) and `bin/build-storefront.sh` (`npm install`, with devDependencies, only if no `node_modules` exists); do not ship `node_modules`.
- The admin config sets `TZ=UTC`; do not make tests depend on local time.
- `Run npm run unit-setup` error: the component import map is missing.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Admin: Vue 2 (+ `@vue/compat`) in 6.5/6.6 → **Vue 3.5**, `@vue/test-utils` 2.x; `wrapTestComponent` with `sync: true` as the standard mount pattern. Jest 30 (`jest-circus`), TS config `_jest.config.ts` with `jiti`, `@swc/jest`, transform `shopware-vite-meta-glob` for `import.meta.glob`. The admin build is Vite (see [T-49](./49-build-deployment.md)); tests still run via Jest unchanged.
- Storefront: new Vitest 4 + happy-dom for component system/Twig UX components in parallel with Jest; earlier versions only Jest. ⚠ GAP: exact introducing release not documented.
- Preset `@shopware-ag/jest-preset-sw6-admin`: according to npm/Snyk 5.x/6.x "in preparation for 6.7", latest 6.0.6 (`npm view`: dist-tag latest; `engines` node ^20..^25, npm >=10); package content read via `npm pack` and verified as runnable, but only with additions (4.3). ⚠ UNVERIFIED: release date (web search).

## 6. Checklist

- [ ] Runner chosen per target: admin = Jest, storefront plugin JS = Jest, storefront component system/Twig UX = Vitest
- [ ] Admin tests `*.spec.js|ts` next to the code, `unmount()` instead of `destroy()`
- [ ] `npm run unit-setup` run once (platform checkout only)
- [ ] Execution via host Node or `node:22` container (not `web`), Node 22 recommended
- [ ] Plugin admin: `ADMIN_PATH` set to admin sources with `node_modules`; `babel.config.json` present
- [ ] Storefront Jest tests under `test/**/*.test.js`, `jest.init.js` with `PluginManager` mock, `babel.config.js`, `modulePaths`
- [ ] `moduleNameMapper` alias adjusted to the project layout
- [ ] `transformIgnorePatterns` for `@shopware-ag/meteor-*`
- [ ] Vitest tests next to Twig components; `bundle:dump` executed
- [ ] Tests independent of the time zone

## 7. Sources

Docs: https://developer.shopware.com/docs/guides/plugins/plugins/testing/jest-admin.html (Vue 2 heavy), https://developer.shopware.com/docs/guides/plugins/plugins/testing/jest-storefront.html; `/docs/guides/plugins/plugins/testing/` returns 404; `/docs/guides/development/testing/` not verified; https://github.com/shopware/jest-preset-sw6-admin; npm package `@shopware-ag/jest-preset-sw6-admin@6.0.6` (`jest-preset.js`, `@tool/*`, README, CHANGELOG checked via `npm pack`).

Source code (under `vendor/shopware/`): `administration/Resources/app/administration/{jest.config.js,_jest.config.ts,package.json (engines, scripts),AGENTS.md,technical-docs/07-testing/01-overview.md}`; `storefront/Resources/app/storefront/{jest.config.js,jest.init.js,vitest.config.mts,__mocks__/shopware.ts,package.json,test/**,src/component-system/component.test.ts}`; `storefront/Resources/views/components/README.md`; project: `compose.yaml`, `compose.override.yaml` (volume `./:/var/www/html`), `docker/Dockerfile`, `bin/build-storefront.sh`, `bin/build-administration.sh`.

### Gaps and uncertainties

- ⚠ GAP: admin preset: run only with a minimal component and a copy of the admin sources; not directly against `vendor/`, not with real Shopware components, `wrapTestComponent` helpers or repository mocks. Preset 6.0.6 depends on `babel-jest ^27` (Jest 30 in the plugin; ran).
- The storefront plugin `jest.config.js` is derived from the core config (not from official docs), but verified by a run (host and `node:22`). ⚠ UNVERIFIED: effect of `babel.config.js`/`package.json` in the plugin folder on `bin/build-storefront.sh`; core plugins with further packages (`bootstrap`).
- ⚠ GAP: Node service in `compose.override.yaml` not created; Docker run verified only as `docker run` on macOS.
- ⚠ GAP: introducing versions of Vitest (storefront) and Vite (admin) not documented; Playwright's role not documented from sources.
- ⚠ UNVERIFIED: references to `coding-guidelines/administration/testing.md` and ADRs lie outside the vendor package.
