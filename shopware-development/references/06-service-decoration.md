# Decorating and overriding existing services (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when the behavior of an existing core or plugin service is to be changed or replaced without touching core code: the decorator pattern with an `Abstract*` class and `getDecorated()`. It also helps with deciding between decoration and events.

Scope: registering new services in [T-05](./05-registering-services.md); events in [T-07](./07-events-subscriber.md); extension points in [T-08](./08-extension-points.md); overriding Store API routes (route attributes, responses) in [T-30](./30-store-api-routes.md). Scaffold: [../templates/decorator/README.md](../templates/decorator/README.md).

## 2. Concepts

- **Decorating** = registering your own service that replaces an existing one via `->decorate(<Id>)` and receives the original (`.inner`) via its constructor. The container swaps the Id; all consumers get the decorator.
- **Decoration pattern (Shopware convention)**: `Abstract<Name>` with `abstract getDecorated(): Abstract<Name>`. The core implementation throws the `DecorationPatternException` in `getDecorated()`; decorators return the inner instance. Consumers always type-hint the abstract class.
- **Only decorate what is intended for it**: abstract class with `getDecorated()`, not `@internal`, not `@final`/`final`. Concrete classes without an abstract base (e.g. `SystemConfigService`) are not meant for this.
- **Decoration vs. event**: the docs say, in essence, "Prefer events when you need to react to something Shopware does. Prefer service decoration when you need to change how an existing service behaves." If an event or extension point exists, prefer it.
- **Priority**: `decorate(Id, null, <priority>)`. Higher priority is applied first and sits inside (closer to the original); lower priority sits further outside. Equal priority: registration order (first = inside).

```text
custom/plugins/SwagExample/src/
├── Resources/config/services.php
├── Service/
│   ├── AbstractGreetingService.php      # Abstract base with getDecorated()
│   ├── GreetingService.php              # Base (throws DecorationPatternException)
│   └── GreetingServiceDecorator.php
└── Core/ListingRouteDecorator.php       # most common case: decorating a core service
```

Most of the time you decorate a **core** service; in that case `AbstractGreetingService`/`GreetingService` are not needed.

### Priorities in the core (example `System/DependencyInjection/configuration.php`)

| Decorator | Priority | Position |
|---|---|---|
| `SystemConfigLoader` (base) | - | innermost |
| `CachedSystemConfigLoader` | -1000 | wraps base |
| `ConfiguredSystemConfigLoader` | -1500 | wraps Cached |
| `MemoizedSystemConfigLoader` | -2000 | outermost |

Without an explicit value, priority 0 applies: the plugin decorator sits **inside** all core decorators with negative priority (e.g. in front of the core caching). If it should sit outside, choose a more negative priority than the outermost core decorator (e.g. `-3000`). Do not rely on the order of several plugins with equal priority (plugin load order).

## 3. Instructions

1. **Check the target service**: is there an `Abstract*` base with `getDecorated()`, and is neither the class nor the base `@internal`/`@final`/`final`?
   `grep -n "getDecorated\|@internal\|@final\|final class" vendor/shopware/core/<path>.php`
2. **Check for an event/extension point** (e.g. `ProductListingCriteriaEvent`, extensions in `Content/Product/Extension/*`, base `Framework/Extensions/Extension.php` with `onPre()/onPost()/onError()`). If one fits, do not build a decorator.
3. **Determine the service Id**: usually the FQCN of the concrete core class (`ProductListingRoute::class`); some have string Ids (`product.repository`). `bin/console debug:container --show-arguments <Id>`.
4. **Write the decorator**: extends the abstract class, the constructor takes the **abstract** class, `getDecorated()` returns it, methods call the inner service (where it makes sense). No additional public methods, no `EventSubscriberInterface` (`DecorationPatternRule`; only listed in `core-rules.neon`, not in `rules.neon`, see [T-48](./48-static-analysis.md)).
5. **services.php**: pass `->decorate(<Id>[, null, <priority>])` and `service(<DecoratorId>.inner)`. If the decorated service is `public()`, set the decorator to `public()` as well (`PublicServiceDecoratorRule`).
6. **Activate**: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`
7. **Verify**: `bin/console debug:container <Id>` shows the decorator, `<DecoratorId>.inner` the original.
8. Optional decoration: 4th parameter of `decorate()` = `ContainerInterface::IGNORE_ON_INVALID_REFERENCE`.

## 4. Examples

### 4a. Making your own service decoratable

`src/Service/AbstractGreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

