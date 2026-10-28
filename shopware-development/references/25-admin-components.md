# Overriding and extending admin components, styles (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when an existing Administration component is to be modified (`override`), derived from (`extend`), or when a new component including template, mixin, directive and SCSS is to be created. It describes the Options API variant with Twig blocks, which is the stable path for plugins in 6.7.15.1.

Scope: module, routes, menu → [T-24 Admin modules](./24-admin-modules.md). Repositories, services, state, notifications → [T-26 Admin data handling](./26-admin-data-handling.md). Snippets → [T-18 Snippets](./18-snippets-translations.md). Build scripts → [T-49 Build and deployment](./49-build-deployment.md). Scaffold: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Concepts

- The Administration is a Vue 3 app (Vue 3.5.22, Vite). In 6.7.15.1 there is no `@vue/compat` alias (`vite.config.mts` aliases `vue` to `vue/dist/vue.esm-bundler.js`). Plugins write plain Vue 3 Options API.
- Component factory `Shopware.Component` (`core/factory/async-component.factory.ts`):
  - `register(name, config)`: new component. A duplicate name leads to a warning and `false`. Without `template` (or `render`/`functional`) it is rejected with a warning.
  - `extend(name, extendName, config)`: creates a NEW component that inherits. The original remains unchanged. `template` is optional (without a template, the parent template is adopted).
  - `override(name, config, index = null)`: modifies the EXISTING component. Multiple overrides are possible, sorted by `index` (default 0).
  - All three also accept a function with a promise, e.g. `() => import('./swag-foo')` (the ES module `default` is unwrapped).
- Templates are `*.html.twig`. TwigJS only evaluates `{% block %}` and `{% parent %}`; `{{ }}` is preserved for Vue. Block content is a normal Vue template. Twig blocks exist here only as an admin mechanism; for storefront Twig see [T-14](./14-storefront-twig-templates.md).
- `this.$super('name', ...args)` calls the implementation of the base (`methods` and `computed`, including getters/setters). Detection via regex `/\.\$super/` on `fn.toString()`.
- Meteor components `mt-*` (`@shopware-ag/meteor-component-library` 5.7.1) are registered globally (`app/adapter/view/vue.adapter.ts`, approx. 45 global, lazy: `mt-data-table`, `mt-colorpicker`, `mt-popover`, `mt-popover-item`). Wrappers with Twig blocks: `mt-card`, `mt-tabs`, `mt-datepicker`, `mt-text-editor` (`app/component/meteor-wrapper/*`). Plain `mt-*` have no Twig blocks. For new UI, prefer `mt-*` over `sw-*`.
- Mixins: `Shopware.Mixin.register/getByName`. Directives: `Shopware.Directive.register`. Core mixins in `app/mixin`, core directives in `app/directive`.
- Decision guide: modify everywhere (e.g. a block in `sw-product-detail`) → `override`. A second, separate variant with the original unchanged → `extend`. Completely new → `register`.

Directory tree (plugin-relative; names below `src/` are a convention, only `main.js`/`main.ts` is mandatory):

```
src/Resources/app/administration/src/
├── main.js
├── mixin/swag-hello.mixin.js
├── directive/swag-highlight.directive.js
├── snippet/{de-DE,en-GB}.json
└── extension/
    ├── sw-product-detail/{index.js, sw-product-detail.html.twig}   # override
    └── swag-custom-text-field/{index.js, swag-custom-text-field.scss} # extend
```

`BundleConfigGenerator::getEntryFile()` looks for `Resources/app/administration/src/main.{js,ts}`. Build output: `src/Resources/public/administration/`.

## 3. Instructions

