# Extension points (reference, Shopware 6.7.15.1)

## 1. Purpose

Extension points are typed hook points through which a plugin can replace core logic (`pre`), post-process it (`post`) or catch errors (`error`), with a typed result. This reference applies when a core extension point is to be consumed or a custom one published.

Scope: pure observation/enrichment via events ([T-07](./07-events-subscriber.md)); replacing an entire service via decoration ([T-06](./06-service-decoration.md)); flow triggers/actions in [T-09](./09-flow-builder.md). Docs recommendation: extension points for changing core logic, events for side effects (mail, logging, external systems).

## 2. Concepts

- Base class `Shopware\Core\Framework\Extensions\Extension` (abstract, `StoppableEventInterface`): inputs as `public readonly` properties, plus mutable `$result` and `$exception`. Core extension classes are `final`.
- Publisher: `ExtensionDispatcher::publish(string $name, Extension $extension, callable $function)` (`final readonly`, constructor `@internal`, service ID = class name). The default logic is passed as a closure.
- Flow of `publish()` (verified against the source code):
  1. dispatch `{NAME}.pre`
  2. if propagation has not been stopped: `$extension->result = $function(...$extension->getParams())`; on `\Throwable`: set `exception`, reset propagation, dispatch `{NAME}.error`; if `result === null` afterwards, the exception is rethrown, otherwise swallowed
  3. `resetPropagation()`, dispatch `{NAME}.post` (runs **always**, even after `stopPropagation()` in `pre`)
  4. return `$extension->result()`
- `pre` + setting `result` + `stopPropagation()` replaces the core logic; `post` changes the result; `error` can catch the error by setting `result`.
- Event names in the subscriber: `MyExtension::onPre()`, `::onPost()`, `::onError()` (read `static::NAME`) or the string `'<NAME>.pre'`.
- Exceptions from `pre` listeners do not pass through the `try` and propagate straight up.

Extension points in 6.7.15.1 (all in `core/`; there are none outside `core/`):

| NAME | Class (under `core/`) | Result | Parameters |
|---|---|---|---|
| `checkout.place-order` | `Checkout/Cart/Extension/CheckoutPlaceOrderExtension` | `OrderPlaceResult` | `cart`, `context`, `data` |
| `checkout.cart.rule-load` | `Checkout/Cart/Extension/CheckoutCartRuleLoaderExtension` | `RuleLoaderResult` | `salesChannelContext`, `originalCart`, `cartBehavior`, `new` (`protected readonly bool`, not public API, but included in `getParams()` and therefore a required parameter of the closure; `CartRuleLoader::_load(...)`) |
| `html-renderer` | `Checkout/Document/Extension/HtmlRendererExtension` | `string` | `document` |
| `pdf-renderer` | `Checkout/Document/Extension/PdfRendererExtension` | `string` | `document` |
| `cms-slots-data.collect` | `Content/Cms/Extension/CmsSlotsDataCollectExtension` | `array<string, CriteriaCollection>` | `slots`, `resolverContext` |
| `cms-slots-data.enrich` | `Content/Cms/Extension/CmsSlotsDataEnrichExtension` | `CmsSlotCollection` | `slots`, `criteriaList`, `identifierResult`, `criteriaResult`, `resolverContext` |
| `cms-slots-data.resolve` | `Content/Cms/Extension/CmsSlotsDataResolveExtension` | `CmsSlotCollection` | `slots`, `resolverContext` |
| `flow.executor` | `Content/Flow/Extension/FlowExecutorExtension` | `void` | `flow`, `event` |
| `remote_thumbnail_url.resolve` | `Content/Media/Extension/ResolveRemoteThumbnailUrlExtension` | `string\|null` | `mediaUrl`, `mediaPath` (deprecated), `width`, `height`, `pattern`, `mediaUpdatedAt` (deprecated), `mediaEntity` (`MediaEntity\|PartialEntity`) |
| `listing-loader.load-previews` | `Content/Product/Extension/LoadPreviewExtension` | `array` | `ids`, `context` |
| `product.listing.criteria` | `Content/Product/Extension/ProductListingCriteriaExtension` | `Criteria` | `criteria`, `context`, `categoryId` |
| `product.calculate-prices` | `Content/Product/Extension/ProductPriceCalculationExtension` | `void` | `products`, `context` |
| `listing-loader.resolve` | `Content/Product/Extension/ResolveListingExtension` | `EntitySearchResult<ProductCollection>` | `criteria`, `context` |
| `listing-loader.resolve-listing-ids` | `Content/Product/Extension/ResolveListingIdsExtension` | `IdSearchResult` | `criteria`, `context` |
| `sitemap.get-file` | `Content/Sitemap/Extension/SitemapFileExtension` | `Response` | `request`, `context`, `filePath` |
| `cache-hash.required` | `Framework/Adapter/Cache/Http/Extension/CacheHashRequiredExtension` | `bool` | `request`, `salesChannelContext`, `cart` |
| `cache-response.resolve-rule-areas` | `Framework/Adapter/Cache/Http/Extension/ResolveCacheRelevantRuleIdsExtension` | `array<string>` | `request`, `ruleAreas`, `salesChannelContext` |
| `canonical-redirect` | `Framework/Routing/Extension/CanonicalRedirectExtension` | `?Response` | `request` |
| `sales-channel-file.render-parameters` | `System/SalesChannel/File/Rendering/Extension/SalesChannelFileRenderParametersExtension` | `array<string,mixed>` | `file`, `context`, `salesChannel` (complete according to the constructor) |
| `storefront.snippets` | `System/Snippet/Extension/StorefrontSnippetsExtension` | `array<string,string>` | `snippets`, `locale`, `catalog`, `snippetSetId`, `fallbackLocale`, `salesChannelId`, `unusedThemes` |

