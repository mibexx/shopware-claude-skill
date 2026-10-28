# Storefront JS plugins (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when custom JavaScript is written for the Storefront, a core JS plugin is overridden/removed, plugin events are handled, or data is loaded via `fetch`. `SF` stands for `storefront/Resources/app/storefront/src` in the core.
Scope: SCSS/assets see [T-13](./13-storefront-styling-assets.md), Twig binding see [T-14](./14-storefront-twig-templates.md), backend routes for AJAX see [T-16](./16-storefront-controller-pages.md), build/watch see [T-49](./49-build-deployment.md), admin JS see [T-24](./24-admin-modules.md).

## 2. Concepts

- **Plugin class:** ES class that extends `Plugin` (`SF/plugin-system/plugin.class.js`), available in plugins/themes via `window.PluginBaseClass`. `init()` is mandatory (otherwise warning "The init method … is not defined").
- **Registration:** `PluginManager.register(name, class, selector, options)`. The manager binds the plugin to all elements of the CSS selector. The name is the instance ID, must be unique and determines the data attributes (dash-case: `ScrollHint` → `data-scroll-hint`).
- **Required entry:** `src/Resources/app/storefront/src/main.js`; `main.ts` takes precedence (`StorefrontPluginConfigurationFactory::getEntryFile()`). Without this file the bundle is silently ignored (`entryFilePath = null`, `BundleConfigGenerator`).
- **Async registration** (lazy chunk, recommended): `register('Name', () => import('./x.plugin'), '[data-x]')`. The manager detects async by the value having no `prototype`. The core registers almost everything this way (`SF/main.js`).
- **Options** (deep merge in this order): `static options`, `this.options`, constructor/register options, `data-<name>-config` (PluginConfigManager), `data-<name>-options` (JSON).
- **Overriding:** `PluginManager.override('Name', NewClass, selector)` = `extend(name, name, …)`: the old registration is deleted via `deregister`, the new one registered, existing instances are torn down via `destroy()` and re-initialized. **Extending under a new name:** `PluginManager.extend('Old', 'New', {…methods}, selector)`. **Removing:** `PluginManager.deregister('OffCanvasCart', '[data-off-canvas-cart]')`.
- **Events:** Every plugin has `this.$emitter` (`NativeEventEmitter`) with `publish(name, detail, cancelable)`, `subscribe(name[.namespace], cb, {once, scope})`, `unsubscribe(name[.namespace])`, `reset()`. Events of other plugins: `PluginManager.getPluginInstanceFromElement(el, 'PluginName').$emitter.subscribe(...)`.
- **Helpers (6.7):** `fetch()` is the standard. `HttpClient` and `DomAccess` are `@deprecated tag:v6.8.0`. `PseudoModalUtil` and the `AjaxModal` plugin are current. `DatePickerPlugin` (flatpickr) is registered but not used by the core itself.
- **External scripts as `<script>` tag:** Twig block `base_body_script` in `base.html.twig` (stable).

Directory tree:

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    └── Resources/
        ├── app/storefront/
        │   ├── src/
        │   │   ├── main.js                       # REQUIRED
        │   │   └── scroll-hint/scroll-hint.plugin.js
        │   └── dist/storefront/js/swag-example/swag-example.js   # build result
        └── views/storefront/base.html.twig
```

The plugin bundle is compiled to `dist/storefront/js/<technical-name-dashed>/<technical-name-dashed>.js`.

## 3. Instructions

1. Create/activate the plugin if needed: `bin/console plugin:create SwagExample`, `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample` (see [T-01](./01-project-structure-dev-environment.md), [T-02](./02-plugin-structure.md)).
2. Create `src/Resources/app/storefront/src/main.js` and `<name>/<name>.plugin.js`.
3. Extend the class with `const { PluginBaseClass } = window;`, define `static options`, implement `init()`. Remove everything that is registered outside of `this.el` (`window`/`document` listeners) again in `destroy()`.
4. Register in `main.js` with `PluginManager.register(Name, () => import(...), '[data-…]')`.
5. In the Twig template set `data-<dash-name>="true"`, optionally `data-<dash-name>-options='{…json…}'`.
6. Build: `bin/build-storefront.sh` (runs `bundle:dump`, `feature:dump`, npm install and build) or `bin/watch-storefront.sh`. Afterwards, if needed, `bin/console theme:compile` and `bin/console cache:clear`.
7. Check in the browser: `PluginManager.getPluginList()`, `PluginManager.getPluginInstances('ScrollHint')`.
8. After loading content via AJAX: `window.PluginManager.initializePluginsInParentElement(el)`.

## 4. Examples

Scaffold: [../templates/storefront-js-plugin/README.md](../templates/storefront-js-plugin/README.md).

`src/Resources/app/storefront/src/scroll-hint/scroll-hint.plugin.js`
```javascript
const { PluginBaseClass } = window;

