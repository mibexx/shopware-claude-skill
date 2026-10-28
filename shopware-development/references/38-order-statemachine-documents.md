# Orders, state machine and documents (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when states of an order, payment or delivery are changed or monitored, custom state machines/states are created, orders are extended, or a custom document type (invoice, delivery note, offer) is needed.

Scope: events in general in [T-07 Events/Subscriber](./07-events-subscriber.md); Flow Builder actions in [T-09 Flow Builder](./09-flow-builder.md); mails for state changes in [T-35 Mail templates](./35-mail-templates.md); entity definitions in [T-27 DAL entities](./27-dal-entities.md); migrations in [T-04 Migrations](./04-database-migrations.md); payment methods in [T-37 Payment](./37-payment-integration.md); documents via apps only in [T-10 App system](./10-app-system-scope.md).

## 2. Concepts

- **Transitions only via `StateMachineRegistry::transition(new Transition($entity, $id, $actionName, 'stateId'), $context)`.** The registry locks (`StateMachineLocker`), checks the transition, writes `state_machine_history` and the entity field in one transaction and dispatches events afterwards. Never write `stateId` directly via the repository.
- **Events after a change** (only if a change actually happened, `hasTransitioned`):
  1. `StateMachineTransitionEvent` (class name = event name).
  2. `StateMachineStateChangeEvent`, name `state_machine.<technicalName>_changed`, once each with side `state_leave` and `state_enter`. `getStateEventName()` returns e.g. `state_enter.order.state.completed`.
  3. For orders, `Checkout/Order/Listener/OrderStateChangeEventListener` (listens to `state_machine.order.state_changed`, `…order_delivery…`, `…order_transaction…`) translates into `OrderStateMachineStateChangeEvent` under the name `<side>.<machine>.<state>` (e.g. `state_enter.order_delivery.state.shipped`). These names are registered as Flow Builder events in `onAddStateEvents`; the event carries the loaded `OrderEntity`.
- **States/transitions are database rows** (`state_machine`, `state_machine_state`, `state_machine_transition` plus translation tables), created via migration. Connected to an entity via `StateMachineStateField`.
- **Documents in 6.7.15.1: two systems.**

| | Legacy (active, default) | DocumentV2 |
|---|---|---|
| Namespace | `Checkout/Document` | `Checkout/DocumentV2` |
| Activation | always | feature flag `DOCUMENT_GENERATION_REWORK` (`default: false`), everything `@experimental stableVersion:v6.8.0` |
| Type building block | `AbstractDocumentRenderer` (tag `document.renderer`) | type class (tag `shopware.document_v2.type`), data provider (tag `shopware.document_v2.provider`), render data DTO, Twig `@Framework/documents/<technicalName>.html.twig`, number range type `document_<technicalName>`, row in `document_type` |
| Outlook | `#[ExperimentalReplacement(version: 'v6.9.0', …)]`: will be replaced in 6.9 | target system |

- **Extend orders** via events (`CartConvertedEvent`, `OrderConvertedEvent`, `CheckoutOrderPlacedEvent`, `OrderEvents::*`), entity extension and custom fields ([T-29](./29-entity-extensions-custom-fields.md)). Do not replace `OrderConverter`; use events or decorate it ([T-06 Decoration](./06-service-decoration.md)).

Plugin tree (`SwagExample`):

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Resources/
    │   ├── config/services.php
    │   └── views/documents/swag_offer.html.twig     # DocumentV2 template
    ├── Migration/
    │   ├── Migration1730000000SwagReviewStateMachine.php
    │   └── Migration1730000001SwagOfferDocumentType.php
    ├── StateMachine/OrderShippedSubscriber.php
    ├── Order/{OrderProcessor,OrderPlacedSubscriber}.php
    └── Document/{SwagOfferDocumentType,SwagOfferRenderData,SwagOfferDataProvider}.php
