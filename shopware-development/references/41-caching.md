# Caching: HTTP cache, invalidation, reverse proxy (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a custom Storefront/Store API route should become cacheable, cache tags are set or invalidated, the cache hash/cookies affect delivery, or a reverse proxy (Varnish, Fastly) is connected.

Scope: controllers/routes in [T-16](./16-storefront-controller-pages.md); Store API routes and overriding them in [T-30](./30-store-api-routes.md); DAL events for triggering invalidation in [T-07](./07-events-subscriber.md); scheduled tasks/workers in [T-33](./33-scheduled-tasks.md) and [T-34](./34-message-queue.md); cookies/consent in [T-42](./42-cookies-consent.md); operations/deployment in [T-49](./49-build-deployment.md).

## 2. Concepts

- **HTTP cache**: `Framework/Adapter/Kernel/HttpCacheKernel` decorates `http_kernel` and stores responses in the pool `cache.http` via `Http/CacheStore`. With an external reverse proxy (`shopware.http_cache.reverse_proxy.enabled: true`), `ReverseProxyCompilerPass` replaces the `CacheStore` with `ReverseProxy/ReverseProxyCache`; the internal cache is dropped, and Shopware only sets tags (header `xkey` for Varnish, `surrogate-key` for Fastly).
- **Making it cacheable**: route with `defaults: [PlatformRequest::ATTRIBUTE_HTTP_CACHE => true]` (`'_httpCache'`), GET only. Tags are set with `CacheTagCollector::addTag(string ...$tags)`. Applies to Storefront and Store API routes.
- **Invalidating**: `CacheInvalidator::invalidate(array $tags, bool $force = false)`. **Delayed** by default: tags land in `AbstractInvalidatorStorage` (MySQL or Redis) and are purged by the scheduled task `shopware.invalidate_cache` (every 5 min.) or `bin/console cache:clear:delayed`. `force: true` or the request header `sw-force-cache-invalidate: 1` purges immediately.
- **Cache hash** (cookie and header `sw-cache-hash`): fingerprint of the context (rule IDs, currency, tax state, login state, version, configured cookies). Only set when needed (login, filled cart, deviating currency/country/payment/shipping method, cache-relevant cookies). Extensible via `HttpCacheCookieEvent` and `CacheHashRequiredExtension`.
- **6.7 is a transitional version**: The new cache behavior ("Cache Rework") sits behind the feature flag **`CACHE_REWORK` (`default: false`, experimental)** and becomes the default with 6.8. In the code (`CacheStateSubscriber`, `CacheStore`, `CacheResponseSubscriber`, `ReverseProxyCache`) it is also switched on by the major flag **`v6.8.0.0`** (`default: false`, `major: true`) and the experimental flag **`PERFORMANCE_TWEAKS`** (`default: false`, `major: true`, "Experimental! Contains Performance Tweaks for future changes"): for cache states (removal), cache hash and currency cookie, any one of the three flags is sufficient. Store API caching (`CacheResponseSubscriber`) and cache-control policies (`CacheControlListener`) only take effect with `CACHE_REWORK` or `v6.8.0.0`, not with `PERFORMANCE_TWEAKS` alone. `CACHE_CONTEXT_HASH_RULES_OPTIMIZATION` is deprecated. Without these flags the old behavior applies (cache states `logged-in`/`cart-filled`, Store API **not** HTTP-cached, `s-maxage` via default TTL).
- **Removed/deprecated**: The `Cached*Route` decorators no longer exist in 6.7.15. `StoreApiRouteCacheTagsEvent` and `StoreApiRouteCacheKeyEvent` are `@deprecated` ("not used anymore", removal in 6.8) and have no effect. Use `CacheTagCollector::addTag()` instead.

