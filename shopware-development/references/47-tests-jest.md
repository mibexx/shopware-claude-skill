# JS-Tests: Jest und Vitest für Administration und Storefront (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn JavaScript/TypeScript eines Plugins oder Themes getestet wird: Admin-Komponenten (Vue 3), Storefront-JS-Plugins, neue Storefront-Komponenten. Sie beantwortet die offene Frage "Jest oder Vitest in der Storefront?" (siehe Abschnitt 2).

Abgrenzung: PHP-Tests [T-46](./46-tests-phpunit.md); Lint/Static Analysis [T-48](./48-statische-analyse.md); Build-Skripte [T-49](./49-build-deployment.md); Storefront-JS-Plugins [T-15](./15-storefront-js-plugins.md); Admin-Komponenten [T-25](./25-admin-komponenten.md).

## 2. Konzepte

**Antwort der Recherche (Code-Befund 6.7.15.1):**
- **Administration: Jest ist maßgeblich** (Jest 30.2.0, `@vue/test-utils` 2.4.6, Vue 3.5.22, Transformer `@swc/jest` plus eigene für `.twig`/`.vue`). Tests `*.spec.js|ts` neben dem Code. Konfig: `jest.config.js` (jiti-Wrapper) lädt `_jest.config.ts`. Scripts `unit`, `unit-watch`, `unit-setup`.
- **Storefront: zwei Runner nebeneinander, kein Ersatz.**
  - **Jest** (30.1.3, `jsdom`, `babel-jest`) für die klassischen JS-Plugins (`src/**`, Tests `test/**/*.test.js`): `npm run unit`. **Für Plugin-JS (PluginManager-Plugins) gilt Jest.**
  - **Vitest** (4.1.11, `happy-dom`) nur für das neue Component-System (`src/component-system/**/*.test.ts`, `build/vite/**/*.test.*`) und die Twig-UX-Komponenten (`Resources/views/components/**/*.test.{js,ts}`): `npm run unit:components`.
- **Ausführung im Projekt-Docker-Setup:** Der `web`-Container (Image `shopware-web:6.7.15.1`, Basis `ghcr.io/shopware/docker-base:8.4-nginx`) hat **kein Node/npm** (geprüft: `docker compose exec -T web sh -c 'which node npm; node -v'` liefert `node: not found`). JS-Tests laufen daher (i) mit Node auf dem Host (Projekt liegt per Volume `./:/var/www/html` auf dem Host, Plugin-Dateien unter `custom/plugins/`) oder (ii) in einem separaten `node:22`-Container. Details Abschnitt 3.0.
- **Vendor-Grenze:** Das Paket `administration` enthält weder `test/` noch `node_modules`. Die Core-`_jest.config.ts` und Helfer (`wrapTestComponent`, Mocks) sind aus `vendor/` nicht direkt nutzbar; ein Plugin braucht ein eigenes Setup oder einen Platform-Checkout.
- **E2E (Playwright, Acceptance Test Suite):** nur Abgrenzung. Im installierten Code nur ein TODO in der Admin-Doku, keine Playwright-Abhängigkeit in den `package.json`.

Verzeichnisse (Core, zur Orientierung):

```
administration/Resources/app/administration/
├── jest.config.js, _jest.config.ts, package.json
├── src/**/<name>.spec.js|ts            # Co-Location
│   └── <name>.spec/*.spec.ts           # Split für Tests > 500 Zeilen
└── test/ (_setup, _helper_, _mocks_, transformer)   # NICHT im Vendor-Paket
storefront/Resources/app/storefront/
├── jest.config.js, jest.init.js        # Jest (Plugins)
├── vitest.config.mts, __mocks__/shopware.ts   # Vitest (Component-System)
├── test/<bereich>/*.test.js
└── src/component-system/*.test.ts
storefront/Resources/views/components/**/*.test.{js,ts}   # Vitest
```

Plugin-Struktur (Konfig/Setup per Lauf geprüft, Vorlage: [templates/testing-js](../templates/testing-js/README.md)):

```
custom/plugins/SwagExample/src/Resources/app/
├── administration/{package.json, babel.config.json, jest.config.js, src/.../my-comp/my-comp.spec.js}
└── storefront/{package.json, babel.config.js, jest.config.js, jest.init.js, src/my-plugin/my-plugin.plugin.js, test/my-plugin/my-plugin.plugin.test.js}
```

