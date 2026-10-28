# Admin-Module, Routen und Menüeinträge (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn die Administration um ein eigenes Modul (Listing/Detail, Routen, Menüeintrag, Einstellungs-Eintrag) erweitert wird, wenn Core-Routen überschrieben oder Tabs ergänzt werden.

Abgrenzung: Komponenten, Overrides, Mixins: [T-25 Admin-Komponenten](./25-admin-komponenten.md). Repository/Datenzugriff im Admin: [T-26](./26-admin-datenhandling.md). Rechte: [T-23 ACL](./23-acl-rechte-rollen.md). Snippets: [T-18](./18-snippets-uebersetzungen.md). Entity dahinter: [T-27](./27-dal-entities.md), [T-04](./04-datenbank-migrationen.md). Build: [T-49](./49-build-deployment.md). Gerüst: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Konzepte

- Ein Admin-Modul ist ein Aufruf `Shopware.Module.register('<namespace>-<name>', manifest)` in `…/administration/src/module/<id>/index.js`, importiert aus `src/main.js` des Plugins. Registrierung in `registerModule()` (`core/factory/module.factory.ts`).
- Modul-ID **muss** mindestens einen Bindestrich enthalten (`[namespace]-[name]`), sonst Abbruch mit Konsolenwarnung. Doppelte IDs werden abgelehnt.
- `type`: `'plugin' | 'core'`. **Immer explizit `type: 'plugin'` setzen.** Zwar fällt `registerModule()` für die Route-Typen auf `'plugin'` zurück (`module.type || 'plugin'`), die Navigation prüft aber `module.type` selbst (`navigationEntry.moduleType = module.type`, `module.type === 'plugin' && !navigationEntry.parent`); ohne explizites `type` entfällt die `parent`-Prüfung und `moduleType` bleibt `undefined` (`core/factory/module.factory.ts`). `type: 'core'` in Plugins nicht verwenden (umgeht `parent`-Prüfung und +1000-Position).
- **Routen** (`routes`): Name = `<id mit Punkten>.<key>` (`swag-example` + `list` -> `swag.example.list`), Pfad `/swag/example/<path>`. Änderbar mit `routePrefixName`/`routePrefixPath`. `component` (String) wird intern zu `components: { default: … }`; mehrere Views über `components`. Child-Routen (`children`): Name `<parent>.<key>`, Pfad vom Parent präfixiert. Das `name` im Manifest ist nur Modulname, nicht Teil des Route-Namens.
- **Menü** (`navigation: [{ id, label, path, parent, icon, color, position, privilege }]`, Modul mit explizitem `type: 'plugin'`): Plugin-Einträge **ohne `parent` werden verworfen** („Navigation entries from plugins are not allowed on the first level"); `label` Pflicht; `position` +1000 für Plugins.
- **Einstellungen** (`settingsItem`, Objekt oder Array): braucht `group`, `to`, `icon` oder `iconComponent`; `id`, `name`, `label` werden ergänzt. `group`: `general`, `localization`, `customer`, `commerce`, `content`, `automation`, `system`, `account`, `plugins`.
- **Route überschreiben/Tab ergänzen:** `routeMiddleware(next, currentRoute)` in eigenem `Module.register` (ohne `routes`). Läuft für jede Top-Level-Route in `getModuleRoutes()` (`MiddlewareHelper.go`); `currentRoute` in-place ändern oder `currentRoute.children` ergänzen.
- **Listing/Detail:** Listing `<sw-page>` + `<sw-entity-listing>` + Mixin `listing`; Detail `<sw-page>` + `<sw-card-view>` + Repository. `detail-route` am Listing, `routerLink` in der Spalte; Detail-Route trägt `meta.parentPath` (Zurück-Pfeil).
- Admin-Templates sind `.html.twig`, keine Vue-SFCs.

```
SwagExample/
└── src/Resources/app/administration/src/
    ├── main.js                          # importiert nur Module/Overrides
    └── module/swag-example/
        ├── index.js                     # Shopware.Module.register(...)
        ├── page/swag-example-list/{index.js, swag-example-list.html.twig}
        ├── page/swag-example-detail/{index.js, swag-example-detail.html.twig}
        ├── component/ view/ acl/index.js    # optional
        └── snippet/{de-DE,en-GB}.json
```
Vorlage im Core: `module/sw-manufacturer/`. Der Scaffold-Generator (`AdminModuleGenerator`) legt Snippets unter `src/Resources/app/administration/src/snippet/{en,de}.json` ab, nicht im Modulordner.

## 3. Anleitung

1. Plugin anlegen: `bin/console plugin:create SwagExample`, dann `bin/console plugin:refresh` und `plugin:install --activate SwagExample` (Optionen für Admin-Modul-Scaffold nicht geprüft).
2. Dateien nach Baum anlegen. Voraussetzung: Entity `swag_example` (Definition + Migration, siehe T-27/T-04).
3. `main.js` importiert das Modul (Einstieg `src/Resources/app/administration/src/main.js`).
4. Komponenten per `Shopware.Component.register` registrieren, bevor das Modul sie in `routes` nennt (Import in `index.js`).
5. Admin bauen: `bin/build-administration.sh` (im Projekt gibt es keine Composer-Skripte dafür); Hot-Reload `bin/watch-administration.sh`; danach `bin/console assets:install` und `bin/console cache:clear`. **Doku-Abweichung:** Doku nennt `bundle:dump-js`; installiert existiert nur `bundle:dump` (`BundleDumpCommand`, schreibt die Plugin-Konfiguration für den Build).
6. Typprüfung/Lint (⚠ experimentell/`@internal`, `@experimental stableVersion:v6.8.0`, siehe [T-48](./48-statische-analyse.md)): einmalig `bin/console administration:setup-extension-tooling`, dann `bin/console administration:check-extensions -- --only=SwagExample` (laut `extension-tooling/README.md`).
7. **Route überschreiben:** zweites `Module.register` mit `routeMiddleware`:
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
8. **Tab ergänzen** (Produkt-Detail): (a) `Shopware.Component.override('sw-product-detail', { template })` mit Twig-Block `sw_product_detail_content_tabs_reviews` (`{% parent %}` plus `<sw-tabs-item :route="{ name: 'sw.product.detail.custom', params: { id: $route.params.id } }">`; Blöcke `sw_product_detail_content_tabs_*`, u. a. `…_additional`); (b) Route per Middleware: `currentRoute.children.push({ name: 'sw.product.detail.custom', path: '/sw/product/detail/:id/custom', component: 'sw-product-detail-custom', meta: { parentPath: 'sw.product.index' } })` – vorher mit `every(...)` Duplikate verhindern (Middleware läuft bei jedem `getModuleRoutes()`); (c) View `Shopware.Component.register('sw-product-detail-custom', {...})`.
9. Gültige `parent`-Werte: `sw-catalogue`, `sw-customer`, `sw-order`, `sw-marketing`; weitere per `grep -n "id: 'sw-" administration/Resources/app/administration/src/module/*/index.js`.

## 4. Beispiele

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
        parent: 'sw-catalogue',      // Pflicht für type 'plugin'
        icon: 'regular-shopping-bag',
        color: '#ff3d58',
        position: 100,
    }],

    settingsItem: {                    // optional: zusätzlich unter Einstellungen
        group: 'plugins',
        to: 'swag.example.list',
        icon: 'regular-rocket',
        label: 'swag-example.general.mainMenuItemGeneral',
    },
});
```
`privilege`-Werte wie `swag_example.viewer` greifen nur, wenn per `acl/index.js` registriert (siehe [T-23](./23-acl-rechte-rollen.md)); sonst weglassen.

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

        // Event `update-records` liefert die ganze EntityCollection, die Gesamtzahl steht in `total`
        updateTotal({ total }) {
            this.total = total;
        },
    },
});
```
Das Mixin `listing` ruft `getList()` auf und liefert `page`, `limit`, `term`, `onPageChange`. Muster: `module/sw-manufacturer/page/sw-manufacturer-list/index.js`.

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
        // nach dem Anlegen wechselt die Route von create auf detail: ohne Watch bliebe `example`
        // eine neue Entity und der nächste Save würde per POST ein Duplikat anlegen
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
Bei `create` ist `exampleId` `null`. `meta.parentPath` wird in `sw-page` (`initPage`) für den Zurück-Pfeil ausgewertet.

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

