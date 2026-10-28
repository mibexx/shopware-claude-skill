# Caching: HTTP-Cache, Invalidierung, Reverse Proxy (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eine eigene Storefront-/Store-API-Route cachebar werden soll, Cache-Tags gesetzt oder invalidiert werden, der Cache-Hash/Cookies die Auslieferung beeinflussen oder ein Reverse Proxy (Varnish, Fastly) angebunden wird.

Abgrenzung: Controller/Routen in [T-16](./16-storefront-controller-pages.md); Store-API-Routen und deren Überschreiben in [T-30](./30-store-api-routen.md); DAL-Events zum Auslösen der Invalidierung in [T-07](./07-events-subscriber.md); Scheduled Tasks/Worker in [T-33](./33-scheduled-tasks.md) und [T-34](./34-message-queue.md); Cookies/Consent in [T-42](./42-cookies-consent.md); Betrieb/Deployment in [T-49](./49-build-deployment.md).

## 2. Konzepte

- **HTTP-Cache**: `Framework/Adapter/Kernel/HttpCacheKernel` dekoriert `http_kernel` und speichert Antworten im Pool `cache.http` über `Http/CacheStore`. Mit externem Reverse Proxy (`shopware.http_cache.reverse_proxy.enabled: true`) ersetzt `ReverseProxyCompilerPass` den `CacheStore` durch `ReverseProxy/ReverseProxyCache`; der interne Cache entfällt, Shopware setzt nur noch Tags (Header `xkey` bei Varnish, `surrogate-key` bei Fastly).
- **Cachebar machen**: Route mit `defaults: [PlatformRequest::ATTRIBUTE_HTTP_CACHE => true]` (`'_httpCache'`), nur GET. Tags setzt man mit `CacheTagCollector::addTag(string ...$tags)`. Gilt für Storefront- und Store-API-Routen.
- **Invalidieren**: `CacheInvalidator::invalidate(array $tags, bool $force = false)`. Standardmäßig **verzögert**: Tags landen in `AbstractInvalidatorStorage` (MySQL oder Redis) und werden vom Scheduled Task `shopware.invalidate_cache` (alle 5 Min.) oder `bin/console cache:clear:delayed` gepurged. `force: true` oder Request-Header `sw-force-cache-invalidate: 1` purgt sofort.
- **Cache-Hash** (Cookie und Header `sw-cache-hash`): Fingerabdruck des Kontexts (Regel-IDs, Währung, Steuerstatus, Login-Status, Version, konfigurierte Cookies). Wird nur gesetzt, wenn nötig (Login, gefüllter Warenkorb, abweichende Währung/Land/Zahlungs-/Versandart, Cache-relevante Cookies). Erweiterbar über `HttpCacheCookieEvent` und `CacheHashRequiredExtension`.
- **6.7 ist Übergangsversion**: Das neue Cache-Verhalten ("Cache Rework") liegt hinter dem Feature-Flag **`CACHE_REWORK` (`default: false`, experimentell)** und wird mit 6.8 Standard. Im Code (`CacheStateSubscriber`, `CacheStore`, `CacheResponseSubscriber`, `ReverseProxyCache`) schaltet es auch das Major-Flag **`v6.8.0.0`** (`default: false`, `major: true`) und das experimentelle Flag **`PERFORMANCE_TWEAKS`** (`default: false`, `major: true`, "Experimental! Contains Performance Tweaks for future changes") ein: Für Cache-States (Wegfall), Cache-Hash und Währungs-Cookie genügt eines der drei Flags. Store-API-Caching (`CacheResponseSubscriber`), und Cache-Control-Policies (`CacheControlListener`) greifen nur mit `CACHE_REWORK` oder `v6.8.0.0`, nicht mit `PERFORMANCE_TWEAKS` allein. `CACHE_CONTEXT_HASH_RULES_OPTIMIZATION` ist deprecated. Ohne diese Flags gilt das alte Verhalten (Cache-States `logged-in`/`cart-filled`, Store-API **nicht** HTTP-gecacht, `s-maxage` via Default-TTL).
- **Entfernt/deprecated**: Die `Cached*Route`-Dekoratoren gibt es in 6.7.15 nicht mehr. `StoreApiRouteCacheTagsEvent` und `StoreApiRouteCacheKeyEvent` sind `@deprecated` ("not used anymore", Entfernung 6.8) und wirkungslos. Stattdessen `CacheTagCollector::addTag()`.

