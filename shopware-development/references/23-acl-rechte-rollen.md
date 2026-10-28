# ACL: Rechte und Rollen (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eigene Admin-Module/Routen rollenbasiert abgesichert werden, wenn ein Plugin eigene Privilegien registriert oder bestehende erweitert, oder wenn geklärt werden muss, wie Benutzer, Rollen und Integrationen zusammenwirken.

Abgrenzung: Aufbau des Admin-Moduls selbst: [T-24 Admin-Module](./24-admin-module.md). Admin-API-Routen: [T-31](./31-admin-api-routen.md). Snippets: [T-18](./18-snippets-uebersetzungen.md). App-Permissions (Manifest): [T-10](./10-app-system-abgrenzung.md). Gerüst: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Konzepte

ACL hat **zwei Ebenen**:

1. **Backend (verbindlich):** `acl_role` enthält eine flache Liste von Privilegien-Strings (`product:read`, `api_acl_privileges_get`, …). `AdminApiSource::isAllowed()` prüft diese. Erzwungen (a) pro Route über das Route-Default `_acl` (`PlatformRequest::ATTRIBUTE_ACL`, `AclAnnotationValidator`), (b) bei DAL-Schreibzugriffen über die Admin-API (`AclWriteValidator`), (c) bei Lesezugriffen samt Associations (`AclCriteriaValidator`: je Association wird `<entity>:read` verlangt).
2. **Administration (nur UI):** *Admin-Privilegien* der Form `<key>.<role>` (`product.viewer`, `.editor`, `.creator`, `.deleter`), im JS per `Shopware.Service('privileges').addPrivilegeMappingEntry()` registriert. Beim Speichern der Rolle lösen sie sich in Backend-Privilegien auf (`getPrivilegesForAdminPrivilegeKeys`). **Beide** Arten stehen in `acl_role.privileges`.

UI-Schutz: `meta.privilege` an Routen (Router-Guard -> `sw.privilege.error.index`), `privilege` an `navigation`/`settingsItem`, `acl.can('x.editor')` in Komponenten/Templates. **UI-ACL ist keine Sicherheit**; echte Absicherung nur über Backend-Privilegien/`_acl`.

- Admin-Flag: `user.admin = true` bzw. `integration.admin = true` -> `isAllowed()` immer `true`; `acl.can()` im JS ebenso.
- Nutzer ↔ Rollen: n:m `acl_user_role`; Integrationen ↔ Rollen: n:m `integration_role`. Effektive Rechte = Vereinigung aller Rollen.
- Plugin-PHP: `Plugin::enrichPrivileges()` hängt Backend-Privilegien an bestehende Admin-Privilegien (oder `all`) an (`PluginAclPrivilegesSubscriber`). Das wirkt **nur beim Laden der Rolle über das DAL** (Admin-UI zeigt sie). Die Backend-Prüfung liest `acl_role.privileges` per SQL (`ApiRequestContextResolver::fetchPermissions`) und sieht die angereicherten Werte nicht: `_acl` lehnt ab, bis die Rolle neu gespeichert wird oder die Privilegien per Migration in `acl_role.privileges` geschrieben werden.

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php                         # enrichPrivileges()
    ├── Controller/ReviewExportController.php   # _acl auf Admin-API-Route
    └── Resources/
        ├── config/{services.php, routes.php}
        └── app/administration/src/
            ├── main.js
            └── module/swag-review/
                ├── index.js                    # meta.privilege, navigation.privilege
                ├── acl/index.js                # addPrivilegeMappingEntry
                ├── page/swag-review-list/{index.js, swag-review-list.html.twig}
                └── snippet/{de-DE,en-GB}.json  # sw-privileges.permissions.<key>.label
