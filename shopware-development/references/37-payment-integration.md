# Zahlungsintegration (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eine eigene Zahlungsart mit Handler anbindet (synchron oder per Redirect), Zahlungsstatus über die State Machine setzt oder Refund/Recurring anbietet.

Abgrenzung: Bestell-State-Machines und Dokumente in [T-38](./38-order-statemachine-dokumente.md); Warenkorb in [T-36](./36-warenkorb.md); Plugin-Lifecycle in [T-03](./03-plugin-lifecycle.md); Services/Tags in [T-05](./05-services-registrieren.md); Webhook-Controller in [T-16](./16-storefront-controller-pages.md) bzw. [T-31](./31-admin-api-routen.md); DAL-Schreiben in [T-28](./28-dal-lesen-schreiben.md); App-basierte Zahlungsarten nur in [T-10](./10-app-system-abgrenzung.md).

## 2. Konzepte

- Seit 6.7 gibt es **genau eine** Basisklasse: `Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler`. Die alten Interfaces (`SynchronousPaymentHandlerInterface`, `AsynchronousPaymentHandlerInterface`, `PreparedPaymentHandlerInterface`, `RefundPaymentHandlerInterface`, `RecurringPaymentHandlerInterface`) existieren in 6.7.15.1 **nicht mehr**.
- Methoden:
  - `supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool` (abstrakt). Enum `PaymentHandlerType`: `RECURRING`, `REFUND`.
  - `pay(Request, PaymentTransactionStruct, Context, ?Struct $validateStruct): ?RedirectResponse` (abstrakt). `null` = synchron, `RedirectResponse` = asynchron.
  - `validate(Cart, RequestDataBag, SalesChannelContext): ?Struct` (optional, vor Persistieren der Bestellung).
  - `finalize(Request, PaymentTransactionStruct, Context): void` (optional; nur nach Redirect aus `pay()`).
  - `refund(RefundPaymentTransactionStruct, Context): void` (nur wenn `supports(REFUND)` true).
  - `recurring(PaymentTransactionStruct, Context): void` (nur wenn `supports(RECURRING)` true).
- **Registrierung**: Tag `shopware.payment.method`. `AutoconfigureCompilerPass` setzt ihn für Klassen, die `AbstractPaymentHandler` erweitern, nur bei aktivem Autoconfigure; im Plugin explizit taggen. Service-ID muss der FQCN sein: `PaymentHandlerRegistry` sucht den Handler über `payment_method.handler_identifier`.
- **Zahlart anlegen** im Plugin-Lifecycle per `payment_method.repository`: `handlerIdentifier` = FQCN, `technicalName` (Pflicht), `pluginId`, Name (übersetzt). Beim Deaktivieren `active=false`, nie löschen.
- **Statuswechsel** nur über `OrderTransactionStateHandler` (Refunds: `OrderTransactionCaptureRefundStateHandler`), nicht durch Schreiben von `stateId`.
- `PaymentTransactionStruct` enthält nur `orderTransactionId`, `returnUrl`, `recurring`; die Bestellung lädt der Handler selbst über `order_transaction.repository`.

Verzeichnisbaum Plugin-relativ:

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                 # install/uninstall/activate/deactivate
    ├── Resources/config/services.php
    └── Service/ExamplePayment.php      # extends AbstractPaymentHandler