## 3. Anleitung

### 3.0 Ausführen im Docker-Setup dieses Projekts

Beide Wege wurden praktisch mit dem Gerüst [templates/testing-js](../templates/testing-js/README.md) geprüft (temporäres Verzeichnis außerhalb des Projekts, Layout `vendor/shopware/*` plus `custom/plugins/SwagExample`).

**Node-Versionen (belegt):** Storefront `engines`: `node ^20.19.0 || >=22.12.0`, `npm >=11.8.0`. Administration `engines`: `node ^20.0.0 || ... || ^25.0.0`, `npm >=10.0.0`. Empfehlung Node 22. Der Host hat Node v26.10.0 / npm 11.19.1 (Admin-`engines` deckt 26 nicht ab; Tests liefen trotzdem). Der Container `node:22` hat npm 10.9.9 (unter der Storefront-Vorgabe; lief trotzdem).

**(i) Node auf dem Host** (geprüft, Storefront und Admin grün):

```bash
cd custom/plugins/SwagExample/src/Resources/app/storefront
npm install && npm run unit
```

**(ii) Separater Node-Container** (geprüft, Storefront und Admin grün; Projektwurzel einhängen, weil Configs per relativem Pfad auf `vendor/` zeigen):

```bash
export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin   # macOS Docker Desktop, falls docker nicht im PATH
docker run --rm -v "$PWD":/app -w /app/custom/plugins/SwagExample/src/Resources/app/storefront \
  node:22 sh -c 'npm install --no-audit --no-fund && npm run unit'
```

`node_modules` ist plattformabhängig (macOS vs. Linux-Container): pro Ordner nur einen Weg nutzen. ⚠ UNGEPRÜFT: Dateibesitz (root) bei `docker run` auf einem Linux-Host; `engine-strict`; `--watch`.

**Kein Weg über `docker compose exec web`.** ⚠ LÜCKE: Ein Node-Service in `compose.override.yaml` wäre denkbar, wurde nicht angelegt/geprüft.

1. **Admin im Platform-Checkout:**

```bash
composer run admin:unit              # alle Tests (Coverage nur mit --ci)
composer run admin:unit:watch
cd src/Administration/Resources/app/administration
npm run unit-setup                   # einmalig: erzeugt test/_helper_/componentWrapper/component-imports.js
npx jest --collectCoverage=false src/core/factory/http.factory.spec.js
```

   Ohne `unit-setup` bricht `_jest.config.ts` mit "Run `npm run unit-setup`" ab. `composer run admin:unit` existiert nur im Platform-Repo, nicht im Projekt-`composer.json`.
2. **Admin im Plugin (am Paket und per Lauf geprüft, Preset 6.0.6):**
   - Preset-Inhalt (`jest-preset.js`, `@tool/setup-env-for-shopware.js`): `testEnvironment: 'jsdom'`, `testMatch` `<rootDir>/src/**/*.spec.js|ts`, Transform `babel-jest` (Preset `@babel/preset-typescript`, Plugin `shopware-vite-meta-glob`) plus Twig-zu-Vue-Transformer, Setup lädt `<adminPath>/src/core/shopware.ts` und `<adminPath>/node_modules/vue/dist/vue.cjs.js`. Das Preset braucht also die **Administration-Quellen mit installiertem `node_modules`**. Im Projekt hat `vendor/shopware/administration/Resources/app/administration` kein `node_modules` (und kein `test/`).
   - Geprüfter Weg: Kopie der Admin-Quellen (`src`, `package.json`, `package-lock.json`, `patches`, `scripts`) außerhalb des Projekts, dort `npm ci --ignore-scripts` (~2000 Pakete, ~1 GB, Container `node:22` und Host), im Plugin `npm install` mit `jest 30.2.0`, `jest-environment-jsdom 30.2.0`, `@shopware-ag/jest-preset-sw6-admin 6.0.6`, `@babel/preset-env 7.29.5`, dazu `babel.config.json` (`{"presets":["@babel/preset-env"]}`, Pflicht) und `jest.config.js` aus Abschnitt 4.3; Aufruf `ADMIN_PATH=<kopie> npm run unit`.
   - ⚠ LÜCKE: `npm ci` direkt in `vendor/` (Standardpfad) nicht ausgeführt (Composer-verwaltet, 1 GB).
   - Das Preset 6.0.6 allein genügt für 6.7.15.1 nicht: Mapper für `lodash-es/*`, `@shopware-ag/meteor-admin-sdk/es`, `admin-extension-sdk/es`, `meteor-component-library`, `@vue/test-utils`, `vue` und `transformIgnorePatterns` müssen ergänzt werden (aus `_jest.config.ts` übernommen); ohne sie Abbruch beim Setup (siehe 4.3).
