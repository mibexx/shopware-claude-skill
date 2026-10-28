# Abgrenzung Plugin vs. App-System (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Reine Abgrenzung, **keine App-Anleitung**. Diese Referenz greift, wenn zu entscheiden ist, ob eine Anforderung als Plugin oder als App umgesetzt wird, oder wenn App-Begriffe (App-Scripts, Script-Hooks, Webhooks, Action-Buttons) auftauchen. Der Skill beschreibt den Plugin-Weg; App-Mechanismen werden hier nur benannt und auf ihr Plugin-Äquivalent abgebildet.

Plugin-Äquivalente: Events/Subscriber [T-07](./07-events-subscriber.md), Extension Points [T-08](./08-extension-points.md), Flow-Actions/-Trigger [T-09](./09-flow-builder.md), Dekoration [T-06](./06-service-dekoration.md), Twig-Blöcke [T-14](./14-storefront-twig-templates.md), Plugin-Aufbau [T-02](./02-plugin-aufbau.md).

## 2. Konzepte

- **Plugin**: PHP-Code im Shopware-Prozess (Composer, `custom/plugins`), voller Zugriff auf DI-Container, DAL, Events, Migrationen, Twig, Admin-/Storefront-Build.
- **App**: `manifest.xml` + deklarative Ressourcen (+ optional externer App-Server). Kein PHP im Core-Prozess; Logik liegt (a) im externen App-Server (HTTP, signiert) oder (b) in **App-Scripts** (Twig im Core-Prozess, sandboxed). Eine App kann auch als Composer-Paket vom Typ `shopware-app` installiert werden (`AppLoader::COMPOSER_TYPE`).
- **App-Scripts/Script-Hooks sind ausschließlich App-Feature.** Der `ScriptLoader` ist `@internal only for use by the app-system`; Scripts werden nur vom `ScriptLifecycleHandler` (ebenfalls `@internal`) aus `Resources/scripts/**/*.twig` einer App in die Tabelle `script` geschrieben (`ScriptFileReader`, `SCRIPT_DIR = '/Resources/scripts'`). Im Plugin-Namespace gibt es keinen Script-Lifecycle. Plugins nutzen Subscriber statt Hooks.
- **Webhooks** und **Action-Buttons** sind Manifest-Konzepte (ausgehende, signierte HTTP-Calls an den App-Server). Plugins nutzen Events/Subscriber direkt. (Webhook-Entities lassen sich technisch auch per Admin-API ohne App anlegen, `webhook.app_id` ist nullable.)

Skelett-Gegenüberstellung:
```
custom/apps/MyApp/                 # AppLoader::loadFromAppDir, Tiefe <= 1
├── manifest.xml                   # Schema: core/Framework/App/Manifest/Schema/manifest-3.0.xsd
└── Resources/
    ├── scripts/<hook-name>/*.twig # Ordnername = Hook-Name; api-{hook}, store-api-{hook}, storefront-{hook}; include/ für Makros
    └── views/, config/, app/...

custom/plugins/SwagExample/        # Plugin: kein Script-Ordner
├── composer.json
└── src/SwagExample.php, src/Subscriber/, src/Resources/config/services.php, ...
```
Unterordner `rule-conditions/` wird nicht als Hook-Script geladen.

### Abgrenzungstabelle

