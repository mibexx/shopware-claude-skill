# Admin data handling, services and state (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when the Administration (JS) needs to read/write data (repository, Criteria), register its own services/ApiServices, manage state (Pinia), show feedback (notifications, snackbar) or handle errors.

Scope: overriding components/styles → [T-25](./25-admin-components.md). Module/routes → [T-24](./24-admin-modules.md). Backend routes for custom requests → [T-31 Admin API routes](./31-admin-api-routes.md). Entities in the backend → [T-27](./27-dal-entities.md). Build → [T-49](./49-build-deployment.md). Scaffold: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Concepts

- **Repository**: service `repositoryFactory` via `inject`, `repositoryFactory.create('<entity_name>')`. Methods: `search(criteria, context)`, `searchIds`, `get(id, context, criteria)`, `create(context)`, `save(entity, context)`, `saveAll`, `sync`, `delete(id, context)`, `clone`, `hasChanges`, `discard`, `assign`, `createVersion/mergeVersion/deleteVersion` (`core/data/repository.data.ts`). Context defaults to `Shopware.Context.api`.
- **Criteria**: `Shopware.Data.Criteria`, re-exported from `@shopware-ag/meteor-admin-sdk/es/data/Criteria` (`core/data/criteria.data.ts`).
- **Entities** are reactive objects with change tracking (origin/draft). `save()` only sends changes (`changeset-generator.data.js`), new → POST, existing → PATCH. After `save()` you MUST reload; the local entity is not updated.
- **Services**: Bottle.js container. Register with `Shopware.Service().register(name, factory)` or `Shopware.Application.addServiceProvider`, lazily on first access. Inject with `inject: [...]` or `Shopware.Service('name')`. Decorate with `registerDecorator`, middleware with `registerMiddleware`.
- **ApiService**: base class `Shopware.Classes.ApiService` (`core/service/api.service.ts`) for custom backend requests.
- **State = Pinia**: `Shopware.Store.register/get/unregister`. Vuex (`Shopware.State`) is `@deprecated tag:v6.8.0`.
- **Feedback**: notification mixin or `snackbarService`. **Errors**: store `error`, `mapPropertyErrors`.

Directory tree (plugin-relative; `api/`, `init/`, `store/` are convention, what matters is the import in `main.js`):

```
src/Resources/app/administration/src/
├── main.js
├── api/swag-demo.api.service.js
├── init/swag-demo-store.init.js
├── store/swag-demo.store.js
└── module/swag-demo/page/swag-demo-list/{index.js, swag-demo-list.html.twig}
```

## 3. Instructions

### 3.1 Repository and Criteria

1. `inject: ['repositoryFactory']`, then `this.repositoryFactory.create('product')`. Entity name = snake-case singular (`product_manufacturer`). `create()` throws if the entity is not known in `Shopware.EntityDefinition` (the schema comes from the backend). Route = `/${entityName.replace(/_/g,'-')}`.
2. Criteria: `new Criteria(page, limit)`, filters `Criteria.equals/contains/equalsAny/range/multi/not`, `addSorting(Criteria.sort(field, 'ASC'))`, `addAssociation('a.b')`, `setTerm`, `setIds`, `setTotalCountMode(1)`, `addAggregation`.
3. `search` returns an `EntityCollection` (array subclass with `.get(id)`, `.total`, `.aggregations`), `searchIds` returns `{ total, data }`. Read methods optionally accept `RepositoryCacheOptions { cacheKey, ttl?, forceReload? }`.
4. Create: `const e = repo.create(Shopware.Context.api); e.name = '..'; await repo.save(e);` (assigns a UUID). Delete: `repo.delete(id)`.
5. Multiple entities: `saveAll`/`sync` (Sync API, one request). The repository option `useSync: true` (3rd parameter of `create`) switches `save()` to the Sync API; `keepApiErrors: true` prevents the errors in the store from being reset.
6. Associations: many-to-one via property, to-many as `EntityCollection` (`.add(entity)`/`.remove(id)`). Derive a many-to-many/to-many repository from the association: `repositoryFactory.create(entity.categories.entity, entity.categories.source)` (`source` is the route `/product/<id>/categories`; pattern in core: `sw-category-tree-field`, `sw-settings-rule-detail`, `sw-rule-modal`).

### 3.2 Service