```
Core-Referenz: `module/sw-manufacturer/{index.js,acl/index.js}`, `module/sw-order/acl/index.js` (`additional_permissions`), `module/sw-users-permissions/acl/index.js`.

## 3. Anleitung

1. Entscheiden: UI-Gating (Admin-Privileg) und/oder Backend-Schutz (`_acl`) – beides vorsehen.
2. `acl/index.js` anlegen, in `module/<name>/index.js` per `import './acl'` laden, Modul in `main.js` importieren (sonst läuft die Registrierung nie).
3. Privilegien je Rolle: `viewer` = lesende Entitäten inkl. aller Hilfs-Entitäten, die die Seite lädt (Sales-Channels, Media, `user_config` …). `editor`/`creator`/`deleter` hängen per `dependencies` an `viewer` (rekursive Auflösung).
4. Fremde Rollen einbinden: `Shopware.Service('privileges').getPrivileges('media.viewer')` als Element im `privileges`-Array.
5. `meta.privilege` an jeder Route, `privilege` an `navigation` und `settingsItem`.
6. In Komponenten `inject: ['acl']`; Templates `acl.can(...)`; Routen programmatisch: `acl.hasAccessToRoute('swag.review.index')`.
7. Snippets für Label (de + en) anlegen.
8. Backend-Routen mit `_acl` versehen (`entity:read|create|update|delete` oder Custom-Strings `api_…`).
9. Fremde Privilegien erweitern: `enrichPrivileges()`.
10. Build/Test:
    ```bash
    bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
    bin/build-administration.sh
    bin/console cache:clear
    # Liste der Backend-Privilegien (Admin-API):
    #  GET /api/_action/acl/privileges              (api_acl_privileges_get)
    #  GET /api/_action/acl/additional_privileges   (api_acl_privileges_additional_get)
    ```
    Test: Einstellungen → Benutzer & Rechte → Rollen: Rolle anlegen, Haken „Reviews → View", Benutzer zuweisen, als dieser Benutzer einloggen.

### Benutzer, Rollen, Integrationen (`ApiRequestContextResolver::getAdminApiSource`)
- Nur User: Privilegien aller zugewiesenen Rollen (`fetchPermissions`) + `AdminApiSource::DEFAULT_USER_PRIVILEGES`; `isAdmin` = `user.admin`.
- Nur Integration: Rollen der Integration (`fetchIntegrationPermissions`), `isAdmin` = `integration.admin`.
- User + Integration (beide gesetzt), drei Fälle in dieser Reihenfolge:
  1. App-Integration (Rechte aus der `acl_role` der App, Manifest `<permissions>`): `isAdmin=false`; ist der User kein Admin, werden App- und User-Rechte geschnitten (`array_intersect`), bei Admin-User gelten die App-Rechte unverändert.
  2. Admin-Integration (`integration.admin=true`): Rechte und `isAdmin` kommen vom **User** (`fetchPermissions` + Default-User-Privilegien, `isAdmin` = `user.admin`); die Integration beschränkt nichts.
  3. Normale Integration: Rechte der Integration; bei Nicht-Admin-User geschnitten mit den User-Rechten (Minimum), bei Admin-User gelten die Integrationsrechte; `isAdmin=false`.
- Integration anlegen: `IntegrationController::upsertIntegration` (`_acl: integration:create|update`); das Feld `admin` darf nur ein Admin setzen. `user.admin` ist WriteProtected.
- `acl_role` ist für Generic-CRUD per `WriteProtection(SYSTEM_SCOPE, USER_SCOPE)` gesperrt; Anlegen/Ändern über `UserController` (`api.acl_role.create|update|delete`, `_acl: acl_role:create|update|delete`).

## 4. Beispiele

`src/Resources/app/administration/src/module/swag-review/acl/index.js`
```js
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',          // oder 'additional_permissions'
    parent: 'catalogues',             // null => Gruppe "Other"; sonst catalogues|content|customers|marketing|orders|settings
    key: 'swag_review',
    roles: {
        viewer: {
            privileges: [
                'swag_review:read',
                'product:read',
                Shopware.Service('privileges').getPrivileges('media.viewer'),
            ],
            dependencies: [],
        },
        editor: {
            privileges: ['swag_review:update'],
            dependencies: ['swag_review.viewer'],
        },
        creator: {
            privileges: ['swag_review:create'],
            dependencies: ['swag_review.viewer', 'swag_review.editor'],
        },
        deleter: {
            privileges: ['swag_review:delete'],
            dependencies: ['swag_review.viewer'],
        },
    },
});
```

`…/module/swag-review/index.js`
```js
import './acl';
import './page/swag-review-list';

Shopware.Module.register('swag-review', {
    type: 'plugin',
    title: 'swag-review.general.title',
    color: '#62ff80',
    icon: 'regular-star',

    routes: {
        index: {
            component: 'swag-review-list',
            path: 'index',
            meta: { privilege: 'swag_review.viewer' },   // Router-Guard
        },
    },

    navigation: [{
        id: 'swag-review',
        label: 'swag-review.general.title',
        path: 'swag.review.index',
        parent: 'sw-catalogue',
        position: 100,
        privilege: 'swag_review.viewer',                 // Menüeintrag ausblenden
    }],
});
```

`…/page/swag-review-list/index.js`
```js
import template from './swag-review-list.html.twig';

Shopware.Component.register('swag-review-list', {
    template,
    inject: ['acl'],
    computed: {
        canCreate() {
            return this.acl.can('swag_review.creator');
        },
    },
});
```

`…/swag-review-list.html.twig` (Muster aus `sw-manufacturer-list.html.twig`)
```twig
{% block swag_review_list %}
<sw-page>
    <template #smart-bar-actions>
        <mt-button
            variant="primary"
            :disabled="!acl.can('swag_review.creator') || undefined"
            v-tooltip="{
                message: $t('sw-privileges.tooltip.warning'),
                disabled: acl.can('swag_review.creator'),
                showOnDisabledElements: true
            }"
        >
            {{ $t('swag-review.general.add') }}
        </mt-button>
    </template>