```

## 3. Instructions

**A. State changes**
1. Inject `StateMachineRegistry` via the constructor (autowiring).
2. Know the action names (`action_name`): order `reopen, process, cancel, complete`; payment among others `paid, paid_partially, refund, refund_partially, fail, cancel, authorize, remind, chargeback`; delivery `ship, ship_partially, retour, retour_partially, cancel, reopen` (docs). Verify existing transitions via API/CLI (`bin/console state-machine:dump order.state`, Graphviz dump).
3. Call `transition()`; return value: `StateMachineStateCollection` with keys `fromPlace` and `toPlace`.
4. A disallowed transition throws `StateMachineException::illegalStateTransition` (lists the allowed actions). If the target state is already reached, nothing happens: no error, no events.
5. Testable via the Admin API: `GET /api/_action/state-machine/{entityName}/{entityId}/state` and `POST /api/_action/state-machine/{entityName}/{entityId}/state/{transition}` (`StateMachineActionController`, checks ACL via `validatePrivilege`; the registry itself does not).
6. For payments, `Checkout/Order/Aggregate/OrderTransaction/OrderTransactionStateHandler` wraps the registry (`paid()`, `process()`, `refund()`, `cancel()`, `authorize()`, `chargeback()` among others, each `(string $transactionId, Context $context)`).

**B. Custom states/transitions**
1. Create a migration (example 4.3). New transitions in core machines (`order.state`, `order_transaction.state`, `order_delivery.state`): look up the machine and state by `technical_name` and insert into `state_machine_transition`, checking for existence first (pattern: `core/Migration/V6_3/Migration1600769357AddTransition.php`).
2. One translation row per state, otherwise names are missing in the Admin.
3. Clear the cache afterwards (the registry caches machines per process).

**C. Document type V2** (only with flag)
1. `bin/console feature:enable DOCUMENT_GENERATION_REWORK`.
2. Type, DTO, provider, template, migration as in 4.6. Each provider needs a unique `getKey()` (otherwise `duplicateProviderKey`).
3. Formats: only `html`, `pdf`, `zugferd_xml`, `zugferd_embedded_pdf` (`DocumentFormat`). A custom `AbstractDocumentRenderer` (tag `shopware.document_v2.renderer`) is only needed for a new format; with multiple renderers per format, the highest priority wins.
4. Generate via `DocumentGenerator` (`Checkout/DocumentV2/Generation`) with `new DocumentGenerationRequest($orderId, 'swag_offer', ['pdf'])`. `DocumentNumberGenerator` assigns the number if `documentNumber` is null.

**D. Document type legacy**
1. Extend `Checkout/Document/Renderer/AbstractDocumentRenderer` (`supports()` returns the type, `render()` returns `RendererResult`, `getDecorated()` throws `DecorationPatternException`).
2. Tag `document.renderer`; `DocumentRendererRegistry` takes the first matching renderer.
3. `document_type`, `document_base_config` and number range `document_<type>` via migration. Model: `InvoiceRenderer` (template `@Framework/documents/invoice.html.twig`, number via `NumberRangeValueGeneratorInterface::getValue('document_' . TYPE, ...)`; in new code inject `AbstractNumberRangeValueGenerator`).

**CLI** (in the web container):
```
bin/console state-machine:dump order.state
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console database:migrate --all SwagExample
bin/console feature:enable DOCUMENT_GENERATION_REWORK   # only for V2
bin/console cache:clear
```

## 4. Examples

### 4.1 Triggering a transition and reading available transitions

`src/Order/OrderProcessor.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Order;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class OrderProcessor
{
    public function __construct(private readonly StateMachineRegistry $stateMachineRegistry)
    {
    }

    public function process(string $orderId, Context $context): void
    {
        $this->stateMachineRegistry->transition(
            new Transition(OrderDefinition::ENTITY_NAME, $orderId, 'process', 'stateId', 'Internal comment'),
            $context
        );
    }

    /**
     * @return list<string>
     */
    public function availableActions(string $orderId, Context $context): array
    {
        $transitions = $this->stateMachineRegistry->getAvailableTransitions(
            OrderDefinition::ENTITY_NAME,
            $orderId,
            'stateId',
            $context
        );

        return array_values(array_map(static fn ($t) => $t->getActionName(), $transitions));
    }
}
```

### 4.2 Reacting to state changes

`src/StateMachine/OrderShippedSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\StateMachine;

