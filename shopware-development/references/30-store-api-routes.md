# Creating and overriding Store API routes (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a custom Store API route (`/store-api/...`) is to be created, an existing core route is to be changed via decoration, a route is to be reused in a Storefront controller, a rate limiter is to be put in front of it, or the OpenAPI description is to be provided.

Scope: Admin API routes (`/api/...`) → [T-31](./31-admin-api-routes.md). Storefront controllers/pages → [T-16](./16-storefront-controller-pages.md). Service decoration in general → [T-06](./06-service-decoration.md). Events as an alternative → [T-07](./07-events-subscriber.md). HTTP cache/invalidation → [T-41](./41-caching.md). Service/route registration → [T-05](./05-registering-services.md).

## 2. Concepts

- A Store API route consists of four parts: `AbstractXRoute` (with `getDecorated()` + method), `XRoute` (implementation, `#[Route]`), `XRouteResponse extends StoreApiResponse`, service registration (`->public()`).
- **Route scope** via class attribute `#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]` (`StoreApiRouteScope::ID = 'store-api'`). Without a scope the `RouteScopeListener` fails. Reference: `core/Content/Product/SalesChannel/ProductListRoute.php`.
- The path starts with `/store-api/...`, the route name with `store-api.`. Arguments `Criteria`, `SalesChannelContext`, `RequestDataBag`, `Request` are injected via argument resolvers (`CriteriaValueResolver`).
- **Overriding = decorating**: the decorator extends `AbstractXRoute`, receives the original route via `.inner`, delegates in `load()`; `getDecorated()` returns the inner route. The base implementation throws `DecorationPatternException`.
- **Storefront usage**: the controller injects the `AbstractXRoute` (not the concrete one) and calls `->load(...)` directly in PHP (no HTTP round trip), e.g. `FormController` with `AbstractContactFormRoute`.
- **OpenAPI** in 6.7 via JSON under `src/Resources/Schema/StoreApi/*.json` or `AdminApi/` (the generator reads `<Bundle>/Resources/Schema/{StoreApi|AdminApi}`, `BundleSchemaPathCollection`). PHP `#[OA\...]` annotations are no longer used in the core.
- **Rate limiter**: config under `shopware.api.rate_limiter`, call `RateLimiter::ensureAccepted('<key>', $clientIp)`; violation → `RateLimitExceededException` (HTTP 429).
- Decision guide: only adjusting the Criteria/data of a core route → an event (`ProductListingCriteriaEvent`, `...LoadedEvent`) is often simpler; changing the behavior/response of a route → decorator; new endpoint → custom route.

Directory tree (plugin `SwagExample`):

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Core/Content/Example/SalesChannel/
    │   ├── AbstractExampleRoute.php
    │   ├── ExampleRoute.php
    │   ├── ExampleRouteResponse.php
    │   └── ExampleRouteDecorator.php        # optional
    ├── Storefront/Controller/ExampleController.php   # optional
    └── Resources/
        ├── config/{services.php, routes.php}
        └── Schema/StoreApi/example.json
```

## 3. Instructions

1. Create the plugin (T-02): `bin/console plugin:create SwagExample` (dev container: `docker compose exec web bin/console ...`).
2. Create the classes from section 4; the base route throws `DecorationPatternException` in `getDecorated()`.
3. Register the route in `services.php`, `->public()` (the core does this for all routes, e.g. `core/Content/DependencyInjection/contact_form.php`).
4. Create `routes.php` with an attribute import, then `cache:clear`.
5. Writing routes: `PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true` in `defaults` (example `ProductReviewSaveRoute`), `RequestDataBag` as argument, validation via `DataValidator` + `DataValidationDefinition`.
6. Cacheable read routes: `PlatformRequest::ATTRIBUTE_HTTP_CACHE => true` (like `ProductListRoute`); for invalidation see [T-41](./41-caching.md).
7. Put the OpenAPI JSON under `src/Resources/Schema/StoreApi/` (format like `core/Framework/Api/ApiDefinition/Generator/Schema/StoreApi/paths/contact-form.json`, `paths` keys without the `/store-api` prefix).
8. Install and check:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear
bin/console debug:router | grep swag-example
curl -X POST http://localhost/store-api/swag-example -H 'sw-access-key: <KEY>' -H 'Content-Type: application/json' -d '{"limit":2}'
```

