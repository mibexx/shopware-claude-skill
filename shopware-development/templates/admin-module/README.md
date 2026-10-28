# Template: Admin-Modul (swag-example)

Zweck: Administrations-Modul für ein Plugin mit Modul-Registrierung (Routen, Menüeintrag, `settingsItem`), Listing- und Detailseite auf Basis der Entity `swag_example` (per `repositoryFactory`), Snippets de-DE/en-GB und ACL-Privilegien. Shopware 6.7.15.1 (Vue 3, Vite, Meteor-Komponenten `mt-*`).

## Voraussetzung: Backend-Entity

Das Modul erwartet eine DAL-Entity `swag_example` (Tabelle `swag_example`) mit den Feldern `id`, `technicalName` (String, `Required`), `name` (übersetzbar, `TranslatedField`) und `active` (Bool), wie im Entity-Template (`ExampleDefinition`). Das Detail-Formular pflegt `technicalName` und `name`; fehlt `technicalName`, schlägt das Anlegen mit "/0/technicalName: This value should not be blank" fehl. Die Entity gehört nicht zu diesem Gerüst, siehe [entity](../entity/README.md) und [migration](../migration/README.md). Ohne Entity wirft `repositoryFactory.create('swag_example')` einen Fehler, weil das Schema nicht bekannt ist.

## Dateibaum

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    └── Resources/app/administration/src/
        ├── main.js                                     # Einstieg, importiert das Modul
        └── module/swag-example/
            ├── index.js                                # Component.register + Module.register
            ├── acl/index.js                            # privileges.addPrivilegeMappingEntry
            ├── page/swag-example-list/{index.js,swag-example-list.html.twig}
            ├── page/swag-example-detail/{index.js,swag-example-detail.html.twig}
            └── snippet/{de,en}.json              # Modul- und Privileg-Labels
```

## Platzhalter

| Platzhalter | Ersetzen durch | Wo |
|---|---|---|
| `swag-example` (Modul-ID) | `<vendor>-<name>`, mindestens ein Bindestrich | `Module.register`, Ordner, Snippet-Prefix |
| `swag.example.index/detail/create` | Route-Namen: Modul-ID mit Punkten + Schlüssel aus `routes` | `navigation.path`, `settingsItem.to`, `detail-route`, `$router.push` |
| `swag_example` | Entity-Name (Snake-Case) und ACL-Key | `repositoryFactory.create(...)`, `acl/index.js`, `meta.privilege`, `acl.can('swag_example.*')` |
| `swag-example-list` / `-detail` | Komponentennamen | `Component.register`, Routen |
| `sw-catalogue` | Menü-Eltern-ID (Core z. B. `sw-catalogue`, `sw-customer`, `sw-order`, `sw-marketing`) | `navigation.parent` |
| `swag-example.*` | Snippet-Schlüssel | `index.js`, Templates, Snippet-Dateien |
| `SwagExample` / `swag/example` | Plugin-Name | Klasse, Namespace, `composer.json` |

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh          # Admin neu bauen (Entwicklung: bin/watch-administration.sh)
bin/console assets:install
bin/console cache:clear
```

Rolle testen: Einstellungen -> Benutzer & Rechte -> Rollen. Die Gruppe "Examples" erscheint unter Kataloge (`parent: 'catalogues'`). Nach Änderungen an Privilegien-Mappings bestehende Rollen neu speichern.

## Regeln (am Quellcode belegt)

- Modul-ID ohne Bindestrich oder doppelte ID: Registrierung bricht mit Konsolenwarnung ab (`module.factory.ts`).
- Route-Name = Modul-ID mit Punkten + Schlüssel aus `routes` (nicht das Feld `name` des Manifests). Pfad = `/swag/example/<path>`.
- `navigation` bei `type: 'plugin'` braucht `parent` und `label`, sonst wird der Eintrag verworfen. `position` wird bei Plugins um 1000 erhöht.
- `settingsItem`: `group` (`general|localization|customer|commerce|content|automation|system|account|plugins`), `to` und `icon` sind nötig. Nur einen der beiden Menüwege behalten, wenn kein doppelter Eintrag gewünscht ist.
- `meta.parentPath` an Detail-/Create-Routen sorgt für den Zurück-Pfeil in `sw-page`.
- Komponenten per `Shopware.Component.register(name, () => import(...))` registrieren (Core-Standard), die Page-`index.js` exportiert das Objekt per `export default`.
- Listing: Mixin `listing` ruft `getList()` auf, liefert `page`, `limit`, `term`, `onPageChange`, `onSortColumn`. `sw-entity-listing` mit `:data-source` (Prop `items` ist deprecated) und `:disable-data-fetching="true"`.
- `mt-card` verlangt `position-identifier`. Meteor-Komponenten `mt-button`, `mt-text-field`, `mt-switch`, `mt-empty-state` sind global registriert, `mt-card` als Shopware-Wrapper.
- `repository.save()` aktualisiert die lokale Entity nicht, daher nach dem Speichern neu laden. Notifications brauchen immer eine `message`.
- Privilegien: `addPrivilegeMappingEntry` verlangt `category`, `parent` (auch `null`) und `key`, sonst wird der Eintrag verworfen. Keine Punkte in `key`/Rollennamen. Rollen `viewer|editor|creator|deleter` mit `dependencies`. Fremde Rollen einbinden mit `Shopware.Service('privileges').getPrivileges('media.viewer')`. Label-Schlüssel: `sw-privileges.permissions.<key>.label`.
- UI-ACL (`meta.privilege`, `acl.can`) ist keine Sicherheit. Backend absichern mit `PlatformRequest::ATTRIBUTE_ACL` (`_acl`) an Routen. Für Entities greift der DAL-ACL über `<entity>:<read|create|update|delete>` automatisch. Plugin-PHP kann mit `Plugin::enrichPrivileges()` Backend-Privilegien an bestehende Admin-Privilegien hängen; das wirkt nur beim DAL-Laden der Rolle (UI), die Backend-Prüfung liest per SQL: Rolle neu speichern oder Privilegien per Migration schreiben (T-23).
- Snippets: Dateien `de.json`/`en.json` unter `Resources/app/administration/src/**` findet der `SnippetFinder` zur Laufzeit. Der Core importiert sie nicht im Manifest, das Gerüst auch nicht.