1. Register in `main.js`: `Shopware.Service().register('swagGreeter', (container) => new SwagGreeter(container.repositoryFactory))`.
2. Inject with `inject: ['swagGreeter']` or `inject: { greeter: 'swagGreeter' }`; outside of components `Shopware.Service('swagGreeter')`.
3. Decorate BEFORE the first access: `Shopware.Service().registerDecorator('systemConfigApiService', (service) => { /* extend */ return service; })`. The decorator is a Bottle decorator: its only parameter is the service, the return value replaces it, there is no `next` (the `next()` comment on `addServiceProviderDecorator` in `core/application.ts` is misleading; core model `app/decorator/state-styling-provider.decorator.ts` ends with `return stateStyleService;`; `registerDecorator` forwards to `Application.addServiceProviderDecorator`, `core/factory/service.factory.ts`).
4. TypeScript: `declare global { interface ServiceContainer { swagGreeter: SwagGreeter } }` (`global.types.ts` line 246 ff.).

### 3.3 Custom API requests

1. Create a backend route with `_routeScope: ['api']` and `_acl` → [T-31](./31-admin-api-routes.md). `httpClient.baseURL` already contains `/api`, no double `/api/...`.
2. `ApiService` subclass. Helpers: `getApiBasePath(id?, prefix?)`, `getBasicHeaders(extra)` (Bearer, Accept, Content-Type, `sw-language-id`), `ApiService.handleResponse(response)`, `ApiService.makeQueryParams`, `ApiService.getVersionHeader`.
3. Register via `Shopware.Application.addServiceProvider('<name>ApiService', ...)`. Automatic registration via glob (`app/init-pre/api-services.init.ts`) only applies to core ApiServices.
4. Alternative without a class: `const httpClient = Shopware.Application.getContainer('init').httpClient;` (`httpClient` is an initializer in the init container, not a service: `inject: ['httpClient']` returns `undefined`; core model `worker-notification-listener.js`, `consent.init.ts`) plus header `Authorization: Bearer ${Shopware.Service('loginService').getToken()}`.
5. Transport: in 6.7, direct requests go through legacy Axios; `useAxiosV1: true` in the request config is an opt-in to Axios v1 (`core/factory/http.factory.js`). According to the migration guide, repository requests already use v1.
6. Errors: the promise is rejected (`error.response.data.errors[]`); `globalErrorHandlingInterceptor` shows standard notifications.

### 3.4 State with Pinia

1. `Shopware.Store.register({ id, state: () => ({...}), getters, actions })` (options syntax; an overload `register(id, setupFn)` exists).
2. Access with `Shopware.Store.get('id')` (throws `Store with id "..." not found`). Wrap it in a computed property in components.
3. No mutations: changes in actions via `this.<state>`. Vuex `State.commit('x/set', v)` corresponds to `Store.get('x').set(v)`.
4. `Shopware.Store.unregister('id')` for component-bound stores (e.g. `beforeUnmount`).
5. TypeScript: add the store ID to `PiniaRootState` (`global.types.ts` line 408 ff.).
6. Read core stores (`session`, `context`, `notification`, `error`, `modals`, `adminMenu`, `extensions`, ...), do not rewrite them (⚠ UNVERIFIED: assumption, core-internal/`@private`).

### 3.5 Notifications and snackbar

1. Mixin `Mixin.getByName('notification')`: `createNotificationSuccess|Info|Warning|Error(config)` and `createSystemNotification*` (`system: true`, persistent). Title default `global.default.success|info|warning|error`.
2. `message` is mandatory: the notification store discards notifications without `message` (debug warning only, `notification.store.ts`). According to the code, the docs example with only `{ title }` has no effect.
3. `success` is shown as a toast but not stored persistently.
4. Actions: `actions: [{ label, route: { name: 'sw.product.index' } }]` or `{ label, method: () => ... }`.
5. Snackbar: `Shopware.Service('snackbarService').addSnackbar({ message, variant: 'success' })`, `removeSnackbar(id)` (`app/service/snackbar.service.ts`, `@public`; according to the docs since 6.7.14.0).
6. `useNotification()` is `@experimental stableVersion:v6.9.0 feature:ADMIN_MIXIN_COMPOSABLES`, `useSnackbar()` is `@experimental stableVersion:v6.8.0 feature:ADMIN_COMPOSITION_API_EXTENSION_SYSTEM` (`app/composables/use-notification.ts`, `use-snackbar.ts`) → not for plugins.
7. `Shopware.Store.get('notification').registerTransformer(key, fn)` exists in the store (`notification.store.ts` line 321).

### 3.6 Error handling

1. On HTTP 400 (`errors[].source.pointer`), `save()`/`sync()` store `ShopwareError` in the `error` store via the `ErrorResolver`; `delete()` via `handleDeleteError`.
2. Field errors: `const { mapPropertyErrors } = Shopware.Component.getComponentHelper();` then `computed: { ...mapPropertyErrors('product', ['name']) }` and in the template `:error="productNameError"`. Others: `mapCollectionPropertyErrors`, `mapPageErrors(errorConfig)`.
3. Directly: `Shopware.Store.get('error').getApiError(entity, 'name')`, `getApiErrorFromPath`, `addApiError({ expression, error })`, `resetApiErrors()`. `new Shopware.Classes.ShopwareError({ code, detail })` (`code` mandatory).
4. Always wrap `await repo.save/search/delete` in `try/catch`.

