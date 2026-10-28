# Events and subscribers (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin should react to events in the core (loading/writing entities, Storefront pages, cart, HTTP) or dispatch its own events. Events are for enriching and observing; replacing logic is done via decoration ([T-06 Service decoration](./06-service-decoration.md)).

Scope: typed replace/post-processing hooks with a result are extension points ([T-08](./08-extension-points.md)); business events as flow triggers see [T-09 Flow Builder](./09-flow-builder.md); service registration in general in [T-05](./05-registering-services.md); DAL read/write API in [T-28](./28-dal-reading-writing.md); script hooks are an app feature ([T-10](./10-app-system-scope.md)).

## 2. Concepts

- Shopware uses the Symfony EventDispatcher. The service `event_dispatcher` is a `Shopware\Core\Framework\Event\NestedEventDispatcher` (`core/Framework/DependencyInjection/event.php`: `->decorate('event_dispatcher')`), which wraps the Symfony `EventDispatcher` (`framework-bundle`, service `event_dispatcher`). `dispatch($event, $name)` first recursively dispatches all nested events (`NestedEvent::getEvents()`), for nested `GenericEvent`s using their `getName()` as the name, and then delegates unchanged (`$event`, `$name`) to the inner dispatcher. Without a name, the Symfony `EventDispatcher` uses `$event::class`. In debug mode, `debug.event_dispatcher` (`TraceableEventDispatcher`) is added as a further decorator; the order of the decorator chain has not been checked.
- Event names are **class names** (`ProductPageLoadedEvent::class`, `EntityWriteEvent::class`, `PreWriteValidationEvent::class`) or **strings** (only DAL events such as `'product.loaded'`). The dispatch name is `$event::class` unless the dispatcher is given a name; `getName()` or an `EVENT_NAME` constant do not change this. A string name only applies to nested `GenericEvent`s (`getName()`, e.g. `product.loaded`) or when the caller passes it explicitly (`dispatch($event, 'name')`). Example: `CheckoutOrderPlacedEvent` has `EVENT_NAME = 'checkout.order.placed'`, but `CartOrderRoute` dispatches it without a name (`core/Checkout/Cart/SalesChannel/CartOrderRoute.php`, `dispatch($event)`): a listener on the string `'checkout.order.placed'` never fires; use `CheckoutOrderPlacedEvent::class`. (The string is used by the Flow Builder, see [T-09](./09-flow-builder.md).)
- Subscriber: class with `EventSubscriberInterface::getSubscribedEvents()`, service with the tag `kernel.event_subscriber`. Format `Event => 'method'` or `Event => ['method', priority]`. Single listener: tag `kernel.event_listener` with `event`, `method`, `priority`.

| Family | Base | Example | Purpose |
|---|---|---|---|
| Kernel | Symfony `KernelEvent` | `KernelEvents::REQUEST` | HTTP lifecycle, headers |
| Page/Storefront | `Storefront\Page\PageLoadedEvent` (`NestedEvent`, `ShopwareSalesChannelEvent`; `getPage()`, `getRequest()`), `StorefrontRenderEvent` | `ProductPageLoadedEvent`, `GenericPageLoadedEvent` | Extend the page struct (extensions), template parameters, criteria |
| Business/Flow | `FlowEventAware` | `CheckoutOrderPlacedEvent`, `MailBeforeSentEvent` | Business events, flow triggers |
| DAL | `NestedEvent`+`GenericEvent` or `Event`+`ShopwareEvent` respectively | `product.written`, `PreWriteValidationEvent` | Persistence lifecycle |
| Cart | `core/Checkout/Cart/Event/*` | `BeforeLineItemAddedEvent`, `CartChangedEvent` | Cart/checkout ([T-36](./36-cart.md)) |

(The table is derived from the source code, not from the docs.)