## Deprecations / 6.8

- `sw-entity-listing` Prop `items` deprecated, hier `dataSource`.
- Vuex (`Shopware.State`) ist deprecated, für State `Shopware.Store` (Pinia) nutzen (siehe Referenz 26).
- Tab-Erweiterungen am Produkt-Detail über `sw_product_detail_content_tabs_*` brechen mit 6.8 (`mt-tabs`/`productDetailTabs`).
- Composables (`useNotification` u. a.) sind in 6.7.15.1 `@experimental`, daher Mixins.

## Lücken und Hinweise

- ⚠ UNGEPRÜFT: Gerüst nicht gebaut und nicht gegen eine laufende Installation getestet (kein PHP, kein Admin-Build). JS per `node --check`, JSON per `JSON.parse`. Muster, Komponenten, Props und Privilegien-Service am Core-Modul `sw-manufacturer`, `sw-settings-tax`, `privileges.service.ts`, `module.factory.ts` gegengelesen.
- ⚠ UNGEPRÜFT: Icon-Namen (`regular-shopping-bag`, `regular-rocket`): Präfix-Schema `regular-*` stammt aus Core-Modulen, einzelne Namen nicht gegen die Icon-Liste geprüft.
- ⚠ UNGEPRÜFT: Die Beispielseiten verzichten auf Suchleiste (`sw-search-bar`) und Sprachumschalter. Dafür wäre `entity`/`defaultSearchConfiguration` im Manifest nötig (Vorbild `sw-manufacturer`).
- ⚠ LÜCKE: Ob der Plugin-Build `main.ts` zusätzlich zu `main.js` lädt, ist nicht belegt. Doku und Scaffold nennen `main.js`.
- ⚠ LÜCKE: Die Doku-Seite "add-custom-module" nennt `bundle:dump-js`. In 6.7.15.1 existiert nur `bundle:dump` (`BundleDumpCommand`). Der Scaffold-Stub (`js-module.stub`) und die Doku zeigen außerdem ein Modul ohne `navigation.parent`, das als Plugin nicht im Menü erscheint. Das Gerüst setzt `parent`.
- ⚠ LÜCKE: Eigene Admin-API-Routen/ApiService (`/api/_action/...`) sind nicht enthalten, siehe Referenz 26 und die Controller-/Routing-Themen.
- Route überschreiben (`routeMiddleware`) und Tabs ergänzen sind in Referenz 24 beschrieben und nicht Teil des Gerüsts.

## Referenzen

- [24 Admin-Module](../../references/24-admin-module.md)
- [25 Admin-Komponenten](../../references/25-admin-komponenten.md)
- [26 Admin-Datenhandling](../../references/26-admin-datenhandling.md)
- [23 ACL, Rechte & Rollen](../../references/23-acl-rechte-rollen.md)
- [18 Snippets & Übersetzungen](../../references/18-snippets-uebersetzungen.md)
- [27 DAL-Entities](../../references/27-dal-entities.md)
- [28 DAL lesen/schreiben](../../references/28-dal-lesen-schreiben.md)
- [49 Build & Deployment](../../references/49-build-deployment.md)
- Weitere Templates: [entity](../entity/README.md), [migration](../migration/README.md), [plugin](../plugin/README.md), [cms-element](../cms-element/README.md)

- Snippet-Dateien sind sprachneutral benannt (`de.json`/`en.json` bzw. `storefront.de.json`/`storefront.en.json`); regionale Namen (`de-DE.json`) beanstandet `bin/console translation:lint-filenames` ohne sprachneutrale Basisdatei, Reparatur mit `--fix` (siehe [T-18](../../references/18-snippets-uebersetzungen.md)).
