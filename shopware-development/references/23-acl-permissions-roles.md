# ACL: permissions and roles (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when custom admin modules/routes are secured by role, when a plugin registers its own privileges or extends existing ones, or when it needs to be clarified how users, roles and integrations interact.

Scope: structure of the admin module itself: [T-24 Admin modules](./24-admin-modules.md). Admin API routes: [T-31](./31-admin-api-routes.md). Snippets: [T-18](./18-snippets-translations.md). App permissions (manifest): [T-10](./10-app-system-scope.md). Scaffold: [../templates/admin-module/README.md](../templates/admin-module/README.md).

## 2. Concepts

ACL has **two levels**:

1. **Backend (binding):** `acl_role` contains a flat list of privilege strings (`product:read`, `api_acl_privileges_get`, …). `AdminApiSource::isAllowed()` checks them. Enforced (a) per route via the route default `_acl` (`PlatformRequest::ATTRIBUTE_ACL`, `AclAnnotationValidator`), (b) on DAL write access via the Admin API (`AclWriteValidator`), (c) on read access including associations (`AclCriteriaValidator`: `<entity>:read` is required for each association).
2. **Administration (UI only):** *admin privileges* of the form `<key>.<role>` (`product.viewer`, `.editor`, `.creator`, `.deleter`), registered in JS via `Shopware.Service('privileges').addPrivilegeMappingEntry()`. When the role is saved, they resolve into backend privileges (`getPrivilegesForAdminPrivilegeKeys`). **Both** kinds are stored in `acl_role.privileges`.

UI protection: `meta.privilege` on routes (router guard -> `sw.privilege.error.index`), `privilege` on `navigation`/`settingsItem`, `acl.can('x.editor')` in components/templates. **UI ACL is not security**; real protection only via backend privileges/`_acl`.

- Admin flag: `user.admin = true` or `integration.admin = true` -> `isAllowed()` always `true`; `acl.can()` in JS likewise.
- Users ↔ roles: n:m `acl_user_role`; integrations ↔ roles: n:m `integration_role`. Effective permissions = union of all roles.
- Plugin PHP: `Plugin::enrichPrivileges()` appends backend privileges to existing admin privileges (or `all`) (`PluginAclPrivilegesSubscriber`). This takes effect **only when the role is loaded via the DAL** (the admin UI shows them). The backend check reads `acl_role.privileges` via SQL (`ApiRequestContextResolver::fetchPermissions`) and does not see the enriched values: `_acl` denies until the role is saved again or the privileges are written into `acl_role.privileges` via a migration.

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php                         # enrichPrivileges()
    ├── Controller/ReviewExportController.php   # _acl on Admin API route
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
Core reference: `module/sw-manufacturer/{index.js,acl/index.js}`, `module/sw-order/acl/index.js` (`additional_permissions`), `module/sw-users-permissions/acl/index.js`.

## 3. Instructions

1. Decide: UI gating (admin privilege) and/or backend protection (`_acl`) – plan for both.
2. Create `acl/index.js`, load it in `module/<name>/index.js` via `import './acl'`, import the module in `main.js` (otherwise the registration never runs).
3. Privileges per role: `viewer` = read entities including all auxiliary entities the page loads (sales channels, media, `user_config` …). `editor`/`creator`/`deleter` depend on `viewer` via `dependencies` (recursive resolution).
4. Include other roles: `Shopware.Service('privileges').getPrivileges('media.viewer')` as an element in the `privileges` array.
5. `meta.privilege` on every route, `privilege` on `navigation` and `settingsItem`.
6. In components `inject: ['acl']`; templates `acl.can(...)`; routes programmatically: `acl.hasAccessToRoute('swag.review.index')`.
7. Create snippets for the label (de + en).
8. Provide backend routes with `_acl` (`entity:read|create|update|delete` or custom strings `api_…`).
9. Extend other privileges: `enrichPrivileges()`.
10. Build/test:
    ```bash
    bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
    bin/build-administration.sh
    bin/console cache:clear
    # List of backend privileges (Admin API):
    #  GET /api/_action/acl/privileges              (api_acl_privileges_get)
    #  GET /api/_action/acl/additional_privileges   (api_acl_privileges_additional_get)
    ```
    Test: Settings → Users & permissions → Roles: create a role, tick "Reviews → View", assign a user, log in as that user.