use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderShippedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            // generic, all entities/machines
            StateMachineTransitionEvent::class => 'onTransition',
            // name = state_machine.<technicalName>_changed
            'state_machine.order_delivery.state_changed' => 'onDeliveryChanged',
            // flow event of the order, name = <side>.<machine>.<state>
            'state_enter.order_delivery.state.shipped' => 'onShipped',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        // $event->getEntityName(), getEntityId(), getFromPlace()/getToPlace()->getTechnicalName()
    }

    public function onDeliveryChanged(StateMachineStateChangeEvent $event): void
    {
        // $event->getTransitionSide() (state_enter|state_leave), getStateName(), getTransition()->getEntityId()
    }

    public function onShipped(OrderStateMachineStateChangeEvent $event): void
    {
        $order = $event->getOrder();
    }
}
```
Registration (`src/Resources/config/services.php`, complete for all examples see 4.6):
```php
$services->set(\SwagExample\StateMachine\OrderShippedSubscriber::class)->tag('kernel.event_subscriber');
```

### 4.3 Custom state machine via migration

`src/Migration/Migration1730000000SwagReviewStateMachine.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000000SwagReviewStateMachine extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        if ($connection->fetchOne('SELECT 1 FROM state_machine WHERE technical_name = :n', ['n' => 'swag_review.state'])) {
            return;
        }

        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $machineId = Uuid::randomBytes();
        $pending = Uuid::randomBytes();
        $approved = Uuid::randomBytes();

        $connection->insert('state_machine', ['id' => $machineId, 'technical_name' => 'swag_review.state', 'created_at' => $now]);
        $connection->insert('state_machine_translation', [
            'state_machine_id' => $machineId,
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'name' => 'Review',
            'created_at' => $now,
        ]);

        foreach ([[$pending, 'pending', 'Pending'], [$approved, 'approved', 'Approved']] as [$id, $name, $label]) {
            $connection->insert('state_machine_state', ['id' => $id, 'technical_name' => $name, 'state_machine_id' => $machineId, 'created_at' => $now]);
            $connection->insert('state_machine_state_translation', [
                'state_machine_state_id' => $id,
                'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'name' => $label,
                'created_at' => $now,
            ]);
        }

        $connection->insert('state_machine_transition', [
            'id' => Uuid::randomBytes(),
            'action_name' => 'approve',
            'state_machine_id' => $machineId,
            'from_state_id' => $pending,
            'to_state_id' => $approved,
            'created_at' => $now,
        ]);

        $connection->update('state_machine', ['initial_state_id' => $pending], ['id' => $machineId]);
    }
}
```
The matching entity field (in the `EntityDefinition`, see [T-27](./27-dal-entities.md)); model: `OrderDefinition` line 125 (`OrderStates::STATE_MACHINE = 'order.state'`):
```php
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StateMachineStateField;

(new StateMachineStateField('state_id', 'stateId', 'swag_review.state'))->addFlags(new Required()),
```
The column `state_id` (FK to `state_machine_state`) must exist in the entity migration. The DAL sets the initial state from `state_machine.initial_state_id` (`InitialStateIdLoader`).

### 4.4 Extending an order

`src/Order/OrderPlacedSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Order;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CartConvertedEvent::class => 'onCartConverted',
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $data = $event->getConvertedCart();
        $data['customFields'] = ($data['customFields'] ?? []) + ['swag_source' => 'shop'];
        $event->setConvertedCart($data);
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $order = $event->getOrder();
        // after persisting: trigger ERP export or similar
    }
}
```
`CartConvertedEvent` is dispatched in `OrderConverter::convertToOrder` (approx. line 252) (order data array); `OrderConvertedEvent` (order to cart, `convertToCart`, approx. line 303) also carries `getConvertedCart()/setConvertedCart()`, but here of type `Cart` (for `CartConvertedEvent` an `array`; there `getOriginalConvertedCart()` returns the unchanged array). `assembleSalesChannelContext` fires `BeforeSalesChannelContextAssembledEvent`/`SalesChannelContextAssembledEvent`.

### 4.5 Custom document type V2 (only with active flag)

`src/Document/SwagOfferDocumentType.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Document;

use Shopware\Core\Checkout\DocumentV2\DocumentFormat;
use Shopware\Core\Checkout\DocumentV2\Type\AbstractDocumentType;

