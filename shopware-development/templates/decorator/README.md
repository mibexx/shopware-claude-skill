# Template: decorator (Service-Dekoration)

Shopware 6.7.15.1. Zwei Varianten des Decoration Patterns: (A) eigenen Service dekorierbar machen (`Abstract*` + Basisklasse + Decorator) und (B) einen Core-Service dekorieren (häufigster Fall, hier `ProductListingRoute`).

## Dateibaum

```
SwagExample/
└── src/
    ├── Service/
    │   ├── AbstractGreetingService.php      # Abstract-Basis mit getDecorated()
    │   ├── GreetingService.php              # Basis-Implementierung (wirft DecorationPatternException)
    │   └── GreetingServiceDecorator.php     # Decorator (A)
    └── Decorator/
        └── ProductListingRouteDecorator.php # Decorator eines Core-Services (B)
```

Bei Variante B entfallen `AbstractGreetingService`/`GreetingService`: nur Decorator-Klasse plus Registrierung nötig. Wurzel-Gerüst: ../plugin/README.md

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample\Service`, `SwagExample\Decorator` | Namespaces | eigener Namespace |
| `GreetingService` / `AbstractGreetingService` | eigener dekorierbarer Service (Variante A) | eigener Service, oder Variante A weglassen |
| `AbstractProductListingRoute` / `ProductListingRoute` | dekorierter Core-Service (Variante B): Abstract-Typ und Service-ID | Ziel-Abstract-Klasse und Service-ID |
| `$criteria->setLimit(12)` | Beispiellogik | eigene Logik |
| Priorität (`null, -3000`) | Position in der Dekorationskette | siehe unten |

## Registrierung (Ausschnitt für `src/Resources/config/services.php`)

```php
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use SwagExample\Decorator\ProductListingRouteDecorator;
use SwagExample\Service\GreetingService;
use SwagExample\Service\GreetingServiceDecorator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Variante A: eigener Service
$services->set(GreetingService::class);

$services->set(GreetingServiceDecorator::class)
    ->decorate(GreetingService::class)
    ->args([service(GreetingServiceDecorator::class . '.inner')]);

// Variante B: Core-Service. Der Core-Service ist public, daher auch der Decorator (PublicServiceDecoratorRule).
$services->set(ProductListingRouteDecorator::class)
    ->decorate(ProductListingRoute::class)
    ->public()
    ->args([service(ProductListingRouteDecorator::class . '.inner')]);
```

`->decorate(<Id>, null, <priority>)`: höhere Priorität wird zuerst angewendet und sitzt weiter innen; ohne Angabe gilt 0, also innerhalb aller Core-Decorators mit negativer Priorität (z. B. `ResolveCriteriaProductListingRoute` mit `-2000`). Soll der Decorator außen sitzen, eine niedrigere Priorität wählen (z. B. `-3000`). Die Reihenfolge bei gleicher Priorität ist nicht verlässlich. Falsche Service-ID: `ServiceNotFoundException` beim Container-Build; optional machbar mit `ContainerInterface::IGNORE_ON_INVALID_REFERENCE` als vierter Parameter. Die Kurzform `service('.inner')` aus der Doku ist ebenfalls Symfony-konform (nicht ausgeführt).

## Einbindung / CLI

```bash
bin/console plugin:refresh && bin/console plugin:install --activate -c SwagExample
bin/console cache:clear
bin/console debug:container 'Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute'   # zeigt den Decorator
```

Zielservice vorher prüfen: Abstract-Basis mit `getDecorated()` vorhanden, weder Klasse noch Basis `@internal`/`@final`; und ob ein Event/Extension-Point reicht (bevorzugen, wenn nur reagiert werden soll): `grep -n "getDecorated\|@internal\|@final\|final class" vendor/shopware/core/<Pfad>.php`.

## Regeln und Fallstricke

- Den Konstruktor mit der ABSTRAKTEN Klasse typisieren, nicht mit der konkreten (sonst fällt der Service aus der Kette).
- Immer den inneren Service aufrufen (außer bewusstes Ersetzen), sonst brechen Core-Verhalten und andere Plugins.
- `getDecorated()` der Basisklasse wirft `DecorationPatternException`; nur auf Decorators sinnvoll.
- Methodensignaturen des Abstract nicht ändern; keine zusätzlichen public Methoden, kein `EventSubscriberInterface` im Decorator.
- Kein `#[Route]` am Decorator (Core-Konvention, `NoRouteOverrideInDecoratorsRule`).
- Konkrete Klassen ohne Abstract-Basis (z. B. `SystemConfigService`) nicht dekorieren; `@internal`/`final` ebenso nicht. Besser: die dafür vorgesehene Schicht (z. B. Loader-Kette) oder Events nutzen.
- Public Core-Service dekoriert: Decorator `->public()`.

## Hinweis: Store-API-Routen dekorieren

Store-API-Routen werden ausschließlich über dieses Decoration Pattern überschrieben (Variante B): Decorator erweitert `AbstractXRoute`, delegiert in `load()` an `getDecorated()`, wird mit `->decorate(XRoute::class)` und `->public()` registriert. Eigene Routen (neue `AbstractXRoute`/`XRoute`/`XRouteResponse`, `#[Route]` mit Route-Scope `store-api`, `routes.php` mit `$routes->import('../../Core/**/*Route.php', 'attribute')`, OpenAPI-Schema als JSON unter `src/Resources/Schema/StoreApi/`, Rate-Limiter) sind nicht Teil dieser Vorlage. Der Storefront-Controller injiziert die Abstract-Route und ruft sie direkt auf.

## Verweise

- ../../references/06-service-dekoration.md
- ../../references/05-services-registrieren.md
- ../../references/08-extension-points.md
- ../../references/07-events-subscriber.md
- Vorlagen: ../plugin/README.md, ../event-subscriber/README.md

## Hinweise und Lücken

- Geprüft: `AbstractProductListingRoute::load(string, Request, SalesChannelContext, Criteria): ProductListingRouteResponse` und `getDecorated()` gegen den Quellcode; Core-Registrierung `decorate(ProductListingRoute::class, null, -2000)->public()` in `core/Content/DependencyInjection/product.php`; `DecorationPatternException(string $class)`.
- Live bestätigt (Review-Runden 2–3, Shopware 6.7.15.1): Der Decorator mit Priorität 0 sitzt innerhalb von `ResolveCriteriaProductListingRoute` (Priorität −2000); die Store-API liefert `limit: 12`.
- ⚠ UNGEPRÜFT: Doku-Beispiel zeigt `#[Route]` am Decorator, der Core nutzt keines; dass es ohne funktioniert ist aus dem Core-Muster abgeleitet.
- Live bestätigt: Decorator-Registrierung in Plugin-`services.php` (Variante B mit `defaults()`) funktioniert. ⚠ UNGEPRÜFT: Laufzeitverhalten eines Decorators ohne `->public()` bei public Original; Symfony übernimmt die Sichtbarkeit normalerweise, die Vorlage setzt es explizit.
- ⚠ UNGEPRÜFT: `#[AsDecorator]` von Symfony wird im Core nicht verwendet und hier nicht empfohlen.
- ⚠ LÜCKE: Store-API-Route komplett (siehe Hinweis oben), Extension-Points (`Extension::onPre/onPost`) und Dekoration von `SystemConfigService`-nahen Loader-Ketten sind nicht als Vorlage enthalten.
