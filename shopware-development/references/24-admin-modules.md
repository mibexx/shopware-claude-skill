# Admin modules, routes and menu entries (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when the Administration is extended with a custom module (listing/detail, routes, menu entry, settings entry), or when core routes are overridden or tabs are added.

Scope: components, overrides, mixins: [T-25 Admin components](./25-admin-components.md). Repository/data access in the admin: [T-26](./26-admin-data-handling.md). Permissions: [T-23 ACL](./23-acl-permissions-roles.md). Snippets: [T-18](./18-snippets-translations.md). Underlying entity: [T-27](./27-dal-entities.md), [T-04](./04-database-migrations.md). Build: [T-49](./49-build-deployment.md). Scaffold: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Concepts

- An admin module is a call `Shopware.Module.register('<namespace>-<name>', manifest)` in `…/administration/src/module/<id>/index.js`, imported from the plugin's `src/main.js`. Registration in `registerModule()` (`core/factory/module.factory.ts`).
- The module ID **must** contain at least one hyphen (`[namespace]-[name]`), otherwise it aborts with a console warning. Duplicate IDs are rejected.
- `type`: `'plugin' | 'core'`. **Always set `type: 'plugin'` explicitly.** Although `registerModule()` falls back to `'plugin'` for the route types (`module.type || 'plugin'`), the navigation checks `module.type` itself (`navigationEntry.moduleType = module.type`, `module.type === 'plugin' && !navigationEntry.parent`); without an explicit `type`, the `parent` check is skipped and `moduleType` remains `undefined` (`core/factory/module.factory.ts`). Do not use `type: 'core'` in plugins (bypasses the `parent` check and the +1000 position).
- **Routes** (`routes`): name = `<id with dots>.<key>` (`swag-example` + `list` -> `swag.example.list`), path `/swag/example/<path>`. Changeable with `routePrefixName`/`routePrefixPath`. `component` (string) becomes `components: { default: … }` internally; multiple views via `components`. Child routes (`children`): name `<parent>.<key>`, path prefixed by the parent. The `name` in the manifest is only the module name, not part of the route name.
- **Menu** (`navigation: [{ id, label, path, parent, icon, color, position, privilege }]`, module with explicit `type: 'plugin'`): plugin entries **without `parent` are discarded** ("Navigation entries from plugins are not allowed on the first level"); `label` required; `position` +1000 for plugins.
- **Settings** (`settingsItem`, object or array): needs `group`, `to`, `icon` or `iconComponent`; `id`, `name`, `label` are added. `group`: `general`, `localization`, `customer`, `commerce`, `content`, `automation`, `system`, `account`, `plugins`.
- **Overriding a route/adding a tab:** `routeMiddleware(next, currentRoute)` in a separate `Module.register` (without `routes`). Runs for every top-level route in `getModuleRoutes()` (`MiddlewareHelper.go`); modify `currentRoute` in place or add to `currentRoute.children`.
- **Listing/detail:** listing `<sw-page>` + `<sw-entity-listing>` + mixin `listing`; detail `<sw-page>` + `<sw-card-view>` + repository. `detail-route` on the listing, `routerLink` in the column; the detail route carries `meta.parentPath` (back arrow).
- Admin templates are `.html.twig`, not Vue SFCs.

```
SwagExample/
└── src/Resources/app/administration/src/
    ├── main.js                          # only imports modules/overrides
    └── module/swag-example/
        ├── index.js                     # Shopware.Module.register(...)
        ├── page/swag-example-list/{index.js, swag-example-list.html.twig}
        ├── page/swag-example-detail/{index.js, swag-example-detail.html.twig}
        ├── component/ view/ acl/index.js    # optional
        └── snippet/{de-DE,en-GB}.json
```
Model in the core: `module/sw-manufacturer/`. The scaffold generator (`AdminModuleGenerator`) places snippets under `src/Resources/app/administration/src/snippet/{en,de}.json`, not in the module folder.

## 3. Instructions

