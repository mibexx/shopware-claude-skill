# Payment integration (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin connects a custom payment method with a handler (synchronous or via redirect), sets payment status via the state machine, or offers refund/recurring.

Scope: order state machines and documents in [T-38](./38-order-statemachine-documents.md); cart in [T-36](./36-cart.md); plugin lifecycle in [T-03](./03-plugin-lifecycle.md); services/tags in [T-05](./05-registering-services.md); webhook controllers in [T-16](./16-storefront-controller-pages.md) or [T-31](./31-admin-api-routes.md); DAL writing in [T-28](./28-dal-reading-writing.md); app-based payment methods only in [T-10](./10-app-system-scope.md).

## 2. Concepts

- Since 6.7 there is **exactly one** base class: `Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler`. The old interfaces (`SynchronousPaymentHandlerInterface`, `AsynchronousPaymentHandlerInterface`, `PreparedPaymentHandlerInterface`, `RefundPaymentHandlerInterface`, `RecurringPaymentHandlerInterface`) **no longer exist** in 6.7.15.1.
- Methods:
  - `supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool` (abstract). Enum `PaymentHandlerType`: `RECURRING`, `REFUND`.
  - `pay(Request, PaymentTransactionStruct, Context, ?Struct $validateStruct): ?RedirectResponse` (abstract). `null` = synchronous, `RedirectResponse` = asynchronous.
  - `validate(Cart, RequestDataBag, SalesChannelContext): ?Struct` (optional, before the order is persisted).
  - `finalize(Request, PaymentTransactionStruct, Context): void` (optional; only after a redirect from `pay()`).
  - `refund(RefundPaymentTransactionStruct, Context): void` (only if `supports(REFUND)` is true).
  - `recurring(PaymentTransactionStruct, Context): void` (only if `supports(RECURRING)` is true).
- **Registration**: tag `shopware.payment.method`. `AutoconfigureCompilerPass` sets it for classes extending `AbstractPaymentHandler`, but only when autoconfigure is active; tag explicitly in the plugin. The service ID must be the FQCN: `PaymentHandlerRegistry` looks up the handler via `payment_method.handler_identifier`.
- **Create the payment method** in the plugin lifecycle via `payment_method.repository`: `handlerIdentifier` = FQCN, `technicalName` (required), `pluginId`, name (translated). On deactivation set `active=false`, never delete.
- **Status changes** only via `OrderTransactionStateHandler` (refunds: `OrderTransactionCaptureRefundStateHandler`), not by writing `stateId`.
- `PaymentTransactionStruct` only contains `orderTransactionId`, `returnUrl`, `recurring`; the handler loads the order itself via `order_transaction.repository`.

Directory tree relative to the plugin:

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                 # install/uninstall/activate/deactivate
    ├── Resources/config/services.php
    └── Service/ExamplePayment.php      # extends AbstractPaymentHandler
```

Optional: custom payment forms ([T-14](./14-storefront-twig-templates.md), [T-15](./15-storefront-js-plugins.md)), webhook controller ([T-16](./16-storefront-controller-pages.md)).

## 3. Instructions

1. Create the plugin: `bin/console plugin:create SwagExample` ([T-02](./02-plugin-structure.md)).
2. Write the handler (`extends AbstractPaymentHandler`), inject services via the constructor.
3. Register the handler with tag `shopware.payment.method`.
4. Create the payment method in `install()` (required: `handlerIdentifier`, `technicalName`, name).
5. Activate: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`.
6. Check it in the Administration (Settings -> Shop -> Payment methods), assign it to the sales channels (`sales_channel_payment_method`) and activate it.
7. **Flow for an order** (`core/Checkout/Payment/PaymentProcessor.php`):
   1. `PaymentProcessor::validate()` calls `handler->validate()` before the order is created; the result is stored on the transaction as `validationData`. Error: `PaymentException::validatePreparedPaymentInterrupted()`.
   2. `PaymentProcessor::pay()` loads the most recent transaction in status `open`, creates a `PaymentToken` (JWT), builds the `returnUrl` (route `payment.finalize.transaction`, `/payment/finalize-transaction`, query `_sw_payment_token`) and calls `handler->pay(...)`.
   3. `RedirectResponse` from `pay()`: the token stays valid. With `null`: the token is invalidated (`finally`).
   4. Exception in `pay()`: logged, transaction set to `failed` via `fail()`. With `errorUrl`, redirect to `errorUrl?error-code=...`, otherwise the exception is rethrown.
   5. Return: `PaymentController::finalizeTransaction()` decodes the token and calls `PaymentProcessor::finalize()` -> `handler->finalize()`. `PaymentException::customerCanceled` (code `CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT`) triggers `cancel()`, any other exception `fail()`.