export default class ScrollHintPlugin extends PluginBaseClass {
    static options = {
        text: 'End reached',
        visibleClass: 'is-visible',
        offset: 100,
    };

    init() {
        this._onScroll = this._onScroll.bind(this);
        window.addEventListener('scroll', this._onScroll, { passive: true });
        this.$emitter.publish('scrollHintInit');
    }

    update() {
        // called when initializePlugins() runs again on the same element
    }

    destroy() {
        // mandatory if the plugin is overridden later
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
// window.PluginManager is already set by the core Storefront JS
const PluginManager = window.PluginManager;

PluginManager.register(
    'ScrollHint',
    () => import('./scroll-hint/scroll-hint.plugin'),
    '[data-scroll-hint]',
);

// Remove a core plugin (optional)
// PluginManager.deregister('OffCanvasCart', '[data-off-canvas-cart]');
```

`src/Resources/views/storefront/base.html.twig` (binding to the DOM and external script)
```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_body_script %}
    {{ parent() }}
    <div class="scroll-hint"
         data-scroll-hint="true"
         data-scroll-hint-options="{{ { text: 'Thanks for reading' }|json_encode }}"></div>
    <script src="https://example.com/library.js" defer></script>
{% endblock %}
```

Reacting to an event of a core plugin (in `init()` of your own plugin):
```javascript
const el = document.querySelector('[data-cookie-permission]');
const cookie = window.PluginManager.getPluginInstanceFromElement(el, 'CookiePermission');
cookie?.$emitter.subscribe('hideCookieBar.SwagExample', () => { /* ... */ });
```
The suffix `.SwagExample` is a namespace. With it, `unsubscribe('hideCookieBar.SwagExample')` removes only your own listener.

Overriding a core plugin (`my-cookie-permission.plugin.js`, then registration in `main.js`):
```javascript
import CookiePermissionPlugin from 'src/plugin/cookie/cookie-permission.plugin';

export default class MyCookiePermission extends CookiePermissionPlugin {
    init() {
        super.init();
        // custom logic
    }
}
```
```javascript
import MyCookiePermission from './my-cookie-permission/my-cookie-permission.plugin';
window.PluginManager.override('CookiePermission', MyCookiePermission, '[data-cookie-permission]');
```
With an async parent, `window.PluginManager.getPlugin('CookiePermission').get('class')` returns only the loader function instead of the class. In that case work directly via `import` (alias `src/plugin/...`) or via `extend('Old','New',{methods})`.

Loading data with `fetch` (instead of `HttpClient`):
```javascript
async loadInfo() {
    const response = await fetch(window.router['frontend.cart.offcanvas'], {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    this.el.innerHTML = await response.text();
    window.PluginManager.initializePluginsInParentElement(this.el);
}
```
Backend routes for this need `XmlHttpRequest => true` (see [T-16](./16-storefront-controller-pages.md)).

Modal via `PseudoModalUtil`:
```javascript
import PseudoModalUtil from 'src/utility/modal-extension/pseudo-modal.util';

const modal = new PseudoModalUtil(htmlContent); // content, useBackdrop = true
modal.open(() => window.PluginManager.initializePluginsInParentElement(modal.getModal()));
// modal.updateContent(newHtml, cb); modal.close();
```
Modal via data attribute without JS: `<button data-ajax-modal="true" data-url="/widgets/...">` (selector `[data-ajax-modal][data-url]`).

## 5. Pitfalls

- `main.js` missing or in the wrong place: the bundle is ignored.
- Build forgotten: the Storefront loads `dist/…`, not `src/…` (except with `bin/watch-storefront.sh`).
- Plugin does not start: wrong selector, `init()` missing, name registered twice (`Plugin "X" is already registered.`).
- `override()` replaces the complete registration. Without `extends CorePlugin`, all core functionality is missing. According to the docs, a plugin can only be overridden once; with two plugins there is a conflict: prefer subscribing to `$emitter` events.
- Listeners in `init()` without `destroy()`: after an override, `addEventListener` listeners remain active (console warning "does not implement destroy()").
- `unsubscribe(name, fn)` is not supported (removes all listeners of the event). Always use a namespace suffix.
- `extend()` with a different name only takes over prototype methods (`Object.assign`), no class fields/`static options`. Pass options in the 5th parameter.
- Markup loaded via AJAX needs `initializePluginsInParentElement`.
- Forgetting `{{ parent() }}` in `base_body_script`: breaks the default scripts.
- New projects with `DomAccess`/`HttpClient`: both `@deprecated tag:v6.8.0` (use `querySelector`/`fetch` instead). `FormValidation`/`FormSubmitLoader` as well (replacement `FormHandler`).
- jQuery no longer exists since 6.5. Bootstrap 5 is available as `window.bootstrap`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `HttpClient` (`http-client.service.js`) and `DomAccess` (`dom-access.helper.js`, focus helper now `window.focusHandler`) are `@deprecated tag:v6.8.0`.
- `FormValidation`, `FormSubmitLoader` are marked as `@deprecated tag:v6.8.0` in `SF/main.js`. Removed since 6.6 (according to the docs): `EllipsisPlugin`, `FadingPlugin`.
- Script tag block: the docs name `layout_head_javascript_hmr_mode`. In the 6.7.15.1 code (`layout/meta.html.twig`) it is `@deprecated tag:v6.8.0` and is only rendered without `feature('v6.8.0.0')`. With the flag active, `layout_head_javascript_assets` applies (Vite/import map). `base_body_script` is stable in both variants.
- Build tooling: `webpack.config.js` and `vite.shopware.config.mts` exist side by side (see T-49). The webpack hot proxy `/_webpack_hot_proxy_/` is deprecated.
- `destroy()`/re-initialization on override and `_extendPlugin` for async plugins are confirmed in the 6.7 code.
- The new mechanisms `window.Shopware`/`ShopwareComponent` (`SF/shopware.ts`, `SF/component-system`; `ShopwareComponent` is `@internal`) are not described here.

## 6. Checklist

- [ ] `src/Resources/app/storefront/src/main.js` (or `main.ts`) exists.
- [ ] Plugin class extends `PluginBaseClass` and implements `init()`.
- [ ] External listeners are removed in `destroy()`.
- [ ] Registration via `register(name, () => import(...), selector)` with a unique name.
- [ ] Data attribute in Twig matches the dash-case name.
- [ ] Events are subscribed with a namespace suffix.
- [ ] Markup loaded later is initialized with `initializePluginsInParentElement`.
- [ ] `bin/build-storefront.sh` executed, cache cleared.
- [ ] No new code with `DomAccess`/`HttpClient`.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/add-custom-javascript.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/override-existing-javascript.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/plugin-reference.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/reacting-to-javascript-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/fetching-data-with-javascript.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/javascript/add-javascript-as-script-tag.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/remove-unnecessary-js-plugin.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/using-a-modal-window.html

Source code (relative to `vendor/shopware/storefront/Resources/app/storefront/src/`): `plugin-system/{plugin.class,plugin.manager,plugin.registry,plugin.config.manager}.js` (`PluginManagerSingleton`: `register`, `deregister`, `extend`, `initializePlugins`, `_destroyPluginInstance`, `_extendPlugin`; facade `PluginManager.override`), `main.js`, `helper/emitter.helper.js`, `helper/dom-access.helper.js`, `service/http-client.service.js`, `utility/modal-extension/pseudo-modal.util.js`, `plugin/{ajax-modal,date-picker,cookie,scroll-up}/*.plugin.js`. Also `storefront/Theme/StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php` (`getEntryFile`), `core/Framework/Plugin/BundleConfigGenerator.php`, `storefront/Resources/views/storefront/layout/meta.html.twig`, `base.html.twig` (`base_body_script`).

### Gaps and uncertainties

- ⚠ UNVERIFIED: the example code was neither built nor run in the browser (source code reading only).
- ⚠ UNVERIFIED: an override of `layout_head_javascript_assets` also takes effect in 6.7 according to a reading of the code, not confirmed in the browser. Hence the recommendation `base_body_script`.
- ⚠ UNVERIFIED: according to the code, the docs variant `getPlugin('CookiePermission').get('class')` only works for already loaded/synchronous plugins; async behavior not verified in the browser.
- ⚠ UNVERIFIED: conflict resolution with two plugins overriding the same core plugin was not tested.
- ⚠ GAP: behavior of the plugin `main.js` in Vite/import map mode (flag `v6.8.0.0`) not examined (see T-49).
- ⚠ UNVERIFIED: "new in 6.7" for `destroy()`/re-initialization is not verified for 6.5/6.6.
- ⚠ UNVERIFIED: dist file name `swag-example` follows the naming scheme (`getContainerPrefix`, `BundleConfigGenerator`), not checked by a build.
- ⚠ GAP: datepicker options (`dateFormat`, `locale`, `selectors`) derived from `static options`; no docs page found.