readonly class SwagOfferDocumentType extends AbstractDocumentType
{
    final public const TECHNICAL_NAME = 'swag_offer';

    public function getTechnicalName(): string
    {
        return self::TECHNICAL_NAME;
    }

    public function getSupportedFormats(): array
    {
        return [DocumentFormat::HTML->value, DocumentFormat::PDF->value];
    }
}
```
`src/Document/SwagOfferRenderData.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Document;

use Shopware\Core\Checkout\DocumentV2\Struct\AbstractRenderData;

final readonly class SwagOfferRenderData extends AbstractRenderData
{
    public function __construct(public string $offerNumber, public string $validUntil)
    {
    }
}
```
`src/Document/SwagOfferDataProvider.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Document;

use Shopware\Core\Checkout\DocumentV2\Provider\AbstractDocumentDataProvider;
use Shopware\Core\Checkout\DocumentV2\Struct\AbstractRenderData;
use Shopware\Core\Checkout\DocumentV2\Struct\ProviderInput;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

final readonly class SwagOfferDataProvider extends AbstractDocumentDataProvider
{
    public function getKey(): string
    {
        return 'swag_offer';
    }

    public function supports(string $documentType): bool
    {
        return $documentType === SwagOfferDocumentType::TECHNICAL_NAME;
    }

    public function enrichOrderCriteria(Criteria $criteria): void
    {
        $criteria->addAssociations(['currency', 'language.locale', 'addresses.country', 'lineItems', 'orderCustomer']);
    }

    public function provideRenderingData(ProviderInput $input, Context $context): AbstractRenderData
    {
        return new SwagOfferRenderData(
            $input->generationRequest->documentNumber ?? '',
            (new \DateTimeImmutable($input->generationRequest->documentDate))->modify('+30 days')->format('Y-m-d'),
        );
    }
}
```

### 4.6 Services, template, migration (V2)

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Document\SwagOfferDataProvider;
use SwagExample\Document\SwagOfferDocumentType;
use SwagExample\Order\OrderPlacedSubscriber;
use SwagExample\Order\OrderProcessor;
use SwagExample\StateMachine\OrderShippedSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire();

    $services->set(OrderProcessor::class);
    $services->set(OrderShippedSubscriber::class)->tag('kernel.event_subscriber');
    $services->set(OrderPlacedSubscriber::class)->tag('kernel.event_subscriber');

    $services->set(SwagOfferDocumentType::class)->tag('shopware.document_v2.type');
    $services->set(SwagOfferDataProvider::class)->tag('shopware.document_v2.provider');
};
```

`src/Resources/views/documents/swag_offer.html.twig` (file name = technical name; `HtmlRenderer::TEMPLATE_PATTERN = '@Framework/documents/%s.html.twig'`)
```twig
{% sw_extends '@Framework/documents/base.html.twig' %}

{% block document_body %}
    <h1>Offer {{ config.offerNumber }}</h1>
    <p>Valid until {{ config.validUntil }}</p>
{% endblock %}
```
The DTO properties appear flat under `config.<property>` (`Template/TemplateContext`). If a name collides with meta fields or with another provider's DTO, `TemplateContext` throws `templateContextPropertyCollision`. The core block `document_body` exists in `core/Framework/Resources/views/documents/base.html.twig` (verified against the source code; the block `document_content` mentioned in the research does NOT exist there). Other blocks there: `document_base`, `document_head`, `document_title_tag`, `document_font_links`; in the legacy `invoice.html.twig` additionally `document_headline`. Twig block details: [T-14](./14-storefront-twig-templates.md).