8. **Refund**: `PaymentRefundProcessor::processRefund($refundId, $context)`; the refund must be `open`; `supports(REFUND, ...)` must return true, otherwise `PaymentException::unknownRefundHandler`. If `refund()` throws, `OrderTransactionCaptureRefundStateHandler::fail` sets the refund to failed. **Success path**: after a successful `refund()`, the `PaymentRefundProcessor` itself does not trigger any transition (source: `core/Checkout/Payment/Cart/PaymentRefundProcessor.php`); the handler must complete the refund itself: `OrderTransactionCaptureRefundStateHandler::complete($refundId, $context)` (other actions: `process`, `cancel`, `fail`, `reopen`; service ID = class, constructor `@internal`). Whether `complete` is allowed directly from `open` depends on the state machine definition; the transitions are not documented in the migrations in the vendor code, call `process()` first if needed. According to the docblock, only via the API, not in the frontend.
9. **Recurring**: `PaymentRecurringProcessor::processRecurring($orderId, $context)` -> `handler->recurring()`; prerequisite `supports(RECURRING, ...)`. `RecurringDataStruct` is **experimental**; `getSubscriptionId()`/`getNextSchedule()` are `@deprecated tag:v6.8.0` without replacement. Do not rely on it.

### Status transitions of the payment transaction

State machine `order_transaction.state` (`OrderTransactionStates::STATE_MACHINE`). States: `open`, `in_progress`, `authorized`, `paid`, `paid_partially`, `reminded`, `unconfirmed`, `failed`, `cancelled`, `refunded`, `refunded_partially`, `chargeback`.

| Method (`OrderTransactionStateHandler`) | Action | Typical use |
|---|---|---|
| `process()` | `process` | open/reminded/failed/paid_partially -> `in_progress` |
| `processUnconfirmed()` | `process_unconfirmed` | -> `unconfirmed` |
| `authorize()` | `authorize` | open/in_progress/reminded -> `authorized` |
| `paid()` | `paid` | among others open/in_progress/failed/cancelled -> `paid` |
| `paidPartially()` | `paid_partially` | -> `paid_partially` |
| `fail()` | `fail` | open/in_progress/failed -> `failed` |
| `cancel()` | `cancel` | -> `cancelled` |
| `reopen()` | `reopen` | cancelled/failed/in_progress -> `open` |
| `remind()` | `remind` | open/paid_partially -> `reminded` |
| `refund()` / `refundPartially()` | `refund` / `refund_partially` | -> `refunded` / `refunded_partially` |
| `chargeback()` | `chargeback` | paid/paid_partially -> `chargeback` |

Transitions documented in migrations: open->paid/paid_partially (`V6_3/Migration1587461582`), in_progress->paid/paid_partially/failed/cancelled/open and failed->open/in_progress/paid/failed (`V6_3/Migration1580746806`), cancelled->paid (`V6_3/Migration1600769357`), authorize from open/in_progress/reminded and chargeback from paid/paid_partially (`V6_3/Migration1591361320`), renaming `pay`->`paid`, `pay_partially`->`paid_partially`, `do_pay`->`process` (`V6_7/Migration1742302302RenamePaidTransitionActions`). An illegal transition throws `IllegalTransitionException`. For each state, Shopware fires a flow trigger `state_enter.order_transaction.state.<name>` ([T-09](./09-flow-builder.md), [T-38](./38-order-statemachine-documents.md)).