1. Find the target component and block: `grep -rn "{% block" <component>.html.twig`. Global components are in `app/component/index.ts`, module components in `module/<module>/index.js` (in the core mostly `.js`, partly `.ts`; pages and components also mostly `index.js`).
2. Decide: override, extend or register (see section 2).
3. Create the template: exclusively `{% block name %}...{% endblock %}`. `{% parent %}` in the block inserts the original content. Import via `import template from './x.html.twig'` (Vite plugin `twigjs-plugin`).
4. Register the component in `index.js` and import it in `main.js`. Order: mixins and directives first (`Mixin.getByName` throws if not yet registered).
5. Multiple overrides of the same component are ordered by the third parameter: `override(name, config, 10)`.
6. SCSS: import the file in `index.js`, variables via `@import "~scss/variables";` (alias to `app/assets/scss/variables.scss`; others: `mixins.scss`, `typography.scss`, `global.scss`, `all.scss`).
7. Use Meteor components directly in the template (`<mt-button variant="primary">`), no registration needed. `mt-card` requires the prop `positionIdentifier`.
8. Build (in the web container):

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh
bin/console assets:install
bin/console cache:clear
bin/watch-administration.sh          # development with hot reload, see T-49
```

`cache:clear` alone does not rebuild the JS. Afterwards, hard reload in the browser.

## 4. Examples

`src/Resources/app/administration/src/main.js`

```js
import './mixin/swag-hello.mixin';
import './directive/swag-highlight.directive';
import './extension/sw-product-detail';
import './extension/swag-custom-text-field';
```

`src/Resources/app/administration/src/mixin/swag-hello.mixin.js`

```js
Shopware.Mixin.register('swag-hello', {
    methods: {
        swagSayHello(name) {
            return `Hello ${name}`;
        },
    },
});
```

`src/Resources/app/administration/src/directive/swag-highlight.directive.js`

```js
Shopware.Directive.register('swag-highlight', {
    mounted(el, binding) {
        el.style.backgroundColor = binding.value ?? 'yellow';
    },
    updated(el, binding) {
        el.style.backgroundColor = binding.value ?? 'yellow';
    },
});
```

`src/Resources/app/administration/src/extension/sw-product-detail/index.js` (override with `$super`)

```js
import template from './sw-product-detail.html.twig';

Shopware.Component.override('sw-product-detail', {
    template,

    mixins: [
        Shopware.Mixin.getByName('swag-hello'),
    ],

    computed: {
        swagGreeting() {
            return this.swagSayHello(this.product?.name ?? '');
        },
    },

    methods: {
        onSave() {
            // own pre-check, then call the original
            return this.$super('onSave');
        },
    },
});
```

`src/Resources/app/administration/src/extension/sw-product-detail/sw-product-detail.html.twig`

```twig
{% block sw_product_detail_content_language_info %}
    {% parent %}
    <p v-swag-highlight="'#e8f4ff'" class="swag-product-detail__greeting">
        {{ swagGreeting }}
    </p>
{% endblock %}
```

Verified against the source code: block `sw_product_detail_content_language_info` and method `onSave()` exist in `module/sw-product/page/sw-product-detail/` (6.7.15.1). `product` as data/computed of the page not individually checked (`?.` guards against it).

`src/Resources/app/administration/src/extension/swag-custom-text-field/index.js` (extend, overriding a method, SCSS)

```js
import './swag-custom-text-field.scss';

// Without its own `template`, the template of sw-text-field is adopted.
Shopware.Component.extend('swag-custom-text-field', 'sw-text-field', {
    methods: {
        handleUpdateModelValue(event) {
            // existing method of sw-text-field (index.ts), trim the value
            return this.$super('handleUpdateModelValue', typeof event === 'string' ? event.trim() : event);
        },
    },
});
```

`.../swag-custom-text-field/swag-custom-text-field.scss`

```scss
@import "~scss/variables";