</sw-page>
{% endblock %}
```

Snippet `snippet/en.json` (Label-Key aus `sw-users-permissions-permissions-grid.html.twig`: `sw-privileges.permissions.<key>.label`):
```json
{ "sw-privileges": { "permissions": { "swag_review": { "label": "Reviews" } } } }
```
Für `additional_permissions`: `sw-privileges.additional_permissions.<key>.label` und `….<key>.<roleName>`.

Backend – Route schützen, `src/Controller/ReviewExportController.php`:
```php
<?php declare(strict_types=1);

namespace SwagExample\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class ReviewExportController extends AbstractController
{
    #[Route(
        path: '/api/_action/swag-review/export',
        name: 'api.action.swag_review.export',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['swag_review:read']],
        methods: ['GET']
    )]
    public function export(Context $context): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
```
`src/Resources/config/routes.php` (`services.xml`/`routes.xml` sind in 6.7 deprecated, Entfernung v6.8.0.0, `Bundle.php`):
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Controller/**/*Controller.php', 'attribute');
};
```
`services.php`: `$services->set(ReviewExportController::class)->public()->call('setContainer', [service('service_container')]);` - Controller als Service mit `->public()` und `->call('setContainer', [service('service_container')])` registrieren; `setContainer()` kommt aus `AbstractController`, daher muss der Controller davon erben, sonst HTTP 500 „Attempted to call an undefined method named setContainer“ (alternativ ohne Basisklasse nur `->public()`) ([T-05](./05-services-registrieren.md), [T-31](./31-admin-api-routen.md)).

Plugin-Klasse `src/SwagExample.php`:
```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
    /**
     * @return array<string, list<string>>
     */
    public function enrichPrivileges(): array
    {
        return [
            // wird beim DAL-Laden der Rolle product.viewer ergänzt (nur UI); für _acl Rolle neu speichern oder per Migration in acl_role.privileges schreiben
            'product.viewer' => ['swag_review:read'],
            // 'all' => Privileg für ALLE Rollen
        ];
    }
}
```

## 5. Fallstricke

- **Nur UI gesichert:** `meta.privilege` ohne Backend-Privilegien/`_acl` ist keine Sicherheit. Umgekehrt: fehlendes Backend-Privileg (`product:read` etc.) -> leere Listen bzw. `missing privileges` (`ApiException::missingPrivileges`).
- `acl/index.js` nicht importiert: Rolle erscheint nicht im Rollen-Grid.
- `parent`/`category`/`key` fehlen: Eintrag wird verworfen, nur `warn` in der Konsole (`isPrivilegeMapping`).
- Snippet fehlt: Label zeigt Rohschlüssel.
- Punkt im `key` oder Rollennamen: Admin-Privilegien werden per Split an `.` erkannt (`existsPrivilege`, Regex `/.+\..+/`).
- Gleiche `category`+`key` mehrfach registriert wird gemergt (`deepMergeObject`): Core-Einträge erweiterbar, nichts entfernbar.
- Nach Änderung der Mappings müssen bestehende Rollen **neu gespeichert** werden, sonst fehlen die neuen Backend-Privilegien in `acl_role.privileges`. Für Plugins wirkt `enrichPrivileges()` nur beim DAL-Laden (UI), nicht persistiert und nicht in der SQL-Prüfung des Backends: Rolle neu speichern oder Privilegien per Migration in `acl_role.privileges` schreiben.
- `enrichPrivileges()`-Key muss ein Eintrag in `acl_role.privileges` sein (z. B. `product.viewer`) oder `all`.
- `acl.can('')`/leerer Key -> `true`; falscher Key -> für Nicht-Admins immer `false`.
- Anti-Pattern: Berechtigung nur per `v-if` im Frontend; Hardcodieren von `admin`-Checks statt `acl.can`.
- Eigene Custom-Privilegien (`api_…`): PHPStan-Regeln prüfen `AclValidPermissionsHelper::CUSTOM_PERMISSIONS` (nur Core-intern relevant).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Mechanik (`privileges`-Service, `acl.can`, `meta.privilege`, `_acl`, `enrichPrivileges`) über 6.5–6.7 stabil; im 6.7-Code keine Deprecation dafür gefunden.
- Annotation `@Acl` ersetzt durch Route-Default `_acl`; Routen als PHP-Attribut `#[Route]` (`Symfony\Component\Routing\Attribute\Route`).
- Aus dem Code (Versionszuordnung nicht belegt): `AdminApiSource::DEFAULT_USER_PRIVILEGES` (u. a. `increment:manage`, `system:queue:process`), `acl_role`-Schreibschutz, `admin`-Flag nur über Controller, `mcp_allowlist` an Integrationen (Privileg `api_action_user_mcp-allowlist`, siehe [T-44](./44-mcp-server.md)), SSO-Komponenten.
- `Shopware.Service('acl')` bezieht Privilegien aus `Shopware.Store.get('session').userPrivileges` (Pinia), nicht mehr aus Vuex – nur den Service nutzen.
- `sw-button` ist deprecated; Core nutzt `mt-button` (Meteor, z. B. `sw-manufacturer-list.html.twig`: Props `variant`, `size`, `disabled`).
- `#[Package]` ist Core-intern (`@internal`); Plugins setzen es nicht.
- Vue 3: `:disabled="!acl.can(...) || undefined"` statt `false`-Attribut.

