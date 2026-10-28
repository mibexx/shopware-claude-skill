# Extending the cart: collectors, processors, validators, line items (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin extends the cart calculation process: custom line item types (surcharges, discounts, services), price calculation, cart errors/validation, or adding custom items via the Store API.

Scope: rule conditions on cart/context in [T-32 Rule Builder](./32-rule-builder.md); Store API routes and route decoration in [T-30](./30-store-api-routes.md); payment handling in [T-37](./37-payment-integration.md); Twig blocks of the line item templates in [T-14](./14-storefront-twig-templates.md); service tags in [T-05](./05-registering-services.md); events in [T-07](./07-events-subscriber.md).

## 2. Concepts

`Processor::process()` (`core/Checkout/Cart/Processor.php`) builds a **new** `Cart` (`$toCalculate`) from `$original` on every calculation:

1. If `$original` has no line items (`runProcessors()`): only persistent errors and extensions are carried over, collect and process are skipped; steps 4 to 6 still run.
2. **Collect**: all `shopware.cart.collector` one after another (`collect()` fills `CartDataCollection`).
3. **Process**: all `shopware.cart.processor` one after another; `AmountCalculator` runs again after each processor.
4. `CartHook` (app scripts), if `CartBehavior::hookAware()`.
5. Calculate the amount, then **Validate**: `Validator` calls all `shopware.cart.validator` and collects `Error`s.
6. `TransactionProcessor`, set `ruleIds`.

- **Order**: `tagged_iterator` sorts by `priority` **descending** (higher = earlier), default 0. Core priorities (`core/Checkout/DependencyInjection/cart.php`, `promotion.php`):
  - ProductCartProcessor/-Collector 5000
  - PromotionCollector/-Processor 4900
  - CustomCartProcessor 4000 (processor; collector default 0)
  - ContainerCartProcessor 3800
  - DiscountCartProcessor 3700
  - CreditCartProcessor 0
  - DeliveryProcessor/-Collector -5000
  - PromotionDeliveryProcessor -5100
- **Custom line item types**: string type + processor (sets `price`, `label`) + optionally `LineItemFactoryInterface` (tag `shopware.cart.line_item.factory`) for the Store API (`POST /store-api/checkout/cart/line-item`).
- Never calculate prices yourself: `QuantityPriceCalculator`, `PercentagePriceCalculator`, `AbsolutePriceCalculator` via DI.
- **Errors**: `Error` subclasses (level NOTICE 0 / WARNING 10 / ERROR 20; `blockOrder()`); in the processor via `$toCalculate->addErrors()`, in the validator via `$errors->add()`.
- Core type constants: `LineItem::PRODUCT_LINE_ITEM_TYPE`, `CUSTOM_LINE_ITEM_TYPE`, `CREDIT_LINE_ITEM_TYPE`, `PROMOTION_LINE_ITEM_TYPE`, `DISCOUNT_LINE_ITEM`, `CONTAINER_LINE_ITEM`, `QUANTITY_LINE_ITEM`.

Directory tree relative to the plugin:

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

`Core/Checkout/Cart` is a convention, not mandatory.

## 3. Instructions