3. **Storefront Jest (per Lauf geprüft):** im Plugin Dev-Abhängigkeiten, `babel.config.js`, `jest.config.js` und `jest.init.js` (PluginManager-Mock) anlegen (Abschnitt 4.1; Versionen aus `storefront/Resources/app/storefront/package.json`: `jest` 30.1.3, `jest-environment-jsdom` 30.2.0, `@babel/core` 7.29.7, `@babel/preset-env` 7.23.7, `@babel/preset-typescript` 7.23.3, `@babel/plugin-proposal-decorators` 7.23.7, `core-js` 3.40.0, `deepmerge` 4.3.1). `babel-jest` ist nicht separat nötig (geprüft). Tests unter `test/**/*.test.js`; Aufruf `npm run unit` im Plugin-Storefront-Ordner (Abschnitt 3.0); im Platform-Checkout `composer run storefront:unit` bzw. `npm run unit` / `npm run unit-watch` im Storefront-Ordner.
4. **Storefront Vitest:** `npm run unit:components`, `unit:components:watch`, `unit:components:coverage`. `vitest.config.mts` liest `var/plugins.json` (nach `bundle:dump`), findet damit auch Plugin-eigene `Resources/views/components` und sammelt `*.test.js|ts` neben der Komponente ein (ohne `plugins.json` nur Core). Keine eigenen Globs auf `views/components` setzen (Config nutzt `followSymbolicLinks: false`).
5. **Feature-Flags in Admin-Tests:** `global.activeFeatureFlags`; ⚠ LÜCKE: ADR `adr/2026-08-06-administration-jest-feature-flag-helpers.md` nur referenziert, im Vendor nicht vorhanden.
6. **Große Specs** (> 500 Zeilen) in `<name>.spec/<szenario>.spec.ts` teilen (`testMatch` enthält `src/**/*.spec/*.spec.ts`).

## 4. Beispiele

### 4.1 Storefront-JS-Plugin (Jest), abgeleitet aus dem Core-Setup

`package.json`-Ergänzung im Plugin (`src/Resources/app/storefront/`; Versionen vom Core übernommen, sonst kollidieren Babel/Jest mit dem Core-Code)

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

`src/Resources/app/storefront/babel.config.js` (Inhalt der Core-Vorlage `storefront/Resources/app/storefront/.babelrc.js`, aber als **`babel.config.js`**: geprüft, dass eine `.babelrc.js` im Plugin für Core-Dateien unter `vendor/` nicht greift und mit Parse-Fehler scheitert)

```js
module.exports = {
    presets: [
        ['@babel/preset-env', { useBuiltIns: 'entry', corejs: '3.34.0', bugfixes: true }],
        '@babel/preset-typescript',
    ],
    plugins: [['@babel/plugin-proposal-decorators', { version: '2023-01' }]],
};
```

