# Template: JS-Tests (Jest) für Administration und Storefront (SwagExample)

Zweck: Jest-Gerüst für ein Plugin: Administration-Komponententest (Vue 3, `@vue/test-utils`) und Storefront-JS-Plugin-Test (PluginManager-Plugin). Shopware 6.7.15.1. Hintergrund: [T-47](../../references/47-tests-jest.md). PHP-Tests: [T-46](../../references/46-tests-phpunit.md), PHPUnit-/Qualitäts-Gerüste: [testing](../testing/README.md), [quality](../quality/README.md); Lint/Statische Analyse: [T-48](../../references/48-statische-analyse.md); Admin-Komponenten: [T-25](../../references/25-admin-komponenten.md), Beispielmodul: [admin-module](../admin-module/README.md); Storefront-JS-Plugins: [T-15](../../references/15-storefront-js-plugins.md), Testobjekt: [storefront-js-plugin](../storefront-js-plugin/README.md); Build: [T-49](../../references/49-build-deployment.md).

Nicht abgedeckt: Playwright/Acceptance Test Suite (E2E) und Vitest (nur für Storefront-Component-System, siehe T-47).

## Praktisch geprüft (Stand dieser Erstellung)

Beide Gerüste wurden in einem temporären Verzeichnis außerhalb des Projekts nachgebaut (Layout `vendor/shopware/{administration,storefront}` + `custom/plugins/SwagExample`) und ausgeführt:

| Variante | Ergebnis |
|---|---|
| Storefront, Host-Node v26.10.0 / npm 11.19.1 | 1 Test grün |
| Storefront, Container `node:22` (Node 22.23.4, npm 10.9.9) | 1 Test grün |
| Administration, Container `node:22` | 2 Tests grün |
| Administration, Host-Node v26.10.0 | 2 Tests grün (v26 liegt außerhalb der Admin-`engines`, siehe unten) |
| Administration mit `ADMIN_PATH` auf eine Kopie der Admin-Quellen | 2 Tests grün |

Nicht geprüft: Lauf direkt gegen die echten Dateien unter `vendor/shopware/...` und gegen ein echtes Plugin in `custom/plugins/` (dort ist absichtlich nichts installiert/geändert worden); Windows/Linux-Host; `--watch`; Coverage.

## Dateibaum

```
SwagExample/src/Resources/app/
├── administration/
│   ├── package.json                 # scripts + devDependencies
│   ├── babel.config.json            # PFLICHT (ohne: "Cannot use import statement")
│   ├── jest.config.js               # Preset + Mapper-Ergänzungen
│   └── src/component/swag-example-counter/
│       ├── index.js
│       ├── swag-example-counter.html.twig
│       └── swag-example-counter.spec.js   # Test neben dem Code (*.spec.js)
└── storefront/
    ├── package.json
    ├── babel.config.js              # PFLICHT, als babel.config.js (nicht .babelrc.js)
    ├── jest.config.js
    ├── jest.init.js                 # PluginManager-Mock + window.PluginBaseClass
    └── test/swag-example/swag-example.plugin.test.js   # *.test.js unter test/
```

Der Storefront-Test importiert `plugin/swag-example/swag-example.plugin`. Dafür das Plugin aus [storefront-js-plugin](../storefront-js-plugin/README.md) nach `storefront/src/swag-example/swag-example.plugin.js` legen (dieses Template enthält es nicht doppelt). Die Admin-Beispielkomponente gehört nur zu diesem Gerüst, nicht zum admin-module-Template.

## Voraussetzung: Node im Projekt-Docker-Setup

**Der `web`-Container dieses Projekts hat kein Node und kein npm** (geprüft: `docker compose exec -T web sh -c 'which node npm'` liefert nichts, `node: not found`; Image `shopware-web:6.7.15.1` aus `ghcr.io/shopware/docker-base:8.4-nginx`). JS-Tests laufen daher nicht per `docker compose exec web ...`. Zwei geprüfte Wege:

**(i) Node auf dem Host.** Das Projekt-Verzeichnis ist per Volume (`./:/var/www/html`, `compose.override.yaml`) eingebunden, die Plugin-Dateien unter `custom/plugins/` liegen also auf dem Host. Dort ausführen:

```bash
cd custom/plugins/SwagExample/src/Resources/app/storefront
npm install        # einmalig
npm run unit
```

**(ii) Separater Node-Container** (Projektwurzel einhängen, weil die Configs per relativem Pfad auf `vendor/` zeigen):

```bash
docker run --rm -v "$PWD":/app \
  -w /app/custom/plugins/SwagExample/src/Resources/app/storefront \
  node:22 sh -c 'npm install --no-audit --no-fund && npm run unit'
```