| Kriterium | Plugin | App (mit/ohne App-Server) |
|---|---|---|
| Betrieb | Code im Shop-Prozess (Self-Hosted/PaaS); ⚠ UNGEPRÜFT: nicht in Shopware-Cloud-Shops frei installierbar | Cloud und Self-Hosted |
| Bestandteil | PHP-Klassen, Composer, Migrationen | `manifest.xml`, Twig-Scripts, Templates, optional externer Server |
| Logik im Core-Prozess | beliebiges PHP | nur Twig-Scripts (sandboxed, Facades) |
| Zugriff | DI, DAL, alle Events, Dekoration | Script-Services/Facades, Webhooks, Admin-/Store-API mit Integration (ACL über `<permissions>`) |
| Script-Hooks | **nein** (Subscriber, [T-07](./07-events-subscriber.md)) | ja (`Resources/scripts/<hook>/*.twig`) |
| Webhooks | n/a (Events/Subscriber) | `<webhooks>` im Manifest -> `WebhookDispatcher` |
| Action-Buttons | nein (Admin-Erweiterung per Vue, [T-24](./24-admin-module.md)) | `<action-button>` (Entities: product, order, category, promotion, customer, cms_page; Views: detail, list) |
| Eigene Tabellen/Entities | Migrationen/EntityDefinition ([T-04](./04-datenbank-migrationen.md), [T-27](./27-dal-entities.md)) | Custom Entities (Manifest) |
| Admin-UI | Vue-Komponenten/Module | Meteor-Admin-Extension-SDK / iframe-Module (`<admin>`) |
| Zahlung/Versand/Steuer | Handler-Klassen ([T-37](./37-payment-integration.md)) | `<payments>`, `<shipping-methods>`, `<tax>` + Remote-Endpunkte |
| Lifecycle | install/update/activate/... in Plugin-Klasse ([T-03](./03-plugin-lifecycle.md)) | Hooks `app-installed`, `app-updated`, `app-activated`, `app-deactivated`, `app-deleted` als Scripts |
| Update-Kopplung | Core-Updates können brechen (interne APIs) | stabiler Vertrag (Manifest/Hooks) |
| Wahl | volle Kontrolle, Performance, tiefe Eingriffe | Marketplace-/Cloud-Tauglichkeit, Isolation, SaaS-Integration |

## 3. Anleitung

Entscheidungshilfe:
- PHP-Klassen im Core-Prozess, Migrationen, DI-Dekoration, eigene Entities per DAL oder Storefront-/Admin-Build nötig: **Plugin**.
- Cloud-Kompatibilität, lose Kopplung, externes System/SaaS als Logikträger: **App**.
- Für diesen Skill gilt der Plugin-Weg.

Gleiche Aufgabe, zwei Wege: "Auf der Produktseite eine Variable ergänzen". App: Script `Resources/scripts/product-page-loaded/<name>.twig` (nur App; ⚠ UNGEPRÜFT: ausführbarer Twig-Inhalt je Hook, Facade-Aufrufe nicht verifiziert). Plugin: Subscriber auf `ProductPageLoadedEvent`, siehe [T-07](./07-events-subscriber.md) (`ProductPageSubscriber`); Registrierung mit Tag `kernel.event_subscriber`.

CLI (nur lesend/zur Einordnung):
```
bin/console app:list                  # AppListCommand
bin/console app:validate <pfad>       # ValidateAppCommand
bin/console debug:event-dispatcher    # Plugin-Pendant zu Hooks (Page-Events)
```
App-Commands: `app:create`, `app:install`, `app:refresh`, `app:activate`, `app:deactivate`, `app:uninstall`, `app:validate` (`core/Framework/App/Command`).

### Script-Hooks in 6.7.15.1 (Übersicht, aus `HOOK_NAME`-Konstanten; nur zur Orientierung)

Ausführung: `ScriptExecutor::execute(Hook)` lädt Scripts per `ScriptLoader::get($hook->getName())`; Twig-Globals `services` (Facades) und `hook`. Ein Script ist aktiv bei `script.active = 1 AND (app.id IS NULL OR app.active = 1)` (SQL in `ScriptLoader.php` Z. 131; ein Script ohne App-Bezug wird also nicht durch eine inaktive App blockiert), außer Lifecycle-Hooks. Webhooks gelten dieselbe Logik in `WebhookManager::isEventDispatchingAllowed()` (Z. 327-340, `core/Framework/Webhook/Service/WebhookManager.php`): `WebhookLoader` lädt `w.active = 1` per `LEFT JOIN app`; ohne App (`appId === null`) wird immer zugestellt, mit App nur bei aktiver App (Ausnahme: `AppChangedEvent`, `AppDeletedEvent`, `AppPermissionsUpdated`) und passenden ACL-Rechten.