1. Create the plugin: `bin/console plugin:create SwagExample`, then `bin/console plugin:refresh` and `plugin:install --activate SwagExample` (options for the admin module scaffold not checked).
2. Create files according to the tree. Prerequisite: entity `swag_example` (definition + migration, see T-27/T-04).
3. `main.js` imports the module (entry point `src/Resources/app/administration/src/main.js`).
4. Register components via `Shopware.Component.register` before the module names them in `routes` (import in `index.js`).
5. Build the admin: `bin/build-administration.sh` (there are no Composer scripts for this in the project); hot reload `bin/watch-administration.sh`; afterwards `bin/console assets:install` and `bin/console cache:clear`. **Docs deviation:** the docs name `bundle:dump-js`; installed, only `bundle:dump` exists (`BundleDumpCommand`, writes the plugin configuration for the build).
6. Type checking/lint (⚠ experimental/`@internal`, `@experimental stableVersion:v6.8.0`, see [T-48](./48-static-analysis.md)): once `bin/console administration:setup-extension-tooling`, then `bin/console administration:check-extensions -- --only=SwagExample` (according to `extension-tooling/README.md`).
7. **Override a route:** second `Module.register` with `routeMiddleware`:
   ```js
   Shopware.Module.register('swag-example-route-override', {
       type: 'plugin',
       routeMiddleware(next, currentRoute) {
           if (currentRoute.name === 'sw.product.detail') {
               const idx = currentRoute.children.findIndex((c) => c.name === 'sw.product.detail.base');
               currentRoute.children[idx] = {
                   ...currentRoute.children[idx],
                   meta: { ...currentRoute.children[idx].meta, privilege: 'product.editor' },
               };
           }
           next(currentRoute);
       },
   });
   ```
8. **Add a tab** (product detail): (a) `Shopware.Component.override('sw-product-detail', { template })` with Twig block `sw_product_detail_content_tabs_reviews` (`{% parent %}` plus `<sw-tabs-item :route="{ name: 'sw.product.detail.custom', params: { id: $route.params.id } }">`; blocks `sw_product_detail_content_tabs_*`, among others `…_additional`); (b) route via middleware: `currentRoute.children.push({ name: 'sw.product.detail.custom', path: '/sw/product/detail/:id/custom', component: 'sw-product-detail-custom', meta: { parentPath: 'sw.product.index' } })` – prevent duplicates with `every(...)` beforehand (the middleware runs on every `getModuleRoutes()`); (c) view `Shopware.Component.register('sw-product-detail-custom', {...})`.
9. Valid `parent` values: `sw-catalogue`, `sw-customer`, `sw-order`, `sw-marketing`; more via `grep -n "id: 'sw-" administration/Resources/app/administration/src/module/*/index.js`.

## 4. Examples

`src/Resources/app/administration/src/main.js`
```js
import './module/swag-example';
```

`…/module/swag-example/index.js`
```js
import './page/swag-example-list';
import './page/swag-example-detail';
import deDE from './snippet/de.json';
import enGB from './snippet/en.json';

Shopware.Module.register('swag-example', {
    type: 'plugin',
    name: 'swag-example',
    title: 'swag-example.general.mainMenuItemGeneral',
    description: 'swag-example.general.descriptionTextModule',
    color: '#ff3d58',
    icon: 'regular-shopping-bag',
    entity: 'swag_example',

    snippets: {
        'de-DE': deDE,
        'en-GB': enGB,
    },

    routes: {
        list: {
            component: 'swag-example-list',
            path: 'list',
            meta: { privilege: 'swag_example.viewer' },
        },
        detail: {
            component: 'swag-example-detail',
            path: 'detail/:id',
            meta: { parentPath: 'swag.example.list', privilege: 'swag_example.viewer' },
            props: {
                default(route) {
                    return { exampleId: route.params.id };
                },
            },
        },
        create: {
            component: 'swag-example-detail',
            path: 'create',
            meta: { parentPath: 'swag.example.list', privilege: 'swag_example.creator' },
        },
    },

    navigation: [{
        id: 'swag-example',
        label: 'swag-example.general.mainMenuItemGeneral',
        path: 'swag.example.list',
        parent: 'sw-catalogue',      // required for type 'plugin'
        icon: 'regular-shopping-bag',
        color: '#ff3d58',
        position: 100,
    }],

    settingsItem: {                    // optional: additionally under Settings
        group: 'plugins',
        to: 'swag.example.list',
        icon: 'regular-rocket',
        label: 'swag-example.general.mainMenuItemGeneral',
    },
});
```
`privilege` values such as `swag_example.viewer` only take effect if registered via `acl/index.js` (see [T-23](./23-acl-permissions-roles.md)); otherwise omit them.