abstract class AbstractGreetingService
{
    abstract public function getDecorated(): AbstractGreetingService;

    abstract public function greet(string $name): string;
}
```

`src/Service/GreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class GreetingService extends AbstractGreetingService
{
    public function getDecorated(): AbstractGreetingService
    {
        throw new DecorationPatternException(self::class);
    }

    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}
```

`src/Service/GreetingServiceDecorator.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

class GreetingServiceDecorator extends AbstractGreetingService
{
    public function __construct(private readonly AbstractGreetingService $inner)
    {
    }

    public function getDecorated(): AbstractGreetingService
    {
        return $this->inner;
    }

    public function greet(string $name): string
    {
        // Always call the inner service, otherwise the chain breaks for other plugins
        return $this->inner->greet($name) . '!';
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Service\GreetingService;
use SwagExample\Service\GreetingServiceDecorator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(GreetingService::class);

    $services->set(GreetingServiceDecorator::class)
        ->decorate(GreetingService::class)
        ->args([service(GreetingServiceDecorator::class . '.inner')]);
};
```

The docs write `service('.inner')` (Symfony shorthand, also valid); the explicit reference `<DecoratorId>.inner` matches the core style.

### 4b. Decorating a core service (most common case)

Core model (`Content/DependencyInjection/product.php`, `ResolveCriteriaProductListingRoute`):

```php
$services->set(ResolveCriteriaProductListingRoute::class)
    ->decorate(ProductListingRoute::class, null, -2000)
    ->public()
    ->args([service(ResolveCriteriaProductListingRoute::class . '.inner'), ...]);
```

Plugin decorator of the Store API route (decoration only, without its own `#[Route]`), `src/Core/ListingRouteDecorator.php`:

```php
<?php declare(strict_types=1);

namespace SwagExample\Core;

use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class ListingRouteDecorator extends AbstractProductListingRoute
{
    public function __construct(private readonly AbstractProductListingRoute $inner)
    {
    }

    public function getDecorated(): AbstractProductListingRoute
    {
        return $this->inner;
    }

    public function load(string $categoryId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductListingRouteResponse
    {
        $criteria->setLimit(12); // Example: adjust the criteria before the call

        return $this->getDecorated()->load($categoryId, $request, $context, $criteria);
    }
}
```

`src/Resources/config/services.php` (excerpt, with all required `use` statements):

```php
<?php declare(strict_types=1);

use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use SwagExample\Core\ListingRouteDecorator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $configurator->services()
        ->set(ListingRouteDecorator::class)
        ->decorate(ProductListingRoute::class)
        ->args([service(ListingRouteDecorator::class . '.inner')]);
};
```

For whether `->public()` is needed, see Pitfalls (the core sets it on its own decorator). Details on Store API routes in [T-30](./30-store-api-routes.md).

## 5. Pitfalls

- **Type-hinting the concrete class**: a constructor with `GreetingService` instead of `AbstractGreetingService` unhooks the service from the chain.
- **Not calling the inner service**: the chain breaks, other plugins/core behavior are lost ("breaks silently").
- **`getDecorated()` on the base class**: throws `DecorationPatternException` ("The getDecorated() function of core class %s cannot be used. This class is the base class.", HTTP 500). Only meaningful on decorators.
- **Decorating `@internal`/`final` classes**: no stability guarantee, no BC. In the core, `DecorationPatternRule` forbids `@internal`/`@final` on classes with the decoration pattern; `@final` = usable, not extensible; `@internal` = private API. Only by annotation, not technically enforced, hence not recommended.
- **Concrete class without an abstract base**, e.g. `SystemConfigService` (constructor `@internal`, `->public()->lazy()`): the decorator would have to `extends SystemConfigService` and pass on all constructor arguments, and breaks on updates. Better to decorate the intended layer (`AbstractSystemConfigLoader`, Id `SystemConfigLoader::class`) or use the events under `System/SystemConfig/Event/*`.
- **Decorating an event subscriber class**: incompatible with `EventSubscriberInterface` (`DecorationPatternRule`).
- **Decorator with its own `#[Route]`**: forbidden (`NoRouteOverrideInDecoratorsRule`); only the core service defines routes.
- **Public service rule**: a decorator of a `public()` service without `->public()` triggers `PublicServiceDecoratorRule` (PHPStan). At runtime, Symfony handles the visibility itself: `DecoratorServicePass::process()` remembers `$public` of the decorated service (lines 73, 78, 86), makes its definition private under the renamed Id (`<Id>.inner`) and sets `setAlias($inner, $id)->setPublic($public)` (line 115); the original Id therefore stays public. `->public()` on the decorator is thus not a runtime requirement but a Shopware convention (PHPStan rule) and useful for readability (`vendor/symfony/dependency-injection/Compiler/DecoratorServicePass.php`).
- **Changing the signature**: even optional additional parameters break all decorators (docs). Introduce new methods as non-abstract at first.
- **Wrong service Id**: `ServiceNotFoundException` during container build (`DecoratorServicePass`); make it optional with `IGNORE_ON_INVALID_REFERENCE`.
- **Multiple decoration without priority**: unintended position relative to the core caching (see priorities).
- **XML services**: deprecated in 6.7, new plugins use `services.php`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- The 6.5/6.6 docs showed `services.xml`; in 6.7.15.1 `services.php` is the standard, XML still loads with a deprecation, removal in `v6.8.0` (`Bundle::registerContainerFile`). Core service files are `*/DependencyInjection/*.php`; look up priority and Id there (no XML service files with `decorates` exist in the installed core, checked via grep).
- The pattern itself (`Abstract*`, `getDecorated()`, `DecorationPatternException`) applies unchanged; PHPStan rules in the core safeguard it.
- New in 6.7 as an alternative: extension points (`Framework/Extensions/Extension.php`, `ExtensionDispatcher`, e.g. `ProductListingCriteriaExtension`, events `<name>.pre/.post/.error`), see [T-08](./08-extension-points.md).

## 6. Checklist

- [ ] Event or extension point checked and found insufficient.
- [ ] Target class has an `Abstract*` base with `getDecorated()`, is not `@internal`/`@final`.
- [ ] Decorator extends the abstract class, constructor type-hints the abstract class.
- [ ] `getDecorated()` returns `$inner`; methods call `$inner`.
- [ ] `decorate(<Id>)` with the correct Id and `service(<DecoratorId>.inner)`.
- [ ] For a `public()` original: decorator also `->public()`.
- [ ] Priority chosen deliberately (inside/outside relative to core decorators).
- [ ] No own `#[Route]` in the decorator.
- [ ] `cache:clear` done and `debug:container <Id>` shows the decorator.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/services/adjusting-service.html (or `.../plugin-fundamentals/adjusting-service.html`, both reachable)
- https://developer.shopware.com/docs/resources/guidelines/code/core/decorator-pattern.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/final-and-internal.html

Source code (relative to `vendor/shopware/`): `core/Framework/Plugin/Exception/DecorationPatternException.php`; `core/Content/Product/SalesChannel/Listing/{AbstractProductListingRoute,ProductListingRoute,ResolveCriteriaProductListingRoute}.php`; `core/Content/DependencyInjection/product.php` (`decorate(ProductListingRoute::class, null, -2000)`); `core/System/SystemConfig/{AbstractSystemConfigLoader,CachedSystemConfigLoader,ConfiguredSystemConfigLoader,MemoizedSystemConfigLoader,SystemConfigLoader,SystemConfigService}.php`; `core/System/DependencyInjection/configuration.php`; `core/DevOps/StaticAnalyze/PHPStan/Rules/{DecorationPatternRule,PublicServiceDecoratorRule,NoRouteOverrideInDecoratorsRule}.php`; `core/Framework/Plugin/Command/Scaffolding/stubs/store-api-{abstract-,}route.stub`; `core/Framework/Bundle.php`; `core/Framework/Extensions/Extension.php`; Symfony `DecoratorServicePass`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: The plugin examples are self-written (docs pattern), not executed in a running installation; `bin/console debug:container` not executed. Signatures of `AbstractProductListingRoute` (`getDecorated`, `load`) verified against the source code.
- ⚠ UNVERIFIED: `service('.inner')` (docs) not executed locally; order at equal priority only derived from Symfony code, plugin load order not checked.
- Confirmed (code, not executed): Symfony transfers the visibility of the decorated service to the alias (`DecoratorServicePass.php` lines 73-86, 115); `->public()` on the decorator is only a Shopware PHPStan convention.
- ⚠ GAP: `#[AsDecorator]` (Symfony) is not used in the core; usage in plugins not confirmed, hence not recommended. Introduction version of extension points not verified.
