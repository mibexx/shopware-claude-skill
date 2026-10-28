# Template: decorator (service decoration)

Shopware 6.7.15.1. Two variants of the decoration pattern: (A) make your own service decoratable (`Abstract*` + base class + decorator) and (B) decorate a core service (most common case, here `ProductListingRoute`).

## File tree

```
SwagExample/
└── src/
    ├── Service/
    │   ├── AbstractGreetingService.php      # abstract base with getDecorated()
    │   ├── GreetingService.php              # base implementation (throws DecorationPatternException)
    │   └── GreetingServiceDecorator.php     # decorator (A)
    └── Decorator/
        └── ProductListingRouteDecorator.php # decorator of a core service (B)
```

With variant B, `AbstractGreetingService`/`GreetingService` are not needed: only the decorator class plus registration is required. Root scaffold: ../plugin/README.md

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample\Service`, `SwagExample\Decorator` | Namespaces | your own namespace |
| `GreetingService` / `AbstractGreetingService` | your own decoratable service (variant A) | your own service, or omit variant A |
| `AbstractProductListingRoute` / `ProductListingRoute` | decorated core service (variant B): abstract type and service ID | target abstract class and service ID |
| `$criteria->setLimit(12)` | example logic | your own logic |
| Priority (`null, -3000`) | position in the decoration chain | see below |

## Registration (excerpt for `src/Resources/config/services.php`)

```php
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use SwagExample\Decorator\ProductListingRouteDecorator;
use SwagExample\Service\GreetingService;
use SwagExample\Service\GreetingServiceDecorator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Variant A: your own service
$services->set(GreetingService::class);

$services->set(GreetingServiceDecorator::class)
    ->decorate(GreetingService::class)
    ->args([service(GreetingServiceDecorator::class . '.inner')]);

// Variant B: core service. The core service is public, so the decorator is too (PublicServiceDecoratorRule).
$services->set(ProductListingRouteDecorator::class)
    ->decorate(ProductListingRoute::class)
    ->public()
    ->args([service(ProductListingRouteDecorator::class . '.inner')]);
```

`->decorate(<Id>, null, <priority>)`: higher priority is applied first and sits further inside; if omitted, 0 applies, i.e. inside all core decorators with negative priority (e.g. `ResolveCriteriaProductListingRoute` with `-2000`). If the decorator should sit outside, choose a lower priority (e.g. `-3000`). The order with equal priority is not reliable. Wrong service ID: `ServiceNotFoundException` during the container build; can be made optional with `ContainerInterface::IGNORE_ON_INVALID_REFERENCE` as the fourth parameter. The short form `service('.inner')` from the docs is also Symfony-compliant (not executed).

## Integration / CLI

```bash
bin/console plugin:refresh && bin/console plugin:install --activate -c SwagExample
bin/console cache:clear
bin/console debug:container 'Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute'   # shows the decorator
```

Check the target service beforehand: abstract base with `getDecorated()` present, neither class nor base `@internal`/`@final`; and whether an event/extension point is enough (prefer it if you only need to react): `grep -n "getDecorated\|@internal\|@final\|final class" vendor/shopware/core/<path>.php`.

## Rules and pitfalls

- Type the constructor with the ABSTRACT class, not the concrete one (otherwise the service drops out of the chain).
- Always call the inner service (unless deliberately replacing it), otherwise core behavior and other plugins break.
- `getDecorated()` of the base class throws `DecorationPatternException`; only meaningful on decorators.
- Do not change the method signatures of the abstract; no additional public methods, no `EventSubscriberInterface` in the decorator.
- No `#[Route]` on the decorator (core convention, `NoRouteOverrideInDecoratorsRule`).
- Do not decorate concrete classes without an abstract base (e.g. `SystemConfigService`); likewise not `@internal`/`final`. Better: use the layer intended for this (e.g. loader chain) or events.
- Public core service decorated: decorator `->public()`.

## Note: decorating Store API routes

Store API routes are overridden exclusively via this decoration pattern (variant B): the decorator extends `AbstractXRoute`, delegates to `getDecorated()` in `load()`, and is registered with `->decorate(XRoute::class)` and `->public()`. Custom routes (new `AbstractXRoute`/`XRoute`/`XRouteResponse`, `#[Route]` with route scope `store-api`, `routes.php` with `$routes->import('../../Core/**/*Route.php', 'attribute')`, OpenAPI schema as JSON under `src/Resources/Schema/StoreApi/`, rate limiter) are not part of this template. The storefront controller injects the abstract route and calls it directly.

## References

- ../../references/06-service-decoration.md
- ../../references/05-registering-services.md
- ../../references/08-extension-points.md
- ../../references/07-events-subscriber.md
- Templates: ../plugin/README.md, ../event-subscriber/README.md

## Notes and gaps

- Checked: `AbstractProductListingRoute::load(string, Request, SalesChannelContext, Criteria): ProductListingRouteResponse` and `getDecorated()` against the source code; core registration `decorate(ProductListingRoute::class, null, -2000)->public()` in `core/Content/DependencyInjection/product.php`; `DecorationPatternException(string $class)`.
- Verified live (review rounds 2–3, Shopware 6.7.15.1): The decorator with priority 0 sits inside `ResolveCriteriaProductListingRoute` (priority −2000); the Store API returns `limit: 12`.
- ⚠ UNVERIFIED: The docs example shows `#[Route]` on the decorator, the core uses none; that it works without one is derived from the core pattern.
- Verified live: decorator registration in plugin `services.php` (variant B with `defaults()`) works. ⚠ UNVERIFIED: runtime behavior of a decorator without `->public()` when the original is public; Symfony normally carries over the visibility, the template sets it explicitly.
- ⚠ UNVERIFIED: Symfony's `#[AsDecorator]` is not used in the core and is not recommended here.
- ⚠ GAP: A complete Store API route (see note above), extension points (`Extension::onPre/onPost`) and decoration of loader chains close to `SystemConfigService` are not included as templates.