.swag-custom-text-field {
    border-left: 3px solid $color-shopware-brand-500;
}
```

Usage: `<swag-custom-text-field v-model:value="foo" class="swag-custom-text-field" />`. **Warning `@private`:** `sw-text-field` is marked `@private` and `@deprecated tag:v6.8.0` in the core (`app/component/form/sw-text-field/index.ts`). The example only serves to illustrate the extend/`$super` pattern. For production code, do not extend a `@private` component; instead build your own component around the public `mt-text-field` or use Meteor components directly. Further notes: `sw-text-field` is (a wrapper around `mt-text-field`); the block `sw_text_field` only encloses the `v-if` branch. A wrapper `<div>` inside it would break the `v-if`/`v-else` chain, which is why the block is not modified here.

⚠ UNVERIFIED: example code was not built in a running 6.7.15.1 admin. The variable name `$color-shopware-brand-500` was not checked against `variables.scss`.

## 5. Pitfalls

1. Wrong block name: TwigJS ignores unknown blocks without an error; the content simply does not appear. Block names change between versions; always check the source template.
2. `{% parent %}` forgotten: the override replaces the block completely, other plugins lose their content.
3. `$super` is not detected with an alias (`const s = this.$super`), an outsourced call or an arrow function as a method. `this.$super('$super')` throws an error. Always `this.$super('name')` in normal method syntax.
4. `register` for a core name: is discarded. For core components use `override` or `extend`.
5. Duplicate name with `register`/`extend`: warning, conflict. Use a vendor prefix (`swag-`).
6. Template with markup outside `{% block %}`: is not merged.
7. `{{ }}`/`{% if %}` from Twig cannot be used in Vue templates, only `v-if`/`v-for`.
8. `Mixin.getByName('x')` throws if `main.js` imports the mixin file after the component.
9. Async override (function config): the block index is only populated on resolution. For template overrides use an object config; it is then indexed synchronously.
10. Hardcoded colors/spacing instead of variables from `~scss/variables` or Meteor tokens.
11. Direct manipulation via `Object.assign` instead of `override`: no `$super`, breaks on updates.
12. Building new UI with `sw-*` instead of `mt-*`.
13. Do not use single-file components and `swDefineOverride()` in 6.7.15.1 (see below).

### Deprecations and version notes (6.5/6.6 → 6.7)

| Topic | 6.5/6.6 | 6.7.15.1 |
|---|---|---|
| Vue | 6.5: Vue 3 with `@vue/compat` (prior knowledge, ⚠ UNVERIFIED) | Vue 3.5.22, no compat alias |
| Bundler | Webpack | Vite (`vite.config.mts`, `build/plugins.vite.ts`) |
| `register/extend/override`, `$super` | yes | yes, stable |
| Twig blocks | yes | yes, plus adapter to `<sw-block>` (`core/factory/twig-block-index.ts`) |
| `<sw-block>`, `overrideComponentSetup` | no | present, `@experimental` (feature `ADMIN_COMPOSITION_API_EXTENSION_SYSTEM`, stable 6.8.0 planned). Do not use for plugins |
| Composables instead of mixins (`use-*.ts`) | no | `@experimental`, `ADMIN_MIXIN_COMPOSABLES`, stable 6.9.0 planned. Mixins remain |
| SFC `*.vue`, `*.override.vue`, `swDefineOverride()` | no | Docs: experimental since 6.7.16.0, in 6.7.15.1 only build parts (`build/vue-setup-transform/*`). Not recommended |
| `sw-*` base components | standard | Meteor `mt-*` gradually replaces them, e.g. `sw-text-field` `@deprecated tag:v6.8.0` |

When `sw-block` is adopted, accessing a migrated block emits a `console.warn` deprecation. Existing Twig overrides continue to work.

## 6. Checklist

- [ ] Target component and block name verified in the source code of the installed version
- [ ] override/extend/register chosen appropriately, vendor prefix for new names
- [ ] Template contains only `{% block %}`, `{% parent %}` where the original should be preserved
- [ ] `this.$super('name')` in normal method syntax
- [ ] Mixins/directives imported in `main.js` before the components
- [ ] New UI with `mt-*`, SCSS with `~scss/variables`
- [ ] No SFC/`swDefineOverride`/`sw-block`/composables in production code
- [ ] `bin/build-administration.sh` run, hard reload tested

## 7. Sources

Docs (https://developer.shopware.com): `/docs/guides/plugins/plugins/administration/module-component-management/customizing-components.html`, `.../using-base-components.html`, `.../templates-styling/writing-templates.html`, `.../templates-styling/add-custom-styles.html`, `.../mixins-directives/add-mixins.html`, `.../single-file-components/tutorial/your-first-override.html`. Shipped: `administration/Resources/app/administration/technical-docs/03-extensibility/*`, `06-ui/01-meteor-component-library.md`.

Source code (under `administration/Resources/app/administration/`): `src/core/factory/async-component.factory.ts` (`register`, `extend`, `override`, `buildSuperRegistry`, `addSuperBehaviour`), `src/core/factory/template.factory.js`, `mixin.factory.ts`, `directive.factory.ts`, `src/app/component/index.ts`, `src/app/component/meteor-wrapper/*`, `src/app/adapter/view/vue.adapter.ts`, `build/plugins.vite.ts`, `vite.config.mts`, `package.json`. PHP: `core/Framework/Plugin/BundleConfigGenerator.php`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: example code not built in a running admin (blocks/methods `sw_product_detail_content_language_info`, `onSave`, `sw_text_field`, `handleUpdateModelValue` present in the source code, runtime behavior not tested).
- ⚠ GAP: order of multiple overrides with the same `index` (assumption: plugin load order, `Array.sort` is stable).
- ⚠ GAP: effect of `compatConfig: { MODE: 2 }` in the plugin Vite build (`build/plugins.vite.ts`) and of `Shopware.compatConfig` (only references, no definition found) not clarified.
- ⚠ GAP: definition of the feature flag `ADMIN_COMPOSITION_API_EXTENSION_SYSTEM` not found in `core/**`.
- ⚠ GAP: docs inconsistent (technical-docs "6.8+", web "stable 6.8.0/SFC 6.9", code already contains parts in 6.7.15.1). The code takes precedence here.
- ⚠ UNVERIFIED: page `add-custom-component.html` not retrieved, content only confirmed via code.
