# Extension Points (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Extension Points sind typisierte Hook-Punkte, über die ein Plugin Kernlogik des Cores ersetzen (`pre`), nachbearbeiten (`post`) oder Fehler abfangen (`error`) kann, mit typisiertem Ergebnis. Diese Referenz greift, wenn ein Core-Extension-Point konsumiert oder ein eigener veröffentlicht werden soll.

Abgrenzung: reine Beobachtung/Anreicherung per Event ([T-07](./07-events-subscriber.md)); Ersetzen eines ganzen Services per Dekoration ([T-06](./06-service-dekoration.md)); Flow-Trigger/-Actions in [T-09](./09-flow-builder.md). Doku-Empfehlung: Extension Points für Kernlogik-Änderung, Events für Seiteneffekte (Mail, Logging, externe Systeme).

## 2. Konzepte

- Basisklasse `Shopware\Core\Framework\Extensions\Extension` (abstrakt, `StoppableEventInterface`): Eingaben als `public readonly`-Properties, dazu veränderbares `$result` und `$exception`. Core-Extension-Klassen sind `final`.
- Publisher: `ExtensionDispatcher::publish(string $name, Extension $extension, callable $function)` (`final readonly`, Konstruktor `@internal`, Service-ID = Klassenname). Die Standardlogik wird als Closure übergeben.
- Ablauf von `publish()` (am Code geprüft):
  1. dispatch `{NAME}.pre`
  2. wenn Propagation nicht gestoppt: `$extension->result = $function(...$extension->getParams())`; bei `\Throwable`: `exception` setzen, Propagation zurücksetzen, dispatch `{NAME}.error`; ist danach `result === null`, wird die Exception erneut geworfen, sonst verschluckt
  3. `resetPropagation()`, dispatch `{NAME}.post` (läuft **immer**, auch nach `stopPropagation()` in `pre`)
  4. Rückgabe `$extension->result()`
- `pre` + `result` setzen + `stopPropagation()` ersetzt die Core-Logik; `post` verändert das Ergebnis; `error` kann durch Setzen von `result` den Fehler abfangen.
- Eventnamen im Subscriber: `MyExtension::onPre()`, `::onPost()`, `::onError()` (lesen `static::NAME`) oder String `'<NAME>.pre'`.
- Exceptions aus `pre`-Listenern laufen nicht durch den `try` und gehen direkt nach oben.

Extension Points in 6.7.15.1 (alle `core/`; Außerhalb von `core/` gibt es keine):