Wichtige Core-Klassen (`core/Framework/Adapter/Cache/`): `CacheInvalidator`, `CacheTagCollector`, `CacheInvalidationSubscriber`, `Http/{CacheStore,CacheResponseSubscriber,CacheHeadersService,HttpCacheKeyGenerator,CachePolicyProvider,CacheAttribute}`, `InvalidatorStorage/{MySQL,Redis}InvalidatorStorage`, `ReverseProxy/{Varnish,Fastly}ReverseProxyGateway`, `Script/Facade/CacheInvalidatorFacade` (App-Scripts: `cache.invalidate([...])`).

Plugin-Baum:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Controller/ExampleController.php
    ├── Subscriber/ManufacturerCacheSubscriber.php
    └── Resources/
        ├── config/{services.php, routes.php}
        └── views/storefront/page/example/index.html.twig
```

## 3. Anleitung

1. **Cache aktivieren**: `SHOPWARE_HTTP_CACHE_ENABLED=1` (Default `1`, `core/Framework/DependencyInjection/services.php` Z. 188-189; zusätzlich `framework.http_cache.enabled: true`).
2. **Route cachebar**: `_httpCache => true`. Erlaubt sind `true`, ein Array (`clientMaxAge`, `sharedMaxAge`/`maxAge`, `states`) oder ein `CacheAttribute`. Nur **GET** wird gecacht (alles andere bekommt eine Uncacheable-Policy, `CacheResponseSubscriber::setResponseCache`); 404-Antworten nie.
3. **Tags setzen**: `CacheTagCollector::addTag('mein-tag', ...)` während des Requests. Tags werden nach Request-URI gesammelt und von `CacheStore::write()` bzw. `ReverseProxyCache::write()` an die Antwort gehängt. Core-Routen tragen ihre Tags selbst ein (`EntityCacheKeyGenerator::buildProductTag($id)` = `product-<id>`, `CategoryRoute::buildName($id)` usw.). Lädt der Controller alles über Store-API-Routen, ist keine eigene Invalidierung nötig.
4. **Invalidieren**: `CacheInvalidator::invalidate($tags, $force)`. Der Core macht das in `CacheInvalidationSubscriber` bei `EntityWrittenContainerEvent`, Indexer-Events und `InvalidateProductCache`. Eigene Entitäten: auf `<entity>.written` lauschen (z. B. `ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT`); der Handler-Parameter ist dort `EntityWrittenEvent`, nicht `EntityWrittenContainerEvent` (der Container-Event ist nur Rückgabe von `create/update` und Event unter `EntityWrittenContainerEvent::class`), sonst TypeError. Eigene Tags invalidieren.
5. **Delayed Invalidation**: `shopware.cache.invalidation.delay_enabled` (Default `true`), `delay_options.storage` (`mysql` oder `redis`, `connection`), `tag_invalidation_log_enabled: true` loggt jeden Purge mit Aufrufer. Verarbeitung: Scheduled Task `shopware.invalidate_cache` (alle 300 s, braucht laufenden Worker) oder `bin/console cache:clear:delayed`, dauerhaft `bin/console cache:watch:delayed`. Im Test/CI-Modus Fallback auf sofort.
6. **Cache-Hash beeinflussen**:
   - Zusätzliche Cookies: `shopware.http_cache.cookies: [...]`.
   - Eigene Bestandteile/Abbruch: Listener auf `HttpCacheCookieEvent` (`add()`, `remove()`, `isCacheable = false` setzt Cookie `not-cacheable` und umgeht den Cache für Folgerequests, `doNotStore = true` nur für die aktuelle Antwort).
   - Wann ein Hash nötig ist: Extension `cache-hash.required` (`CacheHashRequiredExtension::NAME`), Listener auf `…required.post` (Vorbild `storefront/Framework/Cache/CacheCookieEventSubscriber`).
   - Rule-Areas: `ResolveCacheRelevantRuleIdsExtension` (Default `[RuleAreas::PRODUCT_AREA]`); nur mit `CACHE_REWORK` werden Regel-IDs auf diese Areas reduziert, sonst alle.
   - Cache-Key: `HttpCacheKeyEvent` (nur interner Symfony-Cache), `shopware.http_cache.ignored_url_parameters` (Tracking-Parameter wie `gclid`, `utm_*` vorbelegt).
7. **Cache-Control per Policies (nur mit `CACHE_REWORK`)**: `shopware.http_cache.policies` / `default_policies` / `route_policies`. Defaults: `storefront.cacheable` (public, `max_age 0`, `s_maxage 7200`, `must_revalidate`), `store_api.cacheable` (`s_maxage 1800`, `stale_while_revalidate 86400`, `stale_if_error 7200`), `no_cache_private` für Nicht-Cachebares, jeweils `No-Vary-Search: key-order`. Auflösung in `CachePolicyProvider::getPolicy`: Route+Modifier (`route#modifier`), Route, Area-Default; `max_age`/`s_maxage` aus dem `CacheAttribute` überschreiben nur, wenn die Policy den Wert schon enthält. Doku-Leitlinie: Policies statt Listener, die Header umschreiben.
8. **Reverse Proxy** (Projekt `config/packages/shopware.yaml`; Key seit 6.6 `shopware.http_cache.reverse_proxy`, vorher `storefront.reverse_proxy`):
```yaml
shopware:
    http_cache:
        reverse_proxy:
            enabled: true
            hosts: ['http://varnish']
            max_parallel_invalidations: 3
            fastly: { enabled: false }
```
   - **Varnish** (`VarnishReverseProxyGateway`): Antwort-Header `xkey: tag1 tag2`; Purge per HTTP-Methode `PURGE` mit Header `xkey` (max. 50 Tags je Request, gepuffert, Versand in `flush()`/`__destruct`). Braucht das xkey-vmod. `ban()` sendet `PURGE <host><url>`, `banAll()` sendet `BAN`. Optionen `ban_method`, `ban_headers`, `purge_all`, `use_varnish_xkey` sind deprecated und wirkungslos.
   - **Fastly** (`FastlyReverseProxyGateway`): Header `surrogate-key` (optional `tag_prefix`/`instance_tag`), API `https://api.fastly.com`, Soft-Purge-Header `fastly-soft-purge`; `fastly: {enabled: true, api_key, service_id, soft_purge, tag_prefix, instance_tag}`.
   - Antwort muss `Cache-Control: public` + Tag-Header tragen. Die Beispiel-VCL steht nur in der Doku.
   - Eigene Gateways: von `AbstractReverseProxyGateway` ableiten (`tag`, `invalidate`, `ban`, `banAll`, `flush`) und per Dekoration ([T-06](./06-service-dekoration.md)) austauschen.