### 3.7 CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh
bin/watch-administration.sh       # Watcher (development)
bin/console cache:clear
```

## 4. Examples

`src/Resources/app/administration/src/main.js`

```js
import './api/swag-demo.api.service';
import './init/swag-demo-store.init';
import './module/swag-demo';
```

`src/Resources/app/administration/src/api/swag-demo.api.service.js`

```js
const ApiService = Shopware.Classes.ApiService;

export default class SwagDemoApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'swag-demo') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'swagDemoApiService';
    }

    greet(name) {
        const apiRoute = `/_action/${this.getApiBasePath()}/greet`;

        return this.httpClient
            .post(apiRoute, { name }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

Shopware.Application.addServiceProvider('swagDemoApiService', (container) => {
    const initContainer = Shopware.Application.getContainer('init');

    return new SwagDemoApiService(initContainer.httpClient, container.loginService);
});
```

The plugin must provide the route `/api/_action/swag-demo/greet` itself → [T-31](./31-admin-api-routes.md).

`src/Resources/app/administration/src/store/swag-demo.store.js`

```js
export default Shopware.Store.register({
    id: 'swagDemo',

    state: () => ({
        selectedId: null,
        isLoading: false,
    }),

    getters: {
        hasSelection() {
            return this.selectedId !== null;
        },
    },

    actions: {
        select(id) {
            this.selectedId = id;
        },
        setLoading(value) {
            this.isLoading = value;
        },
    },
});
```

`src/Resources/app/administration/src/init/swag-demo-store.init.js`

```js
import '../store/swag-demo.store';
```

(Registration happens as a side effect of the import.)

`src/Resources/app/administration/src/module/swag-demo/page/swag-demo-list/index.js`

```js
import template from './swag-demo-list.html.twig';

const { Criteria } = Shopware.Data;
const { Mixin } = Shopware;

Shopware.Component.register('swag-demo-list', {
    template,

    inject: ['repositoryFactory', 'swagDemoApiService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            products: null,
            isLoading: false,
        };
    },

    computed: {
        productRepository() {
            return this.repositoryFactory.create('product');
        },

        productCriteria() {
            const criteria = new Criteria(1, 25);
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));
            criteria.addAssociation('manufacturer');

            return criteria;
        },

        swagDemoStore() {
            return Shopware.Store.get('swagDemo');
        },
    },

    created() {
        this.loadProducts();
    },

    methods: {
        async loadProducts() {
            this.isLoading = true;

            try {
                this.products = await this.productRepository.search(this.productCriteria);
            } catch (error) {
                this.createNotificationError({
                    message: this.$t('global.notification.unspecifiedSaveErrorMessage'),
                });
            } finally {
                this.isLoading = false;
            }
        },

        async onRename(product) {
            product.name = `${product.name} *`;

            try {
                await this.productRepository.save(product);
                this.createNotificationSuccess({ message: this.$t('global.notification.notificationSaveSuccessMessage', { entityName: 'product' }) });
                await this.loadProducts(); // local entity is not updated automatically
            } catch (error) {
                this.createNotificationError({ message: error.message });
            }
        },

        async onDelete(id) {
            await this.productRepository.delete(id);
            await this.loadProducts();
        },

        onSelect(id) {
            this.swagDemoStore.select(id);
        },
    },
});
```

`src/Resources/app/administration/src/module/swag-demo/page/swag-demo-list/swag-demo-list.html.twig`

```twig
{% block swag_demo_list %}
    <sw-page class="swag-demo-list">
        <template #content>
            <sw-card-view>
                <mt-card position-identifier="swag-demo-list-products" title="Products" :is-loading="isLoading">
                    <ul v-if="products">
                        <li v-for="product in products" :key="product.id" @click="onSelect(product.id)">
                            {{ product.translated.name }}
                        </li>
                    </ul>
                </mt-card>
            </sw-card-view>
        </template>
    </sw-page>
{% endblock %}
```

For module registration (route, navigation) see [T-24](./24-admin-modules.md). ⚠ UNVERIFIED: `mt-card`/`sw-page`/`sw-card-view` in the template not individually verified against the code (according to T-25, `mt-card` requires `positionIdentifier`).

## 5. Pitfalls

- Notification without `message`: nothing visible.
- Entity not reloaded after `save()`: stale values in the UI.
- Wrong entity name (`products`): `EntityDefinition.get` throws; use snake-case singular.
- Association not loaded via `addAssociation`: property empty, changes are not saved.
- Decorating a service after the first access: decorator/middleware does not take effect.
- Duplicate service/ApiService name: warning `already registered`, the second registration is ignored.
- Pinia store accessed before registration: mind the import order in `main.js`.
- Importing `core/factory/...` directly instead of `Shopware.*`: bypasses the plugin/decorator system.
- Criteria limits above 100: an anti-pattern according to `core/AGENTS.md`.
- Direct PATCH requests instead of the repository: change tracking and error resolution do not apply.
- Custom backend route without `_routeScope: ['api']`/ACL: 403/404 or a security hole.
- Double `/api` in `httpClient` paths (`/api/api/...`).

### Deprecations and version notes (6.5/6.6 → 6.7)

- Vuex → Pinia: `Shopware.State` is `@deprecated tag:v6.8.0` (`core/shopware.ts`), as are `state.init.ts`/`state.factory.ts`; `package.json`: "Vuex is deprecated and will be removed with Shopware 6.8.0". `app/state/index.js` is empty (core no longer registers Vuex modules). `Shopware.State.registerModule` still works in 6.7, breaks with 6.8. New plugins: Pinia.
- Notification variants: mixin `success|info|warning|error`, store type `neutral|info|attention|critical|positive` (ongoing Meteor migration, assumption).
- `snackbarService` is new (docs: since 6.7.14.0). Composables are `@experimental`, mixins remain the standard in 6.7.
- Axios: dual client (legacy + v1), v1 becomes the default with the flag `V6_8_0_0` active; request option `useAxiosV1`.
- Repository options `useSync`, `keepApiErrors`, `RepositoryCacheOptions` present in the 6.7 code; from which exact minor version: not documented.
- Hook `beforeUnmount` instead of `beforeDestroy` (Vue 3).

## 6. Checklist

- [ ] `repositoryFactory.create()` with the correct snake-case entity name
- [ ] Associations loaded via `addAssociation`, moderate Criteria limit
- [ ] Reloaded after `save()`, `try/catch` with a notification on error
- [ ] Notifications contain `message`
- [ ] New state with Pinia (`Shopware.Store`), no Vuex
- [ ] Service/ApiService registered with a unique name, imported in `main.js`
- [ ] Decorator registered before the first service access
- [ ] Backend route with `_routeScope`/`_acl` exists (T-31)
- [ ] Field errors bound via `mapPropertyErrors`
- [ ] Admin JS rebuilt, cache cleared

## 7. Sources

Docs (https://developer.shopware.com/docs/guides/plugins/plugins/administration/): `data-handling-processing/using-data-handling.html`, `data-handling-processing/using-vuex-state.html` (shows only Vuex), `services-utilities/making-api-requests.html`, `services-utilities/add-custom-service.html`, `services-utilities/injecting-services.html`, `services-utilities/displaying-user-feedback.html`, `permissions-error-handling/add-error-handling.html`. Bundled: `administration/Resources/app/administration/technical-docs/` (`02-architecture/04-state-management.md`, `04-data-layer/*`, `07-notifications.md`, `09-security/axios-migration-guide.md`).

Source code (under `administration/Resources/app/administration/src/`): `core/data/repository-factory.data.ts`, `repository.data.ts`, `criteria.data.ts`, `entity.data.ts`, `changeset-generator.data.js`, `error-resolver.data.js`; `core/shopware.ts`; `core/application.ts` (`addServiceProvider*`); `core/factory/service.factory.ts`, `http.factory.js`; `core/service/api.service.ts`; `app/init-pre/api-services.init.ts`; `app/store/index.ts`, `notification.store.ts`, `error.store.ts`; `app/mixin/notification.mixin.ts`; `app/service/snackbar.service.ts`, `map-errors.service.ts`; `global.types.ts`.

### Gaps and uncertainties

- ⚠ GAP: docs contradiction Vuex/Pinia. `using-vuex-state.html` only describes Vuex; the code declares Vuex deprecated and the core uses Pinia. No docs page for Pinia was found.
- ⚠ GAP: according to the code, the docs example of a notification without `message` has no effect (code read only, not tested in the browser).
- ⚠ GAP: local technical-docs are inaccurate (`cacheService` instead of `cacheApiService`, deviating `register`/`registerMiddleware` signatures). The code prevails.
- ⚠ UNVERIFIED: the type declaration `declare global` in the plugin is standard TypeScript, not documented in the Shopware docs.
- ⚠ UNVERIFIED: examples not built/run in a live installation. The backend route `swag-demo/greet` is deliberately missing (T-31).
- ⚠ UNVERIFIED: Vue version/compat statements not individually verified against `package.json` for this reference (see T-25: Vue 3.5.22).
