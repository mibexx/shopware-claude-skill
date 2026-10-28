# Rule Builder: eigene Regeln (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eine eigene Regel-Bedingung für den Rule Builder liefern soll (PHP-Klasse plus Admin-Registrierung), Regeln im Code auswerten will (`getRuleIds()`, `RuleIdMatcher`) oder eine Zuweisungs-Karte in der Regel-Detailseite ergänzt.

Abgrenzung: Cart-Processoren/-Validatoren in [T-36 Warenkorb](./36-warenkorb.md); Flow-Builder-Aktionen/-Trigger in [T-09 Flow Builder](./09-flow-builder.md); Entity-Erweiterung `rule -> my_entity` in [T-29](./29-entity-extensions-custom-fields.md); Admin-Snippets in [T-18](./18-snippets-uebersetzungen.md); Admin-Build in [T-49](./49-build-deployment.md).

## 2. Konzepte

- Eine Regel besteht aus **PHP-Klasse** (`Shopware\Core\Framework\Rule\Rule`, Tag `shopware.rule.definition`) und **Admin-Registrierung** (`ruleConditionDataProviderService.addCondition()`). Es gibt keine eigene Tabelle: ein Datensatz `rule_condition` (`type` = `RULE_NAME`, `value` = JSON der Properties) verweist auf die Klasse.
- Pflicht in der Klasse: `public const RULE_NAME` (sonst `RuleException::ruleNameNotImplemented()`), `match(RuleScope): bool`, `getConstraints(): array`. `getConfig(): ?RuleConfig` ist optional, in 6.7 aber der Standardweg für die Admin-UI: `sw-condition-generic` baut das Formular daraus (Endpunkt `GET /api/_info/rule-config`). Eine eigene Vue-Komponente braucht man nur für Sonderfälle.
- **Scopes**: `RuleScope` (abstrakt) -> `CheckoutRuleScope` (SalesChannelContext, Kunde) -> `CartRuleScope` (+ Cart) -> `FlowRuleScope` (+ Order). Daneben `LineItemScope` (extends `CheckoutRuleScope`). `match()` prüft per `instanceof` und liefert sonst `false`.
- **Auswertung**: `CartRuleLoader::_load()` lädt alle Regeln, `RuleCollection::filterMatchingRules()` ruft `Rule::match(new CartRuleScope($cart, $context))`, Treffer landen über `SalesChannelContext::setRuleIds()` bzw. `setAreaRuleIds()` im Context. Verbraucher lesen `$context->getRuleIds()`, meist über `RuleIdMatcher`.
- Regeln sind serialisierte Structs ohne Services: **kein DB-Zugriff in `match()`**.
- Awareness-Konfiguration (`addAwarenessConfiguration`, Restriktionen bei Zuweisung) ist getrennt von der Assignment-Karte (`associationEntitiesConfig`).

Verzeichnisbaum (Plugin-relativ):

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
            └── snippet/{de-DE,en-GB}.json
```

Optional bei eigener Vue-Komponente: `src/component/swag-example-condition/{index.js,swag-example-condition.html.twig}`.

## 3. Anleitung

1. **Klasse** von `Rule` ableiten (Flow-Regeln: `FlowRule`). `RULE_NAME` mit Plugin-Präfix. Property-Name = Schlüssel in `rule_condition.value` = Schlüssel in `getConstraints()` = Feldname in `RuleConfig`.
2. **Konstruktor** mit Operator-Default und nullable Werten; `parent::__construct()` setzt `_name`.
3. **`match()`**: Scope per `instanceof` prüfen, fehlende Werte -> `false`. Vergleiche mit `RuleComparison::numeric/string/stringArray/uuids/datetime`.
4. **`getConstraints()`** mit `RuleConstraints` (`int, float, string, stringArray, bool, uuids, date, datetime, dateBetween, choice, numericOperators, stringOperators, uuidOperators, dateOperators, datetimeOperators`). Geprüft beim Speichern durch `Content/Rule/RuleValidator` (`PreWriteValidationEvent`).
5. **`getConfig()`** mit `RuleConfig`: `operatorSet(array $operators, bool $addEmptyOperator, bool $isMatchAny)`; Felder `intField, numberField, stringField, booleanField, dateField, dateTimeField, selectField(name, options, multi), entitySelectField(name, entity, multi, config), taggedField, field(name, type, config)`. Konstanten `RuleConfig::OPERATOR_SET_STRING|NUMBER|DEFAULT|DATE`. Fehler in `getConfig()` verschluckt der `RuleConfigController` (`catch \Throwable -> continue`).
6. **Service** mit Tag `shopware.rule.definition` registrieren (befüllt `RuleConditionRegistry`). Unbekannte Regel wird zu `UnknownConditionRule` bzw. `InvalidConditionException`.
7. **Admin**: `addCondition(type, {component, label, scopes, group})`. `scopes` (Konstante `SCOPES` in `condition-type-data-provider.decorator.ts`): `global, cart, checkout, lineItem, flow`. `group`: `general, cart, item, customer, promotion, order, misc`. Weitere Gruppen: `upsertGroup(name, {id, name})`; Modul-Typen: `addModuleType`.
8. **Bauen und aktivieren**:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/console cache:clear
   bin/build-administration.sh        # siehe T-49
   ```
