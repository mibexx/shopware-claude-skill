# Rule Builder: custom rules (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin is to provide its own rule condition for the Rule Builder (PHP class plus Admin registration), wants to evaluate rules in code (`getRuleIds()`, `RuleIdMatcher`), or adds an assignment card to the rule detail page.

Scope: cart processors/validators in [T-36 Cart](./36-cart.md); Flow Builder actions/triggers in [T-09 Flow Builder](./09-flow-builder.md); entity extension `rule -> my_entity` in [T-29](./29-entity-extensions-custom-fields.md); Admin snippets in [T-18](./18-snippets-translations.md); Admin build in [T-49](./49-build-deployment.md).

## 2. Concepts

- A rule consists of a **PHP class** (`Shopware\Core\Framework\Rule\Rule`, tag `shopware.rule.definition`) and an **Admin registration** (`ruleConditionDataProviderService.addCondition()`). There is no dedicated table: a `rule_condition` record (`type` = `RULE_NAME`, `value` = JSON of the properties) references the class.
- Mandatory in the class: `public const RULE_NAME` (otherwise `RuleException::ruleNameNotImplemented()`), `match(RuleScope): bool`, `getConstraints(): array`. `getConfig(): ?RuleConfig` is optional, but in 6.7 it is the standard way for the Admin UI: `sw-condition-generic` builds the form from it (endpoint `GET /api/_info/rule-config`). A custom Vue component is only needed for special cases.
- **Scopes**: `RuleScope` (abstract) -> `CheckoutRuleScope` (SalesChannelContext, customer) -> `CartRuleScope` (+ cart) -> `FlowRuleScope` (+ order). Additionally `LineItemScope` (extends `CheckoutRuleScope`). `match()` checks via `instanceof` and otherwise returns `false`.
- **Evaluation**: `CartRuleLoader::_load()` loads all rules, `RuleCollection::filterMatchingRules()` calls `Rule::match(new CartRuleScope($cart, $context))`, matches end up in the context via `SalesChannelContext::setRuleIds()` or `setAreaRuleIds()`. Consumers read `$context->getRuleIds()`, usually via `RuleIdMatcher`.
- Rules are serialized structs without services: **no DB access in `match()`**.
- The awareness configuration (`addAwarenessConfiguration`, restrictions on assignment) is separate from the assignment card (`associationEntitiesConfig`).

Directory tree (plugin-relative):

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Rule/CartHasMinGoodsRule.php
    └── Resources/
        ├── config/services.php
        └── app/administration/src/
            ├── main.js
            ├── decorator/rule-condition.decorator.js
            ├── module/sw-settings-rule/extension/sw-settings-rule-detail-assignments/index.js   # optional
            └── snippet/{de,en}.json
```

Optional with a custom Vue component: `src/component/swag-example-condition/{index.js,swag-example-condition.html.twig}`.

## 3. Instructions

1. Derive the **class** from `Rule` (flow rules: `FlowRule`). `RULE_NAME` with plugin prefix. Property name = key in `rule_condition.value` = key in `getConstraints()` = field name in `RuleConfig`.
2. **Constructor** with operator default and nullable values; `parent::__construct()` sets `_name`.
3. **`match()`**: check the scope via `instanceof`, missing values -> `false`. Comparisons with `RuleComparison::numeric/string/stringArray/uuids/datetime`.
4. **`getConstraints()`** with `RuleConstraints` (`int, float, string, stringArray, bool, uuids, date, datetime, dateBetween, choice, numericOperators, stringOperators, uuidOperators, dateOperators, datetimeOperators`). Checked on save by `Content/Rule/RuleValidator` (`PreWriteValidationEvent`).
5. **`getConfig()`** with `RuleConfig`: `operatorSet(array $operators, bool $addEmptyOperator, bool $isMatchAny)`; fields `intField, numberField, stringField, booleanField, dateField, dateTimeField, selectField(name, options, multi), entitySelectField(name, entity, multi, config), taggedField, field(name, type, config)`. Constants `RuleConfig::OPERATOR_SET_STRING|NUMBER|DEFAULT|DATE`. Errors in `getConfig()` are swallowed by the `RuleConfigController` (`catch \Throwable -> continue`).
6. Register the **service** with tag `shopware.rule.definition` (populates `RuleConditionRegistry`). An unknown rule becomes `UnknownConditionRule` or `InvalidConditionException`.
7. **Admin**: `addCondition(type, {component, label, scopes, group})`. `scopes` (constant `SCOPES` in `condition-type-data-provider.decorator.ts`): `global, cart, checkout, lineItem, flow`. `group`: `general, cart, item, customer, promotion, order, misc`. Additional groups: `upsertGroup(name, {id, name})`; module types: `addModuleType`.
8. **Build and activate**:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/console cache:clear
   bin/build-administration.sh        # see T-49
   ```