| NAME | Klasse (unter `core/`) | Result | Parameter |
|---|---|---|---|
| `checkout.place-order` | `Checkout/Cart/Extension/CheckoutPlaceOrderExtension` | `OrderPlaceResult` | `cart`, `context`, `data` |
| `checkout.cart.rule-load` | `Checkout/Cart/Extension/CheckoutCartRuleLoaderExtension` | `RuleLoaderResult` | `salesChannelContext`, `originalCart`, `cartBehavior`, `new` (`protected readonly bool`, nicht öffentliche API, aber in `getParams()` und damit Pflichtparameter der Closure; `CartRuleLoader::_load(...)`) |
| `html-renderer` | `Checkout/Document/Extension/HtmlRendererExtension` | `string` | `document` |
| `pdf-renderer` | `Checkout/Document/Extension/PdfRendererExtension` | `string` | `document` |
| `cms-slots-data.collect` | `Content/Cms/Extension/CmsSlotsDataCollectExtension` | `array<string, CriteriaCollection>` | `slots`, `resolverContext` |
| `cms-slots-data.enrich` | `Content/Cms/Extension/CmsSlotsDataEnrichExtension` | `CmsSlotCollection` | `slots`, `criteriaList`, `identifierResult`, `criteriaResult`, `resolverContext` |
| `cms-slots-data.resolve` | `Content/Cms/Extension/CmsSlotsDataResolveExtension` | `CmsSlotCollection` | `slots`, `resolverContext` |
| `flow.executor` | `Content/Flow/Extension/FlowExecutorExtension` | `void` | `flow`, `event` |
| `remote_thumbnail_url.resolve` | `Content/Media/Extension/ResolveRemoteThumbnailUrlExtension` | `string\|null` | `mediaUrl`, `mediaPath` (deprecated), `width`, `height`, `pattern`, `mediaUpdatedAt` (deprecated), `mediaEntity` (`MediaEntity\|PartialEntity`) |
| `listing-loader.load-previews` | `Content/Product/Extension/LoadPreviewExtension` | `array` | `ids`, `context` |
| `product.listing.criteria` | `Content/Product/Extension/ProductListingCriteriaExtension` | `Criteria` | `criteria`, `context`, `categoryId` |
| `product.calculate-prices` | `Content/Product/Extension/ProductPriceCalculationExtension` | `void` | `products`, `context` |
| `listing-loader.resolve` | `Content/Product/Extension/ResolveListingExtension` | `EntitySearchResult<ProductCollection>` | `criteria`, `context` |
| `listing-loader.resolve-listing-ids` | `Content/Product/Extension/ResolveListingIdsExtension` | `IdSearchResult` | `criteria`, `context` |
| `sitemap.get-file` | `Content/Sitemap/Extension/SitemapFileExtension` | `Response` | `request`, `context`, `filePath` |
| `cache-hash.required` | `Framework/Adapter/Cache/Http/Extension/CacheHashRequiredExtension` | `bool` | `request`, `salesChannelContext`, `cart` |
| `cache-response.resolve-rule-areas` | `Framework/Adapter/Cache/Http/Extension/ResolveCacheRelevantRuleIdsExtension` | `array<string>` | `request`, `ruleAreas`, `salesChannelContext` |
| `canonical-redirect` | `Framework/Routing/Extension/CanonicalRedirectExtension` | `?Response` | `request` |
| `sales-channel-file.render-parameters` | `System/SalesChannel/File/Rendering/Extension/SalesChannelFileRenderParametersExtension` | `array<string,mixed>` | `file`, `context`, `salesChannel` (vollständig laut Konstruktor) |
| `storefront.snippets` | `System/Snippet/Extension/StorefrontSnippetsExtension` | `array<string,string>` | `snippets`, `locale`, `catalog`, `snippetSetId`, `fallbackLocale`, `salesChannelId`, `unusedThemes` |

`FrameworkExtension` und `ServiceExtension` sind Symfony-DI-Extensions, keine Extension Points (beim Grep ausfiltern).

Verzeichnisbaum eines Plugins mit eigenem Extension Point:
```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php
    ├── Extension/ShippingLabelExtension.php
    ├── Service/ShippingLabelService.php
    ├── Subscriber/ShippingLabelSubscriber.php
    └── Resources/config/services.php
```

## 3. Anleitung

**A. Finden**
1. `grep -rl "extends Extension\b" vendor/shopware/core --include=*.php` (Ordner `*/Extension/`; `DependencyInjection`-Treffer ignorieren).
2. Publisher: `grep -rn "ExtensionDispatcher\|->publish(" vendor/shopware/core`. Dort sieht man die Core-Closure und deren Parameter.
3. Eventname = `NAME` + `.pre|.post|.error`; die Extension-Klasse ist der Typ-Hint des Listeners.
4. Laufzeit: `bin/console debug:event-dispatcher "listing-loader.resolve.pre"` (Symfony-Standard, für Extension-Namen nicht verifiziert).

**B. Konsumieren**
1. Subscriber mit `EventSubscriberInterface`, Namen per `XyzExtension::onPre()` usw.; Methodensignatur = Extension-Klasse.
2. `pre`: `result` setzen **und** `stopPropagation()` (sonst läuft die Core-Closure und überschreibt `result`).
3. `post`: `$extension->result()` lesen/ändern.
4. `error`: `$extension->exception` auswerten; `result` setzen = Fehler schlucken.
5. Als `kernel.event_subscriber` registrieren; Priorität via `[Name::onPre() => ['method', 100]]`.