DAL events (`core/Framework/DataAbstractionLayer/Event/`):
- `EntityLoadedEvent` (`<entity>.loaded`, `getEntities()`), `PartialEntityLoadedEvent` (`<entity>.partial_loaded`), sales channel variant `sales_channel.<entity>.loaded`.
- `EntityWrittenEvent` (`<entity>.written`, `getIds()`, `getWriteResults()`, `getPayloads()`; only AFTER writing), `EntityDeletedEvent` (`<entity>.deleted`), `EntityWrittenContainerEvent` (return value of `create/update/upsert`).
- `EntityWriteEvent` (class name; BEFORE writing a command batch; `getCommands()`, `getCommandsForEntity()`, `getIds($entity)`, `addSuccess()`/`addError()`), `EntityDeleteEvent` (before delete commands). Both have private constructors (`create()` is internal): plugins do not create them.
- `PreWriteValidationEvent` lives under `...\DataAbstractionLayer\Write\Validation\` (not `Event`); dispatched inside the transaction; `getCommands()`, `getCommandsForEntity()`, `getPrimaryKeys()`, `getExceptions()`.
- Search events: `EntitySearchResultLoadedEvent` (`<entity>.search.result.loaded`), `EntityAggregationResultLoadedEvent`, `EntityIdSearchResultLoadedEvent`, `EntitySearchedEvent`.

HTTP-related Shopware events: `BeforeSendResponseEvent` (also for cached responses), `BeforeSendRedirectResponseEvent`, `Storefront\Event\StorefrontRedirectEvent`. Further Storefront events in `storefront/Event/` (`StorefrontRenderEvent`, `SwitchBuyBoxVariantEvent`, `ThemeCompilerConcatenatedStylesEvent`, `MaintenanceRedirectEvent`); page events in `storefront/Page/<Area>/*PageLoadedEvent.php`, each preceded by a `*PageCriteriaEvent`.

Directory tree:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Event/ExampleEvent.php
    ├── Service/ExampleService.php
    ├── Subscriber/{ProductLoadedSubscriber,ProductWrittenSubscriber,ProductPageSubscriber}.php
    └── Resources/config/services.php
```

## 3. Instructions

1. **Find the event**: constant classes `*Events.php` (e.g. `core/Content/Product/ProductEvents.php`), `BusinessEvents.php`; `grep -rn "extends NestedEvent\|extends Event\|implements FlowEventAware" vendor/shopware`; `grep -rn "eventDispatcher->dispatch" ...`. At runtime: `bin/console debug:event-dispatcher 'product.loaded'` (Symfony standard, see gaps), Symfony profiler tab "Events". Business events: `bin/console debug:business-events`.
2. **Write the subscriber**: `implements EventSubscriberInterface`, static `getSubscribedEvents()`, dependencies via constructor (`private readonly`).
3. **Register** as a service with the tag `kernel.event_subscriber` (always explicitly, see gaps).
4. **CLI**:
   ```
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/console cache:clear
   bin/console debug:event-dispatcher 'product.loaded'
   ```
   After changes to the service definition or `getSubscribedEvents()`, clear the container cache.
5. **Custom event**: `extends Symfony\Contracts\EventDispatcher\Event implements ShopwareEvent` (`getContext(): Context`); with sales channel relation `ShopwareSalesChannelEvent`; nested `extends NestedEvent` with `getEvents()`. Inject `EventDispatcherInterface` (service `event_dispatcher`) and `dispatch(new ExampleEvent(...))`, optionally with a fixed name as the second argument.
6. **Flow-capable events** (`FlowEventAware`, `#[IsFlowEventAware]` on aware interfaces): see [T-09](./09-flow-builder.md). The attribute is a marker for aware interfaces, not for event classes.

## 4. Examples

`src/Subscriber/ProductLoadedSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductLoadedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_LOADED_EVENT => 'onProductLoaded',
        ];
    }

    /**
     * @param EntityLoadedEvent<ProductEntity> $event
     */
    public function onProductLoaded(EntityLoadedEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }

        foreach ($event->getEntities() as $product) {
            $product->addExtension('swagExample', new ArrayStruct(['loaded' => true]));
        }
    }
}
```

`src/Subscriber/ProductWrittenSubscriber.php` (Written + PreWriteValidation)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductWrittenSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_WRITTEN_EVENT => 'onProductWritten',
            PreWriteValidationEvent::class => 'onPreWrite',
        ];
    }

    public function onProductWritten(EntityWrittenEvent $event): void
    {
        $this->logger->info('Products written', ['ids' => $event->getIds()]);
    }

    public function onPreWrite(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommandsForEntity(ProductDefinition::ENTITY_NAME) as $command) {
            if ($command instanceof InsertCommand) {
                // Check $command->getPayload(); attach errors via $event->getExceptions()->add(new \RuntimeException(...)) (WriteException::add(\Throwable), thrown in EntityWriteGateway::validateCommands)
            }
        }
    }
}
```

