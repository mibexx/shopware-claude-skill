# Plugin vs. app system: scope (reference, Shopware 6.7.15.1)

## 1. Purpose

Pure delimitation, **not an app guide**. This reference applies when deciding whether a requirement is implemented as a plugin or as an app, or when app terms (app scripts, script hooks, webhooks, action buttons) come up. The skill describes the plugin approach; app mechanisms are only named here and mapped to their plugin equivalent.

Plugin equivalents: events/subscribers [T-07](./07-events-subscriber.md), extension points [T-08](./08-extension-points.md), flow actions/triggers [T-09](./09-flow-builder.md), decoration [T-06](./06-service-decoration.md), Twig blocks [T-14](./14-storefront-twig-templates.md), plugin structure [T-02](./02-plugin-structure.md).

## 2. Concepts

- **Plugin**: PHP code in the Shopware process (Composer, `custom/plugins`), full access to the DI container, DAL, events, migrations, Twig, Admin/Storefront build.
- **App**: `manifest.xml` + declarative resources (+ optionally an external app server). No PHP in the core process; logic lives (a) in the external app server (HTTP, signed) or (b) in **app scripts** (Twig in the core process, sandboxed). An app can also be installed as a Composer package of type `shopware-app` (`AppLoader::COMPOSER_TYPE`).
- **App scripts/script hooks are exclusively an app feature.** The `ScriptLoader` is `@internal only for use by the app-system`; scripts are only written into the `script` table by the `ScriptLifecycleHandler` (also `@internal`) from an app's `Resources/scripts/**/*.twig` (`ScriptFileReader`, `SCRIPT_DIR = '/Resources/scripts'`). There is no script lifecycle in the plugin namespace. Plugins use subscribers instead of hooks.
- **Webhooks** and **action buttons** are manifest concepts (outgoing, signed HTTP calls to the app server). Plugins use events/subscribers directly. (Technically, webhook entities can also be created via the Admin API without an app; `webhook.app_id` is nullable.)

Skeleton comparison:
```
custom/apps/MyApp/                 # AppLoader::loadFromAppDir, depth <= 1
├── manifest.xml                   # Schema: core/Framework/App/Manifest/Schema/manifest-3.0.xsd
└── Resources/
    ├── scripts/<hook-name>/*.twig # Folder name = hook name; api-{hook}, store-api-{hook}, storefront-{hook}; include/ for macros
    └── views/, config/, app/...

custom/plugins/SwagExample/        # Plugin: no scripts folder
├── composer.json
└── src/SwagExample.php, src/Subscriber/, src/Resources/config/services.php, ...
```
The subfolder `rule-conditions/` is not loaded as a hook script.

### Delimitation table

| Criterion | Plugin | App (with/without app server) |
|---|---|---|
| Operation | Code in the shop process (self-hosted/PaaS); ⚠ UNVERIFIED: not freely installable in Shopware Cloud shops | Cloud and self-hosted |
| Components | PHP classes, Composer, migrations | `manifest.xml`, Twig scripts, templates, optional external server |
| Logic in the core process | any PHP | only Twig scripts (sandboxed, facades) |
| Access | DI, DAL, all events, decoration | Script services/facades, webhooks, Admin/Store API with integration (ACL via `<permissions>`) |
| Script hooks | **no** (subscribers, [T-07](./07-events-subscriber.md)) | yes (`Resources/scripts/<hook>/*.twig`) |
| Webhooks | n/a (events/subscribers) | `<webhooks>` in the manifest -> `WebhookDispatcher` |
| Action buttons | no (Admin extension via Vue, [T-24](./24-admin-modules.md)) | `<action-button>` (entities: product, order, category, promotion, customer, cms_page; views: detail, list) |
| Custom tables/entities | Migrations/EntityDefinition ([T-04](./04-database-migrations.md), [T-27](./27-dal-entities.md)) | Custom entities (manifest) |
| Admin UI | Vue components/modules | Meteor Admin Extension SDK / iframe modules (`<admin>`) |
| Payment/shipping/tax | Handler classes ([T-37](./37-payment-integration.md)) | `<payments>`, `<shipping-methods>`, `<tax>` + remote endpoints |
| Lifecycle | install/update/activate/... in the plugin class ([T-03](./03-plugin-lifecycle.md)) | Hooks `app-installed`, `app-updated`, `app-activated`, `app-deactivated`, `app-deleted` as scripts |
| Update coupling | Core updates can break things (internal APIs) | stable contract (manifest/hooks) |
| Choice | full control, performance, deep intervention | marketplace/cloud suitability, isolation, SaaS integration |

## 3. Instructions