| Gruppe | Hook-Namen |
|---|---|
| Warenkorb/Preise | `cart`, `product-pricing` |
| Reviews/Cookies/Dokumente | `product-reviews-widget-loaded`, `cookie-group-collect`, `document-generation` |
| Store-API-Request | `payment-method-route-request`, `shipping-method-route-request` |
| Cache | `cache-invalidation` |
| Custom Endpoints | `api-{hook}`, `store-api-{hook}`, `storefront-{hook}`, `response` |
| Lifecycle | `app-installed`, `app-updated`, `app-activated`, `app-deactivated`, `app-deleted` |
| Storefront-Seiten | `product-page-loaded`, `navigation-page-loaded`, `cms-page-loaded`, `landing-page-loaded`, `search-page-loaded`, `search-widget-loaded`, `suggest-page-loaded`, `sitemap-page-loaded`, `maintenance-page-loaded`, `product-quick-view-widget-loaded` |
| Checkout | `checkout-cart-page-loaded`, `checkout-confirm-page-loaded`, `checkout-register-page-loaded`, `checkout-finish-page-loaded`, `checkout-info-widget-loaded`, `checkout-offcanvas-widget-loaded` |
| Account/Adressen | `account-login-page-loaded`, `account-guest-login-page-loaded`, `account-register-page-loaded`, `account-profile-page-loaded`, `account-overview-page-loaded`, `account-order-page-loaded`, `account-order-detail-page-loaded`, `account-edit-order-page-loaded`, `account-recover-password-page-loaded`, `customer-group-registration-page-loaded`, `address-listing-page-loaded`, `address-detail-page-loaded`, `address-book-widget-loaded` |
| Wishlist | `wishlist-page-loaded`, `wishlist-widget-loaded`, `guest-wishlist-page-loaded`, `guest-wishlist-pagelet-loaded` |
| Pagelets | `header-pagelet-loaded`, `footer-pagelet-loaded`, `menu-offcanvas-pagelet-loaded`, `country-state-data-pagelet-loaded` |

Storefront-Hooks erweitern `Storefront\Page\PageLoadedHook` (`storefront/Page/PageLoadedHook.php`): die Script-/App-Entsprechung der `*PageLoadedEvent`s. Facades (`services.<name>`): `cart`, `price`, `repository`, `writer`, `store`, `config`, `request`, `response`, `cache`, `acl`.

Webhooks: Manifest `<webhooks><webhook name url event onlyLiveVersion/>`; Dispatch durch `WebhookDispatcher` (dekoriert den EventDispatcher) -> `WebhookManager`; nur `Hookable`-Events; Zustellung asynchron (Outbox/Transport), Payload mit App-Secret signiert; keine Antwort in den Core möglich (dafür Scripts bzw. Gateways: checkout, context, in-app-purchases). Action-Button: `App/ActionButton/Executor` sendet signierten POST an die App-URL.

## 4. Beispiele