`…/page/swag-example-list/index.js`
```js
import template from './swag-example-list.html.twig';

const { Criteria } = Shopware.Data;

Shopware.Component.register('swag-example-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('listing')],

    data() {
        return { items: null, isLoading: true, total: 0 };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('swag_example');
        },
        columns() {
            return [{
                property: 'name',
                label: 'swag-example.list.columnName',
                routerLink: 'swag.example.detail',
                inlineEdit: 'string',
                primary: true,
            }];
        },
        criteria() {
            const criteria = new Criteria(this.page, this.limit);
            criteria.setTerm(this.term);
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            return criteria;
        },
    },

    methods: {
        async getList() {
            this.isLoading = true;
            try {
                this.items = await this.repository.search(this.criteria);
                this.total = this.items.total;
            } finally {
                this.isLoading = false;
            }
        },

        // Event `update-records` delivers the whole EntityCollection; the total count is in `total`
        updateTotal({ total }) {
            this.total = total;
        },
    },
});
```
The mixin `listing` calls `getList()` and provides `page`, `limit`, `term`, `onPageChange`. Pattern: `module/sw-manufacturer/page/sw-manufacturer-list/index.js`.

`…/swag-example-list.html.twig`
```twig
{% block swag_example_list %}
<sw-page class="swag-example-list">
    {% block swag_example_list_smart_bar_header %}
    <template #smart-bar-header>
        <h2>{{ $t('swag-example.general.mainMenuItemGeneral') }} ({{ total }})</h2>
    </template>
    {% endblock %}

    {% block swag_example_list_actions %}
    <template #smart-bar-actions>
        <mt-button variant="primary" @click="$router.push({ name: 'swag.example.create' })">
            {{ $t('swag-example.list.buttonAdd') }}
        </mt-button>
    </template>
    {% endblock %}

    <template #content>
        {% block swag_example_list_grid %}
        <sw-entity-listing
            v-if="items"
            detail-route="swag.example.detail"
            identifier="swag-example-list"
            :is-loading="isLoading"
            :columns="columns"
            :repository="repository"
            :data-source="items"
            :criteria-limit="limit"
            :disable-data-fetching="true"
            @update-records="updateTotal"
            @page-change="onPageChange"
        />
        {% endblock %}
    </template>
</sw-page>
{% endblock %}
```

`…/page/swag-example-detail/index.js`
```js
import template from './swag-example-detail.html.twig';

Shopware.Component.register('swag-example-detail', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Shopware.Mixin.getByName('notification')],

    props: {
        exampleId: { type: String, required: false, default: null },
    },

    data() {
        return { example: null, isLoading: false };
    },

    metaInfo() {
        return { title: this.$createTitle() };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('swag_example');
        },
    },

    watch: {
        // after creation the route switches from create to detail: without the watch, `example` would remain
        // a new entity and the next save would create a duplicate via POST
        exampleId() {
            void this.loadEntity();
        },
    },

    created() {
        void this.loadEntity();
    },

    methods: {
        async loadEntity() {
            this.isLoading = true;
            try {
                this.example = this.exampleId
                    ? await this.repository.get(this.exampleId)
                    : this.repository.create();
            } finally {
                this.isLoading = false;
            }
        },

        async onSave() {
            this.isLoading = true;
            try {
                await this.repository.save(this.example);
                if (!this.exampleId) {
                    void this.$router.push({ name: 'swag.example.detail', params: { id: this.example.id } });
                    return;
                }
                await this.loadEntity();
            } catch (error) {
                this.createNotificationError({ message: this.$t('global.notification.unspecifiedSaveErrorMessage') });
            } finally {
                this.isLoading = false;
            }
        },
    },
});
```
With `create`, `exampleId` is `null`. `meta.parentPath` is evaluated in `sw-page` (`initPage`) for the back arrow.

`…/swag-example-detail.html.twig`
```twig
{% block swag_example_detail %}
<sw-page class="swag-example-detail">
    <template #smart-bar-header>
        <h2>{{ example?.name }}</h2>
    </template>

    <template #smart-bar-actions>
        <mt-button variant="primary" :is-loading="isLoading" @click="onSave">
            {{ $t('global.default.save') }}
        </mt-button>
    </template>

    <template #content>
        <sw-card-view>
            <mt-card
                position-identifier="swag-example-detail-base"
                :title="$t('swag-example.detail.cardTitle')"
                :is-loading="isLoading"
            >
                <mt-text-field v-if="example" v-model="example.name" :label="$t('swag-example.list.columnName')" />
            </mt-card>
        </sw-card-view>
    </template>
</sw-page>
{% endblock %}
```

`snippet/en.json` (`de.json` likewise)
```json
{
    "swag-example": {
        "general": {
            "mainMenuItemGeneral": "Examples",
            "descriptionTextModule": "Manage examples"
        },
        "list": { "columnName": "Name", "buttonAdd": "Add example" },
        "detail": { "cardTitle": "General" }
    }
}
```

## 5. Pitfalls