`snippet/en.json` (`de.json` analog)
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

## 5. Fallstricke

- Modul-ID ohne Bindestrich: Registrierung bricht mit Konsolenwarnung ab; immer `<vendor>-<name>`.
- Navigation ohne `parent` bei `type: 'plugin'`: Eintrag verworfen. **Der Scaffold-Stub `js-module.stub` und die Doku `add-custom-module` zeigen genau dieses Beispiel ohne `parent`; dort erscheint der Menüpunkt in 6.7.15.1 laut Code nicht – Code gilt.**
- `label` in `navigation` fehlt: Eintrag verworfen.
- Route-Name wird aus der ID erzeugt, nicht aus `name:`; `navigation.path`/`settingsItem.to` müssen den erzeugten Namen treffen.
- Komponente nicht per `Component.register` registriert: keine Seite (`routes.component` ist nur ein String).
- Zweites `Module.register` mit gleicher ID wird abgelehnt.
- Middleware pusht Child bei jedem Lauf erneut: mit `every`/`find` auf Duplikate prüfen.
- `parentPath` an Detail-Route vergessen: kein Zurück-Pfeil.
- Nicht neu gebaut/Cache: Änderungen wirken erst nach Build und `assets:install`.
- Snippets nicht registriert: über `snippets` im Manifest (Details T-18).
- Anti-Pattern: Core-Komponenten komplett per `Component.override` ersetzen statt Twig-Blöcke mit `{% parent %}` zu erweitern; Core-Module kopieren statt `routeMiddleware`.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Admin ist Vue 3 mit Vite (`vite.config.mts`), Pinia statt Vuex (`Shopware.Store`).
- Icons mit Präfix (`regular-products`, `regular-factory`); Doku nutzt teils noch `default-shopping-paper-bag-product` (Auflösung alter Namen nicht geprüft).
- Core-Listing nutzt `mt-button`, `mt-empty-state` (Meteor) statt `sw-button`; Doku teils noch `sw-button`/`sw-card`.
- `sw-entity-listing`: Prop `items` `@deprecated tag:v6.8.0` -> `dataSource`.
- Produkt-Detail hat hinter Flag `v6.8.0.0` eine neue Tab-Leiste (`<mt-tabs :items="productDetailTabs">`); **Tab-Overrides über `sw_product_detail_content_tabs_*` brechen unter 6.8**, dort `productDetailTabs` erweitern.
- `ModuleManifest` kennt zusätzlich `extensionEntryRoute`, `defaultSearchConfiguration`, `flag`, `routeMiddleware`, `display`; `module.factory.ts` ist TypeScript.
- Core-Snippet-Dateien heißen `de.json`/`en.json` (sprachneutral). Ältere Doku-Beispiele nutzen `de-DE.json`/`en-GB.json`; dafür meldet `bin/console translation:lint-filenames` einen Fehler, solange keine sprachneutrale Basisdatei existiert (Reparatur: `--fix`, siehe [T-18](./18-snippets-uebersetzungen.md)).

