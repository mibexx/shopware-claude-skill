# Template: Admin module (swag-example)

Purpose: Administration module for a plugin with module registration (routes, menu entry, `settingsItem`), listing and detail page based on the entity `swag_example` (via `repositoryFactory`), snippets de-DE/en-GB and ACL privileges. Shopware 6.7.15.1 (Vue 3, Vite, Meteor components `mt-*`).

## Prerequisite: backend entity

The module expects a DAL entity `swag_example` (table `swag_example`) with the fields `id`, `technicalName` (string, `Required`), `name` (translatable, `TranslatedField`) and `active` (bool), as in the entity template (`ExampleDefinition`). The detail form maintains `technicalName` and `name`; if `technicalName` is missing, creation fails with "/0/technicalName: This value should not be blank". The entity is not part of this scaffold, see [entity](../entity/README.md) and [migration](../migration/README.md). Without the entity, `repositoryFactory.create('swag_example')` throws an error because the schema is unknown.

## File tree

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    └── Resources/app/administration/src/
        ├── main.js                                     # entry point, imports the module
        └── module/swag-example/
            ├── index.js                                # Component.register + Module.register
            ├── acl/index.js                            # privileges.addPrivilegeMappingEntry
            ├── page/swag-example-list/{index.js,swag-example-list.html.twig}
            ├── page/swag-example-detail/{index.js,swag-example-detail.html.twig}
            └── snippet/{de,en}.json              # module and privilege labels
```

## Placeholders

| Placeholder | Replace with | Where |
|---|---|---|
| `swag-example` (module ID) | `<vendor>-<name>`, at least one hyphen | `Module.register`, folder, snippet prefix |
| `swag.example.index/detail/create` | Route names: module ID with dots + key from `routes` | `navigation.path`, `settingsItem.to`, `detail-route`, `$router.push` |
| `swag_example` | Entity name (snake case) and ACL key | `repositoryFactory.create(...)`, `acl/index.js`, `meta.privilege`, `acl.can('swag_example.*')` |
| `swag-example-list` / `-detail` | Component names | `Component.register`, routes |
| `sw-catalogue` | Menu parent ID (core e.g. `sw-catalogue`, `sw-customer`, `sw-order`, `sw-marketing`) | `navigation.parent` |
| `swag-example.*` | Snippet keys | `index.js`, templates, snippet files |
| `SwagExample` / `swag/example` | Plugin name | Class, namespace, `composer.json` |

## Integration / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh          # rebuild the admin (development: bin/watch-administration.sh)
bin/console assets:install
bin/console cache:clear
```

Test a role: Settings -> Users & permissions -> Roles. The group "Examples" appears under Catalogues (`parent: 'catalogues'`). After changing privilege mappings, re-save existing roles.

## Rules (verified against the source code)

- Module ID without a hyphen or duplicate ID: registration aborts with a console warning (`module.factory.ts`).
- Route name = module ID with dots + key from `routes` (not the `name` field of the manifest). Path = `/swag/example/<path>`.
- `navigation` with `type: 'plugin'` needs `parent` and `label`, otherwise the entry is discarded. `position` is increased by 1000 for plugins.
- `settingsItem`: `group` (`general|localization|customer|commerce|content|automation|system|account|plugins`), `to` and `icon` are required. Keep only one of the two menu paths if no duplicate entry is wanted.
- `meta.parentPath` on detail/create routes provides the back arrow in `sw-page`.
- Register components via `Shopware.Component.register(name, () => import(...))` (core standard); the page `index.js` exports the object via `export default`.
- Listing: the `listing` mixin calls `getList()` and provides `page`, `limit`, `term`, `onPageChange`, `onSortColumn`. `sw-entity-listing` with `:data-source` (prop `items` is deprecated) and `:disable-data-fetching="true"`.
- `mt-card` requires `position-identifier`. Meteor components `mt-button`, `mt-text-field`, `mt-switch`, `mt-empty-state` are registered globally, `mt-card` as a Shopware wrapper.
- `repository.save()` does not update the local entity, so reload after saving. Notifications always need a `message`.
- Privileges: `addPrivilegeMappingEntry` requires `category`, `parent` (also `null`) and `key`, otherwise the entry is discarded. No dots in `key`/role names. Roles `viewer|editor|creator|deleter` with `dependencies`. Include other roles with `Shopware.Service('privileges').getPrivileges('media.viewer')`. Label key: `sw-privileges.permissions.<key>.label`.
- UI ACL (`meta.privilege`, `acl.can`) is not security. Secure the backend with `PlatformRequest::ATTRIBUTE_ACL` (`_acl`) on routes. For entities, the DAL ACL applies automatically via `<entity>:<read|create|update|delete>`. Plugin PHP can attach backend privileges to existing admin privileges with `Plugin::enrichPrivileges()`; this only takes effect when the role is loaded via the DAL (UI), the backend check reads via SQL: re-save the role or write the privileges via a migration (T-23).
- Snippets: the `SnippetFinder` finds `de.json`/`en.json` files under `Resources/app/administration/src/**` at runtime. The core does not import them in the manifest, and neither does the scaffold.

