# Template: event-subscriber (subscriber and custom event)

Shopware 6.7.15.1. Three subscribers (DAL loaded, DAL written plus `PreWriteValidationEvent`, storefront page event) and a custom event with a service that dispatches it.

## File tree

```
SwagExample/
└── src/
    ├── Subscriber/
    │   ├── ProductLoadedSubscriber.php    # product.loaded (EntityLoadedEvent)
    │   ├── ProductWrittenSubscriber.php   # product.written + PreWriteValidationEvent
    │   └── ProductPageSubscriber.php      # ProductPageLoadedEvent (storefront)
    ├── Event/
    │   └── ExampleEvent.php               # custom event (ShopwareEvent)
    └── Service/
        └── ExampleService.php             # dispatches ExampleEvent
```

Root scaffold: ../plugin/README.md

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample\` | Plugin namespace | your own namespace |
| `swag_example.example` | `ExampleEvent::EVENT_NAME` (only applies because `ExampleService` passes it explicitly on `dispatch()`) | `<prefix>.<name>` |
| `swagExample`, `swagHello` | Names of the extensions on the product and page respectively | your own names |
| `Product*` / event constants | Example events | desired events |

## Registration (excerpt for `src/Resources/config/services.php`)

The subscriber is only active once the service carries the tag `kernel.event_subscriber`. The template convention is to always set the tag explicitly (autoconfigure only takes effect in plugins with `->defaults()->autowire()->autoconfigure()`, verified live in variant B of the plugin template; without `defaults()` it is not active).

```php
use SwagExample\Service\ExampleService;
use SwagExample\Subscriber\ProductLoadedSubscriber;
use SwagExample\Subscriber\ProductPageSubscriber;
use SwagExample\Subscriber\ProductWrittenSubscriber;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

$services->set(ProductLoadedSubscriber::class)
    ->tag('kernel.event_subscriber');

$services->set(ProductWrittenSubscriber::class)
    ->args([service('logger')])
    ->tag('kernel.event_subscriber');

$services->set(ProductPageSubscriber::class)
    ->tag('kernel.event_subscriber');

$services->set(ExampleService::class)
    ->args([service('event_dispatcher')]);
```

Single listener instead of a subscriber: `->tag('kernel.event_listener', ['event' => '...', 'method' => '...', 'priority' => 0])` (core example: `storefront/DependencyInjection/system.php`). XML variant (deprecated): `<tag name="kernel.event_subscriber"/>`, see ../plugin/README.md.

`ProductPageSubscriber` requires `shopware/storefront`. If the plugin should also run without the storefront, omit the class together with its registration or add `shopware/storefront` under `require` in `composer.json`.

## Integration / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample
bin/console cache:clear                              # after a new subscriber/tag or changed getSubscribedEvents()
bin/console debug:event-dispatcher 'product.loaded'  # show listeners per event
```

## Rules and pitfalls

- Event names: the dispatch name is `$event::class`, unless the dispatcher is given a name. An `EVENT_NAME` constant alone is not enough (`CheckoutOrderPlacedEvent` is dispatched without a name: listen on `CheckoutOrderPlacedEvent::class`, not on `'checkout.order.placed'`). Class name (`PreWriteValidationEvent::class`, `ProductPageLoadedEvent::class`, `EntityWriteEvent::class`) or string (`'product.loaded'`, `ProductEvents::PRODUCT_LOADED_EVENT`). Do not mix them up; `PreWriteValidationEvent` lives under `...\Write\Validation\`.
- `*.written` is too late for the previous state: for that use `EntityWriteEvent`/`EntityDeleteEvent` (`addSuccess()`/`addError()`); never call their factory `create()` yourself.
- `product.loaded` is a mass event: no DB queries per entity. Storefront products additionally appear under `sales_channel.product.loaded`.
- A subscriber on `product.written` that writes `product` triggers itself: build in a guard. For versioned entities check against `Defaults::LIVE_VERSION` if needed.
- Events are for enriching/observing. Replacing logic: decoration, see ../decorator/README.md. Offload heavy work (mails, HTTP) to message handlers.
- Custom events: `extends Symfony\Contracts\EventDispatcher\Event`, `implements ShopwareEvent` (`getContext()`); with a sales channel reference `ShopwareSalesChannelEvent`; nested `NestedEvent`. For the Flow Builder `FlowEventAware` (not in the template).
- There are no string constants for plugin lifecycle events: `PluginPostActivateEvent::class` among others (`core/Framework/Plugin/Event/`), see ../plugin/README.md.

## References

- ../../references/07-events-subscriber.md
- ../../references/08-extension-points.md
- ../../references/05-registering-services.md
- ../../references/03-plugin-lifecycle.md
- Templates: ../plugin/README.md, ../decorator/README.md, ../config/README.md

## Notes and gaps

- Deprecations (removal in 6.8.0): `NestedEvent::getFlatEventList()`, `EntityWrittenEvent::getEvents()`/`getExistences()`; do not use. `BusinessEventInterface` no longer exists (today `FlowEventAware`).
- Checked against the source code: `ProductEvents` constants, `EntityLoadedEvent`, `EntityWrittenEvent`, `PreWriteValidationEvent` (methods `getCommandsForEntity`, `getExceptions`), `NestedEventDispatcher::dispatch(object, ?string)`, `ShopwareEvent`.
- Verified live (review rounds 1–3): the subscribers are registered (`bin/console debug:event-dispatcher product.loaded`). Established in the code: `WriteException::add(\Throwable)` and `tryToThrow()` in `EntityWriteGateway::validateCommands()` after the dispatch.
- ⚠ UNVERIFIED: Double registration with autoconfigure plus a manual tag (Symfony behavior; subscribers with `defaults()` run live, a double invocation was not specifically checked).
- ⚠ GAP: Business/flow events (`FlowEventAware`, `BusinessEventCollectorEvent`), kernel events, cart events and `#[AsEventListener]` are only described in the reference, not included as templates.