```

Optional: eigene Zahlungsformulare ([T-14](./14-storefront-twig-templates.md), [T-15](./15-storefront-js-plugins.md)), Webhook-Controller ([T-16](./16-storefront-controller-pages.md)).

## 3. Anleitung

1. Plugin anlegen: `bin/console plugin:create SwagExample` ([T-02](./02-plugin-aufbau.md)).
2. Handler schreiben (`extends AbstractPaymentHandler`), Services per Konstruktor injizieren.
3. Handler mit Tag `shopware.payment.method` registrieren.
4. Zahlart in `install()` anlegen (Pflicht: `handlerIdentifier`, `technicalName`, Name).
5. Aktivieren: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`.
6. In der Administration (Einstellungen -> Shop -> Zahlungsarten) prüfen, den Verkaufskanälen zuweisen (`sales_channel_payment_method`) und aktivieren.
7. **Ablauf bei einer Bestellung** (`core/Checkout/Payment/PaymentProcessor.php`):
   1. `PaymentProcessor::validate()` ruft `handler->validate()` vor dem Anlegen der Bestellung; Ergebnis wird als `validationData` an der Transaktion gespeichert. Fehler: `PaymentException::validatePreparedPaymentInterrupted()`.
   2. `PaymentProcessor::pay()` lädt die jüngste Transaktion im Status `open`, erzeugt einen `PaymentToken` (JWT), baut die `returnUrl` (Route `payment.finalize.transaction`, `/payment/finalize-transaction`, Query `_sw_payment_token`) und ruft `handler->pay(...)`.
   3. `RedirectResponse` aus `pay()`: Token bleibt gültig. Bei `null`: Token wird invalidiert (`finally`).
   4. Exception in `pay()`: geloggt, Transaktion per `fail()` auf `failed`. Mit `errorUrl` Redirect auf `errorUrl?error-code=...`, sonst wird die Exception weitergeworfen.
   5. Rückkehr: `PaymentController::finalizeTransaction()` dekodiert den Token und ruft `PaymentProcessor::finalize()` -> `handler->finalize()`. `PaymentException::customerCanceled` (Code `CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT`) löst `cancel()` aus, jede andere Exception `fail()`.
8. **Refund**: `PaymentRefundProcessor::processRefund($refundId, $context)`; Refund muss `open` sein; `supports(REFUND, ...)` muss bejaht werden, sonst `PaymentException::unknownRefundHandler`. Wirft `refund()`, setzt `OrderTransactionCaptureRefundStateHandler::fail` den Refund auf failed. **Erfolgspfad**: Der `PaymentRefundProcessor` ruft nach einem erfolgreichen `refund()` selbst keinen Übergang auf (Quelle: `core/Checkout/Payment/Cart/PaymentRefundProcessor.php`); der Handler muss den Refund selbst abschließen: `OrderTransactionCaptureRefundStateHandler::complete($refundId, $context)` (weitere Aktionen: `process`, `cancel`, `fail`, `reopen`; Service-ID = Klasse, Konstruktor `@internal`). Ob `complete` direkt aus `open` zulässig ist, hängt von der State-Machine-Definition ab; die Übergänge sind in den Migrationen im Vendor-Code nicht belegt, ggf. zuerst `process()` aufrufen. Laut Docblock nur über die API, nicht im Frontend.
9. **Recurring**: `PaymentRecurringProcessor::processRecurring($orderId, $context)` -> `handler->recurring()`; Voraussetzung `supports(RECURRING, ...)`. `RecurringDataStruct` ist **experimentell**; `getSubscriptionId()`/`getNextSchedule()` sind `@deprecated tag:v6.8.0` ohne Ersatz. Nicht darauf bauen.

### Statusübergänge der Zahlungstransaktion

State Machine `order_transaction.state` (`OrderTransactionStates::STATE_MACHINE`). Zustände: `open`, `in_progress`, `authorized`, `paid`, `paid_partially`, `reminded`, `unconfirmed`, `failed`, `cancelled`, `refunded`, `refunded_partially`, `chargeback`.