Important core classes (`core/Framework/Adapter/Cache/`): `CacheInvalidator`, `CacheTagCollector`, `CacheInvalidationSubscriber`, `Http/{CacheStore,CacheResponseSubscriber,CacheHeadersService,HttpCacheKeyGenerator,CachePolicyProvider,CacheAttribute}`, `InvalidatorStorage/{MySQL,Redis}InvalidatorStorage`, `ReverseProxy/{Varnish,Fastly}ReverseProxyGateway`, `Script/Facade/CacheInvalidatorFacade` (app scripts: `cache.invalidate([...])`).

Plugin tree:
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

## 3. Instructions

1. **Enable the cache**: `SHOPWARE_HTTP_CACHE_ENABLED=1` (default `1`, `core/Framework/DependencyInjection/services.php` lines 188-189; additionally `framework.http_cache.enabled: true`).
2. **Make the route cacheable**: `_httpCache => true`. Allowed are `true`, an array (`clientMaxAge`, `sharedMaxAge`/`maxAge`, `states`) or a `CacheAttribute`. Only **GET** is cached (everything else gets an uncacheable policy, `CacheResponseSubscriber::setResponseCache`); 404 responses never.
3. **Set tags**: `CacheTagCollector::addTag('my-tag', ...)` during the request. Tags are collected per request URI and attached to the response by `CacheStore::write()` or `ReverseProxyCache::write()` respectively. Core routes register their tags themselves (`EntityCacheKeyGenerator::buildProductTag($id)` = `product-<id>`, `CategoryRoute::buildName($id)`, etc.). If the controller loads everything via Store API routes, no custom invalidation is needed.
4. **Invalidate**: `CacheInvalidator::invalidate($tags, $force)`. The core does this in `CacheInvalidationSubscriber` on `EntityWrittenContainerEvent`, indexer events and `InvalidateProductCache`. Custom entities: listen to `<entity>.written` (e.g. `ProductEvents::PRODUCT_MANUFACTURER_WRITTEN_EVENT`); the handler parameter there is `EntityWrittenEvent`, not `EntityWrittenContainerEvent` (the container event is only the return value of `create/update` and the event under `EntityWrittenContainerEvent::class`), otherwise TypeError. Invalidate your own tags.
5. **Delayed invalidation**: `shopware.cache.invalidation.delay_enabled` (default `true`), `delay_options.storage` (`mysql` or `redis`, `connection`), `tag_invalidation_log_enabled: true` logs every purge with its caller. Processing: scheduled task `shopware.invalidate_cache` (every 300 s, requires a running worker) or `bin/console cache:clear:delayed`, continuously `bin/console cache:watch:delayed`. In test/CI mode it falls back to immediate.
6. **Influencing the cache hash**:
   - Additional cookies: `shopware.http_cache.cookies: [...]`.
   - Custom components/abort: listener on `HttpCacheCookieEvent` (`add()`, `remove()`, `isCacheable = false` sets the cookie `not-cacheable` and bypasses the cache for subsequent requests, `doNotStore = true` only for the current response).
   - When a hash is needed: extension `cache-hash.required` (`CacheHashRequiredExtension::NAME`), listener on `…required.post` (model: `storefront/Framework/Cache/CacheCookieEventSubscriber`).
   - Rule areas: `ResolveCacheRelevantRuleIdsExtension` (default `[RuleAreas::PRODUCT_AREA]`); only with `CACHE_REWORK` are rule IDs reduced to these areas, otherwise all.
   - Cache key: `HttpCacheKeyEvent` (internal Symfony cache only), `shopware.http_cache.ignored_url_parameters` (tracking parameters such as `gclid`, `utm_*` preset).
