# Sales-Channel-Unterscheidung (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn Plugin-Code je Sales-Channel, Domain oder Typ (Storefront, Headless) unterschiedlich reagieren soll: Zugriff auf den `SalesChannelContext`, Verzweigung nach ID/Typ/Domain, Theme-Zuordnung, gefilterte Daten über das `SalesChannelRepository`.

Abgrenzung: Mechanik der Konfiguration pro Sales-Channel (`SystemConfigService`, Vererbung): [T-19 Plugin-Konfiguration](./19-plugin-konfiguration.md). Regelbasierte Bedingungen: [T-32 Rule Builder](./32-rule-builder.md). Twig-Blöcke: [T-14](./14-storefront-twig-templates.md). Theme-Zuordnung (Grundlagen): [T-11](./11-theme-grundlagen.md). Store-API-Routen: [T-30](./30-store-api-routen.md).

## 2. Konzepte

- **`SalesChannelContext`** (`core/System/SalesChannel/SalesChannelContext.php`): Request-Kontext einer Storefront-/Store-API-Anfrage. Kapselt DAL-`Context` (Sprache, Währung, Regeln), `SalesChannelEntity`, Währung, Kundengruppe, Steuern, Zahlungs-/Versandart, `ShippingLocation`, optional `CustomerEntity`, Rundung, `LanguageInfo`, Messsystem, Domain-ID.
- **Zugriff:**
  - Controller: Parameter `SalesChannelContext $context` (`SalesChannelContextValueResolver` aus Request-Attribut `sw-sales-channel-context`).
  - Subscriber: Events mit `getSalesChannelContext()` (`ShopwareSalesChannelEvent`), z. B. Page-Loaded-Events, `SalesChannelContextResolvedEvent`, `SalesChannelProcessCriteriaEvent`.
  - Twig (Storefront): globale Variable `context` (`TemplateDataExtension`), also `context.salesChannel`, `context.currency`, `context.customer`.
- **Verzweigen:** `$context->getSalesChannelId()`, pro Domain `getDomainId()` (nullable), pro Typ `getSalesChannel()->getTypeId()`, pro Kundengruppe/Bedingung Regeln (`getRuleIds()`).
- **Domain-Auflösung:** `Storefront\Framework\Routing\RequestTransformer::findSalesChannel()` matcht die URL gegen alle Domains (Direkttreffer, sonst längste Basis-URL) und setzt Attribute `sw-sales-channel-id`, `sw-domain-id`, `_locale`, `sw-snippet-set-id`, `sw-currency-id`, `theme-id`, `theme-name`, `theme-base-name` (Konstanten in `core/SalesChannelRequest.php`).
- **Typen** (`core/Defaults.php`): `SALES_CHANNEL_TYPE_STOREFRONT`, `SALES_CHANNEL_TYPE_API` (Headless), `SALES_CHANNEL_TYPE_PRODUCT_COMPARISON` und in 6.7.15.1 zusätzlich `SALES_CHANNEL_TYPE_AGENTIC_COMMERCE`. Alle vier sind gegen Löschen geschützt (`SalesChannelTypeValidator`). Annahme „fester Satz Storefront/API" ist veraltet.
- **Theme-Zuordnung:** Tabelle `theme_sales_channel`; `ThemeService::assignTheme()` bzw. `bin/console theme:change`. `DatabaseSalesChannelThemeLoader::load()` löst genau ein zugewiesenes Theme plus Eltern-Themes auf.
- **Gefilterte Daten:** `SalesChannelRepository` (Service-ID `sales_channel.<entity>.repository`) ruft vor jeder Suche `SalesChannelDefinitionInterface::processCriteria()` auf (z. B. `ProductAvailableFilter`) und feuert `sales_channel.<entity>.process.criteria`. Das normale `EntityRepository` tut das nicht.

Verzeichnisbaum:

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

Entscheidungshilfe: Verhalten dauerhaft pro Channel einstellbar -> Konfiguration (T-19) statt ID im Code; nach Kundengruppe/Warenkorb/Zeit -> Rule Builder (T-32); nur Typ (Storefront vs. Headless) -> `typeId`.

## 3. Anleitung