## 4. Examples

`composer.json` (excerpt, details in [T-02](./02-plugin-structure.md))

```json
{
    "name": "swag/example",
    "type": "shopware-platform-plugin",
    "autoload": { "psr-4": { "SwagExample\\": "src/" } },
    "extra": {
        "shopware-plugin-class": "SwagExample\\SwagExample",
        "label": { "de-DE": "Beispiel-Zahlung", "en-GB": "Example payment" }
    }
}
```

`src/Service/ExamplePayment.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class ExamplePayment extends AbstractPaymentHandler
{
    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly OrderTransactionCaptureRefundStateHandler $refundStateHandler,
    ) {
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        // only return true for the types that are actually implemented
        return $type === PaymentHandlerType::REFUND;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $transactionId = $transaction->getOrderTransactionId();

        // open -> in_progress
        $this->transactionStateHandler->process($transactionId, $context);

        // Call the external payment provider here (HTTP client, SDK ...).
        // Redirect flow: returnUrl contains the signed token (_sw_payment_token)
        $providerUrl = 'https://payment-provider.example/pay?return=' . \urlencode((string) $transaction->getReturnUrl());

        return new RedirectResponse($providerUrl);
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $transactionId = $transaction->getOrderTransactionId();

        if ($request->query->getBoolean('cancel')) {
            // triggers the "cancel" transition in PaymentProcessor::finalize()
            throw PaymentException::customerCanceled($transactionId, 'Customer canceled the payment');
        }

        // Check the status with the provider, then:
        $this->transactionStateHandler->paid($transactionId, $context);
    }

    public function refund(RefundPaymentTransactionStruct $transaction, Context $context): void
    {
        // $transaction->getRefundId(), $transaction->getOrderTransactionId()
        // Trigger the refund with the provider; throw an exception on error,
        // PaymentRefundProcessor then sets the refund status to "failed".
        // Success: the processor sets NO status, so complete it yourself:
        $this->refundStateHandler->complete($transaction->getRefundId(), $context);
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use SwagExample\Service\ExamplePayment;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(ExamplePayment::class)
        ->args([
            service(OrderTransactionStateHandler::class),
            service(OrderTransactionCaptureRefundStateHandler::class),
        ])
        ->tag('shopware.payment.method');
};
```

`src/SwagExample.php`

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use SwagExample\Service\ExamplePayment;

class SwagExample extends Plugin
{
    private const TECHNICAL_NAME = 'swag_example_payment';

