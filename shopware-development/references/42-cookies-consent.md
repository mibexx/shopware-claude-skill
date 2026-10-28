# Cookies and consent (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin should register its own cookies in the Storefront cookie manager (cookie banner/offcanvas) or react to visitor consent via JavaScript. It also delimits the experimental admin consent system (`core/System/Consent`).

Scope: Storefront JS plugins in general in [T-15](./15-storefront-js-plugins.md); snippets in [T-18](./18-snippets-translations.md); Twig blocks in [T-14](./14-storefront-twig-templates.md); event listeners in [T-07](./07-events-subscriber.md); HTTP cache and cookies in the cache hash in [T-41](./41-caching.md); apps (manifest cookies) only in [T-10](./10-app-system-scope.md).

## 2. Concepts

- **Two completely different systems:**
  1. **Storefront cookie manager** (visitors): `core/Content/Cookie` + `storefront/Framework/Cookie` + JS `storefront/Resources/app/storefront/src/plugin/cookie/`.
  2. **`core/System/Consent`** (new): admin consent management (e.g. usage data/Product Analytics), only for admin users/system, `@internal`, README: "Experimental … internal use only … may change without backward compatibility". Not for Storefront cookies and not a stable plugin API.
- **Registering your own cookies (6.7):** listener on `Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent` (tag `kernel.event_listener`). The event carries `$event->cookieGroupCollection` (`CookieGroupCollection`, key = `technicalName`); inside it `CookieGroup`/`CookieEntry` structs (`CookieEntryCollection` indexed by `CookieEntry::$cookie`, verified against the source code).
- **Successor of `CookieProviderInterface`**: `Shopware\Storefront\Framework\Cookie\CookieProviderInterface` is `@deprecated tag:v6.8.0` (service deprecated since 6.7.3.0).
- **Group constants** in `Shopware\Core\Content\Cookie\Service\CookieProvider` (the class is `@internal`; only use the public constants as a reference, do not extend or decorate it): `SNIPPET_NAME_COOKIE_GROUP_REQUIRED` (`cookie.groupRequired`), `…_STATISTICAL`, `…_COMFORT_FEATURES`, `…_MARKETING`.
- **JS reaction:** `document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, cb)` (`'CookieConfiguration_Update'`); `event.detail` is an object `{ '<cookie-name>': true|false }` containing only changed cookies. The current state is read by `CookieStorageHelper.getItem(name)`.
- **Other ways:** app manifest `<cookies>` (`AppCookieCollectListener`), scripting hook `cookie-group-collect` (since 6.7.14.0), Store API `GET /store-api/cookie-groups` (`CookieRoute`, `@experimental stableVersion:v6.8.0 feature:COOKIE_GROUPS_STORE_API`; this is only the annotation: there is no flag `COOKIE_GROUPS_STORE_API` in `feature.yaml`, the route comes without a BC guarantee).

Plugin tree:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Cookie/CookieGroupCollectSubscriber.php
    └── Resources/
        ├── config/services.php
        ├── snippet/{storefront.de.json,storefront.en.json}
        ├── views/storefront/base.html.twig
        └── app/storefront/src/
            ├── main.js
            └── plugin/cookie-consent-demo/cookie-consent-demo.plugin.js