### Users, roles, integrations (`ApiRequestContextResolver::getAdminApiSource`)
- User only: privileges of all assigned roles (`fetchPermissions`) + `AdminApiSource::DEFAULT_USER_PRIVILEGES`; `isAdmin` = `user.admin`.
- Integration only: roles of the integration (`fetchIntegrationPermissions`), `isAdmin` = `integration.admin`.
- User + integration (both set), three cases in this order:
  1. App integration (permissions from the app's `acl_role`, manifest `<permissions>`): `isAdmin=false`; if the user is not an admin, app and user permissions are intersected (`array_intersect`); for an admin user, the app permissions apply unchanged.
  2. Admin integration (`integration.admin=true`): permissions and `isAdmin` come from the **user** (`fetchPermissions` + default user privileges, `isAdmin` = `user.admin`); the integration restricts nothing.
  3. Normal integration: permissions of the integration; for a non-admin user intersected with the user permissions (minimum), for an admin user the integration permissions apply; `isAdmin=false`.
- Creating an integration: `IntegrationController::upsertIntegration` (`_acl: integration:create|update`); only an admin may set the field `admin`. `user.admin` is WriteProtected.
- `acl_role` is locked for generic CRUD via `WriteProtection(SYSTEM_SCOPE, USER_SCOPE)`; create/change via `UserController` (`api.acl_role.create|update|delete`, `_acl: acl_role:create|update|delete`).

## 4. Examples

`src/Resources/app/administration/src/module/swag-review/acl/index.js`
```js
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',          // or 'additional_permissions'
    parent: 'catalogues',             // null => group "Other"; otherwise catalogues|content|customers|marketing|orders|settings
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
            meta: { privilege: 'swag_review.viewer' },   // router guard
        },
    },

    navigation: [{
        id: 'swag-review',
        label: 'swag-review.general.title',
        path: 'swag.review.index',
        parent: 'sw-catalogue',
        position: 100,
        privilege: 'swag_review.viewer',                 // hide menu entry
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

`…/swag-review-list.html.twig` (pattern from `sw-manufacturer-list.html.twig`)
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

Snippet `snippet/en.json` (label key from `sw-users-permissions-permissions-grid.html.twig`: `sw-privileges.permissions.<key>.label`):
```json
{ "sw-privileges": { "permissions": { "swag_review": { "label": "Reviews" } } } }
```
For `additional_permissions`: `sw-privileges.additional_permissions.<key>.label` and `….<key>.<roleName>`.

Backend – protecting a route, `src/Controller/ReviewExportController.php`:
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
`src/Resources/config/routes.php` (`services.xml`/`routes.xml` are deprecated in 6.7, removal v6.8.0.0, `Bundle.php`):
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Controller/**/*Controller.php', 'attribute');
};
```
`services.php`: `$services->set(ReviewExportController::class)->public()->call('setContainer', [service('service_container')]);` - register the controller as a service with `->public()` and `->call('setContainer', [service('service_container')])`; `setContainer()` comes from `AbstractController`, so the controller must extend it, otherwise HTTP 500 "Attempted to call an undefined method named setContainer" (alternatively, without a base class, only `->public()`) ([T-05](./05-registering-services.md), [T-31](./31-admin-api-routes.md)).

Plugin class `src/SwagExample.php`:
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
            // added when the role product.viewer is loaded via the DAL (UI only); for _acl, save the role again or write it into acl_role.privileges via a migration
            'product.viewer' => ['swag_review:read'],
            // 'all' => privilege for ALL roles
        ];
    }
}
```

## 5. Pitfalls

- **Secured only in the UI:** `meta.privilege` without backend privileges/`_acl` is not security. Conversely: a missing backend privilege (`product:read` etc.) -> empty lists or `missing privileges` (`ApiException::missingPrivileges`).
- `acl/index.js` not imported: the role does not appear in the role grid.
- `parent`/`category`/`key` missing: the entry is discarded, only a `warn` in the console (`isPrivilegeMapping`).
- Snippet missing: the label shows the raw key.
- Dot in the `key` or role name: admin privileges are detected by splitting at `.` (`existsPrivilege`, regex `/.+\..+/`).
- The same `category`+`key` registered multiple times is merged (`deepMergeObject`): core entries can be extended, nothing can be removed.
- After changing the mappings, existing roles must be **saved again**, otherwise the new backend privileges are missing from `acl_role.privileges`. For plugins, `enrichPrivileges()` only takes effect on DAL loading (UI), is not persisted and not part of the backend's SQL check: save the role again or write the privileges into `acl_role.privileges` via a migration.
- The `enrichPrivileges()` key must be an entry in `acl_role.privileges` (e.g. `product.viewer`) or `all`.
- `acl.can('')`/empty key -> `true`; wrong key -> always `false` for non-admins.
- Anti-pattern: permission only via `v-if` in the frontend; hardcoding `admin` checks instead of `acl.can`.
- Own custom privileges (`api_…`): PHPStan rules check `AclValidPermissionsHelper::CUSTOM_PERMISSIONS` (only relevant core-internally).

### Deprecations and version notes (6.5/6.6 → 6.7)

- Mechanics (`privileges` service, `acl.can`, `meta.privilege`, `_acl`, `enrichPrivileges`) stable across 6.5–6.7; no deprecation for them found in the 6.7 code.
- Annotation `@Acl` replaced by the route default `_acl`; routes as PHP attribute `#[Route]` (`Symfony\Component\Routing\Attribute\Route`).
- From the code (version assignment not documented): `AdminApiSource::DEFAULT_USER_PRIVILEGES` (among others `increment:manage`, `system:queue:process`), `acl_role` write protection, `admin` flag only via controller, `mcp_allowlist` on integrations (privilege `api_action_user_mcp-allowlist`, see [T-44](./44-mcp-server.md)), SSO components.
- `Shopware.Service('acl')` gets privileges from `Shopware.Store.get('session').userPrivileges` (Pinia), no longer from Vuex – use only the service.
- `sw-button` is deprecated; core uses `mt-button` (Meteor, e.g. `sw-manufacturer-list.html.twig`: props `variant`, `size`, `disabled`).
- `#[Package]` is core-internal (`@internal`); plugins do not set it.
- Vue 3: `:disabled="!acl.can(...) || undefined"` instead of a `false` attribute.

