# Storefront-JS-Plugins (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eigenes JavaScript für die Storefront geschrieben, ein Core-JS-Plugin überschrieben/entfernt, auf Plugin-Events reagiert oder Daten per `fetch` nachgeladen wird. `SF` steht für `storefront/Resources/app/storefront/src` im Core.
Abgrenzung: SCSS/Assets siehe [T-13](./13-storefront-styling-assets.md), Twig-Anbindung siehe [T-14](./14-storefront-twig-templates.md), Backend-Routen für AJAX siehe [T-16](./16-storefront-controller-pages.md), Build/Watch siehe [T-49](./49-build-deployment.md), Admin-JS siehe [T-24](./24-admin-module.md).

## 2. Konzepte

- **Plugin-Klasse:** ES-Klasse, die `Plugin` (`SF/plugin-system/plugin.class.js`) erweitert, in Plugins/Themes über `window.PluginBaseClass` erreichbar. `init()` ist Pflicht (sonst Warnung „The init method … is not defined“).
- **Registrierung:** `PluginManager.register(name, klasse, selector, options)`. Der Manager bindet das Plugin an alle Elemente des CSS-Selektors. Der Name ist die Instanz-ID, muss eindeutig sein und bestimmt die Data-Attribute (dash-case: `ScrollHint` → `data-scroll-hint`).
- **Pflicht-Einstieg:** `src/Resources/app/storefront/src/main.js`; `main.ts` hat Vorrang (`StorefrontPluginConfigurationFactory::getEntryFile()`). Ohne diese Datei wird das Bundle stillschweigend ignoriert (`entryFilePath = null`, `BundleConfigGenerator`).
- **Async-Registrierung** (Lazy-Chunk, empfohlen): `register('Name', () => import('./x.plugin'), '[data-x]')`. Der Manager erkennt Async daran, dass der Wert keine `prototype` hat. Der Core registriert fast alles so (`SF/main.js`).
- **Optionen** (Deep-Merge in dieser Reihenfolge): `static options`, `this.options`, Konstruktor-/Register-Optionen, `data-<name>-config` (PluginConfigManager), `data-<name>-options` (JSON).
- **Überschreiben:** `PluginManager.override('Name', NeueKlasse, selector)` = `extend(name, name, …)`: alte Registrierung wird per `deregister` gelöscht, neue registriert, bestehende Instanzen werden über `destroy()` abgebaut und neu initialisiert. **Erweitern unter neuem Namen:** `PluginManager.extend('Alt', 'Neu', {…methoden}, selector)`. **Entfernen:** `PluginManager.deregister('OffCanvasCart', '[data-off-canvas-cart]')`.
- **Events:** Jedes Plugin hat `this.$emitter` (`NativeEventEmitter`) mit `publish(name, detail, cancelable)`, `subscribe(name[.namespace], cb, {once, scope})`, `unsubscribe(name[.namespace])`, `reset()`. Fremde Events: `PluginManager.getPluginInstanceFromElement(el, 'PluginName').$emitter.subscribe(...)`.
- **Helfer (6.7):** `fetch()` ist Standard. `HttpClient` und `DomAccess` sind `@deprecated tag:v6.8.0`. `PseudoModalUtil` und `AjaxModal`-Plugin sind aktuell. `DatePickerPlugin` (flatpickr) ist registriert, wird aber vom Core nicht selbst genutzt.
- **Externe Skripte als `<script>`-Tag:** Twig-Block `base_body_script` in `base.html.twig` (stabil).

Verzeichnisbaum:

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    └── Resources/
        ├── app/storefront/
        │   ├── src/
        │   │   ├── main.js                       # PFLICHT
        │   │   └── scroll-hint/scroll-hint.plugin.js
        │   └── dist/storefront/js/swag-example/swag-example.js   # Build-Ergebnis
        └── views/storefront/base.html.twig