9. **CLI**: `bin/console cache:clear` (Symfony-Systemcache), `cache:clear:http` (nur HTTP-Cache), `cache:clear:all` (alle Pools, abgelaufene Tags, alte Verzeichnisse), `cache:pool:clear --all` (nur Objekt-Caches), `cache:clear:delayed`, `cache:watch:delayed`. Prüfen: Response-Header `cache-control`, `sw-cache-hash`, `xkey`/`surrogate-key`.

## 4. Beispiele

`src/Controller/ExampleController.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Controller;

use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use Shopware\Core\Framework\Routing\StorefrontRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class ExampleController extends StorefrontController
{
    public const TAG = 'swag-example-manufacturers';

    public function __construct(private readonly CacheTagCollector $cacheTagCollector)
    {
    }

    #[Route(
        path: '/swag-example',
        name: 'frontend.swag.example',
        defaults: [PlatformRequest::ATTRIBUTE_HTTP_CACHE => true],
        methods: ['GET'],
    )]
    public function show(SalesChannelContext $context): Response
    {
        // Eigener Tag: Antwort wird bei Invalidierung dieses Tags verworfen
        $this->cacheTagCollector->addTag(self::TAG);

        return $this->renderStorefront('@SwagExample/storefront/page/example/index.html.twig', [
            'example' => 'Hello world',
        ]);
    }
}
```
(`Symfony\Component\Routing\Attribute\Route` wird auch von den Core-Controllern genutzt, z. B. `NavigationController`.)