9. Decorating: decorator with `->decorate(ExampleRoute::class)` and `service(Decorator::class . '.inner')`. Core model: `ResolvedCriteriaProductSuggestRoute` (decorates `ProductSuggestRoute`, priority `-2000`, `core/Content/DependencyInjection/product.php`). Core decorators carry no `#[Route]` of their own; the docs (`override-existing-route`) show one on the decorator, but it is not needed if the path is unchanged (assumption, see gaps).
10. Rate limiter: configuration under `shopware.api.rate_limiter` (see examples), use the key in the code via `ensureAccepted`; `ensureAcceptedIfConfigured()`/`resetIfConfigured()` are tolerant variants. Policies: `time_backoff`, `system_config` (Shopware-specific, `RateLimiterFactory::create`), otherwise Symfony (`fixed_window`, `sliding_window`, `token_bucket`). In the Storefront controller catch `RateLimitExceededException` (`getWaitTime()`), pattern `FormController`.
11. Admin API schemas analogously under `src/Resources/Schema/AdminApi/*.json` (the loaders merge all `*.json`: `paths` via `array_replace_recursive`, `components`/`tags` via `array_merge_recursive`, `OpenApiFileLoader`). Entity schemas are generated automatically; fields need the `ApiAware` flag for the Store API.

## 4. Examples

`src/Core/Content/Example/SalesChannel/AbstractExampleRoute.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract class AbstractExampleRoute
{
    abstract public function getDecorated(): AbstractExampleRoute;

    abstract public function load(Criteria $criteria, SalesChannelContext $context): ExampleRouteResponse;
}
```

`src/Core/Content/Example/SalesChannel/ExampleRouteResponse.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<EntitySearchResult<ProductCollection>>
 */
class ExampleRouteResponse extends StoreApiResponse
{
    public function getProducts(): ProductCollection
    {
        return $this->object->getEntities();
    }
}
```

`src/Core/Content/Example/SalesChannel/ExampleRoute.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class ExampleRoute extends AbstractExampleRoute
{
    /**
     * @param SalesChannelRepository<ProductCollection> $productRepository
     */
    public function __construct(private readonly SalesChannelRepository $productRepository)
    {
    }

    public function getDecorated(): AbstractExampleRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/swag-example',
        name: 'store-api.swag-example.search',
        methods: [Request::METHOD_GET, Request::METHOD_POST],
        defaults: [PlatformRequest::ATTRIBUTE_ENTITY => ProductDefinition::ENTITY_NAME],
    )]
    public function load(Criteria $criteria, SalesChannelContext $context): ExampleRouteResponse
    {
        return new ExampleRouteResponse($this->productRepository->search($criteria, $context));
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Core\Content\Example\SalesChannel\ExampleRoute;
use SwagExample\Core\Content\Example\SalesChannel\ExampleRouteDecorator;
use SwagExample\Storefront\Controller\ExampleController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ExampleRoute::class)
        ->public()
        ->args([service('sales_channel.product.repository')]);

    // optional: decorator
    $services->set(ExampleRouteDecorator::class)
        ->decorate(ExampleRoute::class)
        ->public()
        ->args([service(ExampleRouteDecorator::class . '.inner')]);

    // optional: Storefront controller (pattern from storefront/DependencyInjection/controller.php)
    $services->set(ExampleController::class)
        ->public()
        ->args([service(ExampleRoute::class)])
        ->call('setContainer', [service('service_container')]);
};
```

`src/Resources/config/routes.php`

```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Core/**/*Route.php', 'attribute');
    $routes->import('../../Storefront/Controller/*Controller.php', 'attribute');
};
```

`src/Core/Content/Example/SalesChannel/ExampleRouteDecorator.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ExampleRouteDecorator extends AbstractExampleRoute
{
    public function __construct(private readonly AbstractExampleRoute $decorated)
    {
    }

    public function getDecorated(): AbstractExampleRoute
    {
        return $this->decorated;
    }

    public function load(Criteria $criteria, SalesChannelContext $context): ExampleRouteResponse
    {
        $criteria->addAssociation('manufacturer');   // pre-processing

        return $this->decorated->load($criteria, $context);
    }
}
```