```

Das Plugin-Bundle wird zu `dist/storefront/js/<technical-name-dashed>/<technical-name-dashed>.js` kompiliert.

## 3. Anleitung

1. Plugin anlegen/aktivieren, falls nötig: `bin/console plugin:create SwagExample`, `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample` (siehe [T-01](./01-projektstruktur-dev-umgebung.md), [T-02](./02-plugin-aufbau.md)).
2. `src/Resources/app/storefront/src/main.js` und `<name>/<name>.plugin.js` anlegen.
3. Klasse mit `const { PluginBaseClass } = window;` erweitern, `static options` definieren, `init()` implementieren. Alles, was außerhalb von `this.el` registriert wird (`window`-/`document`-Listener), in `destroy()` wieder entfernen.
4. In `main.js` mit `PluginManager.register(Name, () => import(...), '[data-…]')` registrieren.
5. Im Twig-Template `data-<dash-name>="true"` setzen, optional `data-<dash-name>-options='{…json…}'`.
6. Bauen: `bin/build-storefront.sh` (führt `bundle:dump`, `feature:dump`, npm-Install und Build aus) oder `bin/watch-storefront.sh`. Danach bei Bedarf `bin/console theme:compile` und `bin/console cache:clear`.
7. Im Browser prüfen: `PluginManager.getPluginList()`, `PluginManager.getPluginInstances('ScrollHint')`.
8. Nach AJAX-Nachladen: `window.PluginManager.initializePluginsInParentElement(el)`.

## 4. Beispiele

Gerüst: [../templates/storefront-js-plugin/README.md](../templates/storefront-js-plugin/README.md).

`src/Resources/app/storefront/src/scroll-hint/scroll-hint.plugin.js`
```javascript
const { PluginBaseClass } = window;

export default class ScrollHintPlugin extends PluginBaseClass {
    static options = {
        text: 'Ende erreicht',
        visibleClass: 'is-visible',
        offset: 100,
    };

    init() {
        this._onScroll = this._onScroll.bind(this);
        window.addEventListener('scroll', this._onScroll, { passive: true });
        this.$emitter.publish('scrollHintInit');
    }

    update() {
        // wird aufgerufen, wenn initializePlugins() erneut auf demselben Element läuft
    }

    destroy() {
        // Pflicht, wenn das Plugin später überschrieben wird
        window.removeEventListener('scroll', this._onScroll);
    }

    _onScroll() {
        const reachedEnd = window.innerHeight + window.scrollY >= document.body.offsetHeight - this.options.offset;

        this.el.textContent = this.options.text;
        this.el.classList.toggle(this.options.visibleClass, reachedEnd);

        if (reachedEnd) {
            this.$emitter.publish('scrollHintReached', { text: this.options.text });
        }
    }
}
```

`src/Resources/app/storefront/src/main.js`
```javascript
// window.PluginManager ist durch die Core-Storefront-JS bereits gesetzt
const PluginManager = window.PluginManager;

PluginManager.register(
    'ScrollHint',
    () => import('./scroll-hint/scroll-hint.plugin'),
    '[data-scroll-hint]',
);

// Core-Plugin entfernen (optional)
// PluginManager.deregister('OffCanvasCart', '[data-off-canvas-cart]');
```

`src/Resources/views/storefront/base.html.twig` (Binden ans DOM und externes Script)
```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_body_script %}
    {{ parent() }}
    <div class="scroll-hint"
         data-scroll-hint="true"
         data-scroll-hint-options="{{ { text: 'Danke fürs Lesen' }|json_encode }}"></div>
    <script src="https://example.com/library.js" defer></script>
{% endblock %}
```

Auf Event eines Core-Plugins reagieren (in `init()` eines eigenen Plugins):
```javascript
const el = document.querySelector('[data-cookie-permission]');
const cookie = window.PluginManager.getPluginInstanceFromElement(el, 'CookiePermission');
cookie?.$emitter.subscribe('hideCookieBar.SwagExample', () => { /* ... */ });
```
Das Suffix `.SwagExample` ist ein Namespace. Mit ihm entfernt `unsubscribe('hideCookieBar.SwagExample')` nur den eigenen Listener.

Core-Plugin überschreiben (`my-cookie-permission.plugin.js`, dann Registrierung in `main.js`):
```javascript
import CookiePermissionPlugin from 'src/plugin/cookie/cookie-permission.plugin';