Geprüft (Lauf Host-Node 26 und `node:22`): Mit `modulePaths` auf das Plugin-`node_modules` und `babel.config.js` lädt der Alias `src/` die Core-Quellen unter `vendor/` (ohne `node_modules` dort); `core-js` und `deepmerge` im Plugin genügen für `Plugin`-Basisklasse und `$emitter`. Importiert euer Code weitere Core-Module mit Fremdpaketen (z. B. `bootstrap`), diese im Plugin ergänzen. ⚠ UNGEPRÜFT: `bootstrap`-Import wie in der Core-`jest.init.js`, Core-Plugins mit vielen Abhängigkeiten.

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
    // nötig: Core-Code unter vendor/ findet seine Pakete (deepmerge) sonst nicht
    modulePaths: ['<rootDir>/node_modules'],
    moduleNameMapper: {
        // Pfad je Projektlayout anpassen (7x '..' bis zur Projektwurzel bei custom/plugins/<Plugin>)!
        '^src/(.*)$': '<rootDir>/../../../../../../../vendor/shopware/storefront/Resources/app/storefront/src/$1',
        '^plugin/(.*)$': '<rootDir>/src/$1',
    },
    testMatch: ['**/test/**/*.test.js'],
    transform: { '^.+\\.(t|j)s$': 'babel-jest' },
    setupFilesAfterEnv: [join(__dirname, 'jest.init.js')],
};
```

`src/Resources/app/storefront/jest.init.js` (gekürzt nach Core-Vorlage; ohne Mock wirft die `Plugin`-Basisklasse)

```js
global.PluginManager = {
    getPluginInstancesFromElement: () => new Map(),
    getPluginInstanceFromElement: () => ({}),
    getPlugin: () => ({ get: () => [] }),
    getPluginInstances: () => new Map(),
    initializePlugins: jest.fn(() => Promise.resolve()),
    initializePluginsInParentElement: jest.fn(() => Promise.resolve()),
};

// nur nötig, wenn das Plugin window.PluginBaseClass nutzt (im Browser setzt es plugin.manager.js):
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

    test('wird instanziiert', () => {
        expect(plugin).toBeInstanceOf(MyPlugin);
    });
});
```

Der Mock in `jest.init.js` ist verkürzt; die Core-`jest.init.js` setzt zusätzlich `global.bootstrap = bootstrap` (Import `bootstrap`), `global.router = {}` und einen `unhandledRejection`-Logger. Bestes Vorbild: `storefront/Resources/app/storefront/test/plugin/header/account-guest-abort-button.plugin.test.js` (`jest.spyOn(Prototype, '_assignLocation')`, `plugin.$emitter.subscribe(...)`).

### 4.2 Storefront-Komponente (Vitest, nur Component-System)

Hinweis: `ShopwareComponent` (`component-system/component.ts`) ist `@internal` (ohne BC-Garantie); das Beispiel zeigt die Core-Testmethodik, nicht ein stabiles Plugin-API. Plugin-Code sollte sich nicht darauf stützen.

Gekürzt aus `src/component-system/component.test.ts`; Alias `shopware` zeigt auf `__mocks__/shopware.ts` (`ShopwareComponent`, `Shopware.emit/on/…` als `vi.fn()`).

```ts
import { describe, expect, it } from 'vitest';
import ShopwareComponent from './component';

