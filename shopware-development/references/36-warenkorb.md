# Warenkorb erweitern: Collector, Processor, Validator, Line-Items (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin den Warenkorb-Berechnungsprozess erweitert: eigene Line-Item-Typen (Zuschläge, Rabatte, Services), Preisberechnung, Warenkorb-Fehler/-Validierung oder das Hinzufügen eigener Posten über die Store-API.

Abgrenzung: Regel-Bedingungen auf Cart/Context in [T-32 Rule Builder](./32-rule-builder.md); Store-API-Routen und Route-Dekoration in [T-30](./30-store-api-routen.md); Zahlungsabwicklung in [T-37](./37-payment-integration.md); Twig-Blöcke der Line-Item-Templates in [T-14](./14-storefront-twig-templates.md); Service-Tags in [T-05](./05-services-registrieren.md); Events in [T-07](./07-events-subscriber.md).

## 2. Konzepte

`Processor::process()` (`core/Checkout/Cart/Processor.php`) baut bei jeder Berechnung einen **neuen** `Cart` (`$toCalculate`) aus `$original`:

1. Hat `$original` keine Line-Items (`runProcessors()`): nur persistente Fehler und Extensions übernehmen, Collect und Process entfallen; die Schritte 4 bis 6 laufen trotzdem.
2. **Collect**: alle `shopware.cart.collector` nacheinander (`collect()` füllt `CartDataCollection`).
3. **Process**: alle `shopware.cart.processor` nacheinander; nach jedem Processor läuft `AmountCalculator` neu.
4. `CartHook` (App-Scripts), falls `CartBehavior::hookAware()`.
5. Betrag berechnen, dann **Validate**: `Validator` ruft alle `shopware.cart.validator` und sammelt `Error`s.
6. `TransactionProcessor`, `ruleIds` setzen.

- **Reihenfolge**: `tagged_iterator` sortiert nach `priority` **absteigend** (höher = früher), Standard 0. Core-Prioritäten (`core/Checkout/DependencyInjection/cart.php`, `promotion.php`):
  - ProductCartProcessor/-Collector 5000
  - PromotionCollector/-Processor 4900
  - CustomCartProcessor 4000 (Processor; Collector Default 0)
  - ContainerCartProcessor 3800
  - DiscountCartProcessor 3700
  - CreditCartProcessor 0
  - DeliveryProcessor/-Collector -5000
  - PromotionDeliveryProcessor -5100
- **Eigene Line-Item-Typen**: String-Typ + Processor (setzt `price`, `label`) + optional `LineItemFactoryInterface` (Tag `shopware.cart.line_item.factory`) für die Store-API (`POST /store-api/checkout/cart/line-item`).
- Preise nie selbst rechnen: `QuantityPriceCalculator`, `PercentagePriceCalculator`, `AbsolutePriceCalculator` per DI.
- **Fehler**: `Error`-Subklassen (Level NOTICE 0 / WARNING 10 / ERROR 20; `blockOrder()`); im Processor über `$toCalculate->addErrors()`, im Validator über `$errors->add()`.
- Core-Typkonstanten: `LineItem::PRODUCT_LINE_ITEM_TYPE`, `CUSTOM_LINE_ITEM_TYPE`, `CREDIT_LINE_ITEM_TYPE`, `PROMOTION_LINE_ITEM_TYPE`, `DISCOUNT_LINE_ITEM`, `CONTAINER_LINE_ITEM`, `QUANTITY_LINE_ITEM`.

Verzeichnisbaum Plugin-relativ:

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Resources/
    │   ├── config/services.php
    │   └── views/storefront/component/line-item/type/swag-example-giftwrap.html.twig   # optional
    └── Core/Checkout/Cart/
        ├── GiftWrapCartProcessor.php       # Collector + Processor
        ├── GiftWrapCartValidator.php
        ├── GiftWrapLineItemFactory.php
        └── Error/GiftWrapNotAllowedError.php