To extend core routes: `decorate(\Shopware\Core\...\ProductListRoute::class)` and extend the abstract class of the core route contract. Scaffold: [../templates/decorator/README.md](../templates/decorator/README.md).

`src/Storefront/Controller/ExampleController.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Storefront\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagExample\Core\Content\Example\SalesChannel\AbstractExampleRoute;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class ExampleController extends StorefrontController
{
    public function __construct(private readonly AbstractExampleRoute $exampleRoute)
    {
    }

    #[Route(path: '/swag-example', name: 'frontend.swag-example.list', methods: ['GET'], defaults: ['_httpCache' => true])]
    public function list(SalesChannelContext $context): Response
    {
        $products = $this->exampleRoute->load(new Criteria(), $context)->getProducts();

        return $this->renderStorefront('@SwagExample/storefront/page/example.html.twig', ['products' => $products]);
    }
}
```

Note: here the concrete `ExampleRoute` is injected in `services.php`; with a decorator, the decorated instance is delivered under the ID `ExampleRoute::class`. For controller details see [T-16](./16-storefront-controller-pages.md).

`src/Resources/Schema/StoreApi/example.json`

```json
{
    "openapi": "3.2.0",
    "info": [],
    "paths": {
        "/swag-example": {
            "post": {
                "tags": ["Product"],
                "summary": "Example product listing",
                "operationId": "readSwagExample",
                "parameters": [{ "$ref": "#/components/parameters/swLanguageId" }],
                "responses": {
                    "200": { "description": "Entity search result containing products." }
                }
            }
        }
    }
}
```

Rate limiter, configuration (project level, `config/packages/shopware.yaml`; structure like `core/Framework/Resources/config/packages/shopware.yaml` under `shopware.api.rate_limiter`):

```yaml
shopware:
    api:
        rate_limiter:
            swag_example_route:
                enabled: true
                policy: 'time_backoff'
                reset: '24 hours'
                limits:
                    - limit: 3
                      interval: '30 seconds'
                    - limit: 5
                      interval: '60 seconds'
```

Usage in `ExampleRoute::load()` (add the service arguments `service('shopware.rate_limiter')` and `service('request_stack')`):

```php
$ip = $this->requestStack->getMainRequest()?->getClientIp();
if ($ip !== null) {
    $this->rateLimiter->ensureAccepted('swag_example_route', $ip);   // throws RateLimitExceededException (429)
}
// optionally on success: $this->rateLimiter->reset('swag_example_route', $ip);
```

Class `Shopware\Core\Framework\RateLimiter\RateLimiter`. For plugins, the docs additionally describe `rate_limiter.yaml` plus a custom compiler pass (registered in `SwagExample::build()` with `PassConfig::TYPE_BEFORE_OPTIMIZATION`, priority >= 500, so that it runs before the core `RateLimiterCompilerPass`). ⚠ UNVERIFIED: this docs approach was not traced in the code.

⚠ UNVERIFIED: examples not run (no PHP in the host shell).

## 5. Pitfalls

- Missing/different route scope: scope error/404/access denied; set the scope as a class-level `#[Route(defaults: ...)]`.
- Route service not `public` or decorator without `.inner`: container/circular reference errors.
- `routes.php` missing or the glob does not match: route missing in `debug:router`; run `cache:clear` after changes.
- Concrete class instead of the abstract one injected in the controller: decorators are bypassed. (With a decorator behind the ID of the concrete class it still takes effect; better to use the abstract type in the constructor, like the core.)
- Calling `getDecorated()` of the base route: `DecorationPatternException`.
- Fields without `ApiAware` do not appear in the Store API response.
- Writing PHP OpenAPI annotations: no longer evaluated in 6.7, use JSON schema.
- Rate limiter key not configured: `ensureAccepted` throws `RateLimiterException::factoryNotFound`; configure it or use `ensureAcceptedIfConfigured`.
- Compiler pass with too low a priority: config is not merged (the core pass has already run).
- `_httpCache => true` without correct invalidation: stale data.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Route attribute `Symfony\Component\Routing\Attribute\Route` (instead of `Annotation\Route`); scope via `defaults` `_routeScope`, older `@Route(... defaults={"_routeScope"=...})` annotations are gone.
- OpenAPI formerly via `@OA\Get` PHP annotations; in 6.7 JSON (`Generator/Schema/StoreApi|AdminApi`), OpenAPI 3.2.0 (`core/Framework/Api/ApiDefinition/README.md`). Plugin schema folder `Resources/Schema/{StoreApi,AdminApi}`.
- Routing/services in PHP (`services.php`, `routes.php`); `routes.xml` is `@deprecated` for v6.8.0 (`core/Framework/Bundle.php::configureRoutes`), without effect in 6.8.
- Rate limiter service ID `shopware.rate_limiter`.
- `ATTRIBUTE_CONTEXT_TOKEN_REQUIRED`, `_noStore` (`PlatformRequest`): ⚠ UNVERIFIED, not explored in depth.