| Methode (`OrderTransactionStateHandler`) | Aktion | Typische Verwendung |
|---|---|---|
| `process()` | `process` | open/reminded/failed/paid_partially -> `in_progress` |
| `processUnconfirmed()` | `process_unconfirmed` | -> `unconfirmed` |
| `authorize()` | `authorize` | open/in_progress/reminded -> `authorized` |
| `paid()` | `paid` | u. a. open/in_progress/failed/cancelled -> `paid` |
| `paidPartially()` | `paid_partially` | -> `paid_partially` |
| `fail()` | `fail` | open/in_progress/failed -> `failed` |
| `cancel()` | `cancel` | -> `cancelled` |
| `reopen()` | `reopen` | cancelled/failed/in_progress -> `open` |
| `remind()` | `remind` | open/paid_partially -> `reminded` |
| `refund()` / `refundPartially()` | `refund` / `refund_partially` | -> `refunded` / `refunded_partially` |
| `chargeback()` | `chargeback` | paid/paid_partially -> `chargeback` |

Belegte Transitionen in Migrationen: open->paid/paid_partially (`V6_3/Migration1587461582`), in_progress->paid/paid_partially/failed/cancelled/open und failed->open/in_progress/paid/failed (`V6_3/Migration1580746806`), cancelled->paid (`V6_3/Migration1600769357`), authorize aus open/in_progress/reminded und chargeback aus paid/paid_partially (`V6_3/Migration1591361320`), Umbenennung `pay`->`paid`, `pay_partially`->`paid_partially`, `do_pay`->`process` (`V6_7/Migration1742302302RenamePaidTransitionActions`). Ein unzulässiger Übergang wirft `IllegalTransitionException`. Pro Zustand löst Shopware einen Flow-Trigger `state_enter.order_transaction.state.<name>` aus ([T-09](./09-flow-builder.md), [T-38](./38-order-statemachine-dokumente.md)).

## 4. Beispiele

