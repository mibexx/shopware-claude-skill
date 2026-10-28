# Cookies und Consent (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eigene Cookies im Storefront-Cookie-Manager (Cookie-Banner/-Offcanvas) registrieren oder per JavaScript auf die Zustimmung der Besucher reagieren soll. Sie grenzt zugleich das experimentelle Admin-Consent-System (`core/System/Consent`) ab.

Abgrenzung: Storefront-JS-Plugins allgemein in [T-15](./15-storefront-js-plugins.md); Snippets in [T-18](./18-snippets-uebersetzungen.md); Twig-Blöcke in [T-14](./14-storefront-twig-templates.md); Event-Listener in [T-07](./07-events-subscriber.md); HTTP-Cache und Cookies im Cache-Hash in [T-41](./41-caching.md); Apps (Manifest-Cookies) nur in [T-10](./10-app-system-abgrenzung.md).

## 2. Konzepte

- **Zwei völlig verschiedene Systeme:**
  1. **Storefront-Cookie-Manager** (Besucher): `core/Content/Cookie` + `storefront/Framework/Cookie` + JS `storefront/Resources/app/storefront/src/plugin/cookie/`.
  2. **`core/System/Consent`** (neu): Admin-Consent-Verwaltung (z. B. Nutzungsdaten/Product Analytics), nur für Admin-User/System, `@internal`, README: "Experimental … internal use only … may change without backward compatibility". Nicht für Storefront-Cookies und keine stabile Plugin-API.
- **Eigene Cookies registrieren (6.7):** Listener auf `Shopware\Core\Content\Cookie\Event\CookieGroupCollectEvent` (Tag `kernel.event_listener`). Der Event trägt `$event->cookieGroupCollection` (`CookieGroupCollection`, Key = `technicalName`); darin `CookieGroup`/`CookieEntry`-Structs (`CookieEntryCollection` indiziert nach `CookieEntry::$cookie`, am Quellcode verifiziert).
- **Nachfolger von `CookieProviderInterface`**: `Shopware\Storefront\Framework\Cookie\CookieProviderInterface` ist `@deprecated tag:v6.8.0` (Service seit 6.7.3.0 deprecated).
- **Gruppenkonstanten** in `Shopware\Core\Content\Cookie\Service\CookieProvider` (Klasse ist `@internal`, nur die öffentlichen Konstanten als Referenz nutzen, nicht erweitern oder dekorieren): `SNIPPET_NAME_COOKIE_GROUP_REQUIRED` (`cookie.groupRequired`), `…_STATISTICAL`, `…_COMFORT_FEATURES`, `…_MARKETING`.
- **JS-Reaktion:** `document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, cb)` (`'CookieConfiguration_Update'`); `event.detail` ist ein Objekt `{ '<cookie-name>': true|false }` nur mit geänderten Cookies. Aktuellen Stand liest `CookieStorageHelper.getItem(name)`.
- **Weitere Wege:** App-Manifest `<cookies>` (`AppCookieCollectListener`), Scripting-Hook `cookie-group-collect` (seit 6.7.14.0), Store-API `GET /store-api/cookie-groups` (`CookieRoute`, `@experimental stableVersion:v6.8.0 feature:COOKIE_GROUPS_STORE_API`; das ist nur die Annotation: ein Flag `COOKIE_GROUPS_STORE_API` gibt es in `feature.yaml` nicht, die Route ist ohne BC-Garantie).

Plugin-Baum:
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

Fundstellen im Core (relativ zu `vendor/shopware/`): `core/Content/Cookie/{Event,Hook,Service,Struct,SalesChannel}`, `core/Framework/App/Cookie/AppCookieCollectListener.php`, `storefront/Framework/Captcha/CaptchaCookieCollectListener.php` (Referenz-Listener), `storefront/Controller/CookieController.php` (`/cookie/offcanvas`, `/cookie/permission`, `/cookie/consent-offcanvas`, `/cookie/groups`), `storefront/Resources/views/storefront/layout/cookie/*.html.twig`.

## 3. Anleitung

