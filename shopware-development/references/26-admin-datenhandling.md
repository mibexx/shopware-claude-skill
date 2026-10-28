# Admin-Datenhandling, Services und State (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn die Administration (JS) Daten lesen/schreiben (Repository, Criteria), eigene Services/ApiServices registrieren, State verwalten (Pinia), Rückmeldungen (Notifications, Snackbar) anzeigen oder Fehler behandeln soll.

Abgrenzung: Komponenten überschreiben/Styles → [T-25](./25-admin-komponenten.md). Modul/Routen → [T-24](./24-admin-module.md). Backend-Routen für eigene Requests → [T-31 Admin-API-Routen](./31-admin-api-routen.md). Entities im Backend → [T-27](./27-dal-entities.md). Build → [T-49](./49-build-deployment.md). Gerüst: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Konzepte

- **Repository**: Service `repositoryFactory` per `inject`, `repositoryFactory.create('<entity_name>')`. Methoden: `search(criteria, context)`, `searchIds`, `get(id, context, criteria)`, `create(context)`, `save(entity, context)`, `saveAll`, `sync`, `delete(id, context)`, `clone`, `hasChanges`, `discard`, `assign`, `createVersion/mergeVersion/deleteVersion` (`core/data/repository.data.ts`). Kontext default `Shopware.Context.api`.
- **Criteria**: `Shopware.Data.Criteria`, re-exportiert aus `@shopware-ag/meteor-admin-sdk/es/data/Criteria` (`core/data/criteria.data.ts`).
- **Entities** sind reaktive Objekte mit Change-Tracking (Origin/Draft). `save()` sendet nur Änderungen (`changeset-generator.data.js`), neu → POST, bestehend → PATCH. Nach `save()` MUSS neu geladen werden, die lokale Entity wird nicht aktualisiert.
- **Services**: Bottle.js-Container. Registrieren `Shopware.Service().register(name, factory)` bzw. `Shopware.Application.addServiceProvider`, lazy beim ersten Zugriff. Injizieren `inject: [...]` oder `Shopware.Service('name')`. Dekorieren `registerDecorator`, Middleware `registerMiddleware`.
- **ApiService**: Basisklasse `Shopware.Classes.ApiService` (`core/service/api.service.ts`) für eigene Backend-Requests.
- **State = Pinia**: `Shopware.Store.register/get/unregister`. Vuex (`Shopware.State`) ist `@deprecated tag:v6.8.0`.
- **Feedback**: Notification-Mixin oder `snackbarService`. **Fehler**: Store `error`, `mapPropertyErrors`.

Verzeichnisbaum (Plugin-relativ; `api/`, `init/`, `store/` sind Konvention, entscheidend ist der Import in `main.js`):

```
src/Resources/app/administration/src/
├── main.js
├── api/swag-demo.api.service.js
├── init/swag-demo-store.init.js
├── store/swag-demo.store.js
└── module/swag-demo/page/swag-demo-list/{index.js, swag-demo-list.html.twig}
```

## 3. Anleitung

### 3.1 Repository und Criteria

1. `inject: ['repositoryFactory']`, dann `this.repositoryFactory.create('product')`. Entity-Name = Snake-Case-Singular (`product_manufacturer`). `create()` wirft, wenn die Entity nicht in `Shopware.EntityDefinition` bekannt ist (Schema kommt vom Backend). Route = `/${entityName.replace(/_/g,'-')}`.
2. Criteria: `new Criteria(page, limit)`, Filter `Criteria.equals/contains/equalsAny/range/multi/not`, `addSorting(Criteria.sort(feld, 'ASC'))`, `addAssociation('a.b')`, `setTerm`, `setIds`, `setTotalCountMode(1)`, `addAggregation`.
3. `search` liefert `EntityCollection` (Array-Subklasse mit `.get(id)`, `.total`, `.aggregations`), `searchIds` liefert `{ total, data }`. Lese-Methoden akzeptieren optional `RepositoryCacheOptions { cacheKey, ttl?, forceReload? }`.
4. Anlegen: `const e = repo.create(Shopware.Context.api); e.name = '..'; await repo.save(e);` (vergibt UUID). Löschen: `repo.delete(id)`.
5. Mehrere Entities: `saveAll`/`sync` (Sync-API, ein Request). Repository-Option `useSync: true` (3. Parameter von `create`) schaltet `save()` auf die Sync-API; `keepApiErrors: true` verhindert das Zurücksetzen der Fehler im Store.
6. Assoziationen: Many-to-One per Property, To-Many als `EntityCollection` (`.add(entity)`/`.remove(id)`). Many-to-Many-/To-Many-Repository aus der Assoziation ableiten: `repositoryFactory.create(entity.categories.entity, entity.categories.source)` (`source` ist die Route `/product/<id>/categories`; Muster im Core: `sw-category-tree-field`, `sw-settings-rule-detail`, `sw-rule-modal`).