## 6. Checklist

- [ ] Abstract route, route, response and service registration (`->public()`) present
- [ ] Class-level `#[Route]` with `ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]`
- [ ] Path `/store-api/...`, name `store-api.*`
- [ ] `routes.php` imports the routes (no `routes.xml`), `cache:clear` run
- [ ] `debug:router` shows the route, tested via curl with `sw-access-key`
- [ ] Writing routes: login requirement and validation set
- [ ] Fields for the response with `ApiAware`
- [ ] Controllers inject the `AbstractXRoute`
- [ ] Rate limiter key configured, if used
- [ ] OpenAPI JSON under `Resources/Schema/StoreApi/`
- [ ] `_httpCache` only with well-thought-out invalidation

## 7. Sources

Docs (https://developer.shopware.com/docs/guides/plugins/plugins/framework/): `store-api/add-store-api-route.html`, `store-api/override-existing-route.html`, `rate-limiter/add-rate-limiter-to-api-route.html`. `/docs/resources/guidelines/code/store-api.html` was not fetched.

Source code (`core/`): `Framework/Routing/{StoreApiRouteScope,ApiRouteScope,RouteScopeListener,RouteScopeRegistry}.php`, `Framework/Routing/Annotation/CriteriaValueResolver.php`, `PlatformRequest.php`, `System/SalesChannel/{StoreApiResponse,NoContentResponse}.php`, `Content/Product/SalesChannel/{AbstractProductListRoute,ProductListRoute,ProductListResponse}.php`, `Review/ProductReviewSaveRoute.php`, `Suggest/ResolvedCriteriaProductSuggestRoute.php`, `Content/ContactForm/SalesChannel/ContactFormRoute.php`, `Content/DependencyInjection/contact_form.php`, `Framework/Plugin/Exception/DecorationPatternException.php`, `Framework/Bundle.php` (`configureRoutes`), `Framework/Api/ApiDefinition/Generator/{StoreApiGenerator,BundleSchemaPathCollection,OpenApiFileLoader}.php`, `Framework/RateLimiter/{RateLimiter,RateLimiterFactory}.php`, `Framework/DependencyInjection/CompilerPass/RateLimiterCompilerPass.php`, `Framework/Resources/config/packages/shopware.yaml`. Storefront: `Controller/FormController.php`, `DependencyInjection/controller.php`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: examples not run, based on core code.
- ⚠ GAP: the docs `override-existing-route` show `#[Route]` on the decorator, core decorators have none; functioning without `#[Route]` derived from the core pattern, not tested.
- ⚠ UNVERIFIED: the docs variant of the rate limiter (`rate_limiter.yaml` + plugin compiler pass) not verified against the code; only the parameter `shopware.api.rate_limiter` and the core pass are documented.
- ⚠ UNVERIFIED: OpenAPI plugin schema (required fields `info`/tags) not validated; endpoint `/store-api/_info/openapi3.json` from memory.
- Storefront controller registration documented: `storefront/DependencyInjection/controller.php` exists in the package and sets `->call('setContainer', [service('service_container')])` per controller (among others lines 156, 175, 187); the example follows this pattern (plugin-specific execution not tested).
- ⚠ UNVERIFIED: service ID `sales_channel.product.repository` assumed from core convention (documented in `seo.php`, see T-28).