9. **Create a rule** in the Admin: Settings -> Shop -> Rule Builder. If the plugin is uninstalled, rules with an unknown type become invalid (`rule.invalid`, `RulePayloadUpdater`/`RuleIndexer`).
10. **Custom Vue component** (only for special needs): `Shopware.Component.extend('swag-example-condition', 'sw-condition-base', {...})`, the template overrides block `sw_condition_value_content`; write values with `this.ensureValueExist()` and `this.condition.value = {...this.condition.value, key}` (model: `app/component/rule/condition-type/sw-condition-goods-count/index.js`). Registration: `component: 'swag-example-condition'` in `addCondition`.
11. **Evaluation in code**: `$salesChannelContext->getRuleIds()`; filter `RuleIdMatcher::filter()/filterCollection()` for entities with `IdAware & RuleIdAware` (`getAvailabilityRuleId()`, `null` = always available), model `PaymentMethodRoute`. Per area: `getRuleIdsByAreas([RuleAreas::PAYMENT_AREA])`.
12. **Awareness**: `ruleConditionService.addAwarenessConfiguration('myAssociation', {notEquals: [...], equalsAny: [...], snippet: '...'})`; core examples in `AWARENESS_CONFIGURATIONS` (e.g. `paymentMethods`, `shippingMethods`). In the select `sw-select-rule-create` with prop `rule-aware-group-key`. Restrictions only take effect on the UI side.

## 4. Examples

`src/Rule/CartHasMinGoodsRule.php` (modeled on `GoodsCountRule`, without filter container)

```php
<?php declare(strict_types=1);

namespace SwagExample\Rule;

use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

class CartHasMinGoodsRule extends Rule
{
    final public const RULE_NAME = 'swagExampleMinGoods';

    public function __construct(
        protected string $operator = self::OPERATOR_GTE,
        protected ?int $count = null
    ) {
        parent::__construct();
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CartRuleScope || $this->count === null) {
            return false;
        }

        $goods = $scope->getCart()->getLineItems()->filterGoodsFlat();

        return RuleComparison::numeric((float) \count($goods), (float) $this->count, $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'count' => RuleConstraints::int(),
            'operator' => RuleConstraints::numericOperators(false),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_NUMBER)
            ->intField('count');
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Rule\CartHasMinGoodsRule;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(CartHasMinGoodsRule::class)
        ->tag('shopware.rule.definition');
};
```

`src/Resources/app/administration/src/main.js`

```js
import './decorator/rule-condition.decorator';
// No snippet import needed: the server-side SnippetFinder loads snippet/de.json and snippet/en.json at runtime.
```

(Snippet registration in detail: [T-18](./18-snippets-translations.md).)

`src/Resources/app/administration/src/decorator/rule-condition.decorator.js`

```js
Shopware.Application.addServiceProviderDecorator('ruleConditionDataProviderService', (ruleConditionService) => {
    ruleConditionService.addCondition('swagExampleMinGoods', {
        component: 'sw-condition-generic',
        label: 'swag-example.condition.minGoods',
        scopes: ['cart'],
        group: 'cart',
    });

    return ruleConditionService;
});
```