Bei Docker Desktop unter macOS ist `docker` ggf. nicht im PATH: `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`.

Node-Version (belegt in den Core-`package.json`): Storefront `engines.node: ^20.19.0 || >=22.12.0`, `engines.npm: >=11.8.0`; Administration `engines.node: ^20.0.0 || ... || ^25.0.0`, `engines.npm: >=10.0.0`. Empfehlung: Node 22 (gemeinsame Schnittmenge). Der Host hat Node 26, das die Admin-`engines` nicht abdeckt (lief trotzdem). Der `node:22`-Container hat npm 10.9.9, unter der Storefront-Vorgabe `>=11.8.0` (lief trotzdem, nur der Core selbst erzwingt das nicht für Plugins). ⚠ UNGEPRÜFT: Verhalten mit `engine-strict`.

Wichtig: `node_modules` ist plattformabhängig (Host macOS vs. Container Linux). Pro Verzeichnis nur einen Weg verwenden oder vor dem Wechsel `node_modules` löschen.

## Storefront (Jest)

Einrichtung: Dateien aus `storefront/` ins Plugin kopieren, `npm install`, Pfad in `moduleNameMapper` (`^src/`) prüfen (7x `..` bis zur Projektwurzel bei Layout `custom/plugins/<Plugin>/src/Resources/app/storefront`; bei Plugin in `custom/static-plugins` oder Symlink anpassen). Ausführen: `npm run unit`.

Belegt durch den Lauf:
- `modulePaths: ['<rootDir>/node_modules']` ist nötig, sonst findet der Core-Code unter `vendor/` seine Pakete (`deepmerge`) nicht. Der Core-Code liegt ohne eigenes `node_modules` (`vendor/shopware/storefront/.../storefront` hat keins). Core-Importe aus `src/` laden nur das, was im Plugin installiert ist; `core-js` und `deepmerge` reichen für `Plugin`-Basisklasse und `$emitter`. Importiert euer Code weitere Core-Module mit Paketen, diese im Plugin ergänzen.
- `babel.config.js` im Plugin-Ordner (Root-Config) wird gebraucht; eine `.babelrc.js` dort griff nicht für Dateien unter `vendor/` (Test schlug mit Parse-Fehler fehl).
- `babel-jest` muss nicht separat installiert werden (kommt mit Jest).
- Das Beispiel-Plugin liest `window.PluginBaseClass`; im Browser setzt das `plugin.manager.js`. In `jest.init.js` wird es daher aus `src/plugin-system/plugin.class` gesetzt. Plugins, die `PluginBaseClass` direkt importieren, brauchen das nicht.

Auf Ordnerebene wertet `bin/build-storefront.sh` ein `package.json` in `.../app/storefront` aus und führt dort `npm install` aus, sofern noch kein `node_modules` existiert (inklusive `devDependencies`, also Jest). ⚠ UNGEPRÜFT: Auswirkung von `babel.config.js`/`jest.config.js` im Plugin-Ordner auf den Storefront-Build (der Core nutzt `swc-loader`); vor Auslieferung `bin/build-storefront.sh` laufen lassen und `node_modules` nicht ausliefern.

## Administration (Jest + Preset `@shopware-ag/jest-preset-sw6-admin` 6.0.6)

Das Core-`test/`-Verzeichnis (Setup, Helfer, `wrapTestComponent`) liegt nicht im Vendor-Paket `administration`. Das Preset ersetzt es teilweise: `Shopware`-Global, Mixins, Direktiven, Twig-Transformer (Abhängigkeiten des Presets 6.0.6: `@babel/core`, `@babel/preset-typescript`, `babel-jest ^27`, `babel-plugin-shopware-vite-meta-glob`).

Voraussetzung: die Administration-Quellen **mit installiertem `node_modules`** (Preset lädt `src/core/shopware.ts` und `node_modules/vue` daraus). Im Projekt ist das nicht der Fall (`vendor/shopware/administration/.../administration` hat kein `node_modules`). Geprüft wurde `npm ci --ignore-scripts` auf einer Kopie (`src`, `package.json`, `package-lock.json`, `patches`, `scripts`), rund 1 GB, ~2000 Pakete, im Container `node:22` und auf dem Host:

```bash
cp -R vendor/shopware/administration/Resources/app/administration /pfad/admin-kopie   # ausserhalb des Projekts
cd /pfad/admin-kopie && npm ci --ignore-scripts
cd custom/plugins/SwagExample/src/Resources/app/administration
npm install
ADMIN_PATH=/pfad/admin-kopie npm run unit
```