1. Create the plugin ([T-02](./02-plugin-structure.md)), define the type constant.
2. **Collector**: load data and store it in `CartDataCollection` under a unique key (`set/has/get`); check `has()` first. No DB access in the processor (it runs multiple times, after every step).
3. **Processor**: read from `$original` (`filterType`), set the price via a calculator, carry it over with `$toCalculate->add()`. Items that are not added drop out of the cart (`DiscountCartProcessor` removes items with `$original->remove()`).
4. **Validator**: `CartValidatorInterface::validate(Cart, ErrorCollection, SalesChannelContext)`, errors via `$errors->add()`, tag `shopware.cart.validator`. Runs **after** the calculation and does not change the amount automatically; example with removal: `LineItemValidator` (`$cart->getLineItems()->removeElement()`).
5. Register a **factory** for adding/changing via the Store API; `LineItemFactoryRegistry` takes the first handler with `supports()==true`, otherwise `CartException::lineItemTypeNotSupported`.
6. **Discounts**: type `LineItem::DISCOUNT_LINE_ITEM` + `PercentagePriceDefinition(-10)`/`AbsolutePriceDefinition(-5)`/`CurrencyPriceDefinition` (optional `filter` rule) -> `DiscountCartProcessor` calculates automatically (prio 3700, on `$toCalculate->getLineItems()->filterGoods()`; discards 0 amounts and negative totals). Vouchers: type `credit` + `AbsolutePriceDefinition` (`CreditCartProcessor`).
7. **Nested line items**: `LineItem::CONTAINER_LINE_ITEM` with `addChild()`; `ContainerCartProcessor` (3800) sums up the children; a container needs no label (`LineItemValidator`). `getFlat()`/`filterFlatByType` traverse children, `filterType` only the top level. Storefront: `component/line-item/line-item.html.twig` selects the template product/discount/container/generic; add your own type via block `component_line_item_type_include` ([T-14](./14-storefront-twig-templates.md)). Removing/changing children: no standard mechanism (docs).
8. **Error message**: create a snippet for `getMessageKey()` ([T-18](./18-snippets-translations.md)).
9. **CLI**:
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear
   bin/console debug:container --tag=shopware.cart.processor   # priority/order
   ```

## 4. Examples

Custom type `swag_example_giftwrap`: gift wrapping, EUR 2.50 gross per piece, 19 % tax; rejected if no other product is in the cart.

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
        // Load data ONLY here (DB, API); check first whether it is already loaded
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

            $item->setLabel('Gift wrap');
            $item->setRemovable(true);
            $item->setStackable(true);
            $item->setGood(true);
            $item->setPriceDefinition($definition);
            $item->setPrice($this->calculator->calculate($definition, $context));

            $toCalculate->add($item);   // only what is added here stays in the cart
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

        // There must be at least one "good" item that is not a gift wrap
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
        $item->markModified();   // like CustomLineItemFactory::create
        $this->update($item, $data, $context);

        return $item;
    }

    public function update(LineItem $lineItem, array $data, SalesChannelContext $context): void
    {
        // Do NOT take the price from the request - the processor does that
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
        ->tag('shopware.cart.processor', ['priority' => 3900])   // after Custom (4000), before Container (3800)/Discount (3700)
        ->tag('shopware.cart.collector', ['priority' => 3900]);

    $s->set(GiftWrapCartValidator::class)->tag('shopware.cart.validator');
    $s->set(GiftWrapLineItemFactory::class)->tag('shopware.cart.line_item.factory');
};
```

The plugin base class loads `Resources/config/services.*` (PHP confirmed, `Bundle::registerFilesystem`; XML is deprecated, see below). 3900 is an example value, not a docs recommendation.

Adding in PHP:

```php
$this->cartService->add($cart, new LineItem(Uuid::randomHex(), GiftWrapCartProcessor::TYPE, null, 1), $context);
```

(`SalesChannel/CartService::add(Cart, LineItem|array, SalesChannelContext)`.) Store API: `POST /store-api/checkout/cart/line-item` with `{"items":[{"id":"<hex-id>","type":"swag_example_giftwrap","referencedId":"x"}]}` -> `LineItemFactoryRegistry::create` (required: `id`, `type`).

More: [../templates/plugin/README.md](../templates/plugin/README.md), [../templates/decorator/README.md](../templates/decorator/README.md).

## 5. Pitfalls

- DB queries in `process()` instead of `collect()` (performance; the docs warn about this).
- Modifying `$original` instead of filling `$toCalculate`; forgetting to `add()` an item -> it disappears.
- Wrong/missing priority: discounts (3700) only cover items that are already in `$toCalculate`; give custom items that are supposed to be discounted a priority > 3700.
- No label/price: `LineItemValidator` removes the item and creates `IncompleteLineItemError`.
- Taking the price from the request in the factory (security risk). That is why `CustomLineItemFactory` requires `CheckoutPermissions::ALLOW_PRODUCT_PRICE_OVERWRITES`.
- Confusing error level ERROR with `blockOrder()=false`; `blockOrder()` is abstract and must be implemented. `isPersistent()` defaults to true; override it to false for recurring validator errors.
- `Error` is an `\Exception` and gets serialized (`__serialize` removes the trace): no services as properties.
- Custom rounding instead of `CashRounding`/calculator.
- Overriding entire classes instead of using the processor tag or decoration (the `Processor` constructor is `@internal`).
- `LineItem::getPriceDefinition()` is nullable: for strict static analysis, put the definition in a variable (as in the example).