`src/Migration/Migration1730000001SwagOfferDocumentType.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

class Migration1730000001SwagOfferDocumentType extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000001;
    }

    public function update(Connection $connection): void
    {
        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $en = Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM);

        $typeId = Uuid::randomBytes();
        $connection->insert('document_type', ['id' => $typeId, 'technical_name' => 'swag_offer', 'created_at' => $now]);
        $connection->insert('document_type_translation', ['document_type_id' => $typeId, 'language_id' => $en, 'name' => 'Offer', 'created_at' => $now]);

        $numberRangeTypeId = Uuid::randomBytes();
        $connection->insert('number_range_type', ['id' => $numberRangeTypeId, 'global' => 0, 'technical_name' => 'document_swag_offer', 'created_at' => $now]);
        $connection->insert('number_range_type_translation', ['number_range_type_id' => $numberRangeTypeId, 'language_id' => $en, 'type_name' => 'Offer', 'created_at' => $now]);

        $numberRangeId = Uuid::randomBytes();
        $connection->insert('number_range', ['id' => $numberRangeId, 'type_id' => $numberRangeTypeId, 'global' => 1, 'pattern' => 'A{n}', 'start' => 1000, 'created_at' => $now]);
        $connection->insert('number_range_translation', ['number_range_id' => $numberRangeId, 'language_id' => $en, 'name' => 'Offers', 'created_at' => $now]);
    }
}
```
Number range type = `document_` + technical name (`DocumentNumberGenerator::NUMBER_RANGE_DOCUMENT_TYPE_PREFIX`). Columns according to `Migration1536233560BasicData` (approx. lines 2006 and 2036).

More scaffolds: [Migration](../templates/migration/README.md), [Event subscriber](../templates/event-subscriber/README.md), [Entity](../templates/entity/README.md).

## 5. Pitfalls

1. **Setting `stateId` directly via the repository**: bypasses history, events, flows, mails. Always use `transition()`.
2. **Confusing event types**: `state_enter.*`/`state_leave.*` deliver `OrderStateMachineStateChangeEvent` (flow event), `state_machine.<name>_changed` a `StateMachineStateChangeEvent`.
3. **Expecting events from a no-op transition**: if the target state is already reached, nothing happens (`hasTransitioned = false`).
4. **Same action with the same source state multiple times**: not allowed (unique key); `StateMachineRegistry::getTransitionDestinationById` triggers a deprecation in 6.7, an error from 6.8.
5. **Reading the old state from a loaded entity after the change**: reload the entity.
6. **Permissions**: the registry itself switches to `Context::SYSTEM_SCOPE`; only `StateMachineActionController` checks ACL.
7. **Forgetting translation rows**: states without names in the Admin.
8. **V2 type without a `document_type` row or without number range type `document_<name>`**: generation fails or no number, respectively.
9. **Technical name with uppercase letters/hyphens**: `RenderInput` requires `^[a-z0-9_]+$` (`invalidDocumentType`).
10. **Duplicate DTO property names**: `templateContextPropertyCollision`.
11. **Overriding `OrderConverter`**: use events, decoration or entity extension instead.
12. **`@internal` classes** (`DocumentTypeRegistry`, `DocumentRendererRegistry`, most V2 classes): extend only via the documented abstracts and tags.
13. **Wrong Twig block** in the document template (`document_content` does not exist): use `document_body`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Stable in 6.7.15.1: `StateMachineRegistry::transition()`, `Transition`, `getAvailableTransitions()`, event structure. Additions: `StateMachineLocker`, history with the source of the change (`sourceType`).
- 6.7: `Migration1742302302RenamePaidTransitionActions` renames actions of the payment state machine; check old action names in your own code against the DB.
- `@deprecated tag:v6.8.0`: `StateMachineTransitionValidator`; `NumberRangeValueGeneratorInterface` (replacement `AbstractNumberRangeValueGenerator`), `previewPattern` (replacement `previewPatternByNumberRangeId`); ambiguous targets of an action; `DocumentTypeNameSyncSubscriber` (`@deprecated tag:v6.9.0`).
- XML service configuration is deprecated in 6.7 (use `services.php`).
- Order fields: the code uses `Feature::isActive('v6.8.0.0') ? getPrimaryOrderDelivery() : getDeliveries()->first()` everywhere. New code loads the `primaryOrderDelivery`/`primaryOrderTransaction` associations.
- Apps can provide their own document types (`DocumentV2/App/AppDocumentTypeConfig`); the name `app_provided` is reserved.
- In 6.5/6.6 only the legacy system existed; versions of the switchover not documented.

## 6. Checklist