9. **Regel anlegen** in der Admin: Einstellungen -> Shop -> Regel-Builder. Wird das Plugin deinstalliert, werden Regeln mit unbekanntem Typ ungültig (`rule.invalid`, `RulePayloadUpdater`/`RuleIndexer`).
10. **Eigene Vue-Komponente** (nur bei Sonderbedarf): `Shopware.Component.extend('swag-example-condition', 'sw-condition-base', {...})`, Template überschreibt Block `sw_condition_value_content`; Werte mit `this.ensureValueExist()` und `this.condition.value = {...this.condition.value, key}` schreiben (Vorbild: `app/component/rule/condition-type/sw-condition-goods-count/index.js`). Registrierung: `component: 'swag-example-condition'` in `addCondition`.
11. **Auswertung im Code**: `$salesChannelContext->getRuleIds()`; Filter `RuleIdMatcher::filter()/filterCollection()` für Entities mit `IdAware & RuleIdAware` (`getAvailabilityRuleId()`, `null` = immer verfügbar), Vorbild `PaymentMethodRoute`. Bereichsweise: `getRuleIdsByAreas([RuleAreas::PAYMENT_AREA])`.
12. **Awareness**: `ruleConditionService.addAwarenessConfiguration('myAssociation', {notEquals: [...], equalsAny: [...], snippet: '...'})`; Core-Beispiele in `AWARENESS_CONFIGURATIONS` (z. B. `paymentMethods`, `shippingMethods`). Im Select `sw-select-rule-create` mit Prop `rule-aware-group-key`. Restriktionen wirken nur UI-seitig.

## 4. Beispiele