### Deprecations and version notes (6.5/6.6 -> 6.7)

- Interfaces `CartProcessorInterface`, `CartDataCollectorInterface`, `CartValidatorInterface` and tags unchanged.
- Deprecated (removal in 6.8): `Error::getRoute()` and `ErrorRoute`; `CartBehavior::isRecalculation()` and constructor parameter `$isRecalculation` -> `CartBehavior::hasPermission(CheckoutPermissions::*)`; `AbstractCartPersister` (persist cart error parameter) -> `CheckoutPermissions::PERSIST_CART_ERROR`; `LineItem` download/product type API (`State::IS_DOWNLOAD`, `hasState`) -> `getProductType()/isProductType()/setPayloadValue('productType')`; `LineItemCollection::hasLineItemWithState` -> `hasLineItemWithProductType`.
- In 6.7, `ProductCartProcessor` additionally takes `ProductTypeRegistry` (`ProductDefinition::TYPE_DIGITAL`).
- `Processor` has a `ScriptExecutor` (cart hook, since 6.4).
- XML service definitions in plugins are `@deprecated tag:v6.8.0` (use PHP).
- Differences in 6.5/6.6 only inferred from the deprecation situation.

## 6. Checklist

- [ ] Type constant defined, label and price definition set in the processor
- [ ] Data loaded exclusively in `collect()`, `has()` check present
- [ ] Prices via calculator, not calculated manually
- [ ] Items carried over via `$toCalculate->add()`
- [ ] Tags `shopware.cart.processor`/`collector` with a deliberate priority
- [ ] Validator with a unique error `getId()` and implemented `blockOrder()`
- [ ] Factory does not take prices from the request
- [ ] Snippet for `getMessageKey()` and storefront template for the type present
- [ ] `debug:container --tag=shopware.cart.processor` shows the expected order

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/checkout/cart/add-cart-processor-collector.html (does not mention priorities, the code is authoritative)
- https://developer.shopware.com/docs/guides/plugins/plugins/architecture/cart-process.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/howto/use-nested-line-items.html

Source code (`core/Checkout/Cart/`):
- `Processor.php`, `Validator.php`, `CartProcessorInterface.php`, `CartDataCollectorInterface.php`, `CartValidatorInterface.php`, `CartBehavior.php`, `Cart.php`
- `CustomCartProcessor.php`, `CreditCartProcessor.php`, `Processor/{ContainerCartProcessor,DiscountCartProcessor}.php`
- `LineItem/{LineItem,CartDataCollection,LineItemCollection,LineItemValidator}.php`, `LineItemFactoryRegistry.php`, `LineItemFactoryHandler/*`
- `Price/{QuantityPriceCalculator,PercentagePriceCalculator,AbsolutePriceCalculator,AmountCalculator,CashRounding}.php`, `Price/Struct/*`, `Tax/Struct/{TaxRule,TaxRuleCollection}.php`
- `Error/{Error,GenericCartError,IncompleteLineItemError,ErrorCollection}.php`, `Facade/CartFacade.php`, `SalesChannel/CartService.php`
- DI: `core/Checkout/DependencyInjection/{cart,promotion}.php`; `core/Content/Product/Cart/ProductCartProcessor.php`
- `core/Framework/Bundle.php` (`registerFilesystem`)
- Storefront: `storefront/Resources/views/storefront/component/line-item/line-item.html.twig`, `.../type/{product,discount,container,generic}.html.twig`

### Gaps and uncertainties

- ⚠ UNVERIFIED: Example code not executed, only checked against signatures in the source code (`TaxRule` namespace `Shopware\Core\Checkout\Cart\Tax\Struct` is confirmed in the file tree). The validator condition is deliberately simple; adapt the logic as needed.
- ⚠ UNVERIFIED: Snippet naming scheme for cart error texts (presumably `checkout.<messageKey>`) not verified against the code.
- ⚠ GAP: Order within the same priority is not documented (Symfony: registration order).
- ⚠ UNVERIFIED: Priorities apply to 6.7.15.1; 6.5/6.6 values not compared. The `SORTING_PRIORITY` of the price definitions (absolute 75, percentage 50) not examined in depth.