1. Channels ansehen: `bin/console sales-channel:list`.
2. Storefront-Channel anlegen: `bin/console sales-channel:create:storefront --name="Shop B" --url="https://b.example.com" …` (weitere Optionen u. a. `--typeId`, `--languageId`, `--currencyId`, `--customerGroupId`, `--navigationCategoryId`; vorher `--help` prüfen). Headless: `sales-channel:create`.
3. Domain-URL ändern: `sales-channel:update:domain`, `sales-channel:replace:url`.
4. Theme zuordnen: `bin/console theme:change <technischer-Themename> --sales-channel=<ID>` (Optionen `--all`, `--no-compile`, `--sync`) oder im Admin am Sales-Channel unter „Theme". Programmatisch: `ThemeService::assignTheme(string $themeId, string $salesChannelId, Context $context, bool $skipCompile = false): bool`.
5. Kontext holen (Controller-Parameter oder Event) und nach `getSalesChannelId()` / `getDomainId()` verzweigen; Konfiguration über `SystemConfigService` mit Channel-ID (T-19).
6. Für nur im Channel sichtbare Daten: `SalesChannelRepository` statt `EntityRepository`.
7. Regelbasiert statt hart verdrahtet: `SalesChannelRule` (`core/Framework/Rule/SalesChannelRule.php`) matcht `salesChannelId`; Regel-IDs des Kontexts über `$context->getRuleIds()`.

## 4. Beispiele

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