`src/Rule/CartHasMinGoodsRule.php` (orientiert an `GoodsCountRule`, ohne Filter-Container)

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
import './snippet/de.json';
import './snippet/en.json';
```

(Snippet-Registrierung im Detail: [T-18](./18-snippets-uebersetzungen.md).)

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

`type` (hier `swagExampleMinGoods`) muss `RULE_NAME` entsprechen.

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

Pfad laut `generic-condition.mixin`: `global.sw-condition-generic.<type>.<feldname>.placeholder` und `....options.<wert>` (Select-Felder).

Rule-Assignment-Karte: `src/Resources/app/administration/src/module/sw-settings-rule/extension/sw-settings-rule-detail-assignments/index.js`

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

Struktur der Einträge: Core-Service `module/sw-settings-rule/service/rule-assignment-configuration.service.js` (`id, associationName, notAssignedDataTotal, allowAdd, entityName, label, criteria, api, detailRoute, gridColumns, addContext{...}`). Die Karte erscheint nur, wenn das Datenmodell eine Assoziation `rule -> swag_example_entity` hat ([T-29](./29-entity-extensions-custom-fields.md)). Import der Extension in `main.js` nicht vergessen.

Weitere Gerüste: [../templates/plugin/README.md](../templates/plugin/README.md), [../templates/entity/README.md](../templates/entity/README.md).

## 5. Fallstricke

- `RULE_NAME` vergessen: Exception beim Instanziieren. `RULE_NAME` != Admin-`type`: UI zeigt `sw-condition-not-found`.
- Tag `shopware.rule.definition` fehlt: Regel weder in Registry noch im Config-Endpunkt; Speichern wirft `InvalidConditionException`.
- Schlüssel in `getConstraints()` / `getConfig()` / Property nicht identisch: Validierungsfehler bzw. leere Felder.
- Scope nicht geprüft: `match()` kann `CartRuleScope`, `LineItemScope`, `FlowRuleScope` oder reinen `CheckoutRuleScope` erhalten (z. B. aus Container-Regeln wie `FilterRule`). Ohne `instanceof` Fatal Error.
- Falscher `scopes`-Eintrag in `addCondition`: Bedingung erscheint im falschen Kontext. Flow-only-Regeln: nur `['flow']`.
- DB-/Service-Zugriffe in `match()`: wird bei jeder Cart-Berechnung bis zu `MAX_ITERATION` mal ausgewertet.
- Core-Regeln überschreiben statt neue anlegen (Core-Regeln sind `final`/`@internal`).
- `getConfig()` liefert kein `RuleConfig`: `sw-condition-generic` liest die Config aus dem Pinia-Store `ruleConditionsConfig`, das Formular bleibt leer.
- Admin nicht neu gebaut / Cache nicht geleert: Regel/Komponente fehlt.
- Awareness-Konfiguration vergessen: Regel mit Order-/Cart-Bedingungen lässt sich einer Zahlart zuweisen, ohne dass die UI warnt.

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- 6.5.0.0: Rule-Awareness (`addAwarenessConfiguration`, `sw-select-rule-create`) eingeführt (Doku-Angabe).
- 6.7: Standard ist `sw-condition-generic` plus `RuleConfig`; Verzeichnis als typisierte Liste `CONDITIONS` in `condition-type-data-provider.decorator.ts` (TypeScript, `SCOPES`, `GROUPS`, `COMPONENTS`). Neu: `removedInFeature`/`replacement` für deprecated Bedingungen, Flow-only-Erkennung.
- Pinia-Store `ruleConditionsConfig` statt Vuex; ältere Vuex-Beispiele sind veraltet.
- `ShippingMethodCollection::filterByActiveRules()` ist `@deprecated tag:v6.8.0` (-> `RuleIdMatcher`).
- `RuleAreas::CATEGORY_AREA` und `LANDING_PAGE_AREA` sind `@deprecated tag:v6.8.0` (unused).
- `SalesChannelRule` schließt in 6.7 Vergleichs-/"Agentic-Commerce"-Vertriebskanaltypen aus der Auswahl aus.

## 6. Checkliste

- [ ] `RULE_NAME` eindeutig mit Plugin-Präfix und identisch zum Admin-`type`
- [ ] `match()` prüft Scope per `instanceof`, kein DB-Zugriff
- [ ] `getConstraints()` und `getConfig()` decken jede Property ab
- [ ] Service mit Tag `shopware.rule.definition` registriert
- [ ] `addCondition` mit passenden `scopes` und `group`
- [ ] Labels und Placeholder-Snippets (de-DE und en-GB) vorhanden
- [ ] Administration gebaut, Cache geleert, Regel im Rule Builder angelegt und getestet
- [ ] Bei Zuweisung an Entities: Awareness-Konfiguration geprüft
- [ ] Verhalten bei Deinstallation (ungültige Regeln) bedacht

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/rule/add-custom-rules.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/advanced-configuration/add-rule-assignment-configuration.html

Quellcode (`core/` bzw. `administration/Resources/app/administration/src/`):
- `Framework/Rule/{Rule,RuleScope,FlowRule,RuleConfig,RuleConstraints,RuleComparison,RuleIdMatcher}.php`, `Framework/Rule/Collector/RuleConditionRegistry.php`, `Framework/Rule/Api/RuleConfigController.php`, `Framework/DependencyInjection/rule.php`
- `Checkout/CheckoutRuleScope.php`, `Checkout/Cart/Rule/{CartRuleScope,LineItemScope,GoodsCountRule}.php`, `Content/Flow/Rule/FlowRuleScope.php`
- `Checkout/Cart/CartRuleLoader.php`, `Content/Rule/{RuleCollection,RuleDefinition,RuleValidator}.php`, `Framework/DataAbstractionLayer/Field/Flag/RuleAreas.php`
- `Framework/Context.php`, `System/SalesChannel/SalesChannelContext.php`, `Checkout/Payment/SalesChannel/PaymentMethodRoute.php`
- Admin: `app/service/rule-condition.service.ts`, `app/decorator/condition-type-data-provider.decorator.ts`, `app/mixin/generic-condition.mixin.ts`, `app/component/rule/condition-type/sw-condition-generic/`, `module/sw-settings-rule/`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: PHP/JS-Beispiele wurden nicht in einer laufenden Installation gebaut, nur gegen den Quellcode geprüft.
- ⚠ UNGEPRÜFT: Doku-Seite `context-rules-rule-systems.html` nicht abgerufen; ob sie in 6.7 existiert, ist offen. Context/RuleIds-Inhalte stammen aus dem Code.
- ⚠ LÜCKE: Doku nennt die Ableitung von `sw-condition-base` als Standard, der 6.7-Core nutzt `sw-condition-generic` + `getConfig()`. Beide Wege sollten funktionieren; eigene Komponente nicht ausgeführt, Props/Injects von `sw-condition-base` nicht vollständig gelesen.
- ⚠ UNGEPRÜFT: Snippet-Registrierung der Admin-Snippets (siehe T-18) nicht verifiziert.
- ⚠ UNGEPRÜFT: Verhalten beim Plugin-Deinstallieren (Invalidierung von Regeln) und Befehl `bin/console dal:refresh:index --only=rule.indexer` nicht verifiziert (Indexer-Name ungeprüft).
- ⚠ UNGEPRÜFT: `sw-select-rule-create`/`rule-aware-group-key` nur aus Doku und Dateifund belegt.