### 3.2 Service

1. Registrieren in `main.js`: `Shopware.Service().register('swagGreeter', (container) => new SwagGreeter(container.repositoryFactory))`.
2. Injizieren `inject: ['swagGreeter']` oder `inject: { greeter: 'swagGreeter' }`; außerhalb von Komponenten `Shopware.Service('swagGreeter')`.
3. Dekorieren VOR dem ersten Zugriff: `Shopware.Service().registerDecorator('systemConfigApiService', (service) => { /* erweitern */ return service; })`. Der Decorator ist ein Bottle-Decorator: Parameter ist nur der Service, der Rückgabewert ersetzt ihn, es gibt kein `next` (der `next()`-Kommentar an `addServiceProviderDecorator` in `core/application.ts` ist irreführend; Core-Vorbild `app/decorator/state-styling-provider.decorator.ts` endet mit `return stateStyleService;`; `registerDecorator` leitet an `Application.addServiceProviderDecorator` weiter, `core/factory/service.factory.ts`).
4. TypeScript: `declare global { interface ServiceContainer { swagGreeter: SwagGreeter } }` (`global.types.ts` Z. 246 ff.).

### 3.3 Eigene API-Requests

1. Backend-Route mit `_routeScope: ['api']` und `_acl` anlegen → [T-31](./31-admin-api-routen.md). `httpClient.baseURL` enthält bereits `/api`, kein doppeltes `/api/...`.
2. `ApiService`-Subklasse. Helfer: `getApiBasePath(id?, prefix?)`, `getBasicHeaders(extra)` (Bearer, Accept, Content-Type, `sw-language-id`), `ApiService.handleResponse(response)`, `ApiService.makeQueryParams`, `ApiService.getVersionHeader`.
3. Registrieren per `Shopware.Application.addServiceProvider('<name>ApiService', ...)`. Die automatische Registrierung per Glob (`app/init-pre/api-services.init.ts`) gilt nur für Core-ApiServices.
4. Alternative ohne Klasse: `const httpClient = Shopware.Application.getContainer('init').httpClient;` (`httpClient` ist ein Initializer im Init-Container, kein Service: `inject: ['httpClient']` liefert `undefined`; Core-Vorbild `worker-notification-listener.js`, `consent.init.ts`) plus Header `Authorization: Bearer ${Shopware.Service('loginService').getToken()}`.
5. Transport: Direktrequests laufen in 6.7 über Legacy-Axios, `useAxiosV1: true` im Request-Config ist Opt-in auf Axios v1 (`core/factory/http.factory.js`). Repository-Requests laufen laut Migrationsguide bereits mit v1.
6. Fehler: Promise wird abgelehnt (`error.response.data.errors[]`); `globalErrorHandlingInterceptor` zeigt Standard-Notifications.

### 3.4 State mit Pinia

1. `Shopware.Store.register({ id, state: () => ({...}), getters, actions })` (Options-Syntax; Overload `register(id, setupFn)` existiert).
2. Zugriff `Shopware.Store.get('id')` (wirft `Store with id "..." not found`). In Komponenten als Computed kapseln.
3. Keine Mutations: Änderungen in Actions via `this.<state>`. Vuex `State.commit('x/set', v)` entspricht `Store.get('x').set(v)`.
4. `Shopware.Store.unregister('id')` bei komponentengebundenen Stores (z. B. `beforeUnmount`).
5. TypeScript: Store-ID in `PiniaRootState` ergänzen (`global.types.ts` Z. 408 ff.).
6. Core-Stores (`session`, `context`, `notification`, `error`, `modals`, `adminMenu`, `extensions`, ...) lesen, nicht umschreiben (⚠ UNGEPRÜFT: Annahme, Core-intern/`@private`).

### 3.5 Notifications und Snackbar