**C. Eigenen Extension Point anlegen**
1. `final class XyzExtension extends Extension` mit `@extends Extension<ResultType>` und `public const NAME` (Konvention `<plugin>.<aktion>`).
2. Eingaben als `public readonly`-Constructor-Properties; **Namen = Parameternamen der Standardlogik**.
3. Standardlogik als Methode, per First-Class-Callable `$this->method(...)` an `publish()`.
4. `ExtensionDispatcher` injizieren.
5. `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`.

## 4. Beispiele

`src/Extension/ShippingLabelExtension.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Extension;

use Shopware\Core\Framework\Extensions\Extension;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * @extends Extension<string>
 */
final class ShippingLabelExtension extends Extension
{
    // Pflicht: wird von onPre()/onPost()/onError() per static::NAME gelesen
    public const NAME = 'swag-example.shipping-label';

    public function __construct(
        public readonly string $orderNumber,
        public readonly SalesChannelContext $context,
    ) {
    }
}
```

`src/Service/ShippingLabelService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use SwagExample\Extension\ShippingLabelExtension;

class ShippingLabelService
{
    public function __construct(private readonly ExtensionDispatcher $extensions)
    {
    }

    public function createLabel(string $orderNumber, SalesChannelContext $context): string
    {
        return $this->extensions->publish(
            name: ShippingLabelExtension::NAME,
            extension: new ShippingLabelExtension($orderNumber, $context),
            function: $this->doCreateLabel(...),
        );
    }

    // Parametername MUSS dem Property-Namen der Extension entsprechen (Spread mit String-Keys = benannte Argumente)
    private function doCreateLabel(string $orderNumber, SalesChannelContext $context): string
    {
        return 'DEFAULT-' . $orderNumber;
    }
}
```