`src/Subscriber/ManufacturerCacheSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use SwagExample\Controller\ExampleController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ManufacturerCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CacheInvalidator $cacheInvalidator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT => 'onManufacturerWritten'];
    }

    public function onManufacturerWritten(EntityWrittenEvent $event): void
    {
        // verzögert (Default); sofort: invalidate([...], true)
        $this->cacheInvalidator->invalidate([ExampleController::TAG]);
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Cache\CacheTagCollector;
use SwagExample\Controller\ExampleController;
use SwagExample\Subscriber\ManufacturerCacheSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ExampleController::class)
        ->public()
        ->args([service(CacheTagCollector::class)])
        ->call('setContainer', [service('service_container')]);

    $services->set(ManufacturerCacheSubscriber::class)
        ->args([service(CacheInvalidator::class)])
        ->tag('kernel.event_subscriber');
};
```

`src/Resources/config/routes.php` (XML-Routen sind in 6.7 deprecated; `routes.php` wird von `Bundle::configureRoutes` per Glob geladen)
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../Controller/**/*Controller.php', 'attribute');
};
```
Controller-Grundlagen, `setTwig` und Template: [T-16](./16-storefront-controller-pages.md). Event-Subscriber-Gerüst: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

Optional im Projekt (`config/packages/shopware.yaml`), wirksam für Policies nur mit Flag:
```yaml
shopware:
    http_cache:
        cookies: ['my-custom-cookie']        # Cookie beeinflusst Cache-Hash
        route_policies:
            frontend.swag.example: storefront.cacheable