1. Mixin `Mixin.getByName('notification')`: `createNotificationSuccess|Info|Warning|Error(config)` und `createSystemNotification*` (`system: true`, persistent). Titel-Default `global.default.success|info|warning|error`.
2. `message` ist Pflicht: Der Notification-Store verwirft Notifications ohne `message` (nur Debug-Warnung, `notification.store.ts`). Das Doku-Beispiel mit nur `{ title }` wirkt laut Code nicht.
3. `success` wird als Toast gezeigt, aber nicht persistent gespeichert.
4. Actions: `actions: [{ label, route: { name: 'sw.product.index' } }]` oder `{ label, method: () => ... }`.
5. Snackbar: `Shopware.Service('snackbarService').addSnackbar({ message, variant: 'success' })`, `removeSnackbar(id)` (`app/service/snackbar.service.ts`, `@public`; laut Doku ab 6.7.14.0).
6. `useNotification()` ist `@experimental stableVersion:v6.9.0 feature:ADMIN_MIXIN_COMPOSABLES`, `useSnackbar()` ist `@experimental stableVersion:v6.8.0 feature:ADMIN_COMPOSITION_API_EXTENSION_SYSTEM` (`app/composables/use-notification.ts`, `use-snackbar.ts`) → nicht für Plugins.
7. `Shopware.Store.get('notification').registerTransformer(key, fn)` existiert im Store (`notification.store.ts` Z. 321).

### 3.6 Fehlerbehandlung

1. `save()`/`sync()` legen bei HTTP 400 (`errors[].source.pointer`) über den `ErrorResolver` `ShopwareError` im Store `error` ab, `delete()` über `handleDeleteError`.
2. Feldfehler: `const { mapPropertyErrors } = Shopware.Component.getComponentHelper();` dann `computed: { ...mapPropertyErrors('product', ['name']) }` und im Template `:error="productNameError"`. Weitere: `mapCollectionPropertyErrors`, `mapPageErrors(errorConfig)`.
3. Direkt: `Shopware.Store.get('error').getApiError(entity, 'name')`, `getApiErrorFromPath`, `addApiError({ expression, error })`, `resetApiErrors()`. `new Shopware.Classes.ShopwareError({ code, detail })` (`code` Pflicht).
4. Immer `try/catch` um `await repo.save/search/delete`.

