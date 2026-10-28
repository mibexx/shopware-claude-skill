# Template: Storefront-JS-Plugin (SwagExample)

Zweck: Plugin mit einem eigenen Storefront-JS-Plugin (ES-Klasse, die `PluginBaseClass` erweitert), Registrierung in `main.js`, Einbindung per `data`-Attribut in Twig und einem auskommentierten Beispiel zum Überschreiben eines Core-Plugins. Shopware 6.7.15.1.

## Dateibaum

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    └── Resources/
        ├── snippet/storefront.{de,en}.json
        ├── views/storefront/base.html.twig                       # data-Attribut am Button
        └── app/storefront/src/
            ├── main.js                                           # PFLICHT-Einstieg, Registrierung
            ├── swag-example/swag-example.plugin.js               # Plugin-Klasse
            └── override/swag-example-scroll-up.plugin.js         # Beispiel: Core-Plugin erweitern
```

Build-Ergebnis (nicht im Gerüst, wird vom Build erzeugt): `src/Resources/app/storefront/dist/storefront/js/swag-example/swag-example.js`. Bei Auslieferung per Composer/Store mit einchecken.

## Platzhalter

| Platzhalter | Ersetzen durch | Wo |
|---|---|---|
| `SwagExample` (Plugin) | Plugin-/Klassenname | Ordner, Klasse, Namespace, `composer.json` |
| `SwagExample` (JS-Plugin-Name) | Eindeutiger Name der Instanz | `main.js`; bestimmt den Attributnamen: CamelCase -> dash-case (`data-swag-example`, `data-swag-example-options`) |
| `[data-swag-example]` | Selektor | `main.js`, Twig |
| `swag-example` (Ordner/Datei) | Kebab-Case des Features | Pfad der Plugin-Klasse |
| `swagExample.toggle` | Eigener Snippet-Schlüssel | `snippet/storefront.*.json`, Twig |

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-storefront.sh          # JS bauen (Storefront lädt dist/, nicht src/)
bin/console theme:compile --sync # falls alte Dateien ausgeliefert werden
bin/console cache:clear
# Entwicklung mit Hot-Reload:
bin/watch-storefront.sh
```

Im Browser prüfen: `window.PluginManager.getPluginList()` und `window.PluginManager.getPluginInstances('SwagExample')`.

## Regeln (am Quellcode belegt)

- `src/Resources/app/storefront/src/main.js` (oder `main.ts`, hat Vorrang) ist Pflicht. Ohne Datei wird das Bundle still ignoriert.
- Plugin-Klasse: `init()` ist Pflicht (sonst Konsolenwarnung), `static options` für Defaults, `this.$emitter.publish/subscribe` für Events, `destroy()` implementieren und alle Listener außerhalb von `this.el` dort entfernen.
- Optionen-Merge: `static options`, Konstruktor-Optionen, `data-<name>-config`, `data-<name>-options` (JSON).
- Async-Registrierung `() => import(...)` ist der Standard des Cores. Ohne Prototype erkennt der Manager sie als async.
- `PluginManager.override(name, Klasse, selector)` ersetzt die komplette Registrierung. Die neue Klasse muss das Core-Plugin erweitern (`extends`), sonst fehlt dessen Funktionalität. Pro Plugin ist praktisch nur ein Override möglich, Events (`$emitter.subscribe('event.Namespace', ...)`) sind konfliktfrei.
- Nach AJAX-Nachladen: `window.PluginManager.initializePluginsInParentElement(el)`.
- Twig: `{% sw_extends %}`, Block `base_main_inner` (innerhalb von `<main>`) stammt aus `storefront/base.html.twig`; er wird von `page/content/index` und `page/content/product-detail` ebenfalls überschrieben (⚠ dort nur mit `parent()` wirksam). Der Toggle-Button setzt `aria-controls`/`aria-expanded`, das JS schaltet `aria-expanded` um.
- Externe Skripte als `<script>`-Tag: im Block `base_body_script` (`base.html.twig`, immer `{{ parent() }}` aufrufen).

## Deprecations vermeiden

- `HttpClient` (`src/service/http-client.service`): `@deprecated tag:v6.8.0`, stattdessen `fetch()`.
- `DomAccess` (`src/helper/dom-access.helper`): komplett `@deprecated tag:v6.8.0`, stattdessen native DOM-API.
- Core-Plugins `FormValidation`, `FormSubmitLoader`: deprecated, Ersatz `FormHandler`.
- Block `layout_head_javascript_hmr_mode` (Doku-Empfehlung für Script-Tags) ist in 6.7.15.1 `@deprecated tag:v6.8.0`, daher `base_body_script`.

## Lücken und Hinweise

- ⚠ UNGEPRÜFT: Das Gerüst wurde nicht gebaut und nicht im Browser getestet (JS-Syntax per `node --check`, JSON per `JSON.parse`, Signaturen und Blockname am Quellcode geprüft).
- ⚠ UNGEPRÜFT: `import ... from 'src/plugin/scroll-up/scroll-up.plugin'` im Override-Beispiel setzt den Webpack-Alias `src` auf das Core-Storefront voraus (Core-Code nutzt denselben Import-Stil). Das Beispiel ist in `main.js` auskommentiert.
- ⚠ UNGEPRÜFT: Verhalten von `PluginManager.override()` bei Core-Plugins mit Async-Registrierung (aus `plugin.manager.js` abgeleitet, nicht im Browser verifiziert). Die Doku-Variante `getPlugin('X').get('class')` liefert bei Async-Plugins nur die Lade-Funktion, daher wird hier die Klasse direkt importiert.
- ⚠ UNGEPRÜFT: Verhalten von `bin/watch-storefront.sh` im Vite-/Importmap-Modus (Feature-Flag `v6.8.0.0`).
- ⚠ LÜCKE: Sichtbares Styling des Buttons/Ziels (`.is-open`) ist nicht enthalten. SCSS-Einstieg eines Plugins wäre `src/Resources/app/storefront/src/scss/base.scss` (siehe [theme](../theme/README.md)).

## Referenzen

- [15 Storefront-JS-Plugins](../../references/15-storefront-js-plugins.md)
- [14 Storefront-Twig-Templates](../../references/14-storefront-twig-templates.md)
- [13 Storefront-Styling & Assets](../../references/13-storefront-styling-assets.md)
- [18 Snippets & Übersetzungen](../../references/18-snippets-uebersetzungen.md)
- [49 Build & Deployment](../../references/49-build-deployment.md)
- Weitere Templates: [theme](../theme/README.md), [plugin](../plugin/README.md)

- Snippet-Dateien sind sprachneutral benannt (`de.json`/`en.json` bzw. `storefront.de.json`/`storefront.en.json`); regionale Namen (`de-DE.json`) beanstandet `bin/console translation:lint-filenames` ohne sprachneutrale Basisdatei, Reparatur mit `--fix` (siehe [T-18](../../references/18-snippets-uebersetzungen.md)).