## Deprecations / 6.8

- `sw-entity-listing` prop `items` deprecated, `dataSource` used here.
- Vuex (`Shopware.State`) is deprecated; use `Shopware.Store` (Pinia) for state (see reference 26).
- Tab extensions on the product detail page via `sw_product_detail_content_tabs_*` break with 6.8 (`mt-tabs`/`productDetailTabs`).
- Composables (`useNotification` among others) are `@experimental` in 6.7.15.1, hence mixins.

## Gaps and notes

- ⚠ UNVERIFIED: Scaffold not built and not tested against a running installation (no PHP, no admin build). JS via `node --check`, JSON via `JSON.parse`. Patterns, components, props and privileges service cross-checked against the core modules `sw-manufacturer`, `sw-settings-tax`, `privileges.service.ts`, `module.factory.ts`.
- ⚠ UNVERIFIED: Icon names (`regular-shopping-bag`, `regular-rocket`): the prefix scheme `regular-*` comes from core modules; individual names not checked against the icon list.
- ⚠ UNVERIFIED: The example pages omit the search bar (`sw-search-bar`) and language switcher. These would require `entity`/`defaultSearchConfiguration` in the manifest (model: `sw-manufacturer`).
- ⚠ GAP: Whether the plugin build loads `main.ts` in addition to `main.js` is not established. The docs and scaffold name `main.js`.
- ⚠ GAP: The docs page "add-custom-module" names `bundle:dump-js`. In 6.7.15.1 only `bundle:dump` (`BundleDumpCommand`) exists. The scaffold stub (`js-module.stub`) and the docs also show a module without `navigation.parent`, which as a plugin does not appear in the menu. The scaffold sets `parent`.
- ⚠ GAP: Custom admin API routes/ApiService (`/api/_action/...`) are not included, see reference 26 and the controller/routing topics.
- Overriding a route (`routeMiddleware`) and adding tabs are described in reference 24 and are not part of the scaffold.

## References

- [24 Admin modules](../../references/24-admin-modules.md)
- [25 Admin components](../../references/25-admin-components.md)
- [26 Admin data handling](../../references/26-admin-data-handling.md)
- [23 ACL, permissions & roles](../../references/23-acl-permissions-roles.md)
- [18 Snippets & translations](../../references/18-snippets-translations.md)
- [27 DAL entities](../../references/27-dal-entities.md)
- [28 DAL reading/writing](../../references/28-dal-reading-writing.md)
- [49 Build & deployment](../../references/49-build-deployment.md)
- More templates: [entity](../entity/README.md), [migration](../migration/README.md), [plugin](../plugin/README.md), [cms-element](../cms-element/README.md)

- Snippet files are named language-neutrally (`de.json`/`en.json` or `storefront.de.json`/`storefront.en.json`); `bin/console translation:lint-filenames` flags regional names (`de-DE.json`) without a language-neutral base file, fix with `--fix` (see [T-18](../../references/18-snippets-translations.md)).