```

`Core/Checkout/Cart` ist Konvention, nicht Pflicht.

## 3. Anleitung

1. Plugin anlegen ([T-02](./02-plugin-aufbau.md)), Typ-Konstante definieren.
2. **Collector**: Daten laden und in `CartDataCollection` unter eindeutigem Key ablegen (`set/has/get`); vorher `has()` prüfen. Kein DB-Zugriff im Processor (läuft nach jedem Schritt mehrfach).
3. **Processor**: aus `$original` lesen (`filterType`), Preis über Calculator setzen, mit `$toCalculate->add()` übernehmen. Nicht hinzugefügte Items fallen aus dem Warenkorb (`DiscountCartProcessor` entfernt Items mit `$original->remove()`).
4. **Validator**: `CartValidatorInterface::validate(Cart, ErrorCollection, SalesChannelContext)`, Fehler per `$errors->add()`, Tag `shopware.cart.validator`. Läuft **nach** der Berechnung und ändert den Betrag nicht automatisch; Beispiel mit Entfernen: `LineItemValidator` (`$cart->getLineItems()->removeElement()`).
5. **Factory** für Store-API-Hinzufügen/Ändern registrieren; `LineItemFactoryRegistry` nimmt den ersten Handler mit `supports()==true`, sonst `CartException::lineItemTypeNotSupported`.
6. **Rabatte**: Typ `LineItem::DISCOUNT_LINE_ITEM` + `PercentagePriceDefinition(-10)`/`AbsolutePriceDefinition(-5)`/`CurrencyPriceDefinition` (optional `filter`-Rule) -> `DiscountCartProcessor` rechnet automatisch (Prio 3700, auf `$toCalculate->getLineItems()->filterGoods()`; verwirft 0-Beträge und negative Gesamtsummen). Gutscheine: Typ `credit` + `AbsolutePriceDefinition` (`CreditCartProcessor`).
7. **Nested Line-Items**: `LineItem::CONTAINER_LINE_ITEM` mit `addChild()`; `ContainerCartProcessor` (3800) summiert Kinder; Container braucht kein Label (`LineItemValidator`). `getFlat()`/`filterFlatByType` durchlaufen Kinder, `filterType` nur die oberste Ebene. Storefront: `component/line-item/line-item.html.twig` wählt Template product/discount/container/generic; eigenen Typ über Block `component_line_item_type_include` ergänzen ([T-14](./14-storefront-twig-templates.md)). Entfernen/Ändern von Kindern: kein Standardmechanismus (Doku).
8. **Fehlermeldung**: Snippet für `getMessageKey()` anlegen ([T-18](./18-snippets-uebersetzungen.md)).
9. **CLI**:
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear
   bin/console debug:container --tag=shopware.cart.processor   # Priorität/Reihenfolge
   ```

## 4. Beispiele

Eigener Typ `swag_example_giftwrap`: Geschenkverpackung, 2,50 EUR brutto pro Stück, 19 % Steuer; wird abgelehnt, wenn kein anderes Produkt im Warenkorb liegt.

`src/Core/Checkout/Cart/GiftWrapCartProcessor.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class GiftWrapCartProcessor implements CartProcessorInterface, CartDataCollectorInterface
{
    public const TYPE = 'swag_example_giftwrap';
    public const DATA_KEY = 'swag-example-giftwrap-price';
    private const UNIT_PRICE_GROSS = 2.5;

    public function __construct(private readonly QuantityPriceCalculator $calculator)
    {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        // Daten NUR hier laden (DB, API); vorher pruefen, ob schon geladen
        if ($data->has(self::DATA_KEY)) {
            return;
        }
        if ($original->getLineItems()->filterFlatByType(self::TYPE) === []) {
            return;
        }
        $data->set(self::DATA_KEY, self::UNIT_PRICE_GROSS);
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($original->getLineItems()->filterType(self::TYPE) as $item) {
            $price = $data->get(self::DATA_KEY);
            if (!\is_float($price)) {
                continue;
            }

            $definition = new QuantityPriceDefinition(
                $price,
                new TaxRuleCollection([new TaxRule(19.0)]),
                $item->getQuantity()
            );

            $item->setLabel('Geschenkverpackung');
            $item->setRemovable(true);
            $item->setStackable(true);
            $item->setGood(true);
            $item->setPriceDefinition($definition);
            $item->setPrice($this->calculator->calculate($definition, $context));

            $toCalculate->add($item);   // nur was hier hinzugefuegt wird, bleibt im Warenkorb
        }
    }
}
```

`src/Core/Checkout/Cart/Error/GiftWrapNotAllowedError.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Checkout\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

class GiftWrapNotAllowedError extends Error
{
    public function __construct(private readonly string $lineItemId)
    {
        $this->message = \sprintf('Gift wrap %s needs a physical product.', $lineItemId);
        parent::__construct($this->message);
    }

    public function getId(): string
    {
        return $this->getMessageKey() . $this->lineItemId;
    }

    public function getMessageKey(): string
    {
        return 'swag-example-giftwrap-not-allowed';
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return ['id' => $this->lineItemId];
    }
}
```