7. **Cache-Control via policies (only with `CACHE_REWORK`)**: `shopware.http_cache.policies` / `default_policies` / `route_policies`. Defaults: `storefront.cacheable` (public, `max_age 0`, `s_maxage 7200`, `must_revalidate`), `store_api.cacheable` (`s_maxage 1800`, `stale_while_revalidate 86400`, `stale_if_error 7200`), `no_cache_private` for non-cacheable content, each with `No-Vary-Search: key-order`. Resolution in `CachePolicyProvider::getPolicy`: route+modifier (`route#modifier`), route, area default; `max_age`/`s_maxage` from the `CacheAttribute` only override if the policy already contains the value. Docs guideline: policies instead of listeners that rewrite headers.
8. **Reverse proxy** (project `config/packages/shopware.yaml`; key since 6.6 `shopware.http_cache.reverse_proxy`, previously `storefront.reverse_proxy`):
```yaml
shopware:
    http_cache:
        reverse_proxy:
            enabled: true
            hosts: ['http://varnish']
            max_parallel_invalidations: 3
            fastly: { enabled: false }
```
   - **Varnish** (`VarnishReverseProxyGateway`): response header `xkey: tag1 tag2`; purge via HTTP method `PURGE` with header `xkey` (max. 50 tags per request, buffered, sent in `flush()`/`__destruct`). Requires the xkey vmod. `ban()` sends `PURGE <host><url>`, `banAll()` sends `BAN`. Options `ban_method`, `ban_headers`, `purge_all`, `use_varnish_xkey` are deprecated and have no effect.
   - **Fastly** (`FastlyReverseProxyGateway`): header `surrogate-key` (optionally `tag_prefix`/`instance_tag`), API `https://api.fastly.com`, soft-purge header `fastly-soft-purge`; `fastly: {enabled: true, api_key, service_id, soft_purge, tag_prefix, instance_tag}`.
   - The response must carry `Cache-Control: public` + tag header. The example VCL is only in the docs.
   - Custom gateways: extend `AbstractReverseProxyGateway` (`tag`, `invalidate`, `ban`, `banAll`, `flush`) and swap it in via decoration ([T-06](./06-service-decoration.md)).
9. **CLI**: `bin/console cache:clear` (Symfony system cache), `cache:clear:http` (HTTP cache only), `cache:clear:all` (all pools, expired tags, old directories), `cache:pool:clear --all` (object caches only), `cache:clear:delayed`, `cache:watch:delayed`. Check: response headers `cache-control`, `sw-cache-hash`, `xkey`/`surrogate-key`.

## 4. Examples

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
        // Custom tag: response is discarded when this tag is invalidated
        $this->cacheTagCollector->addTag(self::TAG);

        return $this->renderStorefront('@SwagExample/storefront/page/example/index.html.twig', [
            'example' => 'Hello world',
        ]);
    }
}
```
(`Symfony\Component\Routing\Attribute\Route` is also used by the core controllers, e.g. `NavigationController`.)

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
        // delayed (default); immediate: invalidate([...], true)
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

`src/Resources/config/routes.php` (XML routes are deprecated in 6.7; `routes.php` is loaded by `Bundle::configureRoutes` via glob)
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import(__DIR__ . '/../../Controller/**/*Controller.php', 'attribute');
};
```
Controller basics, `setTwig` and template: [T-16](./16-storefront-controller-pages.md). Event subscriber scaffold: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

Optional in the project (`config/packages/shopware.yaml`), effective for policies only with the flag:
```yaml
shopware:
    http_cache:
        cookies: ['my-custom-cookie']        # cookie affects the cache hash
        route_policies:
            frontend.swag.example: storefront.cacheable
```
Enable the flag: `bin/console feature:enable CACHE_REWORK` or `.env` `CACHE_REWORK=1`.

## 5. Pitfalls