```

Locations in the core (relative to `vendor/shopware/`): `core/Content/Cookie/{Event,Hook,Service,Struct,SalesChannel}`, `core/Framework/App/Cookie/AppCookieCollectListener.php`, `storefront/Framework/Captcha/CaptchaCookieCollectListener.php` (reference listener), `storefront/Controller/CookieController.php` (`/cookie/offcanvas`, `/cookie/permission`, `/cookie/consent-offcanvas`, `/cookie/groups`), `storefront/Resources/views/storefront/layout/cookie/*.html.twig`.

## 3. Instructions

**Registering a cookie**
1. Create a listener class with `__invoke(CookieGroupCollectEvent $event)`.
2. Register it as a service with tag `kernel.event_listener` (core model: `storefront/DependencyInjection/captcha.php`, `CaptchaCookieCollectListener`).
3. Get an existing group (`$event->cookieGroupCollection->get(<technicalName>)`) or create a new `CookieGroup`.
4. Fill `CookieEntry($cookieName)` with `name`, `description`, `value`, `expiration` (days), optionally `hidden`.
5. Provide snippets (`storefront.*.json`, keys `cookie.*`); `CookieProvider` translates `name`/`description` via the translator (`translateCookieGroupsAndTheirEntries`). Already translated text is allowed as well. File names and snippet rules: [T-18](./18-snippets-translations.md).
6. Empty groups without entries are removed automatically (`removeCookieGroupsWithoutCookies`), unless the group itself is a cookie.

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear
bin/build-storefront.sh            # or bin/console theme:compile  (after JS changes)
curl -s https://<shop>/cookie/groups | jq .     # check delivered groups
```

**Group as its own cookie (instead of entries):** `$group->setCookie('name')` + `$group->value`/`$group->expiration`. Cannot be combined with `setEntries()`: throws `CookieException::notAllowedPropertyAssignment`.

**Hash and re-prompting:** `CookieRoute::getCookieGroups()` computes a hash (groups sorted by `technicalName`, entries by cookie name) and sets it as the value of the cookie `cookie-config-hash`. If the configuration changes (e.g. a new cookie), the hash differs from the visitor's cookie and the cookie bar appears again (JS: `options.cookieConfigHash` in `cookie-configuration.plugin.js`).

**JS reaction**
1. Import `COOKIE_CONFIGURATION_UPDATE` and subscribe on `document.$emitter` (not `addEventListener`).
2. `event.detail`: map `cookieName -> boolean`. On the first "Accept all" (lastState empty) **all** cookies are included, later only changes (`_getUpdatedCookies`).
3. Check the initial state on load with `CookieStorageHelper.getItem()`: the event does not fire on page load when cookies are already set. Models: `google-analytics.plugin.js` (`handleCookies`), `cms-gdpr-video-element.plugin.js`.
4. Another event: `COOKIE_CONFIGURATION_CLOSE_OFF_CANVAS` (same file).
5. Opening the banner manually: link to route `frontend.cookie.offcanvas` (`customLinkSelector`) or button `.js-cookie-configuration-button button`.

**Alternatives**
- App manifest `<cookies>` (`manifest-3.0.xsd`), processed by `Shopware\Core\Framework\App\Cookie\AppCookieCollectListener` (also a listener on `CookieGroupCollectEvent`).
- Scripting hook `cookie-group-collect` (`CookieGroupCollectHook`, `@since 6.7.14.0`; facades Repository/SystemConfig).
- **Legacy (deprecated):** `CookieProviderInterface::getCookieGroups()` with array schema (`snippet_name`, `cookie`, `value`, `expiration`, `entries[]`, `isRequired`, `hidden`). Still evaluated in 6.7: `CookieProvider::getCookieGroups()` (core) uses `convertLegacyCookies()` as long as the flag `v6.8.0.0` is not active and the legacy provider exists.

**`core/System/Consent` (for delimitation only)**
- Defining/querying/accepting/revoking consents (table `consent_state`); does not perform any actions itself. Scopes `AdminUser`, `System`; states `unset|accepted|declined|revoked`; bundled consents `BackendData` (`system`), `ProductAnalytics` (`admin_user`, permission `user.update_profile`).
- PHP: `Service\ConsentService` (`list`, `getConsentState`, `acceptConsent`, `revokeConsent`, `reset`). Admin API (`auth_required`): `GET /api/consents`, `POST /api/consents/accept`, `POST /api/consents/revoke`. Events `ConsentAcceptedEvent`/`ConsentRevokedEvent`. Admin JS: `administration/Resources/app/administration/src/core/consent/`.
- Extension according to the README: definition as a service with tag `shopware.consent.definition`, scope with tag `shopware.consent.scope`, but `@internal`/experimental.
- No relation to Storefront cookies: Storefront consent runs exclusively via browser cookies (`cookie-preference` and entries); `ConsentService` is not used there.

## 4. Examples

`src/Cookie/CookieGroupCollectSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Cookie;

use Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent;
use Shopware\Core\Content\Cookie\Service\CookieProvider;
use Shopware\Core\Content\Cookie\Struct\CookieEntry;
use Shopware\Core\Content\Cookie\Struct\CookieEntryCollection;
use Shopware\Core\Content\Cookie\Struct\CookieGroup;

class CookieGroupCollectSubscriber
{
    public function __invoke(CookieGroupCollectEvent $event): void
    {
        $entry = new CookieEntry('swag-demo-tracking');        // cookie name
        $entry->name = 'cookie.swagDemoTracking';               // snippet key or final text
        $entry->description = 'cookie.swagDemoTrackingDescription';
        $entry->value = '1';                                    // set on acceptance
        $entry->expiration = 30;                                // days

        $group = $event->cookieGroupCollection->get(CookieProvider::SNIPPET_NAME_COOKIE_GROUP_MARKETING);

        if ($group === null) {                                  // group may have been removed/filtered out as empty
            $group = new CookieGroup(CookieProvider::SNIPPET_NAME_COOKIE_GROUP_MARKETING);
            $group->setEntries(new CookieEntryCollection());
            $event->cookieGroupCollection->add($group);
        }

        $entries = $group->getEntries();
        if ($entries === null) {
            $entries = new CookieEntryCollection();
            $group->setEntries($entries);
        }

        $entries->add($entry);
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Cookie\CookieGroupCollectSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(CookieGroupCollectSubscriber::class)
        ->tag('kernel.event_listener');   // __invoke + type hint is sufficient, as in core (captcha.php)
};
```

`src/Resources/snippet/storefront.de.json`
```json
{ "cookie": { "swagDemoTracking": "Demo-Tracking", "swagDemoTrackingDescription": "Misst die Nutzung des Demo-Features." } }
```

`src/Resources/app/storefront/src/plugin/cookie-consent-demo/cookie-consent-demo.plugin.js`
```js
import Plugin from 'src/plugin-system/plugin.class';
import CookieStorageHelper from 'src/helper/storage/cookie-storage.helper';
import { COOKIE_CONFIGURATION_UPDATE } from 'src/plugin/cookie/cookie-configuration.plugin';

export default class CookieConsentDemoPlugin extends Plugin {
    static options = { cookieName: 'swag-demo-tracking' };

    init() {
        // Consent already given (page load)
        if (CookieStorageHelper.getItem(this.options.cookieName)) {
            this._start();
        }
        // Changes at runtime; detail contains only CHANGED cookies
        document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, this._onUpdate.bind(this));
    }

    _onUpdate(event) {
        const state = event.detail[this.options.cookieName];
        if (state === undefined) { return; }       // unchanged
        state ? this._start() : this._stop();
    }

    _start() { /* start script/tracking */ }
    _stop()  { /* stop tracking, remove own cookies */ }
}
```

`src/Resources/app/storefront/src/main.js`
```js
import CookieConsentDemoPlugin from './plugin/cookie-consent-demo/cookie-consent-demo.plugin';