`src/Subscriber/ProductPageSubscriber.php` (page event; Twig: `{{ page.extensions.swagHello.text }}`)
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
        return [
            ProductPageLoadedEvent::class => 'onProductPageLoaded',
        ];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $event->getPage()->addExtension('swagHello', new ArrayStruct(['text' => 'Hello']));
    }
}
```

`src/Event/ExampleEvent.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Event;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareEvent;
use Symfony\Contracts\EventDispatcher\Event;

class ExampleEvent extends Event implements ShopwareEvent
{
    // Only effective if the caller passes it explicitly to dispatch().
    final public const EVENT_NAME = 'swag_example.example';

    public function __construct(
        private readonly string $payload,
        private readonly Context $context
    ) {
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
```

`src/Service/ExampleService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Context;
use SwagExample\Event\ExampleEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ExampleService
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function run(string $payload, Context $context): void
    {
        $this->eventDispatcher->dispatch(new ExampleEvent($payload, $context));
        // or with a fixed name: dispatch($event, ExampleEvent::EVENT_NAME); then ONLY listeners on this string fire, not those on ExampleEvent::class
    }
}
```

`src/Resources/config/services.php` (PHP format; XML is deprecated in 6.7, see section 5)
```php
<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SwagExample\Subscriber\ProductLoadedSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(SwagExample\Subscriber\ProductWrittenSubscriber::class)
        ->args([service('logger')])
        ->tag('kernel.event_subscriber');

    $services->set(SwagExample\Subscriber\ProductPageSubscriber::class)
        ->tag('kernel.event_subscriber');