`src/Subscriber/ShippingLabelSubscriber.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use SwagExample\Extension\ShippingLabelExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ShippingLabelSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ShippingLabelExtension::onPre() => 'replaceForVip',
            ShippingLabelExtension::onPost() => 'prefixResult',
            ShippingLabelExtension::onError() => 'fallback',
        ];
    }

    public function replaceForVip(ShippingLabelExtension $extension): void
    {
        if (!str_starts_with($extension->orderNumber, 'VIP')) {
            return; // Core-Logik laufen lassen
        }

        $extension->result = 'VIP-LABEL-' . $extension->orderNumber;
        $extension->stopPropagation(); // Closure wird übersprungen, post läuft trotzdem
    }

    public function prefixResult(ShippingLabelExtension $extension): void
    {
        $extension->result = '[' . $extension->result() . ']';
    }

    public function fallback(ShippingLabelExtension $extension): void
    {
        // $extension->exception enthält den Fehler; Ergebnis setzen = Exception wird nicht erneut geworfen
        $extension->result = 'FALLBACK-' . $extension->orderNumber;
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use Shopware\Core\Framework\Extensions\ExtensionDispatcher;
use SwagExample\Service\ShippingLabelService;
use SwagExample\Subscriber\ShippingLabelSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ShippingLabelService::class)
        ->args([service(ExtensionDispatcher::class)]);

    $services->set(ShippingLabelSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

Ergebnis: `createLabel('1000', $ctx)` ergibt `[DEFAULT-1000]`, `createLabel('VIP1', $ctx)` ergibt `[VIP-LABEL-VIP1]`.

Core-Beispiel für paarweises pre/post/error (`core/Checkout/Promotion/Cart/PromotionRedemptionLocker.php`):
```php
public static function getSubscribedEvents(): array
{
    return [
        CheckoutPlaceOrderExtension::onPre() => 'acquireLocks',
        CheckoutPlaceOrderExtension::onError() => 'releaseLocks',
        CheckoutPlaceOrderExtension::onPost() => 'releaseLocks',
    ];
}
```
Zustand wird in `pre` per `$extension->addExtension(...)` angelegt (`ExtendableTrait`) und in `post`/`error` aufgeräumt. Weiteres `post`-Beispiel: `core/System/SalesChannel/File/Rendering/SalesChannelFileStoreApiMcpSubscriber.php`.

Plugin-Grundgerüst: [../templates/plugin/README.md](../templates/plugin/README.md); Subscriber-Gerüst: [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Fallstricke

1. **Closure-Parameternamen passen nicht zu den Extension-Properties.** `getParams()` (`get_object_vars($this)` im Scope der Basisklasse, daher auch `protected` Properties wie `new` bei `checkout.cart.rule-load`) liefert ein assoziatives Array (alle Properties außer `result`, `propagationStopped`, `exception`, `extensions`); der Spread wirkt als benannte Argumente, unbekannter/fehlender Name ergibt `\Error`. Die Doku (creating-custom-extension) zeigt eine parameterlose Closure `function() use (...)`: bei Extensions mit Properties nicht lauffähig (`publish()` ruft `$function(...$extension->getParams())`; PHP wirft bei unbekannten benannten Argumenten `\Error`; aus dem Code abgeleitet, nicht ausgeführt).
2. `stopPropagation()` ohne `result`: Core-Logik übersprungen, Ergebnis `null` (bei nicht-nullable Typ `TypeError` beim Aufrufer).
3. `result` in `pre` setzen, aber nicht stoppen: Core-Closure überschreibt es.
4. `post` läuft immer, auch nach `stopPropagation()` in `pre`.
5. `error` läuft nur, wenn die Closure geworfen hat; Exceptions aus `pre`-Listenern werden nicht gefangen.
6. Ein `result` im `error`-Listener unterdrückt die Exception stillschweigend; bei `void`-Extensions (`FlowExecutorExtension`, `ProductPriceCalculationExtension`) fehlt ein sinnvoller Wert dafür.
7. `NAME` fehlt, oder Name im Listener hartcodiert: Doku mischt `my-plugin.custom-extension` mit `my-plugin.product-filter.pre`. Immer `Klasse::onPre()` nutzen.
8. Core-Extension-Klassen sind `final`, Konstruktor `@internal`: nicht erweitern/instanziieren; öffentlich sind Klassenname, `NAME` und `public`-Properties.
9. `readonly`-Properties sind nicht änderbar; bewusst nicht readonly sind z. B. `ResolveListingIdsExtension::$criteria`, `ResolveCacheRelevantRuleIdsExtension::$ruleAreas`, `StorefrontSnippetsExtension::$snippets`. Sonst über `result`.
10. `stopPropagation()` in einem Listener überspringt nur die in der Reihenfolge danach folgenden Listener desselben Events (Symfony prüft `isPropagationStopped()` vor jedem Listener); Listener davor sind schon gelaufen. In `pre` wird außerdem die Core-Closure übersprungen. `publish()` ruft vor `post` (und nach einem Fehler vor `error`) `resetPropagation()`: ein Stopp in `pre` oder `error` wirkt nicht auf `post`; ein Stopp in `post` hält nur weitere `post`-Listener auf.
11. Extension Point statt Event für reine Seiteneffekte ist falsch; Decoration nur, wenn kein Extension Point existiert (mehrere Plugins mit derselben Decoration brechen sich gegenseitig, Listener-Ketten nicht).

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `ResolveRemoteThumbnailUrlExtension`: Properties `mediaPath` und `mediaUpdatedAt` sind `@deprecated tag:v6.8.0` (Wert stattdessen in `mediaEntity`). Sonst keine `@deprecated` in `core/Framework/Extensions`.
- XML-Service-Definitionen sind für Plugins in 6.7 deprecated; `services.php` verwenden ([T-05](./05-services-registrieren.md)).
- 6.5/6.6: Erweiterung üblicherweise über Events und Decoration (`AbstractXyzRoute::getDecorated()`); in 6.7 zusätzlich Extension Points. Beispiel `CartOrderRoute` publiziert Events und nutzt `checkout.place-order`; `getDecorated()` wirft `DecorationPatternException`.
- `CheckoutPlaceOrderExtension` implementiert zusätzlich `ShopwareSalesChannelEvent` und `CartEvent`.
- ⚠ UNGEPRÜFT: Einführungsversion der Extension Points (Annahme: 6.6/6.7-Linie, in 6.5 nicht vorhanden).

## 6. Checkliste

- [ ] Prüfen, ob für das Ziel ein Extension Point existiert (Tabelle/Grep), bevor dekoriert wird
- [ ] Listener nutzt `XyzExtension::onPre()/onPost()/onError()` statt Strings
- [ ] `pre`-Ersatz setzt `result` und ruft `stopPropagation()`
- [ ] `post`-Logik berücksichtigt, dass `post` auch nach Stop läuft
- [ ] `error`-Listener setzt `result` nur bewusst (Exception wird sonst verschluckt)
- [ ] Eigener Extension Point: `final`, `NAME`, `@extends Extension<T>`, `public readonly`-Properties
- [ ] Closure-Parameternamen = Property-Namen der Extension
- [ ] `ExtensionDispatcher` per Service-ID (Klassenname) injiziert
- [ ] Subscriber mit `kernel.event_subscriber` getaggt, `cache:clear` ausgeführt

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/finding-extensions.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/creating-custom-extension.html
- https://developer.shopware.com/docs/guides/plugins/plugins/framework/extension/extension-vs-events.html

Quellcode (`vendor/shopware/`):
- `core/Framework/Extensions/Extension.php` (`onPre/onPost/onError`, `result()`, `getParams()`, `stopPropagation()`, `resetPropagation()`), `core/Framework/Extensions/ExtensionDispatcher.php` (`publish`, `pre`, `post`, `error`)
- `core/Framework/DependencyInjection/event.php:42` (Service-Registrierung), `core/Framework/Struct/ExtendableTrait.php`
- `core/Framework/Routing/CanonicalRedirectService.php` (Publisher-Muster), `core/Checkout/Cart/SalesChannel/CartOrderRoute.php:93-97`, `core/Checkout/Cart/CartRuleLoader.php`
- `core/Checkout/Promotion/Cart/PromotionRedemptionLocker.php`
- `core/Content/Product/SalesChannel/Listing/ProductListingLoader.php`, `core/Content/Product/SalesChannel/Price/ProductPriceCalculator.php:56`, `core/Framework/Adapter/Cache/Http/CacheHeadersService.php:51`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Einführungsversion der Extension Points (6.5/6.6 vs. 6.7).
- Fehler bei parameterloser Closure (Fallstrick 1): `ExtensionDispatcher::publish` spreizt `getParams()` mit String-Keys (benannte Argumente); nach PHP-Semantik `\Error` bei unbekanntem Namen. Aus dem Code abgeleitet, nicht ausgeführt; Doku-Beispiele sind Skizzen.
- ⚠ UNGEPRÜFT: `debug:event-dispatcher <name>` nicht ausgeführt; Beispiel nicht in laufender Installation getestet.
- Belegt: Konstruktor von `SalesChannelFileRenderParametersExtension` hat genau `file`, `context`, `salesChannel`; alle Parameterlisten der Tabelle gegen die Konstruktoren der 20 Extension-Klassen geprüft (`canonical-redirect`: nur `request`; `html-renderer`/`pdf-renderer`: nur `document`).
- Reihenfolge/Priorität mehrerer Listener folgt Symfony-Standard (nicht Shopware-spezifisch geprüft).
- Doku-Aussage "Events fire after actions" ist verallgemeinert; viele Events (z. B. `*CriteriaEvent`) feuern vor der Aktion.