Plugin-Weg für die Produktseiten-Erweiterung (Gegenstück zum Hook `product-page-loaded`):

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
Service-Registrierung und Gerüst: [T-07](./07-events-subscriber.md), [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Fallstricke

- Script-Hooks in Plugins erwarten: es gibt keinen Loader dafür; Subscriber verwenden. Ein Eintrag in die Tabelle `script` ohne `app_id` ist denkbar, aber nicht unterstützt und nicht getestet.
- (App-seitig, zur Einordnung) Hook-Ordnername muss exakt dem `getName()` des Hooks entsprechen; Script nutzt nur Services, die der Hook liefert (sonst `ScriptException::scriptExecutionFailed`).
- Webhooks als synchrones Eingriffsmittel missbrauchen: sie sind asynchron; Fehlerzähler `error_count` deaktiviert Webhooks.
- Script-Änderungen in Produktion nicht sichtbar: Cache `shopware-executable-app-scripts` (`ScriptLoader::CACHE_KEY`) wird bei `script.written`/`app.written` invalidiert.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Scripts/Hooks seit 6.4.8.0 (laut Doku); Konzept in 6.7 unverändert, Hook-Liste gewachsen (⚠ UNGEPRÜFT: Einführungsversion z. B. von `cookie-group-collect`, `document-generation`, `product-pricing`).
- `@deprecated tag:v6.8.0`: `ScriptResponseFactoryFacade::render()` (stattdessen `StorefrontScriptResponseFactoryFacade` in Storefront-Hooks), Teile von `ResponseCacheConfiguration` (stattdessen `sharedMaxAge`/`clientMaxAge`), zugehörige `ScriptException`-Factorys.
- Webhooks: Feature-Flag `WEBHOOKS_REWORK` (Default `false`, experimentell; Outbox-Retries statt Messenger); Legacy-Pfade `@deprecated tag:v6.8.0` in `WebhookOutboxStore`, `WebhookEventMessageHandler`, `WebhookDrainToAsyncCommand`.
- Manifest-Schema `manifest-2.0.xsd` und `manifest-3.0.xsd` vorhanden (Unterschiede nicht geprüft).

## 6. Checkliste

- [ ] Entscheidung Plugin vs. App anhand der Tabelle begründet (PHP im Core-Prozess/Migrationen/Dekoration -> Plugin)
- [ ] Keine Script-Hooks/App-Scripts im Plugin geplant; Subscriber stattdessen
- [ ] Webhook-Bedarf: stattdessen Event-Subscriber im Plugin geprüft
- [ ] Admin-Buttons im Plugin über Vue-Erweiterung, nicht Manifest-`<action-button>`
- [ ] Cloud-Anforderung des Kunden geklärt (⚠ UNGEPRÜFT, siehe Lücken)
- [ ] Für reine App-Aufgaben auf die offizielle App-Dokumentation verwiesen statt hier zu improvisieren

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/apps/app-scripts/ (behandelt nur Apps)
- https://developer.shopware.com/docs/resources/references/app-reference/script-reference/ (`script-hooks-reference.html`, `trigger-events-reference.html`, `webhook-events-reference.html` u. a.)

Quellcode (`vendor/shopware/`):
- `core/Framework/Script/Execution/{Hook,ScriptExecutor,ScriptLoader,FunctionHook,InterfaceHook,TraceHook}.php`, `core/Framework/Script/Api/*`
- `core/Framework/App/Lifecycle/{AppLoader,ScriptFileReader,Handler/ScriptLifecycleHandler}.php`
- `core/Framework/App/Manifest/{Manifest,Schema/manifest-3.0.xsd,Xml/Webhook/Webhook,Xml/Administration/ActionButton}.php`, `core/Framework/App/ActionButton/Executor`, `core/Framework/App/Command/*`
- `core/Framework/Webhook/{WebhookDispatcher,WebhookDefinition,Service/WebhookManager}` (+ Outbox, Transport, Authorization)
- `storefront/Page/PageLoadedHook.php`, `storefront/Framework/Script/Api/StorefrontHook`, `storefront/Page/**/*LoadedHook`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: "Kein Script-Support für Plugins" belegt durch `@internal only for use by the app-system`, Fehlen im Plugin-Namespace und Doku; technischer Umweg nicht getestet.
- ⚠ UNGEPRÜFT: Shopware-Cloud-Einschränkung für Plugins stammt aus Konzeptwissen, nicht aus der Recherche belegt.
- ⚠ UNGEPRÜFT: Einführungsversionen einzelner Hooks, Unterschiede manifest 2.0/3.0, Details der Webhook-Signatur.
- ⚠ UNGEPRÜFT: Meteor-Admin-SDK-Bezug (`<admin>`-Modul) nur aus Manifest-Verzeichnis abgeleitet.
- ⚠ LÜCKE: Facade-Methoden je Hook nicht einzeln geprüft; Hooks ohne `HOOK_NAME`-Konstante (z. B. Store-API-Funktionen) nur genannt. Test-Hooks ausgeschlossen.
- `/docs/guides/plugins/overview.html` nicht abgerufen.