`src/Core/Checkout/Cart/GiftWrapCartValidator.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagExample\Core\Checkout\Cart\Error\GiftWrapNotAllowedError;

class GiftWrapCartValidator implements CartValidatorInterface
{
    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        $wraps = $cart->getLineItems()->filterFlatByType(GiftWrapCartProcessor::TYPE);
        if ($wraps === []) {
            return;
        }

        // Es muss mindestens ein "gutes" Item geben, das keine Geschenkverpackung ist
        $others = array_filter(
            $cart->getLineItems()->filterGoodsFlat(),
            static fn ($item): bool => $item->getType() !== GiftWrapCartProcessor::TYPE
        );
        if ($others !== []) {
            return;
        }

        foreach ($wraps as $wrap) {
            $errors->add(new GiftWrapNotAllowedError($wrap->getId()));
        }
    }
}
```

`src/Core/Checkout/Cart/GiftWrapLineItemFactory.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryHandler\LineItemFactoryInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class GiftWrapLineItemFactory implements LineItemFactoryInterface
{
    public function supports(string $type): bool
    {
        return $type === GiftWrapCartProcessor::TYPE;
    }

    public function create(array $data, SalesChannelContext $context): LineItem
    {
        $item = new LineItem($data['id'], $data['type'], $data['referencedId'] ?? null, $data['quantity'] ?? 1);
        $item->markModified();   // wie CustomLineItemFactory::create
        $this->update($item, $data, $context);

        return $item;
    }

    public function update(LineItem $lineItem, array $data, SalesChannelContext $context): void
    {
        // Preis NICHT aus dem Request uebernehmen - das macht der Processor
        if (isset($data['payload']) && \is_array($data['payload'])) {
            $lineItem->setPayload($data['payload']);
        }
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use SwagExample\Core\Checkout\Cart\GiftWrapCartProcessor;
use SwagExample\Core\Checkout\Cart\GiftWrapCartValidator;
use SwagExample\Core\Checkout\Cart\GiftWrapLineItemFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $c): void {
    $s = $c->services();

    $s->set(GiftWrapCartProcessor::class)
        ->args([service(QuantityPriceCalculator::class)])
        ->tag('shopware.cart.processor', ['priority' => 3900])   // nach Custom (4000), vor Container (3800)/Discount (3700)
        ->tag('shopware.cart.collector', ['priority' => 3900]);

    $s->set(GiftWrapCartValidator::class)->tag('shopware.cart.validator');
    $s->set(GiftWrapLineItemFactory::class)->tag('shopware.cart.line_item.factory');
};
```

Die Plugin-Basisklasse lädt `Resources/config/services.*` (PHP belegt, `Bundle::registerFilesystem`; XML ist deprecated, siehe unten). 3900 ist ein Beispielwert, keine Doku-Empfehlung.

Hinzufügen im PHP:

```php
$this->cartService->add($cart, new LineItem(Uuid::randomHex(), GiftWrapCartProcessor::TYPE, null, 1), $context);
```

(`SalesChannel/CartService::add(Cart, LineItem|array, SalesChannelContext)`.) Store-API: `POST /store-api/checkout/cart/line-item` mit `{"items":[{"id":"<hex-id>","type":"swag_example_giftwrap","referencedId":"x"}]}` -> `LineItemFactoryRegistry::create` (Pflicht: `id`, `type`).

Weiteres: [../templates/plugin/README.md](../templates/plugin/README.md), [../templates/decorator/README.md](../templates/decorator/README.md).

## 5. Fallstricke

- DB-Abfragen in `process()` statt `collect()` (Performance; Doku warnt).
- `$original` verändern statt `$toCalculate` befüllen; Item vergessen zu `add()` -> verschwindet.
- Falsche/fehlende Priorität: Rabatte (3700) beziehen nur Items, die schon in `$toCalculate` liegen; eigene Posten, die rabattiert werden sollen, mit Priorität > 3700 vergeben.
- Kein Label/Preis: `LineItemValidator` entfernt das Item und erzeugt `IncompleteLineItemError`.
- Preis aus dem Request in der Factory übernehmen (Sicherheitsrisiko). `CustomLineItemFactory` verlangt deshalb `CheckoutPermissions::ALLOW_PRODUCT_PRICE_OVERWRITES`.
- Fehler-Level ERROR mit `blockOrder()=false` verwechseln; `blockOrder()` ist abstrakt und muss implementiert werden. `isPersistent()` Default true; für wiederkehrende Validator-Fehler auf false überschreiben.
- `Error` ist `\Exception` und wird serialisiert (`__serialize` entfernt Trace): keine Services als Properties.
- Eigene Rundung statt `CashRounding`/Calculator.
- Ganze Klassen überschreiben statt Processor-Tag oder Dekoration (`Processor`-Konstruktor ist `@internal`).
- `LineItem::getPriceDefinition()` ist nullable: für strikte Static-Analysis die Definition in eine Variable legen (wie im Beispiel).

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- Interfaces `CartProcessorInterface`, `CartDataCollectorInterface`, `CartValidatorInterface` und Tags unverändert.
- Deprecated (Entfernung 6.8): `Error::getRoute()` und `ErrorRoute`; `CartBehavior::isRecalculation()` und Konstruktor-Parameter `$isRecalculation` -> `CartBehavior::hasPermission(CheckoutPermissions::*)`; `AbstractCartPersister` (persist-Cart-Error-Parameter) -> `CheckoutPermissions::PERSIST_CART_ERROR`; `LineItem`-Download-/Produkttyp-API (`State::IS_DOWNLOAD`, `hasState`) -> `getProductType()/isProductType()/setPayloadValue('productType')`; `LineItemCollection::hasLineItemWithState` -> `hasLineItemWithProductType`.
- `ProductCartProcessor` nimmt in 6.7 zusätzlich `ProductTypeRegistry` (`ProductDefinition::TYPE_DIGITAL`).
- `Processor` hat einen `ScriptExecutor` (Cart-Hook, ab 6.4).
- XML-Service-Definitionen im Plugin sind `@deprecated tag:v6.8.0` (PHP verwenden).
- Abweichungen 6.5/6.6 nur aus der Deprecation-Lage erschlossen.