`composer.json` (Auszug, Details [T-02](./02-plugin-aufbau.md))

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
        // nur die tatsaechlich implementierten Typen bejahen
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

        // Hier den externen Zahlungsanbieter aufrufen (HTTP-Client, SDK ...).
        // Redirect-Flow: returnUrl enthaelt den signierten Token (_sw_payment_token)
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
            // loest in PaymentProcessor::finalize() den Uebergang "cancel" aus
            throw PaymentException::customerCanceled($transactionId, 'Customer canceled the payment');
        }

        // Status beim Anbieter pruefen, danach:
        $this->transactionStateHandler->paid($transactionId, $context);
    }

    public function refund(RefundPaymentTransactionStruct $transaction, Context $context): void
    {
        // $transaction->getRefundId(), $transaction->getOrderTransactionId()
        // Rueckerstattung beim Anbieter anstossen; bei Fehler Exception werfen,
        // PaymentRefundProcessor setzt den Refund-Status dann auf "failed".
        // Erfolg: der Processor setzt KEINEN Status, daher selbst abschliessen:
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
        // Zahlart nicht loeschen (Bestellungen referenzieren sie), nur deaktivieren
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

Die Doku zeigt `name` direkt am Payload; `translations` mit Sprachcodes folgt dem DAL-Muster ([T-28](./28-dal-lesen-schreiben.md)). Plugin-Gerüst: [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Fallstricke

- **Alte Interfaces/Tags** (`SynchronousPaymentHandlerInterface`, Tags `shopware.payment.method.sync`/`.async`): existieren nicht mehr; `AbstractPaymentHandler` + Tag `shopware.payment.method`.
- **`technicalName` fehlt**: `payment_method.technical_name` ist seit 6.7 `NOT NULL` (`Migration1697112044PaymentAndShippingTechnicalNameRequired`), `Required` in `PaymentMethodDefinition`; Anlegen schlägt sonst fehl.
- **`supports()` gibt immer `true`**: Handler wird auch für Refund/Recurring angenommen, `refund()` läuft in die Default-Exception. Nur implementierte Typen bejahen (`DefaultPayment` gibt `false` zurück).
- **`finalize()` erwartet, obwohl `pay()` `null` liefert**: `finalize` wird nur nach einer `RedirectResponse` aufgerufen.
- **Status direkt per Repository setzen**: umgeht State Machine, Events und Flows.
- **Zahlart beim Deinstallieren löschen**: `PaymentMethodValidator` verhindert das Löschen von Plugin-Zahlarten (`PaymentException::pluginPaymentMethodDeleteRestriction`), zusätzlich `RestrictDelete` bei Bestellungen. Nur deaktivieren.
- **`handlerIdentifier` != Service-ID**: Registry findet den Handler nicht; Service-ID = FQCN (`::class`).
- **Exceptions in `pay()` schlucken**: nur geworfene Exceptions führen zu `failed`/Fehlerseite. Kundenabbruch in `finalize()` mit `PaymentException::customerCanceled()`, sonst `fail` statt `cancel`.
- **Handler-Zustand speichern**: Handler sind Singletons im Service-Locator; keine request-spezifischen Daten in Properties.
- **Beträge aus dem Request lesen** statt aus `order_transaction` (`amount`): Best Practice, Annahme.

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- **Interfaces -> Abstract Class**: 6.5/6.6 hatten `SynchronousPaymentHandlerInterface::pay(SyncPaymentTransaction, RequestDataBag, SalesChannelContext)`, `AsynchronousPaymentHandlerInterface::pay(AsyncPaymentTransactionStruct, ...)` + `finalize(...)`, `PreparedPaymentHandlerInterface::validate/capture`, `RefundPaymentHandlerInterface`, `RecurringPaymentHandlerInterface`. In 6.7: ein `AbstractPaymentHandler` mit `Request`, `PaymentTransactionStruct`, `Context` statt `SalesChannelContext`.
- **Tags**: `.sync`, `.async`, `.prepared`, `.refund`, `.recurring` -> ein Tag `shopware.payment.method` (`tagged-service-contracts.php`).
- **Capture-Konzept**: `capture()` entfällt; stattdessen `validate()` und `pay()` mit `$validateStruct`.
- **`technicalName`** Pflicht (Migrationen `V6_7/Migration1697112043...`/`...44...`).
- **Aktionen umbenannt**: `pay`->`paid`, `pay_partially`->`paid_partially`, `do_pay`->`process`. `StateMachineTransitionActions::ACTION_DO_PAY` ist `@deprecated tag:v6.8.0` (-> `ACTION_PROCESS`); `OrderTransactionStateHandler::payPartially()` ist `@deprecated tag:v6.8.0` (-> `paidPartially()`).
- **Token**: `TokenStruct`/`JWTFactoryV2` (`TokenFactoryInterfaceV2`) `@deprecated tag:v6.8.0` zugunsten `PaymentToken`, `PaymentTokenGenerator`, `PaymentTokenLifecycle`; je nach Feature-Flag `v6.8.0.0` neues oder altes Verfahren; `REPEATED_PAYMENT_FINALIZE` ist ein weiteres Flag im Controller. Für Handler-Autoren transparent.
- **6.8 angekündigt**: `PaymentProcessor::finalize(PaymentToken $token, Request $request, SalesChannelContext $context): void` (Docblock). Klasse nicht direkt aufrufen (`@internal`-Konstruktor, `#[BecomesFinal]`).
- **Refund/Recurring**: `RefundPaymentTransactionStruct` und `PaymentHandlerType::REFUND` sind 6.7-API; `RecurringDataStruct` experimentell, Getter in 6.8 entfernt.

## 6. Checkliste

- [ ] Handler erweitert `AbstractPaymentHandler`, `supports()` bejaht nur implementierte Typen
- [ ] Service-ID = FQCN, Tag `shopware.payment.method` gesetzt
- [ ] Zahlart mit `handlerIdentifier`, `technicalName`, `pluginId` und Übersetzungen angelegt
- [ ] Deaktivieren/Deinstallieren setzt `active=false`, löscht nicht
- [ ] Statuswechsel nur über `OrderTransactionStateHandler`
- [ ] Kundenabbruch über `PaymentException::customerCanceled()`
- [ ] Zahlart in der Administration den Verkaufskanälen zugewiesen und aktiviert
- [ ] Beträge/Bestelldaten aus `order_transaction`/Bestellung geladen, nicht aus dem Request
- [ ] Keine request-spezifischen Daten in Handler-Properties

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/payment/add-payment-plugin.html

Quellcode (`core/`):
- `Checkout/Payment/Cart/PaymentHandler/{AbstractPaymentHandler,PaymentHandlerType,PaymentHandlerRegistry,DefaultPayment,PrePayment,InvoicePayment,CashPayment,DebitPayment}.php`
- `Checkout/Payment/Cart/{PaymentTransactionStruct,RefundPaymentTransactionStruct,PaymentTransactionStructFactory,AbstractPaymentTransactionStructFactory,PaymentRefundProcessor,PaymentRecurringProcessor}.php`, `Cart/Recurring/RecurringDataStruct.php`
- `Checkout/Payment/{PaymentProcessor,PaymentException,PaymentMethodDefinition}.php`, `Controller/PaymentController.php`, `SalesChannel/HandlePaymentMethodRoute.php`, `DataAbstractionLayer/PaymentMethodValidator.php`
- `Checkout/DependencyInjection/payment.php`, `Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`, `DevOps/StaticAnalyze/PHPStan/tagged-service-contracts.php`
- `Checkout/Order/Aggregate/OrderTransaction/{OrderTransactionStateHandler,OrderTransactionStates}.php`, `System/StateMachine/Aggregation/StateMachineTransition/StateMachineTransitionActions.php`
- `Framework/Plugin/Util/PluginIdProvider.php`, `Framework/App/Lifecycle/Handler/PaymentMethodLifecycleHandler.php`
- `Migration/V6_3/*`, `Migration/V6_7/{Migration1742302302RenamePaidTransitionActions,Migration1697112044PaymentAndShippingTechnicalNameRequired}.php`, `Test/Integration/PaymentHandler/TestPaymentHandler.php`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele nicht ausgeführt (Container lief nicht); gegen Signaturen im Quellcode geschrieben (Methoden von `OrderTransactionStateHandler`, `customerCanceled`, `AbstractPaymentHandler` per Stichprobe bestätigt).
- ⚠ LÜCKE: Vollständige Transitionsmatrix nicht belegt, insbesondere Übergänge von/nach `unconfirmed` und die Tabellenzeilen zu `paid`/`refund`. Verbindliche Quelle: Tabelle `state_machine_transition` der Installation bzw. Admin-API `_action/state-machine/order_transaction/{id}/state`.
- ⚠ UNGEPRÜFT: Alt-Signaturen 6.5/6.6 stammen aus Gedächtnis und Doku-Migrationsnotiz, im 6.7-Code nicht mehr vorhanden; genaue Version der Entfernung der Interfaces nicht geprüft.
- ⚠ LÜCKE: Doku-Beispiel-`technicalName` lautet `swag_example-example_payment`; erzwungen sind nur `Required`/`NOT NULL` und Eindeutigkeit (`PaymentException::duplicateTechnicalName` existiert, Prüfstelle nicht untersucht).
- ⚠ UNGEPRÜFT: `translations` mit Sprachcodes im `create()`-Payload an `payment_method` nicht getestet. Autoconfigure greift in Plugin-`services.php` (live bestätigt) nur mit `->defaults()->autoconfigure()`; daher explizites Tag.
- ⚠ LÜCKE: Nicht untersucht: Storefront-Zahlungsformulare, Webhooks/Notifications des Anbieters, Capture-Entitäten (`order_transaction_capture`), Admin-Komponenten für Zahlarten, Handler-Auflösung für App-Zahlarten, `OrderTransactionCaptureRefundStateHandler`-Übergangsdefinitionen (Methoden `complete/process/cancel/fail/reopen` und Aufrufer `PaymentRefundProcessor` sind belegt; erlaubte Übergänge nicht). Weitere Doku-Seiten (Refund/Recurring) nicht abgerufen.