**Cookie registrieren**
1. Listener-Klasse mit `__invoke(CookieGroupCollectEvent $event)` anlegen.
2. Als Service mit Tag `kernel.event_listener` registrieren (Core-Vorbild: `storefront/DependencyInjection/captcha.php`, `CaptchaCookieCollectListener`).
3. Bestehende Gruppe holen (`$event->cookieGroupCollection->get(<technicalName>)`) oder neue `CookieGroup` anlegen.
4. `CookieEntry($cookieName)` mit `name`, `description`, `value`, `expiration` (Tage), optional `hidden` füllen.
5. Snippets (`storefront.*.json`, Schlüssel `cookie.*`) hinterlegen; `CookieProvider` übersetzt `name`/`description` über den Translator (`translateCookieGroupsAndTheirEntries`). Bereits übersetzter Text ist ebenfalls erlaubt. Dateinamen und Snippet-Regeln: [T-18](./18-snippets-uebersetzungen.md).
6. Leere Gruppen ohne Einträge werden automatisch entfernt (`removeCookieGroupsWithoutCookies`), außer die Gruppe ist selbst ein Cookie.

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear
bin/build-storefront.sh            # bzw. bin/console theme:compile  (nach JS-Änderungen)
curl -s https://<shop>/cookie/groups | jq .     # ausgelieferte Gruppen prüfen
```

**Gruppe als eigenes Cookie (statt Entries):** `$group->setCookie('name')` + `$group->value`/`$group->expiration`. Nicht kombinierbar mit `setEntries()`: wirft `CookieException::notAllowedPropertyAssignment`.

**Hash und erneute Abfrage:** `CookieRoute::getCookieGroups()` berechnet einen Hash (Gruppen sortiert nach `technicalName`, Entries nach Cookie-Name) und setzt ihn als Wert des Cookies `cookie-config-hash`. Ändert sich die Konfiguration (z. B. neues Cookie), weicht der Hash vom Besucher-Cookie ab und die Cookie-Leiste erscheint erneut (JS: `options.cookieConfigHash` in `cookie-configuration.plugin.js`).

**JS-Reaktion**
1. `COOKIE_CONFIGURATION_UPDATE` importieren und auf `document.$emitter` subscriben (nicht `addEventListener`).
2. `event.detail`: Map `cookieName -> boolean`. Beim ersten "Alle akzeptieren" (lastState leer) kommen **alle** Cookies, später nur Änderungen (`_getUpdatedCookies`).
3. Initialzustand beim Laden mit `CookieStorageHelper.getItem()` prüfen: Das Event feuert nicht beim Seitenaufruf mit bereits gesetzten Cookies. Vorbilder: `google-analytics.plugin.js` (`handleCookies`), `cms-gdpr-video-element.plugin.js`.
4. Weiteres Event: `COOKIE_CONFIGURATION_CLOSE_OFF_CANVAS` (gleiche Datei).
5. Banner manuell öffnen: Link auf Route `frontend.cookie.offcanvas` (`customLinkSelector`) oder Button `.js-cookie-configuration-button button`.

**Alternativen**
- App-Manifest `<cookies>` (`manifest-3.0.xsd`), verarbeitet von `Shopware\Core\Framework\App\Cookie\AppCookieCollectListener` (ebenfalls Listener auf `CookieGroupCollectEvent`).
- Scripting-Hook `cookie-group-collect` (`CookieGroupCollectHook`, `@since 6.7.14.0`; Facades Repository/SystemConfig).
- **Legacy (deprecated):** `CookieProviderInterface::getCookieGroups()` mit Array-Schema (`snippet_name`, `cookie`, `value`, `expiration`, `entries[]`, `isRequired`, `hidden`). In 6.7 noch ausgewertet: `CookieProvider::getCookieGroups()` (Core) nutzt, solange das Flag `v6.8.0.0` nicht aktiv ist und der Legacy-Provider existiert, `convertLegacyCookies()`.

**`core/System/Consent` (nur zur Abgrenzung)**
- Definieren/abfragen/akzeptieren/widerrufen von Consents (Tabelle `consent_state`); führt selbst keine Aktionen aus. Scopes `AdminUser`, `System`; Status `unset|accepted|declined|revoked`; mitgelieferte Consents `BackendData` (`system`), `ProductAnalytics` (`admin_user`, Permission `user.update_profile`).
- PHP: `Service\ConsentService` (`list`, `getConsentState`, `acceptConsent`, `revokeConsent`, `reset`). Admin-API (`auth_required`): `GET /api/consents`, `POST /api/consents/accept`, `POST /api/consents/revoke`. Events `ConsentAcceptedEvent`/`ConsentRevokedEvent`. Admin-JS: `administration/Resources/app/administration/src/core/consent/`.
- Erweiterung laut README: Definition als Service mit Tag `shopware.consent.definition`, Scope mit Tag `shopware.consent.scope`, aber `@internal`/experimentell.
- Keine Beziehung zu Storefront-Cookies: Storefront-Consent läuft ausschließlich über Browser-Cookies (`cookie-preference` und Einträge); `ConsentService` wird dort nicht genutzt.

## 4. Beispiele

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
        $entry = new CookieEntry('swag-demo-tracking');        // Cookie-Name
        $entry->name = 'cookie.swagDemoTracking';               // Snippet-Key oder fertiger Text
        $entry->description = 'cookie.swagDemoTrackingDescription';
        $entry->value = '1';                                    // wird beim Akzeptieren gesetzt
        $entry->expiration = 30;                                // Tage

        $group = $event->cookieGroupCollection->get(CookieProvider::SNIPPET_NAME_COOKIE_GROUP_MARKETING);

        if ($group === null) {                                  // Gruppe kann entfernt/leer gefiltert sein
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
        ->tag('kernel.event_listener');   // __invoke + Typ-Hint genügt, wie in Core (captcha.php)
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
        // Bereits erteilte Zustimmung (Seitenaufruf)
        if (CookieStorageHelper.getItem(this.options.cookieName)) {
            this._start();
        }
        // Änderungen zur Laufzeit; detail enthält nur GEÄNDERTE Cookies
        document.$emitter.subscribe(COOKIE_CONFIGURATION_UPDATE, this._onUpdate.bind(this));
    }

    _onUpdate(event) {
        const state = event.detail[this.options.cookieName];
        if (state === undefined) { return; }       // unverändert
        state ? this._start() : this._stop();
    }

    _start() { /* Skript/Tracking starten */ }
    _stop()  { /* Tracking beenden, eigene Cookies entfernen */ }
}
```