## 6. Checkliste

- [ ] Typ-Konstante definiert, Label und Preisdefinition im Processor gesetzt
- [ ] Daten ausschließlich in `collect()` geladen, `has()`-Prüfung vorhanden
- [ ] Preise über Calculator, nicht selbst berechnet
- [ ] Items per `$toCalculate->add()` übernommen
- [ ] Tags `shopware.cart.processor`/`collector` mit bewusster Priorität
- [ ] Validator mit eindeutiger Fehler-`getId()` und implementiertem `blockOrder()`
- [ ] Factory übernimmt keine Preise aus dem Request
- [ ] Snippet für `getMessageKey()` und Storefront-Template für den Typ vorhanden
- [ ] `debug:container --tag=shopware.cart.processor` zeigt die erwartete Reihenfolge

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/add-cart-processor-collector.html (nennt keine Prioritäten, Code maßgeblich)
- https://developer.shopware.com/docs/guides/plugins/plugins/architecture/cart-process.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/use-nested-line-items.html

Quellcode (`core/Checkout/Cart/`):
- `Processor.php`, `Validator.php`, `CartProcessorInterface.php`, `CartDataCollectorInterface.php`, `CartValidatorInterface.php`, `CartBehavior.php`, `Cart.php`
- `CustomCartProcessor.php`, `CreditCartProcessor.php`, `Processor/{ContainerCartProcessor,DiscountCartProcessor}.php`
- `LineItem/{LineItem,CartDataCollection,LineItemCollection,LineItemValidator}.php`, `LineItemFactoryRegistry.php`, `LineItemFactoryHandler/*`
- `Price/{QuantityPriceCalculator,PercentagePriceCalculator,AbsolutePriceCalculator,AmountCalculator,CashRounding}.php`, `Price/Struct/*`, `Tax/Struct/{TaxRule,TaxRuleCollection}.php`
- `Error/{Error,GenericCartError,IncompleteLineItemError,ErrorCollection}.php`, `Facade/CartFacade.php`, `SalesChannel/CartService.php`
- DI: `core/Checkout/DependencyInjection/{cart,promotion}.php`; `core/Content/Product/Cart/ProductCartProcessor.php`
- `core/Framework/Bundle.php` (`registerFilesystem`)
- Storefront: `storefront/Resources/views/storefront/component/line-item/line-item.html.twig`, `.../type/{product,discount,container,generic}.html.twig`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispielcode nicht ausgeführt, nur gegen Signaturen im Quellcode geprüft (`TaxRule`-Namespace `Shopware\Core\Checkout\Cart\Tax\Struct` ist im Dateibaum bestätigt). Die Validator-Bedingung ist bewusst einfach; Logik nach Bedarf anpassen.
- ⚠ UNGEPRÜFT: Snippet-Namensschema für Cart-Fehlertexte (vermutlich `checkout.<messageKey>`) nicht am Code verifiziert.
- ⚠ LÜCKE: Reihenfolge innerhalb gleicher Priorität nicht dokumentiert (Symfony: Registrierungsreihenfolge).
- ⚠ UNGEPRÜFT: Prioritäten gelten für 6.7.15.1; 6.5/6.6-Werte nicht verglichen. Das `SORTING_PRIORITY` der Preisdefinitionen (absolute 75, percentage 50) nicht vertieft.