export default class MyCookiePermission extends CookiePermissionPlugin {
    init() {
        super.init();
        // eigene Logik
    }
}
```
```javascript
import MyCookiePermission from './my-cookie-permission/my-cookie-permission.plugin';
window.PluginManager.override('CookiePermission', MyCookiePermission, '[data-cookie-permission]');
```
Bei Async-Parent liefert `window.PluginManager.getPlugin('CookiePermission').get('class')` nur die Lade-Funktion statt der Klasse. Dann direkt per `import` (Alias `src/plugin/...`) oder per `extend('Alt','Neu',{methoden})` arbeiten.

Daten laden mit `fetch` (statt `HttpClient`):
```javascript
async loadInfo() {
    const response = await fetch(window.router['frontend.cart.offcanvas'], {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    this.el.innerHTML = await response.text();
    window.PluginManager.initializePluginsInParentElement(this.el);
}
```
Backend-Routen dafür brauchen `XmlHttpRequest => true` (siehe [T-16](./16-storefront-controller-pages.md)).

Modal per `PseudoModalUtil`:
```javascript
import PseudoModalUtil from 'src/utility/modal-extension/pseudo-modal.util';

const modal = new PseudoModalUtil(htmlContent); // content, useBackdrop = true
modal.open(() => window.PluginManager.initializePluginsInParentElement(modal.getModal()));
// modal.updateContent(neuerHtml, cb); modal.close();
```
Modal per Data-Attribut ohne JS: `<button data-ajax-modal="true" data-url="/widgets/...">` (Selektor `[data-ajax-modal][data-url]`).

## 5. Fallstricke

- `main.js` fehlt oder liegt falsch: Bundle wird ignoriert.
- Build vergessen: Die Storefront lädt `dist/…`, nicht `src/…` (außer bei `bin/watch-storefront.sh`).
- Plugin startet nicht: Selektor falsch, `init()` fehlt, Name doppelt registriert (`Plugin "X" is already registered.`).
- `override()` ersetzt die komplette Registrierung. Ohne `extends CorePlugin` fehlen alle Core-Funktionen. Laut Doku ist ein Plugin nur einmal überschreibbar, bei zwei Plugins Konflikt: lieber `$emitter`-Events abonnieren.
- Listener in `init()` ohne `destroy()`: Nach einem Override bleiben `addEventListener`-Listener aktiv (Konsolen-Warnung „does not implement destroy()“).
- `unsubscribe(name, fn)` wird nicht unterstützt (entfernt alle Listener des Events). Immer Namespace-Suffix nutzen.
- `extend()` mit anderem Namen übernimmt nur Prototyp-Methoden (`Object.assign`), keine Klassenfelder/`static options`. Optionen im 5. Parameter übergeben.
- Per AJAX nachgeladenes Markup braucht `initializePluginsInParentElement`.
- `{{ parent() }}` im `base_body_script` vergessen: zerstört Standard-Scripts.
- Neue Projekte mit `DomAccess`/`HttpClient`: beide `@deprecated tag:v6.8.0` (stattdessen `querySelector`/`fetch`). `FormValidation`/`FormSubmitLoader` ebenfalls (Ersatz `FormHandler`).
- jQuery gibt es seit 6.5 nicht mehr. Bootstrap 5 steht als `window.bootstrap` bereit.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `HttpClient` (`http-client.service.js`) und `DomAccess` (`dom-access.helper.js`, Fokus-Helfer jetzt `window.focusHandler`) sind `@deprecated tag:v6.8.0`.
- `FormValidation`, `FormSubmitLoader` sind in `SF/main.js` als `@deprecated tag:v6.8.0` markiert. Seit 6.6 entfernt (laut Doku): `EllipsisPlugin`, `FadingPlugin`.
- Script-Tag-Block: Die Doku nennt `layout_head_javascript_hmr_mode`. Er ist im 6.7.15.1-Code (`layout/meta.html.twig`) `@deprecated tag:v6.8.0` und wird nur ohne `feature('v6.8.0.0')` gerendert. Mit aktivem Flag gilt `layout_head_javascript_assets` (Vite/Importmap). `base_body_script` ist in beiden Varianten stabil.
- Build-Tooling: `webpack.config.js` und `vite.shopware.config.mts` liegen nebeneinander (siehe T-49). Der Webpack-Hot-Proxy `/_webpack_hot_proxy_/` ist deprecated.
- `destroy()`/Re-Initialisierung beim Override und `_extendPlugin` für Async-Plugins sind am 6.7-Code belegt.
- Neue Mechanismen `window.Shopware`/`ShopwareComponent` (`SF/shopware.ts`, `SF/component-system`; `ShopwareComponent` ist `@internal`) sind hier nicht beschrieben.

## 6. Checkliste

- [ ] `src/Resources/app/storefront/src/main.js` (oder `main.ts`) existiert.
- [ ] Plugin-Klasse erweitert `PluginBaseClass` und implementiert `init()`.
- [ ] Externe Listener werden in `destroy()` entfernt.
- [ ] Registrierung per `register(name, () => import(...), selector)` mit eindeutigem Namen.
- [ ] Data-Attribut im Twig entspricht dem dash-case-Namen.
- [ ] Events werden mit Namespace-Suffix abonniert.
- [ ] Nachgeladenes Markup wird mit `initializePluginsInParentElement` initialisiert.
- [ ] `bin/build-storefront.sh` ausgeführt, Cache geleert.
- [ ] Kein neuer Code mit `DomAccess`/`HttpClient`.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/add-custom-javascript.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/override-existing-javascript.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/plugin-reference.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/reacting-to-javascript-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/fetching-data-with-javascript.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/add-javascript-as-script-tag.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/remove-unnecessary-js-plugin.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/using-a-modal-window.html

Quellcode (relativ zu `vendor/shopware/storefront/Resources/app/storefront/src/`): `plugin-system/{plugin.class,plugin.manager,plugin.registry,plugin.config.manager}.js` (`PluginManagerSingleton`: `register`, `deregister`, `extend`, `initializePlugins`, `_destroyPluginInstance`, `_extendPlugin`; Fassade `PluginManager.override`), `main.js`, `helper/emitter.helper.js`, `helper/dom-access.helper.js`, `service/http-client.service.js`, `utility/modal-extension/pseudo-modal.util.js`, `plugin/{ajax-modal,date-picker,cookie,scroll-up}/*.plugin.js`. Außerdem `storefront/Theme/StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php` (`getEntryFile`), `core/Framework/Plugin/BundleConfigGenerator.php`, `storefront/Resources/views/storefront/layout/meta.html.twig`, `base.html.twig` (`base_body_script`).

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispielcode wurde weder gebaut noch im Browser ausgeführt (nur Quellcode-Lektüre).
- ⚠ UNGEPRÜFT: Ein Override von `layout_head_javascript_assets` greift laut Code-Lesart auch in 6.7, nicht per Browser bestätigt. Daher Empfehlung `base_body_script`.
- ⚠ UNGEPRÜFT: Die Doku-Variante `getPlugin('CookiePermission').get('class')` funktioniert laut Code nur bei bereits geladenen/synchronen Plugins, Async-Verhalten nicht im Browser verifiziert.
- ⚠ UNGEPRÜFT: Konfliktauflösung bei zwei Plugins, die dasselbe Core-Plugin überschreiben, wurde nicht getestet.
- ⚠ LÜCKE: Verhalten von Plugin-`main.js` im Vite-/Importmap-Modus (Flag `v6.8.0.0`) nicht untersucht (siehe T-49).
- ⚠ UNGEPRÜFT: „Neu in 6.7“ für `destroy()`/Re-Initialisierung ist für 6.5/6.6 nicht verifiziert.
- ⚠ UNGEPRÜFT: Dist-Dateiname `swag-example` folgt dem Namensschema (`getContainerPrefix`, `BundleConfigGenerator`), nicht per Build geprüft.
- ⚠ LÜCKE: Datepicker-Optionen (`dateFormat`, `locale`, `selectors`) aus `static options` abgeleitet, keine Doku-Seite gefunden.