## 6. Checkliste

- [ ] Backend-Schutz (`_acl` bzw. Entity-Privilegien) zusätzlich zum UI-Gating umgesetzt.
- [ ] `acl/index.js` angelegt, importiert; `parent`, `category`, `key` gesetzt, kein Punkt im `key`.
- [ ] `viewer` enthält alle Hilfs-Entitäten, die die Seite lädt.
- [ ] `editor`/`creator`/`deleter` mit `dependencies` auf `viewer`.
- [ ] `meta.privilege` an allen Routen, `privilege` an Navigation/Settings-Item.
- [ ] Buttons/Aktionen per `acl.can(...)` mit `|| undefined` deaktiviert.
- [ ] Snippets `sw-privileges.permissions.<key>.label` (de + en) vorhanden.
- [ ] Mit einer Nicht-Admin-Rolle getestet (nach Neu-Speichern der Rolle).
- [ ] Bestehende Rollen per `enrichPrivileges()` (nur UI-Anzeige) und zusätzlich durch Neu-Speichern bzw. Migration versorgt, falls nötig.

## 7. Quellen

- https://developer.shopware.com/docs/guides/plugins/plugins/administration/permissions-error-handling/add-acl-rules.html
- `https://developer.shopware.com/docs/concepts/framework/acl.html` -> 404, nicht verwendbar.
- Core: `core/Framework/Api/Acl/AclAnnotationValidator`, `AclWriteValidator`, `AclCriteriaValidator`, `Event/AclGetAdditionalPrivilegesEvent`, `Role/AclRoleDefinition`; `core/Framework/Api/Context/AdminApiSource` (`isAllowed`, `DEFAULT_USER_PRIVILEGES`); `core/Framework/Routing/ApiRequestContextResolver`; `core/Framework/Api/Controller/AclController`, `UserController`, `IntegrationController`; `core/PlatformRequest.php` (`ATTRIBUTE_ACL`); `core/Framework/Plugin.php` (`enrichPrivileges`), `Plugin/Subscriber/PluginAclPrivilegesSubscriber`.
- `core/Framework/Webhook/AclPrivilegeCollection` – für Webhook-Filterung, nicht für Routen.
- Admin (`administration/Resources/app/administration/src/`): `app/service/privileges.service.ts`, `app/service/acl.service.ts`, `core/factory/router.factory.js` (Guard), `core/factory/module.factory.ts`, `module/sw-users-permissions/**`, Beispiele `module/sw-manufacturer`, `sw-order/acl`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Doku nennt Snippet-Pfad `sw.privileges.${category}.${key}.${role}`; Code nutzt `sw-privileges.permissions.<key>.label` bzw. `sw-privileges.additional_permissions.<key>.label|<role>` – Code gilt.
- ⚠ LÜCKE: Doku-Beispiel mit `parent: null`; im Code ist `parent` Pflichtschlüssel (Wert `null` erlaubt, Gruppe „Other").
- ⚠ UNGEPRÜFT: Beispiele (Modul, Controller, Plugin) nicht ausgeführt; `routes.php`/`services.php` gegen `Bundle::configureRoutes()` bzw. Core-Muster geprüft, nicht ausgeführt.
- ⚠ UNGEPRÜFT: `enrichPrivileges` mit Schlüsseln außerhalb von Admin-Privilegien nur aus `PluginAclPrivilegesSubscriber` gelesen; Tooltip-Key `sw-privileges.tooltip.warning` im Snippet nicht geprüft.
- ⚠ UNGEPRÜFT: Versionszuordnung der 6.7-Neuerungen nicht per Changelog belegt; Doku-Inhalt per Web-Abruf zusammengefasst; keine alternative Konzeptseite gesucht.