    public function install(InstallContext $installContext): void
    {
        $this->addPaymentMethod($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        // Do not delete the payment method (orders reference it), only deactivate it
        $this->setPaymentMethodIsActive(false, $uninstallContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->setPaymentMethodIsActive(true, $activateContext->getContext());
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setPaymentMethodIsActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    private function addPaymentMethod(Context $context): void
    {
        if ($this->getPaymentMethodId($context) !== null) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(static::class, $context);

        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([[
            'handlerIdentifier' => ExamplePayment::class,
            'technicalName' => self::TECHNICAL_NAME,
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
            'translations' => [
                'de-DE' => ['name' => 'Beispiel-Zahlung', 'description' => 'Zahlung ueber Beispielanbieter'],
                'en-GB' => ['name' => 'Example payment', 'description' => 'Pay via example provider'],
            ],
        ]], $context);
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $id = $this->getPaymentMethodId($context);
        if ($id === null) {
            return;
        }

        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->update([['id' => $id, 'active' => $active]], $context);
    }

    private function getPaymentMethodId(Context $context): ?string
    {
        /** @var EntityRepository $paymentRepository */
        $paymentRepository = $this->container->get('payment_method.repository');

        return $paymentRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', ExamplePayment::class)),
            $context
        )->firstId();
    }
}
```

The docs show `name` directly on the payload; `translations` with language codes follows the DAL pattern ([T-28](./28-dal-reading-writing.md)). Plugin scaffold: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Pitfalls

- **Old interfaces/tags** (`SynchronousPaymentHandlerInterface`, tags `shopware.payment.method.sync`/`.async`): no longer exist; use `AbstractPaymentHandler` + tag `shopware.payment.method`.
- **`technicalName` missing**: `payment_method.technical_name` has been `NOT NULL` since 6.7 (`Migration1697112044PaymentAndShippingTechnicalNameRequired`), `Required` in `PaymentMethodDefinition`; creation fails otherwise.
- **`supports()` always returns `true`**: the handler is also assumed for refund/recurring, `refund()` runs into the default exception. Only return true for implemented types (`DefaultPayment` returns `false`).
- **Expecting `finalize()` although `pay()` returns `null`**: `finalize` is only called after a `RedirectResponse`.
- **Setting the status directly via the repository**: bypasses the state machine, events and flows.
- **Deleting the payment method on uninstall**: `PaymentMethodValidator` prevents deletion of plugin payment methods (`PaymentException::pluginPaymentMethodDeleteRestriction`), additionally `RestrictDelete` for orders. Only deactivate.
- **`handlerIdentifier` != service ID**: the registry does not find the handler; service ID = FQCN (`::class`).
- **Swallowing exceptions in `pay()`**: only thrown exceptions lead to `failed`/error page. Customer cancellation in `finalize()` with `PaymentException::customerCanceled()`, otherwise `fail` instead of `cancel`.
- **Storing handler state**: handlers are singletons in the service locator; no request-specific data in properties.
- **Reading amounts from the request** instead of from `order_transaction` (`amount`): best practice, assumption.

### Deprecations and version notes (6.5/6.6 -> 6.7)

- **Interfaces -> abstract class**: 6.5/6.6 had `SynchronousPaymentHandlerInterface::pay(SyncPaymentTransaction, RequestDataBag, SalesChannelContext)`, `AsynchronousPaymentHandlerInterface::pay(AsyncPaymentTransactionStruct, ...)` + `finalize(...)`, `PreparedPaymentHandlerInterface::validate/capture`, `RefundPaymentHandlerInterface`, `RecurringPaymentHandlerInterface`. In 6.7: one `AbstractPaymentHandler` with `Request`, `PaymentTransactionStruct`, `Context` instead of `SalesChannelContext`.
- **Tags**: `.sync`, `.async`, `.prepared`, `.refund`, `.recurring` -> one tag `shopware.payment.method` (`tagged-service-contracts.php`).
- **Capture concept**: `capture()` is dropped; instead `validate()` and `pay()` with `$validateStruct`.
- **`technicalName`** required (migrations `V6_7/Migration1697112043...`/`...44...`).
- **Actions renamed**: `pay`->`paid`, `pay_partially`->`paid_partially`, `do_pay`->`process`. `StateMachineTransitionActions::ACTION_DO_PAY` is `@deprecated tag:v6.8.0` (-> `ACTION_PROCESS`); `OrderTransactionStateHandler::payPartially()` is `@deprecated tag:v6.8.0` (-> `paidPartially()`).
- **Token**: `TokenStruct`/`JWTFactoryV2` (`TokenFactoryInterfaceV2`) `@deprecated tag:v6.8.0` in favor of `PaymentToken`, `PaymentTokenGenerator`, `PaymentTokenLifecycle`; depending on feature flag `v6.8.0.0`, new or old mechanism; `REPEATED_PAYMENT_FINALIZE` is another flag in the controller. Transparent for handler authors.
- **Announced for 6.8**: `PaymentProcessor::finalize(PaymentToken $token, Request $request, SalesChannelContext $context): void` (docblock). Do not call the class directly (`@internal` constructor, `#[BecomesFinal]`).
- **Refund/recurring**: `RefundPaymentTransactionStruct` and `PaymentHandlerType::REFUND` are 6.7 API; `RecurringDataStruct` experimental, getters removed in 6.8.

## 6. Checklist

- [ ] Handler extends `AbstractPaymentHandler`, `supports()` only returns true for implemented types
- [ ] Service ID = FQCN, tag `shopware.payment.method` set
- [ ] Payment method created with `handlerIdentifier`, `technicalName`, `pluginId` and translations
- [ ] Deactivate/uninstall sets `active=false`, does not delete
- [ ] Status changes only via `OrderTransactionStateHandler`
- [ ] Customer cancellation via `PaymentException::customerCanceled()`
- [ ] Payment method assigned to the sales channels in the Administration and activated
- [ ] Amounts/order data loaded from `order_transaction`/order, not from the request
- [ ] No request-specific data in handler properties

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin.html

Source code (`core/`):
- `Checkout/Payment/Cart/PaymentHandler/{AbstractPaymentHandler,PaymentHandlerType,PaymentHandlerRegistry,DefaultPayment,PrePayment,InvoicePayment,CashPayment,DebitPayment}.php`
- `Checkout/Payment/Cart/{PaymentTransactionStruct,RefundPaymentTransactionStruct,PaymentTransactionStructFactory,AbstractPaymentTransactionStructFactory,PaymentRefundProcessor,PaymentRecurringProcessor}.php`, `Cart/Recurring/RecurringDataStruct.php`
- `Checkout/Payment/{PaymentProcessor,PaymentException,PaymentMethodDefinition}.php`, `Controller/PaymentController.php`, `SalesChannel/HandlePaymentMethodRoute.php`, `DataAbstractionLayer/PaymentMethodValidator.php`
- `Checkout/DependencyInjection/payment.php`, `Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`, `DevOps/StaticAnalyze/PHPStan/tagged-service-contracts.php`
- `Checkout/Order/Aggregate/OrderTransaction/{OrderTransactionStateHandler,OrderTransactionStates}.php`, `System/StateMachine/Aggregation/StateMachineTransition/StateMachineTransitionActions.php`
- `Framework/Plugin/Util/PluginIdProvider.php`, `Framework/App/Lifecycle/Handler/PaymentMethodLifecycleHandler.php`
- `Migration/V6_3/*`, `Migration/V6_7/{Migration1742302302RenamePaidTransitionActions,Migration1697112044PaymentAndShippingTechnicalNameRequired}.php`, `Test/Integration/PaymentHandler/TestPaymentHandler.php`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Examples not executed (container was not running); written against signatures in the source code (methods of `OrderTransactionStateHandler`, `customerCanceled`, `AbstractPaymentHandler` confirmed by spot check).
- ⚠ GAP: Complete transition matrix not documented, in particular transitions from/to `unconfirmed` and the table rows for `paid`/`refund`. Authoritative source: table `state_machine_transition` of the installation or Admin API `_action/state-machine/order_transaction/{id}/state`.
- ⚠ UNVERIFIED: Old 6.5/6.6 signatures come from memory and a docs migration note, no longer present in the 6.7 code; exact version in which the interfaces were removed not checked.
- ⚠ GAP: The docs example `technicalName` is `swag_example-example_payment`; only `Required`/`NOT NULL` and uniqueness are enforced (`PaymentException::duplicateTechnicalName` exists, the location of the check was not examined).
- ⚠ UNVERIFIED: `translations` with language codes in the `create()` payload for `payment_method` not tested. Autoconfigure applies in plugin `services.php` (verified live) only with `->defaults()->autoconfigure()`; hence the explicit tag.
- ⚠ GAP: Not examined: storefront payment forms, provider webhooks/notifications, capture entities (`order_transaction_capture`), admin components for payment methods, handler resolution for app payment methods, `OrderTransactionCaptureRefundStateHandler` transition definitions (methods `complete/process/cancel/fail/reopen` and caller `PaymentRefundProcessor` are documented; allowed transitions are not). Further docs pages (refund/recurring) not retrieved.