### 3.7 CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh
bin/watch-administration.sh       # Watcher (Entwicklung)
bin/console cache:clear
```

## 4. Beispiele

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

Die Route `/api/_action/swag-demo/greet` muss das Plugin selbst bereitstellen → [T-31](./31-admin-api-routen.md).

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

(Registrierung geschieht als Seiteneffekt beim Import.)

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
                await this.loadProducts(); // lokale Entity wird nicht automatisch aktualisiert
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
                <mt-card position-identifier="swag-demo-list-products" title="Produkte" :is-loading="isLoading">
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

Modul-Registrierung (Route, Navigation) siehe [T-24](./24-admin-module.md). ⚠ UNGEPRÜFT: `mt-card`/`sw-page`/`sw-card-view` im Template nicht einzeln gegen den Code geprüft (`mt-card` verlangt laut T-25 `positionIdentifier`).

## 5. Fallstricke

- Notification ohne `message`: nichts sichtbar.
- Entity nach `save()` nicht neu geladen: alte Werte in der UI.
- Falscher Entity-Name (`products`): `EntityDefinition.get` wirft; Snake-Case-Singular verwenden.
- Association nicht per `addAssociation` geladen: Property leer, Änderungen werden nicht gespeichert.
- Service nach dem ersten Zugriff dekorieren: Decorator/Middleware greift nicht.
- Doppelter Service-/ApiService-Name: Warnung `already registered`, zweite Registrierung wird ignoriert.
- Pinia-Store vor Registrierung angesprochen: Import-Reihenfolge in `main.js` beachten.
- `core/factory/...` direkt importieren statt `Shopware.*`: umgeht das Plugin-/Decorator-System.
- Criteria-Limits über 100: laut `core/AGENTS.md` Anti-Pattern.
- Direkte PATCH-Requests statt Repository: Change-Tracking und Fehlerauflösung greifen nicht.
- Eigene Backend-Route ohne `_routeScope: ['api']`/ACL: 403/404 oder Sicherheitslücke.
- Doppeltes `/api` in `httpClient`-Pfaden (`/api/api/...`).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Vuex → Pinia: `Shopware.State` ist `@deprecated tag:v6.8.0` (`core/shopware.ts`), ebenso `state.init.ts`/`state.factory.ts`; `package.json`: "Vuex is deprecated and will be removed with Shopware 6.8.0". `app/state/index.js` ist leer (Core registriert keine Vuex-Module mehr). `Shopware.State.registerModule` läuft in 6.7 noch, bricht mit 6.8. Neue Plugins: Pinia.
- Notification-Varianten: Mixin `success|info|warning|error`, Store-Typ `neutral|info|attention|critical|positive` (laufende Meteor-Migration, Annahme).
- `snackbarService` neu (Doku: ab 6.7.14.0). Composables `@experimental`, Mixins bleiben Standard in 6.7.
- Axios: Dual-Client (Legacy + v1), v1 wird mit aktivem Flag `V6_8_0_0` Default; Request-Option `useAxiosV1`.
- Repository-Optionen `useSync`, `keepApiErrors`, `RepositoryCacheOptions` im 6.7-Code vorhanden; ab welcher Minor-Version genau: nicht belegt.
- Hook `beforeUnmount` statt `beforeDestroy` (Vue 3).

## 6. Checkliste

- [ ] `repositoryFactory.create()` mit korrektem Snake-Case-Entity-Namen
- [ ] Assoziationen per `addAssociation` geladen, Criteria-Limit moderat
- [ ] Nach `save()` neu geladen, `try/catch` mit Notification im Fehlerfall
- [ ] Notifications enthalten `message`
- [ ] Neuer State mit Pinia (`Shopware.Store`), kein Vuex
- [ ] Service/ApiService mit eindeutigem Namen registriert, Import in `main.js`
- [ ] Decorator vor erstem Service-Zugriff registriert
- [ ] Backend-Route mit `_routeScope`/`_acl` vorhanden (T-31)
- [ ] Feldfehler per `mapPropertyErrors` angebunden
- [ ] Admin-JS neu gebaut, Cache geleert

## 7. Quellen

Doku (https://developer.shopware.com/docs/guides/plugins/plugins/administration/): `data-handling-processing/using-data-handling.html`, `data-handling-processing/using-vuex-state.html` (zeigt nur Vuex), `services-utilities/making-api-requests.html`, `services-utilities/add-custom-service.html`, `services-utilities/injecting-services.html`, `services-utilities/displaying-user-feedback.html`, `permissions-error-handling/add-error-handling.html`. Mitgeliefert: `administration/Resources/app/administration/technical-docs/` (`02-architecture/04-state-management.md`, `04-data-layer/*`, `07-notifications.md`, `09-security/axios-migration-guide.md`).

Quellcode (unter `administration/Resources/app/administration/src/`): `core/data/repository-factory.data.ts`, `repository.data.ts`, `criteria.data.ts`, `entity.data.ts`, `changeset-generator.data.js`, `error-resolver.data.js`; `core/shopware.ts`; `core/application.ts` (`addServiceProvider*`); `core/factory/service.factory.ts`, `http.factory.js`; `core/service/api.service.ts`; `app/init-pre/api-services.init.ts`; `app/store/index.ts`, `notification.store.ts`, `error.store.ts`; `app/mixin/notification.mixin.ts`; `app/service/snackbar.service.ts`, `map-errors.service.ts`; `global.types.ts`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Doku-Widerspruch Vuex/Pinia. `using-vuex-state.html` beschreibt nur Vuex; Code deklariert Vuex als deprecated und der Core nutzt Pinia. Eine Doku-Seite für Pinia wurde nicht gefunden.
- ⚠ LÜCKE: Doku-Beispiel Notification ohne `message` ist laut Code wirkungslos (nur Code gelesen, nicht im Browser getestet).
- ⚠ LÜCKE: Lokale technical-docs ungenau (`cacheService` statt `cacheApiService`, abweichende `register`-/`registerMiddleware`-Signaturen). Der Code gilt.
- ⚠ UNGEPRÜFT: Typ-Deklaration `declare global` im Plugin ist Standard-TypeScript, nicht aus der Shopware-Doku belegt.
- ⚠ UNGEPRÜFT: Beispiele nicht in einer laufenden Installation gebaut/ausgeführt. Backend-Route `swag-demo/greet` fehlt bewusst (T-31).
- ⚠ UNGEPRÜFT: Vue-Version/Compat-Aussagen nicht einzeln am `package.json` für diese Referenz verifiziert (siehe T-25: Vue 3.5.22).
