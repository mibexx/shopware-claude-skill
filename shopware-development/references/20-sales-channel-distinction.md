# Sales channel distinction (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when plugin code should react differently per sales channel, domain or type (storefront, headless): access to the `SalesChannelContext`, branching by ID/type/domain, theme assignment, filtered data via the `SalesChannelRepository`.

Scope: mechanics of configuration per sales channel (`SystemConfigService`, inheritance): [T-19 Plugin configuration](./19-plugin-configuration.md). Rule-based conditions: [T-32 Rule Builder](./32-rule-builder.md). Twig blocks: [T-14](./14-storefront-twig-templates.md). Theme assignment (basics): [T-11](./11-theme-basics.md). Store API routes: [T-30](./30-store-api-routes.md).

## 2. Concepts

- **`SalesChannelContext`** (`core/System/SalesChannel/SalesChannelContext.php`): request context of a storefront/Store API request. Encapsulates the DAL `Context` (language, currency, rules), `SalesChannelEntity`, currency, customer group, taxes, payment/shipping method, `ShippingLocation`, optionally `CustomerEntity`, rounding, `LanguageInfo`, measurement system, domain ID.
- **Access:**
  - Controller: parameter `SalesChannelContext $context` (`SalesChannelContextValueResolver` from the request attribute `sw-sales-channel-context`).
  - Subscriber: events with `getSalesChannelContext()` (`ShopwareSalesChannelEvent`), e.g. page loaded events, `SalesChannelContextResolvedEvent`, `SalesChannelProcessCriteriaEvent`.
  - Twig (storefront): global variable `context` (`TemplateDataExtension`), i.e. `context.salesChannel`, `context.currency`, `context.customer`.
- **Branching:** `$context->getSalesChannelId()`, per domain `getDomainId()` (nullable), per type `getSalesChannel()->getTypeId()`, per customer group/condition rules (`getRuleIds()`).
- **Domain resolution:** `Storefront\Framework\Routing\RequestTransformer::findSalesChannel()` matches the URL against all domains (direct hit, otherwise longest base URL) and sets the attributes `sw-sales-channel-id`, `sw-domain-id`, `_locale`, `sw-snippet-set-id`, `sw-currency-id`, `theme-id`, `theme-name`, `theme-base-name` (constants in `core/SalesChannelRequest.php`).
- **Types** (`core/Defaults.php`): `SALES_CHANNEL_TYPE_STOREFRONT`, `SALES_CHANNEL_TYPE_API` (headless), `SALES_CHANNEL_TYPE_PRODUCT_COMPARISON` and in 6.7.15.1 additionally `SALES_CHANNEL_TYPE_AGENTIC_COMMERCE`. All four are protected against deletion (`SalesChannelTypeValidator`). The assumption of a "fixed set storefront/API" is outdated.
- **Theme assignment:** table `theme_sales_channel`; `ThemeService::assignTheme()` or `bin/console theme:change`. `DatabaseSalesChannelThemeLoader::load()` resolves exactly one assigned theme plus parent themes.
- **Filtered data:** `SalesChannelRepository` (service ID `sales_channel.<entity>.repository`) calls `SalesChannelDefinitionInterface::processCriteria()` before every search (e.g. `ProductAvailableFilter`) and fires `sales_channel.<entity>.process.criteria`. The normal `EntityRepository` does not.

Directory tree:

```
custom/plugins/SwagExample/
└── src/
    ├── Resources/config/services.php
    ├── Resources/config/routes.php
    ├── Resources/views/storefront/component/channel-hint.html.twig
    ├── Subscriber/ChannelSubscriber.php
    ├── Storefront/Controller/ChannelController.php
    └── Service/ChannelDetector.php
```

Decision guide: behavior permanently configurable per channel -> configuration (T-19) instead of an ID in code; by customer group/cart/time -> Rule Builder (T-32); only type (storefront vs. headless) -> `typeId`.

## 3. Instructions

1. View channels: `bin/console sales-channel:list`.
2. Create a storefront channel: `bin/console sales-channel:create:storefront --name="Shop B" --url="https://b.example.com" …` (further options among others `--typeId`, `--languageId`, `--currencyId`, `--customerGroupId`, `--navigationCategoryId`; check `--help` first). Headless: `sales-channel:create`.
3. Change the domain URL: `sales-channel:update:domain`, `sales-channel:replace:url`.
4. Assign a theme: `bin/console theme:change <technical-theme-name> --sales-channel=<ID>` (options `--all`, `--no-compile`, `--sync`) or in the Admin on the sales channel under "Theme". Programmatically: `ThemeService::assignTheme(string $themeId, string $salesChannelId, Context $context, bool $skipCompile = false): bool`.
5. Get the context (controller parameter or event) and branch by `getSalesChannelId()` / `getDomainId()`; configuration via `SystemConfigService` with channel ID (T-19).
6. For data visible only in the channel: `SalesChannelRepository` instead of `EntityRepository`.
7. Rule-based instead of hard-wired: `SalesChannelRule` (`core/Framework/Rule/SalesChannelRule.php`) matches `salesChannelId`; rule IDs of the context via `$context->getRuleIds()`.