- [ ] State changes exclusively via `StateMachineRegistry::transition()` or `OrderTransactionStateHandler`
- [ ] Action name checked against actual transitions (`state-machine:dump`)
- [ ] Event name chosen correctly (`state_enter.<machine>.state.<state>` for the flow event, `state_machine.<name>_changed` for the struct event)
- [ ] Custom states/transitions via idempotent migration incl. translation rows and `initial_state_id`
- [ ] `StateMachineStateField` with a matching `state_id` column in the entity migration
- [ ] Order extension via events/extension instead of overriding `OrderConverter`
- [ ] Document system decided (legacy default vs. V2 behind `DOCUMENT_GENERATION_REWORK`)
- [ ] V2: tags, `document_type` row, number range `document_<name>`, template `<name>.html.twig`, unique `getKey()`
- [ ] Core Twig block name in the document template checked against the core template
- [ ] New order accesses use `primaryOrderDelivery`/`primaryOrderTransaction`
- [ ] See [review checklist](../checklists/review-checklist.md)

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/order/using-the-state-machine.html (matches the code)
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/documents/v2/add-a-document-type.html (template path, tags, `document_type` + number range)

Source code (relative to `vendor/shopware/core/`):
- `System/StateMachine/{StateMachineRegistry,Transition,StateMachineLocker,StateMachineException,StateMachineTransitionValidator}.php`, `Event/{StateMachineTransitionEvent,StateMachineStateChangeEvent}.php`, `Loader/InitialStateIdLoader.php`, `Api/StateMachineActionController.php`, `Command/WorkflowDumpCommand.php`
- `Migration/V6_3/{Migration1536232760StateMachine,Migration1600769357AddTransition}.php`, `Migration/V6_7/Migration1742302302RenamePaidTransitionActions.php`
- `Checkout/Order/{OrderStates,OrderDefinition,OrderEvents}.php`, `Order/Event/OrderStateMachineStateChangeEvent.php`, `Order/Listener/OrderStateChangeEventListener.php`, `Order/Aggregate/OrderTransaction/OrderTransactionStateHandler.php`
- `Checkout/Cart/Order/{OrderConverter,CartConvertedEvent,OrderConvertedEvent}.php`, `Checkout/Cart/Event/CheckoutOrderPlacedEvent.php`
- `Checkout/Document/Renderer/{AbstractDocumentRenderer,InvoiceRenderer,DocumentRendererRegistry}.php`, `Checkout/DependencyInjection/document.php`
- `Checkout/DocumentV2/` (`Type/`, `Provider/`, `Renderer/`, `Struct/`, `Template/TemplateContext.php`, `Config/DocumentNumberGenerator.php`, `Generation/`, `DependencyInjection/documentV2.php`)
- `Framework/Resources/config/packages/feature.yaml` (flag `DOCUMENT_GENERATION_REWORK`), `Framework/Resources/views/documents/{base,invoice}.html.twig`
- `System/NumberRange/ValueGenerator/`, `Migration/V6_3/Migration1536233560BasicData.php`

### Gaps and uncertainties

- ⚠ UNVERIFIED: None of the examples were executed (only derived from classes and migrations of the installed code).
- ⚠ UNVERIFIED: Column names of the table `state_machine_state_translation` (`state_machine_state_id`, `language_id`, `name`) assumed from the pattern of other translation tables.
- ⚠ UNVERIFIED: `number_range_type.global = 0` assumed; sales channel assignment (`number_range_sales_channel`) is missing in the example.
- ⚠ UNVERIFIED: Whether a plugin template in the `@Framework` namespace for a new (non-overriding) template is resolved by the `TemplateFinder`, and whether `base.html.twig` (uses legacy variables such as `nestedLineItems`) works together with V2 render data; not checked.
- ⚠ UNVERIFIED: V2 generation flow (`DocumentGenerator`) only read in construction/registration; the docs require a `document_type` row and number range.
- ⚠ GAP: Legacy document docs not retrieved; the legacy description comes from the code. Which actions `Migration1742302302` renames was not evaluated.
- ⚠ GAP: Version information for 6.5/6.6 cannot be proven from the code. Further order hooks (`OrderCriteriaEvent`, `OrderStateChangeCriteriaEvent`, `OrderAddressService`, `RecalculationService`) not evaluated.
- ⚠ UNVERIFIED: Signatures of `feature:enable`, `database:migrate`, `plugin:*` not rechecked against the code.