`FrameworkExtension` and `ServiceExtension` are Symfony DI extensions, not extension points (filter them out when grepping).

Directory tree of a plugin with its own extension point:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Extension/ShippingLabelExtension.php
    ├── Service/ShippingLabelService.php
    ├── Subscriber/ShippingLabelSubscriber.php
    └── Resources/config/services.php
```

## 3. Instructions

**A. Finding**
1. `grep -rl "extends Extension\b" vendor/shopware/core --include=*.php` (folders `*/Extension/`; ignore `DependencyInjection` hits).
2. Publisher: `grep -rn "ExtensionDispatcher\|->publish(" vendor/shopware/core`. There you can see the core closure and its parameters.
3. Event name = `NAME` + `.pre|.post|.error`; the extension class is the listener's type hint.
4. At runtime: `bin/console debug:event-dispatcher "listing-loader.resolve.pre"` (Symfony standard, not verified for extension names).

**B. Consuming**
1. Subscriber with `EventSubscriberInterface`, names via `XyzExtension::onPre()` etc.; method signature = extension class.
2. `pre`: set `result` **and** call `stopPropagation()` (otherwise the core closure runs and overwrites `result`).
3. `post`: read/modify `$extension->result()`.
4. `error`: evaluate `$extension->exception`; setting `result` = swallowing the error.
5. Register as `kernel.event_subscriber`; priority via `[Name::onPre() => ['method', 100]]`.

**C. Creating a custom extension point**
1. `final class XyzExtension extends Extension` with `@extends Extension<ResultType>` and `public const NAME` (convention `<plugin>.<action>`).
2. Inputs as `public readonly` constructor properties; **names = parameter names of the default logic**.
3. Default logic as a method, passed to `publish()` as a first-class callable `$this->method(...)`.
4. Inject `ExtensionDispatcher`.
5. `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`.

## 4. Examples

`src/Extension/ShippingLabelExtension.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Extension;

use Shopware\Core\Framework\Extensions\Extension;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * @extends Extension<string>
 */
final class ShippingLabelExtension extends Extension
{
    // Required: read by onPre()/onPost()/onError() via static::NAME
    public const NAME = 'swag-example.shipping-label';

    public function __construct(
        public readonly string $orderNumber,
        public readonly SalesChannelContext $context,
    ) {
    }
}
```

`src/Service/ShippingLabelService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagExample\Extension\ShippingLabelExtension;

class ShippingLabelService
{
    public function __construct(private readonly ExtensionDispatcher $extensions)
    {
    }

    public function createLabel(string $orderNumber, SalesChannelContext $context): string
    {
        return $this->extensions->publish(
            name: ShippingLabelExtension::NAME,
            extension: new ShippingLabelExtension($orderNumber, $context),
            function: $this->doCreateLabel(...),
        );
    }