describe('MyComponent', () => {
    it('liest Optionen aus data-component-options', () => {
        const el = document.createElement('div');
        el.setAttribute('data-component-options', JSON.stringify({ a: 1 }));
        // Konstruktor: (el, options, name)
        const c = new (class extends ShopwareComponent {})(el, {}, 'Sw:Test');
        expect(c.options).toMatchObject({ a: 1 });
    });
});
```

⚠ UNGEPRÜFT: nicht ausgeführt; Konstruktor-Signatur aus `component.test.ts` abgeleitet.

### 4.3 Administration-Komponente (Vue 3, Vue Test Utils 2)

Muster nach `technical-docs/07-testing/01-overview.md`. `wrapTestComponent`, `flushPromises`, `global.repositoryFactoryMock`, `global.activeAclRoles`, `global.activeFeatureFlags` kommen aus `test/_setup/*` und `test/_helper_/*` des Platform-Repos (nicht im Vendor).

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

    it('rendert', () => {
        expect(wrapper.vm).toBeTruthy();
    });
});
```

Preset-Variante für ein Plugin (**geprüft** mit Preset 6.0.6, Jest 30.2.0, Admin-Quellen 6.7.15.1; Komponente mit `Shopware.Component.register`, Test mit `Shopware.Component.build` + `mount`, 2 Tests grün auf Host-Node 26 und `node:22`). Die README des Presets (nur `adminPath`, `@babel/plugin-proposal-class-properties`) ist für 6.7 unvollständig; die Ergänzungen stammen aus der Core-`_jest.config.ts`:

```js
// src/Resources/app/administration/jest.config.js (gekürzt, vollständig: templates/testing-js)
const path = require('path');
const adminPath = process.env.ADMIN_PATH
    || path.resolve(__dirname, '../../../../../../../vendor/shopware/administration/Resources/app/administration');
process.env.ADMIN_PATH = adminPath;   // das Preset mappt "src/..." darauf
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
        // zusätzlich: css/scss-Mock und '^src(.*)$' (siehe Template)
    },
    transformIgnorePatterns: ['/node_modules/(?!(@shopware-ag/meteor-component-library|@shopware-ag/meteor-icon-kit|uuidv7)/)'],
    testMatch: ['<rootDir>/src/**/*.spec.js', '<rootDir>/src/**/*.spec.ts'],
};
```

Zusätzlich `babel.config.json` (`{"presets":["@babel/preset-env"]}`); ohne sie "Cannot use import statement outside a module". `@vue/test-utils` muss im Plugin nicht installiert werden (Mapper auf das Admin-`node_modules`). Kein `wrapTestComponent`/`repositoryFactoryMock`/`activeAclRoles` (Core-`test/`-Helfer, mit Preset ⚠ LÜCKE nicht geprüft). Stubs/Mocks (`global.stubs`, `global.mocks`, `global.provide`) sind VTU-2-API; mit echten Shopware-Komponenten (`sw-page`, `mt-button`) im Plugin ⚠ UNGEPRÜFT.

## 5. Fallstricke

- Doku `jest-admin.html` zeigt Vue-2-Muster (`wrapper.destroy()`, `createLocalVue`, `shallowMount(Shopware.Component.build(...))`). In 6.7 (Vue 3): `wrapper.unmount()`, `global.stubs`, `await wrapTestComponent(name, { sync: true })`. Auch die Core-Übersichtsdatei nutzt noch `destroy()`; in VTU 2 existiert es nicht.
- Storefront-Jest-Dateien müssen `**/test/**/*.test.js` heißen; `*.spec.js` werden dort nicht gefunden. Admin dagegen nur `*.spec.js|ts` unter `src/`. Die Doku nennt im Baum `.spec.js`, im Text `.test.js`; der Code (`testMatch`) entscheidet.
- Storefront-Plugin ohne `PluginManager`-Mock: `Plugin`-Basisklasse wirft.
- Jest-Tests für Plugin-JS nicht in Vitest-Config schreiben oder umgekehrt (`jest.fn` vs. `vi.fn`).
- `@shopware-ag/meteor-*` sind ES-Module und müssen in `transformIgnorePatterns` ausgenommen werden (Core tut das; im Plugin-Setup nachbauen).
- **`web`-Container hat kein Node:** JS-Tests nie per `docker compose exec web`; Host-Node oder `docker run node:22` (3.0).
- `.babelrc.js` im Plugin greift nicht für Core-Code unter `vendor/`; `babel.config.js` (Storefront) bzw. `babel.config.json` (Admin) verwenden. Ohne `modulePaths` findet Core-Code im Storefront-Alias seine Pakete nicht.
- Das Preset 6.0.6 braucht ein Admin-`node_modules` (Setup lädt `<adminPath>/node_modules/vue`); `vendor/shopware/administration` hat keins.
- Das Plugin-`package.json` in `.../app/administration` bzw. `.../app/storefront` wird von `bin/build-administration.sh` (`npm install --omit=dev`) und `bin/build-storefront.sh` (`npm install`, mit devDependencies, nur wenn kein `node_modules` existiert) mit installiert; `node_modules` nicht ausliefern.
- Admin-Config setzt `TZ=UTC`; Tests nicht von lokaler Zeit abhängig machen.
- `Run npm run unit-setup`-Fehler: Component-Import-Map fehlt.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Admin: Vue 2 (+ `@vue/compat`) in 6.5/6.6 → **Vue 3.5**, `@vue/test-utils` 2.x; `wrapTestComponent` mit `sync: true` als Standard-Mountmuster. Jest 30 (`jest-circus`), TS-Config `_jest.config.ts` mit `jiti`, `@swc/jest`, Transform `shopware-vite-meta-glob` für `import.meta.glob`. Admin-Build ist Vite (siehe [T-49](./49-build-deployment.md)), Tests laufen unverändert über Jest.
- Storefront: neu Vitest 4 + happy-dom für Component-System/Twig-UX-Komponenten parallel zu Jest; frühere Versionen nur Jest. ⚠ LÜCKE: genaues Einführungsrelease nicht belegt.
- Preset `@shopware-ag/jest-preset-sw6-admin`: laut npm/Snyk 5.x/6.x "zur Vorbereitung auf 6.7", neueste 6.0.6 (`npm view`: dist-tag latest; `engines` node ^20..^25, npm >=10); Paketinhalt per `npm pack` gelesen und lauffähig geprüft, aber nur mit Ergänzungen (4.3). ⚠ UNGEPRÜFT: Erscheinungsdatum (Websuche).

## 6. Checkliste

- [ ] Runner je Ziel gewählt: Admin = Jest, Storefront-Plugin-JS = Jest, Storefront-Component-System/Twig-UX = Vitest
- [ ] Admin-Tests `*.spec.js|ts` neben dem Code, `unmount()` statt `destroy()`
- [ ] `npm run unit-setup` einmalig ausgeführt (nur Platform-Checkout)
- [ ] Ausführung über Host-Node oder `node:22`-Container (nicht `web`), Node 22 empfohlen
- [ ] Plugin-Admin: `ADMIN_PATH` auf Admin-Quellen mit `node_modules` gesetzt; `babel.config.json` vorhanden
- [ ] Storefront-Jest-Tests unter `test/**/*.test.js`, `jest.init.js` mit `PluginManager`-Mock, `babel.config.js`, `modulePaths`
- [ ] `moduleNameMapper`-Alias auf das Projektlayout angepasst
- [ ] `transformIgnorePatterns` für `@shopware-ag/meteor-*`
- [ ] Vitest-Tests neben Twig-Komponenten; `bundle:dump` ausgeführt
- [ ] Tests unabhängig von Zeitzone

## 7. Quellen

Doku: https://developer.shopware.com/docs/guides/plugins/plugins/testing/jest-admin.html (Vue-2-lastig), https://developer.shopware.com/docs/guides/plugins/plugins/testing/jest-storefront.html; `/docs/guides/plugins/plugins/testing/` liefert 404; `/docs/guides/development/testing/` nicht verifiziert; https://github.com/shopware/jest-preset-sw6-admin; npm-Paket `@shopware-ag/jest-preset-sw6-admin@6.0.6` (`jest-preset.js`, `@tool/*`, README, CHANGELOG per `npm pack` geprüft).

Quellcode (unter `vendor/shopware/`): `administration/Resources/app/administration/{jest.config.js,_jest.config.ts,package.json (engines, scripts),AGENTS.md,technical-docs/07-testing/01-overview.md}`; `storefront/Resources/app/storefront/{jest.config.js,jest.init.js,vitest.config.mts,__mocks__/shopware.ts,package.json,test/**,src/component-system/component.test.ts}`; `storefront/Resources/views/components/README.md`; Projekt: `compose.yaml`, `compose.override.yaml` (Volume `./:/var/www/html`), `docker/Dockerfile`, `bin/build-storefront.sh`, `bin/build-administration.sh`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Admin-Preset: Lauf nur mit einer Minimalkomponente und Kopie der Admin-Quellen; nicht gegen `vendor/` direkt, nicht mit echten Shopware-Komponenten, `wrapTestComponent`-Helfern oder Repository-Mocks. Preset 6.0.6 hängt von `babel-jest ^27` ab (Jest 30 im Plugin; lief).
- Storefront-Plugin-`jest.config.js` ist aus der Core-Config abgeleitet (nicht aus offizieller Doku), aber per Lauf geprüft (Host und `node:22`). ⚠ UNGEPRÜFT: Auswirkung von `babel.config.js`/`package.json` im Plugin-Ordner auf `bin/build-storefront.sh`; Core-Plugins mit weiteren Paketen (`bootstrap`).
- ⚠ LÜCKE: Node-Service in `compose.override.yaml` nicht angelegt; Docker-Lauf nur als `docker run` auf macOS geprüft.
- ⚠ LÜCKE: Einführungsversionen von Vitest (Storefront) und Vite (Admin) nicht belegt; Playwright-Rolle nicht aus Quellen belegt.
- ⚠ UNGEPRÜFT: Verweise auf `coding-guidelines/administration/testing.md` und ADRs liegen außerhalb des Vendor-Pakets.