- Module ID without a hyphen: registration aborts with a console warning; always `<vendor>-<name>`.
- Navigation without `parent` with `type: 'plugin'`: entry discarded. **The scaffold stub `js-module.stub` and the docs `add-custom-module` show exactly this example without `parent`; there, according to the code, the menu item does not appear in 6.7.15.1 – code takes precedence.**
- `label` missing in `navigation`: entry discarded.
- The route name is generated from the ID, not from `name:`; `navigation.path`/`settingsItem.to` must match the generated name.
- Component not registered via `Component.register`: no page (`routes.component` is just a string).
- A second `Module.register` with the same ID is rejected.
- Middleware pushes the child again on every run: check for duplicates with `every`/`find`.
- `parentPath` forgotten on the detail route: no back arrow.
- Not rebuilt/cache: changes only take effect after the build and `assets:install`.
- Snippets not registered: via `snippets` in the manifest (details T-18).
- Anti-pattern: replacing core components entirely via `Component.override` instead of extending Twig blocks with `{% parent %}`; copying core modules instead of `routeMiddleware`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Admin is Vue 3 with Vite (`vite.config.mts`), Pinia instead of Vuex (`Shopware.Store`).
- Icons with prefix (`regular-products`, `regular-factory`); the docs partly still use `default-shopping-paper-bag-product` (resolution of old names not checked).
- Core listing uses `mt-button`, `mt-empty-state` (Meteor) instead of `sw-button`; the docs partly still `sw-button`/`sw-card`.
- `sw-entity-listing`: prop `items` `@deprecated tag:v6.8.0` -> `dataSource`.
- Behind the flag `v6.8.0.0`, the product detail has a new tab bar (`<mt-tabs :items="productDetailTabs">`); **tab overrides via `sw_product_detail_content_tabs_*` break under 6.8**; extend `productDetailTabs` there.
- `ModuleManifest` additionally knows `extensionEntryRoute`, `defaultSearchConfiguration`, `flag`, `routeMiddleware`, `display`; `module.factory.ts` is TypeScript.
- Core snippet files are named `de.json`/`en.json` (language-neutral). Older docs examples use `de-DE.json`/`en-GB.json`; for these, `bin/console translation:lint-filenames` reports an error as long as no language-neutral base file exists (fix: `--fix`, see [T-18](./18-snippets-translations.md)).

## 6. Checklist

- [ ] Module ID `<vendor>-<name>` with hyphen, unique.
- [ ] `main.js` imports the module; all components are registered.
- [ ] `navigation` with `parent` and `label`; `path` = generated route name.
- [ ] Detail/create route with `meta.parentPath`.
- [ ] `privilege` entries only set if ACL is registered (T-23).
- [ ] Snippets de + en registered in the manifest.
- [ ] Listing: `detail-route`, `dataSource` (not `items`), `routerLink` on the primary column.
- [ ] Middleware routes checked for duplicates.
- [ ] Admin built, `assets:install` and `cache:clear` run.

## 7. Sources

- https://developer.shopware.com/docs/guides/plugins/plugins/administration/module-component-management/add-custom-module.html
- …/administration/routing-navigation/add-custom-route.html, `add-menu-entry.html`, `overriding-routes.html`, `add-new-tab.html`
- Admin (`administration/Resources/app/administration/`): `src/core/factory/module.factory.ts` (`registerModule`, `getModuleRoutes`, `addSettingsItemsToStore`; types `ModuleManifest`, `Navigation`, `SettingsItem`), `src/core/helper/middleware.helper.js`, `src/module/sw-manufacturer/`, `sw-settings-tax/index.js`, `sw-product/` (tab blocks), `src/app/component/structure/sw-page/`, `entity/sw-entity-listing/`, `AGENTS.md`, `src/module/AGENTS.md`, `technical-docs/02-architecture/03-module-system.md`, `extension-tooling/README.md`.
- Core: `core/Framework/Plugin/Command/BundleDumpCommand.php`, `Scaffolding/Generator/AdminModuleGenerator.php`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: entity `swag_example` is assumed; example not built/run in a running instance.
- ⚠ UNVERIFIED: `mt-card`, `mt-text-field` derived from the Meteor docs; only `mt-button`, `mt-empty-state` in the core listing are verified.
- ⚠ UNVERIFIED: whether the plugin build additionally loads `src/snippet/*.json` automatically is not verified (T-18). Whether `main.ts` is loaded in addition to `main.js`: not documented.
- ⚠ GAP: docs deviation `bundle:dump-js` (does not exist) vs. `bundle:dump`; options of `plugin:create` for the admin module not checked.
- ⚠ UNVERIFIED: availability of `administration:check-extensions`/`setup-extension-tooling` not tested; old icon names (`default-*`) not checked; 6.5/6.6 differences derived from the state of the code.