```
Flag aktivieren: `bin/console feature:enable CACHE_REWORK` oder `.env` `CACHE_REWORK=1`.

## 5. Fallstricke

- **Veraltete Hooks**: `StoreApiRouteCacheTagsEvent`/`…KeyEvent` (und abgeleitete `CategoryRouteCacheTagsEvent` etc.) werden nicht mehr dispatcht. `CacheTagCollector::addTag()` verwenden.
- **Tag vergessen**: `_httpCache` ohne Tag: Seite wird nie durch Datenänderung invalidiert, nur durch TTL.
- **`CacheTagCollector` außerhalb eines Requests**: Tags hängen an der Request-URI (`sw-original-request-uri` bei Sub-Requests/ESI); ohne aktuellen Request landet der Tag unter `n/a` und geht verloren.
- **`new AddCacheTagEvent(...)`** ist per PHPStan-Regel `NoAddCacheTagEventRule` verboten; `addTag()` dedupliziert.
- **Invalidierung wirkungslos**: Delayed Invalidation ohne laufenden Worker/Scheduled Task. `force: true` oder `cache:clear:delayed`.
- **POST/Formulare cachen**: nur GET; Seiten mit Flash-Messages werden bewusst umgangen (`CacheCookieEventSubscriber`).
- **Personalisierte Inhalte in cachebarer Seite**: ohne `CACHE_REWORK` (bzw. `v6.8.0.0`/`PERFORMANCE_TWEAKS`) umgehen Cache-States (`sw-states`, `shopware.cache.invalidation.http_cache`) eingeloggte Kunden/gefüllte Warenkörbe; mit Flag der Cache-Hash. Eigene personalisierte Inhalte per `HttpCacheCookieEvent` (`isCacheable = false`) oder per AJAX/ESI nachladen.
- **Cookie-Abhängigkeit nicht registriert**: Antwort variiert nach eigenem Cookie, der fehlt in `shopware.http_cache.cookies`: falsche Antworten aus dem Cache.
- **Store-API-Routen werden nicht gecacht**: ohne `CACHE_REWORK` oder `v6.8.0.0` setzt `CacheResponseSubscriber` für Area `store_api` immer `noCache`, auch mit `_httpCache`.
- **Zu viele Tags/Permutationen**: Tags minimal halten, Cache-Key muss alle Eingaben enthalten.
- **Tracking-Parameter** erzeugen sonst je einen Cache-Eintrag: `ignored_url_parameters` (Standardliste vorhanden).
- **Alte Assets**: Gecachtes HTML verweist auf alte Theme-/Media-Dateien; Löschen nur asynchron (Guideline `http-cache-and-assets`).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

| Thema | 6.5/6.6 | 6.7.15.1 |
|---|---|---|
| Reverse-Proxy-Key | 6.5 `storefront.reverse_proxy`, ab 6.6 `shopware.http_cache.reverse_proxy` | `shopware.http_cache.reverse_proxy` |
| Store-API-Route-Cache | `Cached*Route`, `StoreApiRouteCacheTagsEvent`/`KeyEvent` | Klassen entfernt, Events deprecated und ungenutzt; Tags über `CacheTagCollector` |
| Cache-Control | `s-maxage` aus Default-TTL (`SHOPWARE_HTTP_DEFAULT_TTL`, 7200) | Policies, aber nur mit `CACHE_REWORK`; `default_ttl`, `stale_while_revalidate`, `stale_if_error` deprecated (6.8) |
| Cache-States | `sw-states`-Cookie, `CacheStateValidator` | deprecated (6.8); mit Flag entfallen sie, stattdessen Cache-Hash |
| Cache-Hash | alle Regel-IDs, Währung | mit Flag nur Regel-IDs der Rule-Areas, `sw-currency`-Cookie entfällt, Poisoning-Schutz (Client-Hash muss dem erwarteten entsprechen) |
| Store-API-HTTP-Cache | aus | mit Flag an (`store_api.cacheable`) |
| Soft-Purge | Fastly `soft_purge` | zusätzlich `shopware.http_cache.soft_purge`, `RefreshHttpCacheMessage` regeneriert veraltete Einträge |
| Delayed Invalidation | seit 6.5, MySQL/Redis | unverändert (Default an); neu `tag_invalidation_log_enabled` |
| Route-spezifische Invalidierungs-Optionen (`product_listing_route` usw.) | wirksam | deprecated, ohne Wirkung |
| `CacheControlListener`, `BeforeCacheControlEvent` | aktiv | deprecated, mit Flag inaktiv |
| `CACHE_CONTEXT_HASH_RULES_OPTIMIZATION` | | deprecated, ersetzt durch `CACHE_REWORK` |

XML-Service-/Routen-Konfiguration ist in 6.7 deprecated (`services.php`, `routes.php`).

## 6. Checkliste

- [ ] Route nur GET und mit `PlatformRequest::ATTRIBUTE_HTTP_CACHE => true`
- [ ] Eigene Tags über `CacheTagCollector::addTag()` gesetzt (keine deprecated Store-API-Events)
- [ ] Invalidierung über `CacheInvalidator` an passende `<entity>.written`-Events gehängt
- [ ] Delayed Invalidation verstanden: Worker/Scheduled Task läuft oder `force` genutzt
- [ ] Cookie-abhängige Inhalte in `shopware.http_cache.cookies` bzw. per `HttpCacheCookieEvent` abgebildet
- [ ] Status von `CACHE_REWORK` geprüft (`bin/console feature:list`), Policy-/Store-API-Aussagen nur mit Flag angewandt
- [ ] Bei Reverse Proxy: Tag-Header (`xkey`/`surrogate-key`) in der Antwort geprüft
- [ ] Personalisierte Inhalte per AJAX/ESI statt in der cachebaren Seite
- [ ] Nach Änderungen `bin/console cache:clear` bzw. `cache:clear:http`

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/caching/
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/add-caching-to-custom-controller.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/http-cache-and-assets.html
- https://developer.shopware.com/docs/guides/hosting/infrastructure/reverse-http-cache.html

Quellcode (relativ zu `vendor/shopware/`):
- `core/Framework/Adapter/Cache/{CacheInvalidator,CacheTagCollector,CacheInvalidationSubscriber,InvalidateCacheEvent,InvalidateCacheTask,InvalidateCacheTaskHandler}.php`, `Event/{AddCacheTagEvent,HttpCacheCookieEvent,HttpCacheKeyEvent,HttpCacheHitEvent,HttpCacheStoreEvent}.php`
- `core/Framework/Adapter/Cache/Http/{CacheResponseSubscriber,CacheHeadersService,CacheStore,HttpCacheKeyGenerator,CachePolicyProvider,CachePolicy,DefaultPolicies,CacheAttribute,CacheRelevantRulesResolver,CacheControlListener}.php`, `Http/Extension/{CacheHashRequiredExtension,ResolveCacheRelevantRuleIdsExtension}.php`
- `core/Framework/Adapter/Cache/InvalidatorStorage/*`, `ReverseProxy/*`, `Script/Facade/CacheInvalidatorFacade.php`, `StoreApiRouteCacheTagsEvent.php`, `StoreApiRouteCacheKeyEvent.php`
- `core/Framework/Adapter/Kernel/HttpCacheKernel.php`, `Adapter/Command/{CacheClearHttpCommand,CacheClearAllCommand,CacheInvalidateDelayedCommand,CacheWatchDelayedCommand}.php`
- `core/Framework/DependencyInjection/{cache.php,services.php,Configuration.php}` (cache.invalidation ca. Z. 775-830, http_cache ca. Z. 1336-1470)
- `core/Framework/Resources/config/packages/{shopware.yaml,framework.yaml,feature.yaml}`, `core/PlatformRequest.php`, `core/Framework/Bundle.php` (`configureRoutes`)
- Beispiele: `core/Content/Product/SalesChannel/Detail/ProductDetailRoute.php`, `core/Content/Category/SalesChannel/CategoryRoute.php`, `storefront/Controller/{NavigationController,ProductController,ScriptController}.php`, `storefront/Framework/Cache/CacheCookieEventSubscriber.php`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Aktivierungsstatus von `CACHE_REWORK` in der konkreten Installation (Default `false`); alle Aussagen zu Policies/Store-API-Cache/Cache-Hash-Areas gelten nur mit Flag oder ab 6.8. Der Befehl `bin/console feature:enable CACHE_REWORK` wurde nicht ausgeführt.
- ⚠ UNGEPRÜFT: Controller-Registrierung (`public()`, `setContainer`) und `routes.php`-Glob-Import mit `attribute`-Typ wurden nicht in einer Laufzeitumgebung getestet (Muster aus Doku/Core; die Recherche zeigte ursprünglich XML).
- ⚠ LÜCKE: Die Doku-Seite "add-caching-to-custom-controller" nennt noch "Store API tags generated"; ohne `CACHE_REWORK` gilt das nur für die Storefront-Seite.
- ⚠ LÜCKE: `CacheTagCollection` ist registriert, aber im Core nur in der DI-Definition gefunden; Rolle unklar, nicht empfohlen.
- ⚠ UNGEPRÜFT: Verhalten von `ignored_url_parameters` beim Überschreiben im Projekt (Merge/Ersatz); Redis-Invalidator-Konfiguration (`delay_options.connection`-Format); VCL-Beispiele und Fastly-Details nur aus der Doku-Zusammenfassung.
- ⚠ UNGEPRÜFT: Dass der Scheduled Task `shopware.invalidate_cache` einen laufenden Worker braucht, ist aus dem Aufbau abgeleitet.