`src/Subscriber/ChannelSubscriber.php` (Verhalten per Channel-Konfiguration; Criteria-Hook)
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
        // Kontext: $event->getSalesChannelContext(); hier nur Beispiel-Hook
        $event->getCriteria()->addAssociation('manufacturer');
    }
}
```

`src/Resources/config/services.php` (`services.xml` ist in 6.7 deprecated, siehe [T-05](./05-services-registrieren.md))
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

`src/Resources/config/routes.php` (ohne diese Datei wird der Controller nicht als Route geladen und liefert 404; `Bundle::configureRoutes()` importiert `Resources/config/routes.php` per Glob, Muster wie `core/Framework/Resources/config/routes.php`)
```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Storefront/Controller/**/*Controller.php', 'attribute');
};
```

`src/Resources/views/storefront/component/channel-hint.html.twig` (Einbindung in einen Block: T-14)
```twig
{% if context.salesChannel.typeId == constant('Shopware\\Core\\Defaults::SALES_CHANNEL_TYPE_STOREFRONT') %}
    <p>Channel: {{ context.salesChannel.translated.name }} ({{ context.currency.isoCode }})</p>
{% endif %}
```

Eigenes Sales-Channel-Repository (Definition erbt von der Basis-Definition, Tag `shopware.sales_channel.entity.definition`):
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
Das Repository `sales_channel.<entity_name>.repository` legt der `SalesChannelEntityCompilerPass` automatisch an:
```php
/** @var SalesChannelRepository<ExampleCollection> $repo */
$result = $repo->search(new Criteria(), $salesChannelContext);
```
Entity-Grundlagen: [T-27](./27-dal-entities.md), [T-28](./28-dal-lesen-schreiben.md).

## 5. Fallstricke

- `EntityRepository` statt `SalesChannelRepository` in Storefront-Code: keine Sichtbarkeits-/Aktiv-Filter.
- Sales-Channel-IDs hartkodieren; besser Konfiguration (T-19) oder Regel.
- Domain mit Sub-Pfad statt Subdomain: laut Doku Session-Konflikte zwischen Channels; im Code gewinnt die längste Basis-URL.
- `getDomainId()` ist `?string` (z. B. Store-API ohne Domain-Kontext); `getCustomer()` ist nullable.
- Kontext nie selbst bauen (Konstruktor `@internal`), `setRuleIds`/`lockRules` sind Kernprozessen vorbehalten; `SalesChannelContextService`/Factory verwenden.
- Eigene Entity ohne Tag `shopware.sales_channel.entity.definition`: kein SC-Repository.
- Annahme, es gebe nur Storefront und API: veraltet (Produktvergleich, Agentic Commerce).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Neuer Typ `SALES_CHANNEL_TYPE_AGENTIC_COMMERCE` in 6.7.15.1 (Einführungs-Minor nicht belegt).
- `SalesChannelRequest::ATTRIBUTE_SALES_CHANNEL_MAINTENANCE_IP_WHITLELIST` und DAL-Feld `maintenanceIpWhitelist` deprecated (seit 6.7.13.0, Entfernung v6.8.0) -> `…_ALLOWLIST` / `maintenanceIpAllowlist`.
- Neue Felder: `measurementUnits` (Since 6.7.1.0), `businessTimeZone` (Since 6.7.13.0); Domain: `isExternalStorefront`, `measurementUnits`.
- `SalesChannelContext`: `getMeasurementSystem()`, `getCartLock()`, `getLanguageInfo()`. Twig-Globals `controllerName`/`controllerAction` deprecated (v6.8.0) -> `activeRoute`.
- Intern existiert neben `$permissionsLocked` die Property `$permisionsLocked` (Tippfehler); `@deprecated tag:v6.8.0`, entfällt ohne Ersatz. Nur `$permissionsLocked`/`isPermissionsLocked()` verwenden (`SalesChannelContext.php`).
- `services.xml` und `routes.xml` in Plugins sind deprecated (Entfernung v6.8.0.0, `Bundle.php`) -> `services.php`/`routes.php`.

## 6. Checkliste

- [ ] Kontext per Controller-Parameter oder Event-Getter bezogen, nicht selbst erzeugt.
- [ ] Verzweigung nach Typ/ID/Domain statt Hardcoding; Channel-spezifisches über Konfiguration oder Regeln.
- [ ] `getDomainId()` und `getCustomer()` auf `null` behandelt.
- [ ] Für Storefront-Daten `SalesChannelRepository` verwendet.
- [ ] Eigene SC-Definition mit Tag `shopware.sales_channel.entity.definition` registriert.
- [ ] Config-Zugriff mit `$context->getSalesChannelId()` (siehe T-19).
- [ ] Theme-Zuordnung per `theme:change` geprüft; Typ-Annahmen berücksichtigen alle vier Typen.
- [ ] Mehrere Channels getestet (Subdomains statt Sub-Pfade).

## 7. Quellen

- https://developer.shopware.com/docs/concepts/commerce/catalog/sales-channels.html
- https://developer.shopware.com/docs/guides/plugins/plugins/architecture/context-rules-rule-systems.html (behandelt `SalesChannelContext` nicht)
- Code: `core/System/SalesChannel/SalesChannelContext.php`, `Context/SalesChannelContextValueResolver`, `SalesChannelContextService`, `Entity/SalesChannelRepository` (`processCriteria`), `SalesChannelDefinitionInterface`, `SalesChannelDefinitionInstanceRegistry`; `core/System/DependencyInjection/CompilerPass/SalesChannelEntityCompilerPass.php`; `core/Content/Product/SalesChannel/SalesChannelProductDefinition.php`.
- `core/PlatformRequest.php`, `core/SalesChannelRequest.php`, `core/Defaults.php`, `core/Framework/Routing/SalesChannelRequestContextResolver.php`, `core/Framework/Rule/SalesChannelRule.php`, `core/System/SalesChannel/Subscriber/SalesChannelTypeValidator.php`.
- `storefront/Framework/Routing/RequestTransformer.php` (`findSalesChannel`), `storefront/Framework/Twig/TemplateDataExtension.php`, `storefront/Theme/ThemeSalesChannel`, `DatabaseSalesChannelThemeLoader`, `ThemeService::assignTheme`, `Command/ThemeChangeCommand`.
- CLI: `core/Maintenance/SalesChannel/Command/*`, `storefront/Framework/Command/SalesChannelCreateStorefrontCommand.php`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispielcode nicht ausgeführt, nur Quellcode gelesen; `routes.php`-Import gegen `Bundle::configureRoutes()` und Core-`routes.php` geprüft.
- ⚠ UNGEPRÜFT: Getter `SalesChannelEntity::getTypeId()` existiert (in der Recherche nicht einzeln verifiziert; Stichprobe 6.7.15.1 bestätigt `getTypeId()` und `getType()`); Twig-Zugriff `context.salesChannel.translated.name` nach üblichem Muster angenommen.
- ⚠ LÜCKE: Anzeigenamen der Typen im Admin (Produktvergleich) und Beschreibung von „Agentic Commerce" nicht dokumentiert/geprüft.
- ⚠ LÜCKE: CLI-Optionen von `sales-channel:create:storefront` nur teilweise gelistet; Doku-Seite zu Rules behandelt den Kontext nicht.
- ⚠ UNGEPRÜFT: Mehrere Themes pro Channel: der Loader löst genau ein Theme plus Vorfahren auf.
- ⚠ UNGEPRÜFT: 6.5/6.6-Unterschiede nicht belegbar (nur 6.7.15.1 installiert).