- **Outdated hooks**: `StoreApiRouteCacheTagsEvent`/`…KeyEvent` (and derived `CategoryRouteCacheTagsEvent` etc.) are no longer dispatched. Use `CacheTagCollector::addTag()`.
- **Forgotten tag**: `_httpCache` without a tag: the page is never invalidated by data changes, only by TTL.
- **`CacheTagCollector` outside a request**: tags are attached to the request URI (`sw-original-request-uri` for sub-requests/ESI); without a current request the tag ends up under `n/a` and is lost.
- **`new AddCacheTagEvent(...)`** is forbidden by the PHPStan rule `NoAddCacheTagEventRule`; `addTag()` deduplicates.
- **Invalidation has no effect**: delayed invalidation without a running worker/scheduled task. `force: true` or `cache:clear:delayed`.
- **Caching POST/forms**: GET only; pages with flash messages are deliberately bypassed (`CacheCookieEventSubscriber`).
- **Personalized content in a cacheable page**: without `CACHE_REWORK` (or `v6.8.0.0`/`PERFORMANCE_TWEAKS`) cache states (`sw-states`, `shopware.cache.invalidation.http_cache`) bypass logged-in customers/filled carts; with the flag, the cache hash does. Load your own personalized content via `HttpCacheCookieEvent` (`isCacheable = false`) or via AJAX/ESI.
- **Cookie dependency not registered**: the response varies by a custom cookie that is missing from `shopware.http_cache.cookies`: wrong responses from the cache.
- **Store API routes are not cached**: without `CACHE_REWORK` or `v6.8.0.0`, `CacheResponseSubscriber` always sets `noCache` for area `store_api`, even with `_httpCache`.
- **Too many tags/permutations**: keep tags minimal; the cache key must contain all inputs.
- **Tracking parameters** would otherwise each create a cache entry: `ignored_url_parameters` (default list provided).
- **Old assets**: cached HTML points to old theme/media files; deletion only asynchronously (guideline `http-cache-and-assets`).

### Deprecations and version notes (6.5/6.6 → 6.7)

| Topic | 6.5/6.6 | 6.7.15.1 |
|---|---|---|
| Reverse proxy key | 6.5 `storefront.reverse_proxy`, from 6.6 `shopware.http_cache.reverse_proxy` | `shopware.http_cache.reverse_proxy` |
| Store API route cache | `Cached*Route`, `StoreApiRouteCacheTagsEvent`/`KeyEvent` | classes removed, events deprecated and unused; tags via `CacheTagCollector` |
| Cache-Control | `s-maxage` from default TTL (`SHOPWARE_HTTP_DEFAULT_TTL`, 7200) | policies, but only with `CACHE_REWORK`; `default_ttl`, `stale_while_revalidate`, `stale_if_error` deprecated (6.8) |
| Cache states | `sw-states` cookie, `CacheStateValidator` | deprecated (6.8); with the flag they are dropped, replaced by the cache hash |
| Cache hash | all rule IDs, currency | with the flag only rule IDs of the rule areas, `sw-currency` cookie dropped, poisoning protection (client hash must match the expected one) |
| Store API HTTP cache | off | on with the flag (`store_api.cacheable`) |
| Soft purge | Fastly `soft_purge` | additionally `shopware.http_cache.soft_purge`, `RefreshHttpCacheMessage` regenerates stale entries |
| Delayed invalidation | since 6.5, MySQL/Redis | unchanged (on by default); new `tag_invalidation_log_enabled` |
| Route-specific invalidation options (`product_listing_route` etc.) | effective | deprecated, no effect |
| `CacheControlListener`, `BeforeCacheControlEvent` | active | deprecated, inactive with the flag |
| `CACHE_CONTEXT_HASH_RULES_OPTIMIZATION` | | deprecated, replaced by `CACHE_REWORK` |

XML service/route configuration is deprecated in 6.7 (`services.php`, `routes.php`).

## 6. Checklist