    // Parameter name MUST match the extension's property name (spread with string keys = named arguments)
    private function doCreateLabel(string $orderNumber, SalesChannelContext $context): string
    {
        return 'DEFAULT-' . $orderNumber;
    }
}
```

`src/Subscriber/ShippingLabelSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use SwagExample\Extension\ShippingLabelExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ShippingLabelSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ShippingLabelExtension::onPre() => 'replaceForVip',
            ShippingLabelExtension::onPost() => 'prefixResult',
            ShippingLabelExtension::onError() => 'fallback',
        ];
    }

    public function replaceForVip(ShippingLabelExtension $extension): void
    {
        if (!str_starts_with($extension->orderNumber, 'VIP')) {
            return; // Let the core logic run
        }

        $extension->result = 'VIP-LABEL-' . $extension->orderNumber;
        $extension->stopPropagation(); // Closure is skipped, post still runs
    }

    public function prefixResult(ShippingLabelExtension $extension): void
    {
        $extension->result = '[' . $extension->result() . ']';
    }

    public function fallback(ShippingLabelExtension $extension): void
    {
        // $extension->exception contains the error; setting a result = exception is not rethrown
        $extension->result = 'FALLBACK-' . $extension->orderNumber;
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use SwagExample\Service\ShippingLabelService;
use SwagExample\Subscriber\ShippingLabelSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ShippingLabelService::class)
        ->args([service(ExtensionDispatcher::class)]);

    $services->set(ShippingLabelSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

Result: `createLabel('1000', $ctx)` yields `[DEFAULT-1000]`, `createLabel('VIP1', $ctx)` yields `[VIP-LABEL-VIP1]`.

Core example for paired pre/post/error (`core/Checkout/Promotion/Cart/PromotionRedemptionLocker.php`):
```php
public static function getSubscribedEvents(): array
{
    return [
        CheckoutPlaceOrderExtension::onPre() => 'acquireLocks',
        CheckoutPlaceOrderExtension::onError() => 'releaseLocks',
        CheckoutPlaceOrderExtension::onPost() => 'releaseLocks',
    ];
}
```
State is created in `pre` via `$extension->addExtension(...)` (`ExtendableTrait`) and cleaned up in `post`/`error`. Another `post` example: `core/System/SalesChannel/File/Rendering/SalesChannelFileStoreApiMcpSubscriber.php`.

Basic plugin scaffold: [../templates/plugin/README.md](../templates/plugin/README.md); subscriber scaffold: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Pitfalls

1. **Closure parameter names do not match the extension properties.** `getParams()` (`get_object_vars($this)` in the scope of the base class, hence also `protected` properties such as `new` in `checkout.cart.rule-load`) returns an associative array (all properties except `result`, `propagationStopped`, `exception`, `extensions`); the spread acts as named arguments, an unknown/missing name results in `\Error`. The docs (creating-custom-extension) show a parameterless closure `function() use (...)`: not runnable for extensions with properties (`publish()` calls `$function(...$extension->getParams())`; PHP throws `\Error` for unknown named arguments; derived from the code, not executed).
2. `stopPropagation()` without `result`: core logic skipped, result `null` (with a non-nullable type, `TypeError` at the caller).
3. Setting `result` in `pre` but not stopping: the core closure overwrites it.
4. `post` always runs, even after `stopPropagation()` in `pre`.
5. `error` only runs if the closure threw; exceptions from `pre` listeners are not caught.
6. A `result` in the `error` listener silently suppresses the exception; for `void` extensions (`FlowExecutorExtension`, `ProductPriceCalculationExtension`) there is no meaningful value for it.
7. `NAME` missing, or name hard-coded in the listener: the docs mix `my-plugin.custom-extension` with `my-plugin.product-filter.pre`. Always use `Class::onPre()`.
8. Core extension classes are `final`, constructor `@internal`: do not extend/instantiate them; public are the class name, `NAME` and the `public` properties.
9. `readonly` properties cannot be changed; deliberately not readonly are e.g. `ResolveListingIdsExtension::$criteria`, `ResolveCacheRelevantRuleIdsExtension::$ruleAreas`, `StorefrontSnippetsExtension::$snippets`. Otherwise go through `result`.
10. `stopPropagation()` in a listener only skips the listeners of the same event that come after it in order (Symfony checks `isPropagationStopped()` before each listener); listeners before it have already run. In `pre`, the core closure is also skipped. `publish()` calls `resetPropagation()` before `post` (and, after an error, before `error`): a stop in `pre` or `error` does not affect `post`; a stop in `post` only halts further `post` listeners.
11. Using an extension point instead of an event for pure side effects is wrong; decoration only if no extension point exists (multiple plugins with the same decoration break each other, listener chains do not).

### Deprecations and version notes (6.5/6.6 → 6.7)

- `ResolveRemoteThumbnailUrlExtension`: properties `mediaPath` and `mediaUpdatedAt` are `@deprecated tag:v6.8.0` (value in `mediaEntity` instead). Otherwise no `@deprecated` in `core/Framework/Extensions`.
- XML service definitions are deprecated for plugins in 6.7; use `services.php` ([T-05](./05-registering-services.md)).
- 6.5/6.6: extension usually via events and decoration (`AbstractXyzRoute::getDecorated()`); in 6.7 additionally extension points. Example: `CartOrderRoute` publishes events and uses `checkout.place-order`; `getDecorated()` throws `DecorationPatternException`.
- `CheckoutPlaceOrderExtension` additionally implements `ShopwareSalesChannelEvent` and `CartEvent`.
- ⚠ UNVERIFIED: Introduction version of extension points (assumption: 6.6/6.7 line, not present in 6.5).

## 6. Checklist

- [ ] Check whether an extension point exists for the goal (table/grep) before decorating
- [ ] Listener uses `XyzExtension::onPre()/onPost()/onError()` instead of strings
- [ ] `pre` replacement sets `result` and calls `stopPropagation()`
- [ ] `post` logic accounts for `post` running even after a stop
- [ ] `error` listener sets `result` only deliberately (otherwise the exception is swallowed)
- [ ] Custom extension point: `final`, `NAME`, `@extends Extension<T>`, `public readonly` properties
- [ ] Closure parameter names = property names of the extension
- [ ] `ExtensionDispatcher` injected via service ID (class name)
- [ ] Subscriber tagged with `kernel.event_subscriber`, `cache:clear` run

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/finding-extensions.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/creating-custom-extension.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/extension-vs-events.html

Source code (`vendor/shopware/`):
- `core/Framework/Extensions/Extension.php` (`onPre/onPost/onError`, `result()`, `getParams()`, `stopPropagation()`, `resetPropagation()`), `core/Framework/Extensions/ExtensionDispatcher.php` (`publish`, `pre`, `post`, `error`)
- `core/Framework/DependencyInjection/event.php:42` (service registration), `core/Framework/Struct/ExtendableTrait.php`
- `core/Framework/Routing/CanonicalRedirectService.php` (publisher pattern), `core/Checkout/Cart/SalesChannel/CartOrderRoute.php:93-97`, `core/Checkout/Cart/CartRuleLoader.php`
- `core/Checkout/Promotion/Cart/PromotionRedemptionLocker.php`
- `core/Content/Product/SalesChannel/Listing/ProductListingLoader.php`, `core/Content/Product/SalesChannel/Price/ProductPriceCalculator.php:56`, `core/Framework/Adapter/Cache/Http/CacheHeadersService.php:51`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Introduction version of extension points (6.5/6.6 vs. 6.7).
- Error with a parameterless closure (pitfall 1): `ExtensionDispatcher::publish` spreads `getParams()` with string keys (named arguments); per PHP semantics `\Error` for an unknown name. Derived from the code, not executed; docs examples are sketches.
- ⚠ UNVERIFIED: `debug:event-dispatcher <name>` not executed; example not tested in a running installation.
- Confirmed: the constructor of `SalesChannelFileRenderParametersExtension` has exactly `file`, `context`, `salesChannel`; all parameter lists in the table checked against the constructors of the 20 extension classes (`canonical-redirect`: only `request`; `html-renderer`/`pdf-renderer`: only `document`).
- Order/priority of multiple listeners follows the Symfony standard (not checked specifically for Shopware).
- The docs statement "Events fire after actions" is a generalization; many events (e.g. `*CriteriaEvent`) fire before the action.