Decision guide:
- PHP classes in the core process, migrations, DI decoration, custom entities via DAL or Storefront/Admin build required: **plugin**.
- Cloud compatibility, loose coupling, external system/SaaS as the logic carrier: **app**.
- For this skill, the plugin approach applies.

Same task, two approaches: "Add a variable to the product page". App: script `Resources/scripts/product-page-loaded/<name>.twig` (app only; ⚠ UNVERIFIED: executable Twig content per hook, facade calls not verified). Plugin: subscriber on `ProductPageLoadedEvent`, see [T-07](./07-events-subscriber.md) (`ProductPageSubscriber`); registration with the tag `kernel.event_subscriber`.

CLI (read-only/for orientation):
```
bin/console app:list                  # AppListCommand
bin/console app:validate <path>       # ValidateAppCommand
bin/console debug:event-dispatcher    # Plugin counterpart to hooks (page events)
```
App commands: `app:create`, `app:install`, `app:refresh`, `app:activate`, `app:deactivate`, `app:uninstall`, `app:validate` (`core/Framework/App/Command`).

### Script hooks in 6.7.15.1 (overview, from `HOOK_NAME` constants; for orientation only)

Execution: `ScriptExecutor::execute(Hook)` loads scripts via `ScriptLoader::get($hook->getName())`; Twig globals `services` (facades) and `hook`. A script is active if `script.active = 1 AND (app.id IS NULL OR app.active = 1)` (SQL in `ScriptLoader.php` line 131; a script without an app relation is therefore not blocked by an inactive app), except for lifecycle hooks. The same logic applies to webhooks in `WebhookManager::isEventDispatchingAllowed()` (lines 327-340, `core/Framework/Webhook/Service/WebhookManager.php`): `WebhookLoader` loads `w.active = 1` via `LEFT JOIN app`; without an app (`appId === null`) delivery always happens, with an app only if the app is active (exception: `AppChangedEvent`, `AppDeletedEvent`, `AppPermissionsUpdated`) and has matching ACL permissions.

| Group | Hook names |
|---|---|
| Cart/prices | `cart`, `product-pricing` |
| Reviews/cookies/documents | `product-reviews-widget-loaded`, `cookie-group-collect`, `document-generation` |
| Store API request | `payment-method-route-request`, `shipping-method-route-request` |
| Cache | `cache-invalidation` |
| Custom endpoints | `api-{hook}`, `store-api-{hook}`, `storefront-{hook}`, `response` |
| Lifecycle | `app-installed`, `app-updated`, `app-activated`, `app-deactivated`, `app-deleted` |
| Storefront pages | `product-page-loaded`, `navigation-page-loaded`, `cms-page-loaded`, `landing-page-loaded`, `search-page-loaded`, `search-widget-loaded`, `suggest-page-loaded`, `sitemap-page-loaded`, `maintenance-page-loaded`, `product-quick-view-widget-loaded` |
| Checkout | `checkout-cart-page-loaded`, `checkout-confirm-page-loaded`, `checkout-register-page-loaded`, `checkout-finish-page-loaded`, `checkout-info-widget-loaded`, `checkout-offcanvas-widget-loaded` |
| Account/addresses | `account-login-page-loaded`, `account-guest-login-page-loaded`, `account-register-page-loaded`, `account-profile-page-loaded`, `account-overview-page-loaded`, `account-order-page-loaded`, `account-order-detail-page-loaded`, `account-edit-order-page-loaded`, `account-recover-password-page-loaded`, `customer-group-registration-page-loaded`, `address-listing-page-loaded`, `address-detail-page-loaded`, `address-book-widget-loaded` |
| Wishlist | `wishlist-page-loaded`, `wishlist-widget-loaded`, `guest-wishlist-page-loaded`, `guest-wishlist-pagelet-loaded` |
| Pagelets | `header-pagelet-loaded`, `footer-pagelet-loaded`, `menu-offcanvas-pagelet-loaded`, `country-state-data-pagelet-loaded` |

Storefront hooks extend `Storefront\Page\PageLoadedHook` (`storefront/Page/PageLoadedHook.php`): the script/app counterpart of the `*PageLoadedEvent`s. Facades (`services.<name>`): `cart`, `price`, `repository`, `writer`, `store`, `config`, `request`, `response`, `cache`, `acl`.

Webhooks: manifest `<webhooks><webhook name url event onlyLiveVersion/>`; dispatch by `WebhookDispatcher` (decorates the EventDispatcher) -> `WebhookManager`; only `Hookable` events; delivery asynchronous (outbox/transport), payload signed with the app secret; no response back into the core possible (use scripts or gateways for that: checkout, context, in-app-purchases). Action button: `App/ActionButton/Executor` sends a signed POST to the app URL.