`type` (here `swagExampleMinGoods`) must match `RULE_NAME`.

`src/Resources/app/administration/src/snippet/en.json`

```json
{
    "swag-example": {
        "condition": { "minGoods": "Minimum goods count" }
    },
    "global": {
        "sw-condition-generic": {
            "swagExampleMinGoods": {
                "count": { "placeholder": "Enter count..." }
            }
        }
    }
}
```

Path according to `generic-condition.mixin`: `global.sw-condition-generic.<type>.<fieldname>.placeholder` and `....options.<value>` (select fields).

Rule assignment card: `src/Resources/app/administration/src/module/sw-settings-rule/extension/sw-settings-rule-detail-assignments/index.js`

```js
Shopware.Component.override('sw-settings-rule-detail-assignments', {
    computed: {
        associationEntitiesConfig() {
            const config = this.$super('associationEntitiesConfig');
            config.push({
                id: 'swag_example_entity',
                notAssignedDataTotal: 0,
                entityName: 'swag_example_entity',
                label: 'swag-example.assignments.myEntity',
                criteria: () => {
                    const criteria = new Shopware.Data.Criteria(1, this.associationLimit);
                    criteria.addFilter(Shopware.Data.Criteria.equals('availabilityRuleId', this.rule.id));
                    return criteria;
                },
                api: () => ({ ...Shopware.Context.api }),
                detailRoute: 'swag.example.detail',
                gridColumns: [{ property: 'name', label: 'global.entities.swag_example_entity', rawData: true, sortable: true, allowEdit: false }],
                deleteContext: { type: 'one-to-many', entity: 'swag_example_entity', column: 'availabilityRuleId' },
            });
            return config;
        },
    },
});
```

Structure of the entries: core service `module/sw-settings-rule/service/rule-assignment-configuration.service.js` (`id, associationName, notAssignedDataTotal, allowAdd, entityName, label, criteria, api, detailRoute, gridColumns, addContext{...}`). The card only appears if the data model has an association `rule -> swag_example_entity` ([T-29](./29-entity-extensions-custom-fields.md)). Don't forget to import the extension in `main.js`.

More scaffolds: [../templates/plugin/README.md](../templates/plugin/README.md), [../templates/entity/README.md](../templates/entity/README.md).

## 5. Pitfalls

- `RULE_NAME` forgotten: exception on instantiation. `RULE_NAME` != Admin `type`: the UI shows `sw-condition-not-found`.
- Tag `shopware.rule.definition` missing: the rule is neither in the registry nor in the config endpoint; saving throws `InvalidConditionException`.
- Keys in `getConstraints()` / `getConfig()` / property not identical: validation errors or empty fields.
- Scope not checked: `match()` can receive `CartRuleScope`, `LineItemScope`, `FlowRuleScope` or a plain `CheckoutRuleScope` (e.g. from container rules like `FilterRule`). Without `instanceof`, fatal error.
- Wrong `scopes` entry in `addCondition`: the condition appears in the wrong context. Flow-only rules: only `['flow']`.
- DB/service access in `match()`: it is evaluated on every cart calculation up to `MAX_ITERATION` times.
- Overriding core rules instead of creating new ones (core rules are `final`/`@internal`).
- `getConfig()` returns no `RuleConfig`: `sw-condition-generic` reads the config from the Pinia store `ruleConditionsConfig`, the form stays empty.
- Admin not rebuilt / cache not cleared: rule/component missing.
- Awareness configuration forgotten: a rule with order/cart conditions can be assigned to a payment method without the UI warning.

### Deprecations and version notes (6.5/6.6 -> 6.7)