    $services->set(SwagExample\Service\ExampleService::class)
        ->args([service('event_dispatcher')]);
};
```

Scaffold: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md). Basic plugin scaffold: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Pitfalls

- A subscriber without the tag `kernel.event_subscriber` is never called (the core registers this way, e.g. `core/Content/DependencyInjection/product.php`).
- Do not confuse class-based events (`EntityWriteEvent::class`, `PreWriteValidationEvent::class`, page events) with strings; `PreWriteValidationEvent` lives under `Write\Validation`.
- `*.written` is too late for the before state: use `EntityWriteEvent`/`EntityDeleteEvent` with `addSuccess()`/`addError()` for that.
- No repository writes in `PreWriteValidationEvent` (running transaction, risk of recursion/deadlock); only validate. (Recommendation, not confirmed in the code.)
- Infinite loop: a subscriber on `product.written` that itself writes `product` triggers itself again; protect with a guard (context state, field comparison).
- `product.loaded` also runs in the Admin context, for variants and in bulk loads: no DB query per entity. For Storefront products, also consider `sales_channel.product.loaded` and `product.partial_loaded`.
- Versioned entities: if needed, check `$event->getContext()->getVersionId() === Defaults::LIVE_VERSION` (docs "listening-to-events"), otherwise the subscriber also runs for draft versions.
- "Replacing" logic via an event is not possible; use decoration ([T-06](./06-service-decoration.md)) or an extension point ([T-08](./08-extension-points.md)).
- Do not do heavy work (mail, HTTP) synchronously in the subscriber; offload it to a message handler ([T-34](./34-message-queue.md)).
- Container cache not cleared after adding a new subscriber.
- The docs name `BusinessEventInterface` as a search term (finding-events): it no longer exists in 6.7.15.1 (no hit in `core`/`storefront`); today it is `FlowEventAware`.
- `EntityWriteEvent::create()`/`EntityDeleteEvent::create()` are internal factories.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `@deprecated tag:v6.8.0`: `NestedEvent::getFlatEventList()`, a partial method in `NestedEventCollection`, `EntityWrittenEvent::$events`/`$existences` including getters (`getEvents()`, `getExistences()`), the corresponding method in `EntityWrittenContainerEvent` (reason in the code: "unused").
- XML service definitions in plugins are deprecated in 6.7 (`Bundle::triggerXmlConfigDeprecation`, 6.8 no longer loads XML): use `services.php` (alternatively `services.yaml`). Checked: `Bundle::registerContainerFile` loads XML, YAML and PHP.
- New in the 6.7 event set (present in the code): `DocumentGeneratedEvent`, `DocumentDeletedEvent` (DocumentV2), `CustomerPasswordChangedEvent`, `RevocationRequestEvent`. ⚠ UNVERIFIED: whether they were missing in 6.6.

## 6. Checklist

- [ ] Event name correct (class vs. string; DAL: `<entity>.loaded` etc.)
- [ ] Subscriber registered as a service with the tag `kernel.event_subscriber`
- [ ] Service file in PHP format (`services.php`), not XML
- [ ] Method signature uses exactly the event class
- [ ] No heavy/synchronous work and no per-entity queries in `*.loaded`
- [ ] Self-triggering writes (`*.written` writes the same entity) guarded
- [ ] For versioned entities, `LIVE_VERSION` checked if relevant
- [ ] Before state only via `EntityWriteEvent`/`EntityDeleteEvent`
- [ ] `plugin:refresh`, `cache:clear` run, listener checked via `debug:event-dispatcher`
- [ ] For replacing logic, decoration/extension point considered instead

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/event/listening-to-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/event/finding-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/event/add-custom-event.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/using-database-events.html
- https://developer.shopware.com/docs/guides/plugins/plugins/architecture/events.html

Source code (`vendor/shopware/`):
- `core/Framework/DependencyInjection/event.php` (decorator `NestedEventDispatcher`); `vendor/symfony/event-dispatcher/EventDispatcher.php` (`dispatch`: `$eventName ??= $event::class`)
- `core/Framework/Event/{NestedEventDispatcher,NestedEvent,ShopwareEvent,GenericEvent,ShopwareSalesChannelEvent,FlowEventAware,IsFlowEventAware,BusinessEventRegistry,BeforeSendResponseEvent}.php`
- `core/Framework/DataAbstractionLayer/Event/{EntityLoadedEvent,EntityWrittenEvent,EntityWrittenContainerEvent,EntityWriteEvent,EntityDeleteEvent,EntityDeletedEvent}.php`
- `core/Framework/DataAbstractionLayer/Write/Validation/PreWriteValidationEvent.php`; dispatch in `core/Framework/DataAbstractionLayer/Dbal/EntityWriteGateway.php` (lines 109, 134, 140)
- `core/Content/Product/ProductEvents.php`, `core/Content/DependencyInjection/product.php`
- `core/Checkout/Cart/Event/CheckoutOrderPlacedEvent.php`, `core/Framework/Routing/{CoreSubscriber,RouteEventSubscriber}.php`
- `storefront/Page/PageLoadedEvent.php`, `storefront/Page/Product/{ProductPageLoadedEvent,ProductPageLoader}.php`, `storefront/Event/StorefrontRenderEvent.php`
- `core/Framework/Bundle.php` (`registerContainerFile`)

### Gaps and uncertainties

- ⚠ UNVERIFIED: `bin/console debug:event-dispatcher` was not executed (no container access); Symfony standard command, assumption.
- Autoconfiguration of `EventSubscriberInterface` only takes effect in plugins with `->defaults()->autowire()->autoconfigure()` (verified live); without `defaults()` it is not active, so set the tag explicitly when in doubt.
- Confirmed: `PreWriteValidationEvent::getExceptions()` returns the `WriteException` of the `WriteContext`; `add(\Throwable): WriteException` (`WriteException.php`), `tryToThrow()` throws it in `EntityWriteGateway::validateCommands()` right after the dispatch (lines 140-146, 833) and aborts the transaction. For field errors, `WriteConstraintViolationException` (`core/Framework/Validation/`) is the better choice; not executed.
- ⚠ UNVERIFIED: All examples only checked against source code signatures, not tested in a running instance.
- ⚠ GAP: 6.5/6.6 behavior (removal of `BusinessEventInterface`, older `BeforeDeleteEvent`) not evaluated.
- The docs page "architecture/events" does not explicitly distinguish kernel/business/page events; the family table is derived from the code.