`src/Resources/app/storefront/src/main.js`
```js
import CookieConsentDemoPlugin from './plugin/cookie-consent-demo/cookie-consent-demo.plugin';

window.PluginManager.register('CookieConsentDemo', CookieConsentDemoPlugin, '[data-cookie-consent-demo]');
```

`src/Resources/views/storefront/base.html.twig` (Block `base_body_script` existiert in `storefront/base.html.twig`, am Quellcode verifiziert)
```twig
{% sw_extends '@Storefront/storefront/base.html.twig' %}

{% block base_body_script %}
    {{ parent() }}
    <div data-cookie-consent-demo></div>
{% endblock %}
```
Alternativ ohne Selektor-Element: `PluginManager.register('CookieConsentDemo', Cls, 'body')`. Plugin-Registrierung und Build: [T-15](./15-storefront-js-plugins.md). Weitere Gerüste: [Storefront-JS-Plugin](../templates/storefront-js-plugin/README.md), [Event-Subscriber](../templates/event-subscriber/README.md).

## 5. Fallstricke

- `CookieProviderInterface` für neue Plugins: deprecated, in 6.8 entfernt. Stattdessen `CookieGroupCollectEvent`.
- Listener ohne Tag `kernel.event_listener`: wird nicht aufgerufen.
- Gruppe **und** Entries an derselben `CookieGroup`: `CookieException`.
- Leere Gruppe erwartet sichtbar: wird entfernt.
- Cookie ohne `value`/`expiration` registriert, aber Setzen beim Akzeptieren erwartet: Der Manager setzt es dann nicht selbst (aus dem Legacy-Interface abgeleitet, für neue Structs analog angenommen).
- Tracking-Skript lädt unabhängig vom Consent, oder nur das Event abonniert, aber den Initialzustand nicht geprüft.
- `event.detail` als Liste behandeln; `undefined` (unverändert) mit `false` (abgelehnt) verwechseln.
- Hartcodierte Texte statt Snippets `cookie.*`: keine Übersetzung.
- Eigene Cookies als technisch notwendig (`isRequired`) einordnen, obwohl nicht notwendig (DSGVO).
- `core/System/Consent` für Storefront-Einwilligungen nutzen: falscher Scope (Admin-Consent).
- Nach Listener-Änderung Cache leeren; JS-Änderungen erfordern Storefront-Build.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

| Thema | 6.5/6.6 | 6.7.15.1 |
|---|---|---|
| Cookie registrieren | Service mit Tag `shopware.cookie_provider` (Interface `CookieProviderInterface`, Array-Schema); ⚠ UNGEPRÜFT, aus Vorwissen | `CookieGroupCollectEvent`; Interface `@deprecated tag:v6.8.0`, Service-Deprecation seit **6.7.3.0** |
| Standardprovider | `Shopware\Storefront\Framework\Cookie\CookieProvider` | zusätzlich Core `Shopware\Core\Content\Cookie\Service\CookieProvider` (`@internal`) |
| Apps | `AppCookieProvider` (Decorator) | `AppCookieCollectListener` (Core); `AppCookieProvider` deprecated "ohne Ersatz" |
| Cookie-Gruppen-API | | `GET /store-api/cookie-groups` (`@experimental stableVersion:v6.8.0`; Annotation nennt `feature:COOKIE_GROUPS_STORE_API`, ein solches Flag existiert in `feature.yaml` nicht) |
| Scripting-Hook | | `cookie-group-collect` seit 6.7.14.0 |
| JS | `COOKIE_CONFIGURATION_UPDATE` | unverändert |
| `core/System/Consent` | nicht vorhanden | neu, experimentell, nur Admin |
| 6.8 (Ausblick) | | `convertLegacyCookies` und Interface entfallen (`Feature::isActive('v6.8.0.0')`) |