⚠ LÜCKE: `npm ci` direkt in `vendor/` (Standardpfad der `jest.config.js`) wurde bewusst nicht ausgeführt; `vendor/` wird von Composer verwaltet, eine Neuinstallation überschreibt es. Kopie mit `ADMIN_PATH` ist der geprüfte Weg. `--ignore-scripts` überspringt `preinstall` (Validierung) und `postinstall` (`patch-package`); für Tests reichte das.

Belegt durch den Lauf (nicht aus der Preset-README, die nur `adminPath` nennt und veraltet ist):
- Das Preset muss mit dem 6.7-Admin ergänzt werden: `ADMIN_PATH` als Umgebungsvariable (das Preset mappt `^src(.*)$` darauf), Mapper für `lodash-es/*`, `@shopware-ag/meteor-admin-sdk/es`, `admin-extension-sdk/es`, `meteor-component-library`, `@vue/test-utils`, `vue` und `transformIgnorePatterns` für `@shopware-ag/meteor-*` (an `_jest.config.ts` des Cores angelehnt). Ohne sie bricht das Setup mit "Cannot use import statement" bzw. "Could not locate module" ab.
- `@vue/test-utils` und `vue` werden aus dem Admin-`node_modules` gemappt (eine Vue-Instanz); im Plugin nicht separat installieren.
- `babel.config.json` mit `@babel/preset-env` ist Pflicht.
- Nur Testmuster `Shopware.Component.register` + `Shopware.Component.build(...)` + `mount` ist geprüft. `wrapTestComponent`, `flushPromises`-Global, `global.repositoryFactoryMock`, `activeAclRoles`, `activeFeatureFlags` (T-47 4.3) sind Core-`test/`-Helfer und mit dem Preset NICHT geprüft (⚠ LÜCKE); `flushPromises` kann aus `@vue/test-utils` importiert werden.

Stubs und Mocks (Vue Test Utils 2): `mount(component, { props, global: { stubs: { 'sw-icon': true }, mocks: { $t: (k) => k }, provide: { repositoryFactory: ... } } })`. ⚠ UNGEPRÜFT: `stubs`/`mocks`/`provide` sind VTU-2-API und im Beispiel nur leer eingetragen; mit echten Shopware-Komponenten (z. B. `sw-page`, `mt-button`) nicht ausgeführt. `Shopware.Component.build` löst Twig-Blöcke/Vererbung auf, registriert aber keine Kindkomponenten: unbekannte `sw-*`-Tags werden als Custom-Element gerendert (Warnung) oder müssen gestubbt werden.

Zusätzlich: `admin:unit` aus dem Platform-Repo existiert im Projekt nicht (kein `composer run admin:unit`); siehe T-47.

## Fallstricke

- `web`-Container hat kein Node (siehe oben).
- Storefront-Tests heißen `*.test.js` unter `test/`, Admin-Tests `*.spec.js|ts` unter `src/`.
- `.babelrc.js` im Plugin reicht für Core-Code unter `vendor/` nicht; `babel.config.js` bzw. `babel.config.json` verwenden.
- Admin: Core-Specs (`src/**/*.spec.js` unter `ADMIN_PATH`) nicht mitlaufen lassen: `testMatch` bleibt auf `<rootDir>` (Plugin).
- Preset 6.0.6 hängt von `babel-jest ^27` ab, das Plugin nutzt Jest 30 (lief, aber Versionsmischung; ⚠ UNGEPRÜFT auf Dauer/Updates).
- `node_modules` des Plugins nicht committen/ausliefern; `bin/build-administration.sh` installiert für Plugins mit `package.json` per `npm install --omit=dev`, `bin/build-storefront.sh` ohne `--omit=dev`.
- `TZ=UTC` wird in der Admin-Config gesetzt; Tests nicht von der lokalen Zeitzone abhängig machen.
- Linux-Host: Dateien aus `docker run` gehören ggf. root (⚠ UNGEPRÜFT; unter Docker Desktop macOS nicht aufgefallen). Ggf. `--user "$(id -u):$(id -g)"` ergänzen.

## Lücken

- ⚠ LÜCKE: Preset-Eignung dauerhaft: Preset ist laut `CHANGELOG` auf Vue 3/Vite-Meta-Glob ausgelegt, aber im Setup fehlen 6.7-Mapper; die obige Konfiguration ist aus dem Core-`_jest.config.ts` abgeleitet und nur mit einer Minimalkomponente geprüft.
- ⚠ LÜCKE: Lauf gegen das echte `vendor/` und ein echtes Plugin in `custom/plugins/` (siehe oben).
- ⚠ LÜCKE: Vitest-Tests (Storefront-Component-System) und Playwright/Acceptance Test Suite: keine Anleitung hier.
- ⚠ LÜCKE: Integration in CI/Release-Skripte (`composer.json` des Projekts enthält keine JS-Test-Scripts).