## 6. Checkliste

- [ ] Modul-ID `<vendor>-<name>` mit Bindestrich, eindeutig.
- [ ] `main.js` importiert das Modul; alle Komponenten sind registriert.
- [ ] `navigation` mit `parent` und `label`; `path` = erzeugter Route-Name.
- [ ] Detail-/Create-Route mit `meta.parentPath`.
- [ ] `privilege`-Einträge nur gesetzt, wenn ACL registriert ist (T-23).
- [ ] Snippets de + en im Manifest registriert.
- [ ] Listing: `detail-route`, `dataSource` (nicht `items`), `routerLink` an Primärspalte.
- [ ] Middleware-Routen auf Duplikate geprüft.
- [ ] Admin gebaut, `assets:install` und `cache:clear` ausgeführt.

## 7. Quellen

- https://developer.shopware.com/docs/guides/plugins/plugins/administration/module-component-management/add-custom-module.html
- …/administration/routing-navigation/add-custom-route.html, `add-menu-entry.html`, `overriding-routes.html`, `add-new-tab.html`
- Admin (`administration/Resources/app/administration/`): `src/core/factory/module.factory.ts` (`registerModule`, `getModuleRoutes`, `addSettingsItemsToStore`; Typen `ModuleManifest`, `Navigation`, `SettingsItem`), `src/core/helper/middleware.helper.js`, `src/module/sw-manufacturer/`, `sw-settings-tax/index.js`, `sw-product/` (Tab-Blöcke), `src/app/component/structure/sw-page/`, `entity/sw-entity-listing/`, `AGENTS.md`, `src/module/AGENTS.md`, `technical-docs/02-architecture/03-module-system.md`, `extension-tooling/README.md`.
- Core: `core/Framework/Plugin/Command/BundleDumpCommand.php`, `Scaffolding/Generator/AdminModuleGenerator.php`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Entity `swag_example` ist angenommen; Beispiel nicht in laufender Instanz gebaut/ausgeführt.
- ⚠ UNGEPRÜFT: `mt-card`, `mt-text-field` aus Meteor-Doku abgeleitet; verifiziert sind nur `mt-button`, `mt-empty-state` im Core-Listing.
- ⚠ UNGEPRÜFT: Ob der Plugin-Build zusätzlich `src/snippet/*.json` automatisch lädt, nicht verifiziert (T-18). Ob neben `main.js` auch `main.ts` geladen wird: nicht belegt.
- ⚠ LÜCKE: Doku-Abweichung `bundle:dump-js` (existiert nicht) vs. `bundle:dump`; Optionen von `plugin:create` für Admin-Modul nicht geprüft.
- ⚠ UNGEPRÜFT: Verfügbarkeit von `administration:check-extensions`/`setup-extension-tooling` nicht getestet; alte Icon-Namen (`default-*`) nicht geprüft; 6.5/6.6-Unterschiede aus Code-Zustand abgeleitet.