## 6. Checkliste

- [ ] Eigene Cookies über `CookieGroupCollectEvent`, nicht über das deprecated `CookieProviderInterface`
- [ ] Listener mit Tag `kernel.event_listener` registriert
- [ ] Passende Gruppe gewählt (Konstanten aus `CookieProvider`), Cookie nicht fälschlich als notwendig eingestuft
- [ ] `name`/`description` als Snippets (`cookie.*`) in allen Sprachen hinterlegt
- [ ] `value`/`expiration` gesetzt, falls der Manager das Cookie setzen soll
- [ ] JS prüft Initialzustand per `CookieStorageHelper.getItem()` und abonniert `COOKIE_CONFIGURATION_UPDATE` auf `document.$emitter`
- [ ] `event.detail[cookieName] === undefined` wird als "unverändert" behandelt
- [ ] Storefront-Build und Cache-Clear nach Änderungen
- [ ] Kein Einsatz von `core/System/Consent` für Besucher-Einwilligungen

## 7. Quellen

Doku (⚠ nicht live abgerufen, Pfade aus dem Plan):
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/add-cookie-to-manager.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/reacting-to-cookie-consent-changes.html

Quellcode (relativ zu `vendor/shopware/`):
- `core/Content/Cookie/Event/CookieGroupCollectEvent.php`, `Hook/CookieGroupCollectHook.php`, `Service/CookieProvider.php` (`getCookieGroups`, `convertLegacyCookies`, Konstanten), `Struct/{CookieGroup,CookieEntry,CookieGroupCollection,CookieEntryCollection}.php`, `SalesChannel/CookieRoute.php`, `CookieException.php`
- `core/Framework/App/Cookie/AppCookieCollectListener.php`, `core/Framework/App/Manifest/Xml/Cookie/Cookies.php`
- `storefront/Framework/Cookie/{CookieProviderInterface,CookieProvider,AppCookieProvider}.php` (deprecated), `storefront/DependencyInjection/{services.php,captcha.php}`, `storefront/Framework/Captcha/CaptchaCookieCollectListener.php`, `storefront/Controller/CookieController.php`
- `storefront/Resources/app/storefront/src/plugin/cookie/cookie-configuration.plugin.js`, `helper/storage/cookie-storage.helper.js`, `plugin/google-analytics/google-analytics.plugin.js`, `plugin/cms-gdpr-video-element/cms-gdpr-video-element.plugin.js`
- `storefront/Resources/views/storefront/base.html.twig` (Block `base_body_script`)
- `core/System/Consent/` (README, `ConsentService`, `ConsentController`, `Definition/*`), `core/System/DependencyInjection/consent.php`, `administration/Resources/app/administration/src/core/consent/`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Doku-Seiten nicht live abgerufen; sie zeigen vermutlich noch das alte `CookieProviderInterface`-Muster (Tag `shopware.cookie_provider`). Maßgeblich ist der Code.
- ⚠ UNGEPRÜFT: 6.5/6.6-Angaben stammen aus Vorwissen, nicht aus dem installierten Code. Beispiele wurden nicht ausgeführt (angelehnt an `CaptchaCookieCollectListener`).
- ⚠ UNGEPRÜFT: Ob das Plugin-Loading `services.php` neben XML lädt: `Bundle::registerContainerFile` lädt `services.*` über XML/YAML/PHP-Loader (XML deprecated); gesondert nicht getestet.
- ⚠ LÜCKE: Verhalten "Cookie ohne `value`" und genaue Hash-Prüfung im JS (`cookieConfigHash`) nicht bis ins Detail gelesen.
- Belegt: Ein Feature-Flag `COOKIE_GROUPS_STORE_API` existiert weder in `feature.yaml` noch im Code (`Feature::isActive`); es steht nur in der `@experimental`-Annotation von `CookieRoute`/`AbstractCookieRoute`.
- ⚠ LÜCKE: Ob `core/System/Consent` künftig Storefront-Consent übernimmt: nicht belegt (experimentell, `@internal`, ohne BC-Garantie).