window.PluginManager.register('CookieConsentDemo', CookieConsentDemoPlugin, '[data-cookie-consent-demo]');
```

`src/Resources/views/storefront/base.html.twig` (block `base_body_script` exists in `storefront/base.html.twig`, verified against the source code)
```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_body_script %}
    {{ parent() }}
    <div data-cookie-consent-demo></div>
{% endblock %}
```
Alternatively without a selector element: `PluginManager.register('CookieConsentDemo', Cls, 'body')`. Plugin registration and build: [T-15](./15-storefront-js-plugins.md). Further scaffolds: [Storefront JS plugin](../templates/storefront-js-plugin/README.md), [Event subscriber](../templates/event-subscriber/README.md).

## 5. Pitfalls

- `CookieProviderInterface` for new plugins: deprecated, removed in 6.8. Use `CookieGroupCollectEvent` instead.
- Listener without tag `kernel.event_listener`: is not called.
- Group cookie **and** entries on the same `CookieGroup`: `CookieException`.
- Empty group expected to be visible: it is removed.
- Cookie registered without `value`/`expiration`, but expected to be set on acceptance: the manager then does not set it itself (derived from the legacy interface, assumed analogously for the new structs).
- Tracking script loads regardless of consent, or only the event is subscribed but the initial state is not checked.
- Treating `event.detail` as a list; confusing `undefined` (unchanged) with `false` (declined).
- Hard-coded texts instead of `cookie.*` snippets: no translation.
- Classifying your own cookies as technically required (`isRequired`) although they are not (GDPR).
- Using `core/System/Consent` for Storefront consent: wrong scope (admin consent).
- Clear the cache after listener changes; JS changes require a Storefront build.

### Deprecations and version notes (6.5/6.6 → 6.7)

| Topic | 6.5/6.6 | 6.7.15.1 |
|---|---|---|
| Registering a cookie | service with tag `shopware.cookie_provider` (interface `CookieProviderInterface`, array schema); ⚠ UNVERIFIED, from prior knowledge | `CookieGroupCollectEvent`; interface `@deprecated tag:v6.8.0`, service deprecation since **6.7.3.0** |
| Default provider | `Shopware\Storefront\Framework\Cookie\CookieProvider` | additionally core `Shopware\Core\Content\Cookie\Service\CookieProvider` (`@internal`) |
| Apps | `AppCookieProvider` (decorator) | `AppCookieCollectListener` (core); `AppCookieProvider` deprecated "without replacement" |
| Cookie groups API | | `GET /store-api/cookie-groups` (`@experimental stableVersion:v6.8.0`; annotation names `feature:COOKIE_GROUPS_STORE_API`, no such flag exists in `feature.yaml`) |
| Scripting hook | | `cookie-group-collect` since 6.7.14.0 |
| JS | `COOKIE_CONFIGURATION_UPDATE` | unchanged |
| `core/System/Consent` | not present | new, experimental, admin only |
| 6.8 (outlook) | | `convertLegacyCookies` and the interface are dropped (`Feature::isActive('v6.8.0.0')`) |

## 6. Checklist

- [ ] Own cookies via `CookieGroupCollectEvent`, not via the deprecated `CookieProviderInterface`
- [ ] Listener registered with tag `kernel.event_listener`
- [ ] Suitable group chosen (constants from `CookieProvider`), cookie not wrongly classified as required
- [ ] `name`/`description` provided as snippets (`cookie.*`) in all languages
- [ ] `value`/`expiration` set if the manager should set the cookie
- [ ] JS checks the initial state via `CookieStorageHelper.getItem()` and subscribes to `COOKIE_CONFIGURATION_UPDATE` on `document.$emitter`
- [ ] `event.detail[cookieName] === undefined` is treated as "unchanged"
- [ ] Storefront build and cache clear after changes
- [ ] No use of `core/System/Consent` for visitor consent

## 7. Sources

Docs (⚠ not fetched live, paths from the plan):
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/add-cookie-to-manager.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/reacting-to-cookie-consent-changes.html

Source code (relative to `vendor/shopware/`):
- `core/Content/Cookie/Event/CookieGroupCollectEvent.php`, `Hook/CookieGroupCollectHook.php`, `Service/CookieProvider.php` (`getCookieGroups`, `convertLegacyCookies`, constants), `Struct/{CookieGroup,CookieEntry,CookieGroupCollection,CookieEntryCollection}.php`, `SalesChannel/CookieRoute.php`, `CookieException.php`
- `core/Framework/App/Cookie/AppCookieCollectListener.php`, `core/Framework/App/Manifest/Xml/Cookie/Cookies.php`
- `storefront/Framework/Cookie/{CookieProviderInterface,CookieProvider,AppCookieProvider}.php` (deprecated), `storefront/DependencyInjection/{services.php,captcha.php}`, `storefront/Framework/Captcha/CaptchaCookieCollectListener.php`, `storefront/Controller/CookieController.php`
- `storefront/Resources/app/storefront/src/plugin/cookie/cookie-configuration.plugin.js`, `helper/storage/cookie-storage.helper.js`, `plugin/google-analytics/google-analytics.plugin.js`, `plugin/cms-gdpr-video-element/cms-gdpr-video-element.plugin.js`
- `storefront/Resources/views/storefront/base.html.twig` (block `base_body_script`)
- `core/System/Consent/` (README, `ConsentService`, `ConsentController`, `Definition/*`), `core/System/DependencyInjection/consent.php`, `administration/Resources/app/administration/src/core/consent/`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Docs pages not fetched live; they probably still show the old `CookieProviderInterface` pattern (tag `shopware.cookie_provider`). The code is authoritative.
- ⚠ UNVERIFIED: 6.5/6.6 statements come from prior knowledge, not from the installed code. Examples were not executed (modeled on `CaptchaCookieCollectListener`).
- ⚠ UNVERIFIED: Whether plugin loading loads `services.php` alongside XML: `Bundle::registerContainerFile` loads `services.*` via XML/YAML/PHP loaders (XML deprecated); not tested separately.
- ⚠ GAP: Behavior of "cookie without `value`" and the exact hash check in JS (`cookieConfigHash`) not read in full detail.
- Confirmed: A feature flag `COOKIE_GROUPS_STORE_API` exists neither in `feature.yaml` nor in the code (`Feature::isActive`); it only appears in the `@experimental` annotation of `CookieRoute`/`AbstractCookieRoute`.
- ⚠ GAP: Whether `core/System/Consent` will take over Storefront consent in the future: not confirmed (experimental, `@internal`, without BC guarantee).