- 6.5.0.0: rule awareness (`addAwarenessConfiguration`, `sw-select-rule-create`) introduced (per the docs).
- 6.7: the standard is `sw-condition-generic` plus `RuleConfig`; the registry is a typed list `CONDITIONS` in `condition-type-data-provider.decorator.ts` (TypeScript, `SCOPES`, `GROUPS`, `COMPONENTS`). New: `removedInFeature`/`replacement` for deprecated conditions, flow-only detection.
- Pinia store `ruleConditionsConfig` instead of Vuex; older Vuex examples are outdated.
- `ShippingMethodCollection::filterByActiveRules()` is `@deprecated tag:v6.8.0` (-> `RuleIdMatcher`).
- `RuleAreas::CATEGORY_AREA` and `LANDING_PAGE_AREA` are `@deprecated tag:v6.8.0` (unused).
- In 6.7, `SalesChannelRule` excludes comparison/"Agentic Commerce" sales channel types from the selection.

## 6. Checklist

- [ ] `RULE_NAME` unique with plugin prefix and identical to the Admin `type`
- [ ] `match()` checks the scope via `instanceof`, no DB access
- [ ] `getConstraints()` and `getConfig()` cover every property
- [ ] Service registered with tag `shopware.rule.definition`
- [ ] `addCondition` with appropriate `scopes` and `group`
- [ ] Label and placeholder snippets (de-DE and en-GB) present
- [ ] Administration built, cache cleared, rule created and tested in the Rule Builder
- [ ] When assigning to entities: awareness configuration checked
- [ ] Behavior on uninstall (invalid rules) considered

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/rule/add-custom-rules.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/advanced-configuration/add-rule-assignment-configuration.html

Source code (`core/` or `administration/Resources/app/administration/src/`):
- `Framework/Rule/{Rule,RuleScope,FlowRule,RuleConfig,RuleConstraints,RuleComparison,RuleIdMatcher}.php`, `Framework/Rule/Collector/RuleConditionRegistry.php`, `Framework/Rule/Api/RuleConfigController.php`, `Framework/DependencyInjection/rule.php`
- `Checkout/CheckoutRuleScope.php`, `Checkout/Cart/Rule/{CartRuleScope,LineItemScope,GoodsCountRule}.php`, `Content/Flow/Rule/FlowRuleScope.php`
- `Checkout/Cart/CartRuleLoader.php`, `Content/Rule/{RuleCollection,RuleDefinition,RuleValidator}.php`, `Framework/DataAbstractionLayer/Field/Flag/RuleAreas.php`
- `Framework/Context.php`, `System/SalesChannel/SalesChannelContext.php`, `Checkout/Payment/SalesChannel/PaymentMethodRoute.php`
- Admin: `app/service/rule-condition.service.ts`, `app/decorator/condition-type-data-provider.decorator.ts`, `app/mixin/generic-condition.mixin.ts`, `app/component/rule/condition-type/sw-condition-generic/`, `module/sw-settings-rule/`

### Gaps and uncertainties

- ⚠ UNVERIFIED: The PHP/JS examples were not built in a running installation, only checked against the source code.
- ⚠ UNVERIFIED: Docs page `context-rules-rule-systems.html` not retrieved; whether it exists in 6.7 is open. Context/RuleIds content comes from the code.
- ⚠ GAP: The docs name deriving from `sw-condition-base` as the standard, the 6.7 core uses `sw-condition-generic` + `getConfig()`. Both ways should work; custom component not executed, props/injects of `sw-condition-base` not read completely.
- Snippet registration verified against the source code: `administration/Snippet/SnippetFinder::addPluginPaths` searches `<plugin>/Resources/app/administration/src` of every active plugin recursively for `administration.json`, `<lang>.json` (e.g. `de.json`) and `<locale>.json` (e.g. `de-DE.json`) and delivers them via `/api/_admin/snippets`; a JS import of the JSON files is not required (see T-18).
- ⚠ UNVERIFIED: Behavior on plugin uninstall (invalidation of rules) and the command `bin/console dal:refresh:index --only=rule.indexer` not verified (indexer name unverified).
- ⚠ UNVERIFIED: `sw-select-rule-create`/`rule-aware-group-key` only confirmed from the docs and a file find.