## 6. Checklist

- [ ] Backend protection (`_acl` or entity privileges) implemented in addition to UI gating.
- [ ] `acl/index.js` created, imported; `parent`, `category`, `key` set, no dot in the `key`.
- [ ] `viewer` contains all auxiliary entities the page loads.
- [ ] `editor`/`creator`/`deleter` with `dependencies` on `viewer`.
- [ ] `meta.privilege` on all routes, `privilege` on navigation/settings item.
- [ ] Buttons/actions disabled via `acl.can(...)` with `|| undefined`.
- [ ] Snippets `sw-privileges.permissions.<key>.label` (de + en) present.
- [ ] Tested with a non-admin role (after saving the role again).
- [ ] Existing roles supplied via `enrichPrivileges()` (UI display only) and additionally by saving again or via migration, if needed.

## 7. Sources

- https://developer.shopware.com/docs/guides/plugins/plugins/administration/permissions-error-handling/add-acl-rules.html
- `https://developer.shopware.com/docs/concepts/framework/acl.html` -> 404, not usable.
- Core: `core/Framework/Api/Acl/AclAnnotationValidator`, `AclWriteValidator`, `AclCriteriaValidator`, `Event/AclGetAdditionalPrivilegesEvent`, `Role/AclRoleDefinition`; `core/Framework/Api/Context/AdminApiSource` (`isAllowed`, `DEFAULT_USER_PRIVILEGES`); `core/Framework/Routing/ApiRequestContextResolver`; `core/Framework/Api/Controller/AclController`, `UserController`, `IntegrationController`; `core/PlatformRequest.php` (`ATTRIBUTE_ACL`); `core/Framework/Plugin.php` (`enrichPrivileges`), `Plugin/Subscriber/PluginAclPrivilegesSubscriber`.
- `core/Framework/Webhook/AclPrivilegeCollection` – for webhook filtering, not for routes.
- Admin (`administration/Resources/app/administration/src/`): `app/service/privileges.service.ts`, `app/service/acl.service.ts`, `core/factory/router.factory.js` (guard), `core/factory/module.factory.ts`, `module/sw-users-permissions/**`, examples `module/sw-manufacturer`, `sw-order/acl`.

### Gaps and uncertainties

- ⚠ GAP: the docs name the snippet path `sw.privileges.${category}.${key}.${role}`; the code uses `sw-privileges.permissions.<key>.label` or `sw-privileges.additional_permissions.<key>.label|<role>` – code takes precedence.
- ⚠ GAP: docs example with `parent: null`; in the code `parent` is a required key (value `null` allowed, group "Other").
- ⚠ UNVERIFIED: examples (module, controller, plugin) not run; `routes.php`/`services.php` checked against `Bundle::configureRoutes()` or core patterns, not run.
- ⚠ UNVERIFIED: `enrichPrivileges` with keys outside admin privileges only read from `PluginAclPrivilegesSubscriber`; tooltip key `sw-privileges.tooltip.warning` not checked in the snippet.
- ⚠ UNVERIFIED: version assignment of the 6.7 changes not documented via changelog; docs content summarized via web retrieval; no alternative concept page searched for.