- [ ] Route GET only and with `PlatformRequest::ATTRIBUTE_HTTP_CACHE => true`
- [ ] Custom tags set via `CacheTagCollector::addTag()` (no deprecated Store API events)
- [ ] Invalidation via `CacheInvalidator` hooked to the matching `<entity>.written` events
- [ ] Delayed invalidation understood: worker/scheduled task is running or `force` is used
- [ ] Cookie-dependent content mapped in `shopware.http_cache.cookies` or via `HttpCacheCookieEvent`
- [ ] Status of `CACHE_REWORK` checked (`bin/console feature:list`), policy/Store API statements applied only with the flag
- [ ] With reverse proxy: tag header (`xkey`/`surrogate-key`) checked in the response
- [ ] Personalized content via AJAX/ESI instead of in the cacheable page
- [ ] After changes `bin/console cache:clear` or `cache:clear:http`

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/caching/
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/advanced/add-caching-to-custom-controller.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/http-cache-and-assets.html
- https://developer.shopware.com/docs/guides/hosting/infrastructure/reverse-http-cache.html

Source code (relative to `vendor/shopware/`):
- `core/Framework/Adapter/Cache/{CacheInvalidator,CacheTagCollector,CacheInvalidationSubscriber,InvalidateCacheEvent,InvalidateCacheTask,InvalidateCacheTaskHandler}.php`, `Event/{AddCacheTagEvent,HttpCacheCookieEvent,HttpCacheKeyEvent,HttpCacheHitEvent,HttpCacheStoreEvent}.php`
- `core/Framework/Adapter/Cache/Http/{CacheResponseSubscriber,CacheHeadersService,CacheStore,HttpCacheKeyGenerator,CachePolicyProvider,CachePolicy,DefaultPolicies,CacheAttribute,CacheRelevantRulesResolver,CacheControlListener}.php`, `Http/Extension/{CacheHashRequiredExtension,ResolveCacheRelevantRuleIdsExtension}.php`
- `core/Framework/Adapter/Cache/InvalidatorStorage/*`, `ReverseProxy/*`, `Script/Facade/CacheInvalidatorFacade.php`, `StoreApiRouteCacheTagsEvent.php`, `StoreApiRouteCacheKeyEvent.php`
- `core/Framework/Adapter/Kernel/HttpCacheKernel.php`, `Adapter/Command/{CacheClearHttpCommand,CacheClearAllCommand,CacheInvalidateDelayedCommand,CacheWatchDelayedCommand}.php`
- `core/Framework/DependencyInjection/{cache.php,services.php,Configuration.php}` (cache.invalidation approx. lines 775-830, http_cache approx. lines 1336-1470)
- `core/Framework/Resources/config/packages/{shopware.yaml,framework.yaml,feature.yaml}`, `core/PlatformRequest.php`, `core/Framework/Bundle.php` (`configureRoutes`)
- Examples: `core/Content/Product/SalesChannel/Detail/ProductDetailRoute.php`, `core/Content/Category/SalesChannel/CategoryRoute.php`, `storefront/Controller/{NavigationController,ProductController,ScriptController}.php`, `storefront/Framework/Cache/CacheCookieEventSubscriber.php`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Activation status of `CACHE_REWORK` in the concrete installation (default `false`); all statements on policies/Store API cache/cache hash areas apply only with the flag or from 6.8. The command `bin/console feature:enable CACHE_REWORK` was not executed.
- ⚠ UNVERIFIED: Controller registration (`public()`, `setContainer`) and the `routes.php` glob import with type `attribute` were not tested in a runtime environment (pattern from docs/core; the research originally showed XML).
- ⚠ GAP: The docs page "add-caching-to-custom-controller" still mentions "Store API tags generated"; without `CACHE_REWORK` this only applies to the Storefront page.
- ⚠ GAP: `CacheTagCollection` is registered, but in the core it was only found in the DI definition; role unclear, not recommended.
- ⚠ UNVERIFIED: Behavior of `ignored_url_parameters` when overridden in the project (merge/replace); Redis invalidator configuration (`delay_options.connection` format); VCL examples and Fastly details only from the docs summary.
- ⚠ UNVERIFIED: That the scheduled task `shopware.invalidate_cache` requires a running worker is inferred from the structure.