## 4. Examples

`src/Service/ChannelDetector.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Defaults;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ChannelDetector
{
    public function isStorefront(SalesChannelContext $context): bool
    {
        return $context->getSalesChannel()->getTypeId() === Defaults::SALES_CHANNEL_TYPE_STOREFRONT;
    }

    public function isHeadless(SalesChannelContext $context): bool
    {
        return $context->getSalesChannel()->getTypeId() === Defaults::SALES_CHANNEL_TYPE_API;
    }
}
```

`src/Storefront/Controller/ChannelController.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Storefront\Controller;

use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class ChannelController extends StorefrontController
{
    #[Route(path: '/swag/channel-info', name: 'frontend.swag.channel-info', methods: ['GET'])]
    public function info(SalesChannelContext $context): JsonResponse
    {
        return new JsonResponse([
            'salesChannelId' => $context->getSalesChannelId(),
            'domainId' => $context->getDomainId(),
            'currency' => $context->getCurrency()->getIsoCode(),
            'customerGroupId' => $context->getCustomerGroupId(),
            'languageId' => $context->getLanguageId(),
            'customer' => $context->getCustomerId(),
            'ruleIds' => $context->getRuleIds(),
        ]);
    }
}
```

`src/Subscriber/ChannelSubscriber.php` (behavior via channel configuration; criteria hook)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Event\SalesChannelProcessCriteriaEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly SystemConfigService $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageLoadedEvent::class => 'onProductPage',
            'sales_channel.product.process.criteria' => 'onProductCriteria',
        ];
    }

    public function onProductPage(ProductPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        if (!$this->config->getBool('SwagExample.config.hint', $context->getSalesChannelId())) {
            return;
        }
        $event->getPage()->addExtension('swagChannelHint', new ArrayStruct([
            'domainId' => $context->getDomainId(),
        ]));
    }

    public function onProductCriteria(SalesChannelProcessCriteriaEvent $event): void
    {
        // Context: $event->getSalesChannelContext(); example hook only
        $event->getCriteria()->addAssociation('manufacturer');
    }
}
```

`src/Resources/config/services.php` (`services.xml` is deprecated in 6.7, see [T-05](./05-registering-services.md))
```php
<?php declare(strict_types=1);

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagExample\Service\ChannelDetector;
use SwagExample\Storefront\Controller\ChannelController;
use SwagExample\Subscriber\ChannelSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(ChannelDetector::class);

    $services->set(ChannelSubscriber::class)
        ->args([service(SystemConfigService::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ChannelController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);
};
```

`src/Resources/config/routes.php` (without this file the controller is not loaded as a route and returns 404; `Bundle::configureRoutes()` imports `Resources/config/routes.php` via glob, pattern like `core/Framework/Resources/config/routes.php`)
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Storefront/Controller/**/*Controller.php', 'attribute');
};
```

`src/Resources/views/storefront/component/channel-hint.html.twig` (inclusion in a block: T-14)
```twig
{% if context.salesChannel.typeId == constant('Shopware\\Core\\Defaults::SALES_CHANNEL_TYPE_STOREFRONT') %}
    <p>Channel: {{ context.salesChannel.translated.name }} ({{ context.currency.isoCode }})</p>
{% endif %}
```

Custom sales channel repository (definition extends the base definition, tag `shopware.sales_channel.entity.definition`):
```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelDefinitionInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SalesChannelExampleDefinition extends ExampleDefinition implements SalesChannelDefinitionInterface
{
    public function processCriteria(Criteria $criteria, SalesChannelContext $context): void
    {
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('salesChannelId', $context->getSalesChannelId()));
    }
}
```
```php
// services.php
$services->set(\SwagExample\Core\Content\Example\SalesChannelExampleDefinition::class)
    ->tag('shopware.sales_channel.entity.definition');
```
The repository `sales_channel.<entity_name>.repository` is created automatically by the `SalesChannelEntityCompilerPass`:
```php
/** @var SalesChannelRepository<ExampleCollection> $repo */
$result = $repo->search(new Criteria(), $salesChannelContext);
```
Entity basics: [T-27](./27-dal-entities.md), [T-28](./28-dal-reading-writing.md).

## 5. Pitfalls

- `EntityRepository` instead of `SalesChannelRepository` in storefront code: no visibility/active filters.
- Hard-coding sales channel IDs; better configuration (T-19) or a rule.
- Domain with sub-path instead of subdomain: according to the docs, session conflicts between channels; in the code the longest base URL wins.
- `getDomainId()` is `?string` (e.g. Store API without domain context); `getCustomer()` is nullable.
- Never build the context yourself (constructor `@internal`), `setRuleIds`/`lockRules` are reserved for core processes; use `SalesChannelContextService`/factory.
- Custom entity without tag `shopware.sales_channel.entity.definition`: no SC repository.
- Assuming there are only storefront and API: outdated (product comparison, Agentic Commerce).

### Deprecations and version notes (6.5/6.6 → 6.7)

- New type `SALES_CHANNEL_TYPE_AGENTIC_COMMERCE` in 6.7.15.1 (introduction minor not confirmed).
- `SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE_IP_WHITLELIST` and DAL field `maintenanceIpWhitelist` deprecated (since 6.7.13.0, removal v6.8.0) -> `…_ALLOWLIST` / `maintenanceIpAllowlist`.
- New fields: `measurementUnits` (since 6.7.1.0), `businessTimeZone` (since 6.7.13.0); domain: `isExternalStorefront`, `measurementUnits`.
- `SalesChannelContext`: `getMeasurementSystem()`, `getCartLock()`, `getLanguageInfo()`. Twig globals `controllerName`/`controllerAction` deprecated (v6.8.0) -> `activeRoute`.
- Internally, besides `$permissionsLocked`, the property `$permisionsLocked` (typo) exists; `@deprecated tag:v6.8.0`, removed without replacement. Only use `$permissionsLocked`/`isPermissionsLocked()` (`SalesChannelContext.php`).
- `services.xml` and `routes.xml` in plugins are deprecated (removal v6.8.0.0, `Bundle.php`) -> `services.php`/`routes.php`.

## 6. Checklist

- [ ] Context obtained via controller parameter or event getter, not created yourself.
- [ ] Branching by type/ID/domain instead of hard-coding; channel-specific behavior via configuration or rules.
- [ ] `getDomainId()` and `getCustomer()` handled for `null`.
- [ ] `SalesChannelRepository` used for storefront data.
- [ ] Custom SC definition registered with tag `shopware.sales_channel.entity.definition`.
- [ ] Config access with `$context->getSalesChannelId()` (see T-19).
- [ ] Theme assignment checked via `theme:change`; type assumptions consider all four types.
- [ ] Multiple channels tested (subdomains instead of sub-paths).

## 7. Sources

- https://developer.shopware.com/docs/concepts/commerce/catalog/sales-channels.html
- https://developer.shopware.com/docs/guides/plugins/plugins/architecture/context-rules-rule-systems.html (does not cover `SalesChannelContext`)
- Code: `core/System/SalesChannel/SalesChannelContext.php`, `Context/SalesChannelContextValueResolver`, `SalesChannelContextService`, `Entity/SalesChannelRepository` (`processCriteria`), `SalesChannelDefinitionInterface`, `SalesChannelDefinitionInstanceRegistry`; `core/System/DependencyInjection/CompilerPass/SalesChannelEntityCompilerPass.php`; `core/Content/Product/SalesChannel/SalesChannelProductDefinition.php`.
- `core/PlatformRequest.php`, `core/SalesChannelRequest.php`, `core/Defaults.php`, `core/Framework/Routing/SalesChannelRequestContextResolver.php`, `core/Framework/Rule/SalesChannelRule.php`, `core/System/SalesChannel/Subscriber/SalesChannelTypeValidator.php`.
- `storefront/Framework/Routing/RequestTransformer.php` (`findSalesChannel`), `storefront/Framework/Twig/TemplateDataExtension.php`, `storefront/Theme/ThemeSalesChannel`, `DatabaseSalesChannelThemeLoader`, `ThemeService::assignTheme`, `Command/ThemeChangeCommand`.
- CLI: `core/Maintenance/SalesChannel/Command/*`, `storefront/Framework/Command/SalesChannelCreateStorefrontCommand.php`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: Example code not executed, only source code read; `routes.php` import checked against `Bundle::configureRoutes()` and the core `routes.php`.
- ⚠ UNVERIFIED: The getter `SalesChannelEntity::getTypeId()` exists (not individually verified during research; a 6.7.15.1 sample confirms `getTypeId()` and `getType()`); Twig access `context.salesChannel.translated.name` assumed according to the usual pattern.
- ⚠ GAP: Display names of the types in the Admin (product comparison) and description of "Agentic Commerce" not documented/checked.
- ⚠ GAP: CLI options of `sales-channel:create:storefront` only partially listed; the docs page on rules does not cover the context.
- ⚠ UNVERIFIED: Multiple themes per channel: the loader resolves exactly one theme plus ancestors.
- ⚠ UNVERIFIED: 6.5/6.6 differences cannot be confirmed (only 6.7.15.1 installed).