## 4. Examples

Plugin approach for the product page extension (counterpart to the hook `product-page-loaded`):

`src/Subscriber/ProductPageSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductPageSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onLoaded'];
    }

    public function onLoaded(ProductPageLoadedEvent $event): void
    {
        $event->getPage()->addExtension('myInfo', new ArrayStruct(['hello' => 'world']));
    }
}
```
Service registration and scaffold: [T-07](./07-events-subscriber.md), [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Pitfalls

- Expecting script hooks in plugins: there is no loader for them; use subscribers. An entry in the `script` table without `app_id` is conceivable but neither supported nor tested.
- (App side, for orientation) The hook folder name must exactly match the hook's `getName()`; a script can only use services the hook provides (otherwise `ScriptException::scriptExecutionFailed`).
- Misusing webhooks as a synchronous means of intervention: they are asynchronous; the error counter `error_count` deactivates webhooks.
- Script changes not visible in production: the cache `shopware-executable-app-scripts` (`ScriptLoader::CACHE_KEY`) is invalidated on `script.written`/`app.written`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Scripts/hooks since 6.4.8.0 (according to the docs); concept unchanged in 6.7, hook list has grown (⚠ UNVERIFIED: introduction version of e.g. `cookie-group-collect`, `document-generation`, `product-pricing`).
- `@deprecated tag:v6.8.0`: `ScriptResponseFactoryFacade::render()` (instead `StorefrontScriptResponseFactoryFacade` in Storefront hooks), parts of `ResponseCacheConfiguration` (instead `sharedMaxAge`/`clientMaxAge`), the corresponding `ScriptException` factories.
- Webhooks: feature flag `WEBHOOKS_REWORK` (default `false`, experimental; outbox retries instead of Messenger); legacy paths `@deprecated tag:v6.8.0` in `WebhookOutboxStore`, `WebhookEventMessageHandler`, `WebhookDrainToAsyncCommand`.
- Manifest schemas `manifest-2.0.xsd` and `manifest-3.0.xsd` present (differences not checked).

## 6. Checklist

- [ ] Plugin vs. app decision justified using the table (PHP in the core process/migrations/decoration -> plugin)
- [ ] No script hooks/app scripts planned in the plugin; subscribers instead
- [ ] Webhook need: event subscribers in the plugin checked instead
- [ ] Admin buttons in the plugin via Vue extension, not manifest `<action-button>`
- [ ] Customer's cloud requirement clarified (⚠ UNVERIFIED, see gaps)
- [ ] For pure app tasks, referred to the official app documentation instead of improvising here

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/apps/app-scripts/ (covers apps only)
- https://developer.shopware.com/docs/resources/references/app-reference/script-reference/ (`script-hooks-reference.html`, `trigger-events-reference.html`, `webhook-events-reference.html` among others)

Source code (`vendor/shopware/`):
- `core/Framework/Script/Execution/{Hook,ScriptExecutor,ScriptLoader,FunctionHook,InterfaceHook,TraceHook}.php`, `core/Framework/Script/Api/*`
- `core/Framework/App/Lifecycle/{AppLoader,ScriptFileReader,Handler/ScriptLifecycleHandler}.php`
- `core/Framework/App/Manifest/{Manifest,Schema/manifest-3.0.xsd,Xml/Webhook/Webhook,Xml/Administration/ActionButton}.php`, `core/Framework/App/ActionButton/Executor`, `core/Framework/App/Command/*`
- `core/Framework/Webhook/{WebhookDispatcher,WebhookDefinition,Service/WebhookManager}` (+ Outbox, Transport, Authorization)
- `storefront/Page/PageLoadedHook.php`, `storefront/Framework/Script/Api/StorefrontHook`, `storefront/Page/**/*LoadedHook`

### Gaps and uncertainties

- ⚠ UNVERIFIED: "No script support for plugins" is supported by `@internal only for use by the app-system`, its absence in the plugin namespace and the docs; a technical workaround was not tested.
- ⚠ UNVERIFIED: The Shopware Cloud restriction for plugins comes from conceptual knowledge, not confirmed by the research.
- ⚠ UNVERIFIED: Introduction versions of individual hooks, differences between manifest 2.0/3.0, details of the webhook signature.
- ⚠ UNVERIFIED: The Meteor Admin SDK reference (`<admin>` module) is only derived from the manifest directory.
- ⚠ GAP: Facade methods per hook not checked individually; hooks without a `HOOK_NAME` constant (e.g. Store API functions) only named. Test hooks excluded.
- `/docs/guides/plugins/overview.html` not retrieved.
