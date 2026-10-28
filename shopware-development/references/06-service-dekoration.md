# Bestehende Services dekorieren und überschreiben (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn das Verhalten eines bestehenden Core- oder Plugin-Services geändert oder ersetzt werden soll, ohne Core-Code anzufassen: das Decorator-Pattern mit `Abstract*`-Klasse und `getDecorated()`. Sie hilft auch bei der Entscheidung Dekoration vs. Event.

Abgrenzung: Services neu registrieren in [T-05](./05-services-registrieren.md); Events in [T-07](./07-events-subscriber.md); Extension-Points in [T-08](./08-extension-points.md); Store-API-Routen überschreiben (Route-Attribute, Antworten) in [T-30](./30-store-api-routen.md). Gerüst: [../templates/decorator/README.md](../templates/decorator/README.md).

## 2. Konzepte

- **Dekorieren** = eigenen Service registrieren, der per `->decorate(<Id>)` einen bestehenden ersetzt und das Original (`.inner`) per Konstruktor erhält. Der Container tauscht die Id; alle Konsumenten bekommen den Decorator.
- **Decoration Pattern (Shopware-Konvention)**: `Abstract<Name>` mit `abstract getDecorated(): Abstract<Name>`. Die Core-Implementierung wirft in `getDecorated()` die `DecorationPatternException`; Decorators geben die innere Instanz zurück. Konsumenten typisieren immer die Abstract-Klasse.
- **Nur dekorieren, was vorgesehen ist**: Abstract-Klasse mit `getDecorated()`, nicht `@internal`, nicht `@final`/`final`. Konkrete Klassen ohne Abstract-Basis (z. B. `SystemConfigService`) sind nicht dafür gedacht.
- **Dekoration vs. Event**: Doku sinngemäß „Prefer events when you need to react to something Shopware does. Prefer service decoration when you need to change how an existing service behaves." Existiert ein Event oder Extension-Point, diesen bevorzugen.
- **Priorität**: `decorate(Id, null, <priority>)`. Höhere Priorität wird zuerst angewendet und sitzt innen (näher am Original); niedrigere liegt weiter außen. Gleiche Priorität: Registrierungsreihenfolge (erster = innen).

```text
custom/plugins/SwagExample/src/
├── Resources/config/services.php
├── Service/
│   ├── AbstractGreetingService.php      # Abstract-Basis mit getDecorated()
│   ├── GreetingService.php              # Basis (wirft DecorationPatternException)
│   └── GreetingServiceDecorator.php
└── Core/ListingRouteDecorator.php       # häufigster Fall: Core-Service dekorieren
```

Meist dekoriert man einen **Core**-Service; dann entfallen `AbstractGreetingService`/`GreetingService`.

### Prioritäten im Core (Beispiel `System/DependencyInjection/configuration.php`)

| Decorator | Priorität | Position |
|---|---|---|
| `SystemConfigLoader` (Basis) | - | innerster |
| `CachedSystemConfigLoader` | -1000 | wickelt Basis |
| `ConfiguredSystemConfigLoader` | -1500 | wickelt Cached |
| `MemoizedSystemConfigLoader` | -2000 | äußerster |

Ohne Angabe gilt Priorität 0: der Plugin-Decorator sitzt **innerhalb** aller Core-Decorators mit negativer Priorität (z. B. vor dem Core-Caching). Soll er außen sitzen, negativere Priorität als der äußerste Core-Decorator wählen (z. B. `-3000`). Reihenfolge bei gleicher Priorität mehrerer Plugins (Plugin-Ladereihenfolge) nicht verlassen.

## 3. Anleitung

1. **Zielservice prüfen**: `Abstract*`-Basis mit `getDecorated()` vorhanden, weder Klasse noch Basis `@internal`/`@final`/`final`?
   `grep -n "getDecorated\|@internal\|@final\|final class" vendor/shopware/core/<Pfad>.php`
2. **Event/Extension-Point prüfen** (z. B. `ProductListingCriteriaEvent`, Extensions in `Content/Product/Extension/*`, Basis `Framework/Extensions/Extension.php` mit `onPre()/onPost()/onError()`). Wenn passend, keinen Decorator bauen.
3. **Service-Id ermitteln**: meist FQCN der konkreten Core-Klasse (`ProductListingRoute::class`), einige haben String-Ids (`product.repository`). `bin/console debug:container --show-arguments <Id>`.
4. **Decorator schreiben**: extends Abstract-Klasse, Konstruktor nimmt die **Abstract**-Klasse, `getDecorated()` gibt sie zurück, Methoden rufen (sofern sinnvoll) den inneren Service auf. Keine zusätzlichen public Methoden, kein `EventSubscriberInterface` (`DecorationPatternRule`; steht nur in `core-rules.neon`, nicht in `rules.neon`, siehe [T-48](./48-statische-analyse.md)).
5. **services.php**: `->decorate(<Id>[, null, <priority>])` und `service(<DecoratorId>.inner)` übergeben. Ist der dekorierte Service `public()`, den Decorator ebenfalls `public()` setzen (`PublicServiceDecoratorRule`).
6. **Aktivieren**: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear`
7. **Prüfen**: `bin/console debug:container <Id>` zeigt den Decorator, `<DecoratorId>.inner` das Original.
8. Optionale Dekoration: 4. Parameter von `decorate()` = `ContainerInterface::IGNORE_ON_INVALID_REFERENCE`.

## 4. Beispiele

### 4a. Eigenen Service dekorierbar machen

`src/Service/AbstractGreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

abstract class AbstractGreetingService
{
    abstract public function getDecorated(): AbstractGreetingService;

    abstract public function greet(string $name): string;
}
```

`src/Service/GreetingService.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class GreetingService extends AbstractGreetingService
{
    public function getDecorated(): AbstractGreetingService
    {
        throw new DecorationPatternException(self::class);
    }

    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}
```

`src/Service/GreetingServiceDecorator.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

class GreetingServiceDecorator extends AbstractGreetingService
{
    public function __construct(private readonly AbstractGreetingService $inner)
    {
    }

    public function getDecorated(): AbstractGreetingService
    {
        return $this->inner;
    }

    public function greet(string $name): string
    {
        // Immer den inneren Service aufrufen, sonst bricht die Kette für andere Plugins
        return $this->inner->greet($name) . '!';
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Service\GreetingService;
use SwagExample\Service\GreetingServiceDecorator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(GreetingService::class);

    $services->set(GreetingServiceDecorator::class)
        ->decorate(GreetingService::class)
        ->args([service(GreetingServiceDecorator::class . '.inner')]);
};
```

Die Doku schreibt `service('.inner')` (Symfony-Kurzform, ebenfalls gültig); der explizite Verweis `<DecoratorId>.inner` entspricht dem Core-Stil.

### 4b. Core-Service dekorieren (häufigster Fall)

Core-Vorbild (`Content/DependencyInjection/product.php`, `ResolveCriteriaProductListingRoute`):

```php
$services->set(ResolveCriteriaProductListingRoute::class)
    ->decorate(ProductListingRoute::class, null, -2000)
    ->public()
    ->args([service(ResolveCriteriaProductListingRoute::class . '.inner'), ...]);
```

Plugin-Decorator der Store-API-Route (nur Dekoration, ohne eigene `#[Route]`), `src/Core/ListingRouteDecorator.php`:

```php
<?php declare(strict_types=1);

namespace SwagExample\Core;

use Shopware\Core\Content\Product\SalesChannel\Listing\AbstractProductListingRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class ListingRouteDecorator extends AbstractProductListingRoute
{
    public function __construct(private readonly AbstractProductListingRoute $inner)
    {
    }

    public function getDecorated(): AbstractProductListingRoute
    {
        return $this->inner;
    }

    public function load(string $categoryId, Request $request, SalesChannelContext $context, Criteria $criteria): ProductListingRouteResponse
    {
        $criteria->setLimit(12); // Beispiel: Criteria vor dem Aufruf anpassen

        return $this->getDecorated()->load($categoryId, $request, $context, $criteria);
    }
}
```

`src/Resources/config/services.php` (Ausschnitt, mit allen nötigen `use`):

```php
<?php declare(strict_types=1);

use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use SwagExample\Core\ListingRouteDecorator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $configurator->services()
        ->set(ListingRouteDecorator::class)
        ->decorate(ProductListingRoute::class)
        ->args([service(ListingRouteDecorator::class . '.inner')]);
};
```

Ob `->public()` nötig ist, siehe Fallstricke (Core setzt es beim eigenen Decorator). Details zu Store-API-Routen in [T-30](./30-store-api-routen.md).

## 5. Fallstricke

- **Konkrete Klasse typisieren**: Konstruktor mit `GreetingService` statt `AbstractGreetingService` klinkt den Service aus der Kette aus.
- **Inneren Service nicht aufrufen**: Kette bricht, andere Plugins/Core-Verhalten gehen verloren („breaks silently").
- **`getDecorated()` auf der Basisklasse**: wirft `DecorationPatternException` („The getDecorated() function of core class %s cannot be used. This class is the base class.", HTTP 500). Nur auf Decorators sinnvoll.
- **`@internal`/`final` Klassen dekorieren**: keine Stabilitätsgarantie, kein BC. `DecorationPatternRule` verbietet im Core `@internal`/`@final` an Klassen mit Decoration-Pattern; `@final` = nutzbar, nicht erweiterbar; `@internal` = privates API. Nur per Annotation, nicht technisch erzwungen, daher nicht empfohlen.
- **Konkrete Klasse ohne Abstract-Basis**, z. B. `SystemConfigService` (Konstruktor `@internal`, `->public()->lazy()`): Decorator müsste `extends SystemConfigService` sein und alle Konstruktor-Argumente weiterreichen und bricht bei Updates. Besser die vorgesehene Schicht dekorieren (`AbstractSystemConfigLoader`, Id `SystemConfigLoader::class`) oder die Events unter `System/SystemConfig/Event/*` nutzen.
- **Event-Subscriber-Klasse dekorieren**: mit `EventSubscriberInterface` inkompatibel (`DecorationPatternRule`).
- **Decorator mit eigener `#[Route]`**: verboten (`NoRouteOverrideInDecoratorsRule`); nur der Core-Service definiert Routen.
- **Public-Service-Regel**: Decorator eines `public()` Services ohne `->public()` schlägt in `PublicServiceDecoratorRule` (PHPStan) an. Zur Laufzeit übernimmt Symfony die Sichtbarkeit selbst: `DecoratorServicePass::process()` merkt sich `$public` des dekorierten Services (Z. 73, 78, 86), macht dessen Definition privat unter der umbenannten Id (`<Id>.inner`) und setzt `setAlias($inner, $id)->setPublic($public)` (Z. 115); die ursprüngliche Id bleibt also öffentlich. `->public()` am Decorator ist daher keine Laufzeitvoraussetzung, sondern Shopware-Konvention (PHPStan-Regel) und für Lesbarkeit sinnvoll (`vendor/symfony/dependency-injection/Compiler/DecoratorServicePass.php`).
- **Signatur ändern**: Auch optionale Zusatzparameter brechen alle Decorators (Doku). Neue Methoden zunächst nicht-abstrakt einführen.
- **Falsche Service-Id**: `ServiceNotFoundException` beim Container-Build (`DecoratorServicePass`); optional machen mit `IGNORE_ON_INVALID_REFERENCE`.
- **Mehrfach-Dekoration ohne Priorität**: Position relativ zum Core-Caching ungewollt (siehe Prioritäten).
- **XML-Services**: in 6.7 deprecated, neue Plugins mit `services.php`.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- 6.5/6.6-Doku zeigte `services.xml`; in 6.7.15.1 ist `services.php` Standard, XML lädt noch mit Deprecation, Entfernung `v6.8.0` (`Bundle::registerContainerFile`). Core-Service-Dateien sind `*/DependencyInjection/*.php`; Priorität und Id dort nachschlagen (im installierten Core existieren keine XML-Service-Dateien mit `decorates`, per grep geprüft).
- Das Pattern selbst (`Abstract*`, `getDecorated()`, `DecorationPatternException`) gilt unverändert; PHPStan-Regeln im Core sichern es ab.
- Neu in 6.7 als Alternative: Extension-Points (`Framework/Extensions/Extension.php`, `ExtensionDispatcher`, z. B. `ProductListingCriteriaExtension`, Events `<name>.pre/.post/.error`), siehe [T-08](./08-extension-points.md).

## 6. Checkliste

- [ ] Event oder Extension-Point geprüft und als unzureichend befunden.
- [ ] Zielklasse hat `Abstract*`-Basis mit `getDecorated()`, ist nicht `@internal`/`@final`.
- [ ] Decorator extends die Abstract-Klasse, Konstruktor typisiert die Abstract-Klasse.
- [ ] `getDecorated()` gibt `$inner` zurück; Methoden rufen `$inner` auf.
- [ ] `decorate(<Id>)` mit korrekter Id und `service(<DecoratorId>.inner)`.
- [ ] Bei `public()`-Original: Decorator ebenfalls `->public()`.
- [ ] Priorität bewusst gewählt (innen/außen relativ zu Core-Decorators).
- [ ] Keine eigene `#[Route]` im Decorator.
- [ ] `cache:clear` und `debug:container <Id>` zeigt den Decorator.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/services/adjusting-service.html (bzw. `.../plugin-fundamentals/adjusting-service.html`, beide abrufbar)
- https://developer.shopware.com/docs/resources/guidelines/code/core/decorator-pattern.html
- https://developer.shopware.com/docs/resources/guidelines/code/core/final-and-internal.html

Quellcode (relativ zu `vendor/shopware/`): `core/Framework/Plugin/Exception/DecorationPatternException.php`; `core/Content/Product/SalesChannel/Listing/{AbstractProductListingRoute,ProductListingRoute,ResolveCriteriaProductListingRoute}.php`; `core/Content/DependencyInjection/product.php` (`decorate(ProductListingRoute::class, null, -2000)`); `core/System/SystemConfig/{AbstractSystemConfigLoader,CachedSystemConfigLoader,ConfiguredSystemConfigLoader,MemoizedSystemConfigLoader,SystemConfigLoader,SystemConfigService}.php`; `core/System/DependencyInjection/configuration.php`; `core/DevOps/StaticAnalyze/PHPStan/Rules/{DecorationPatternRule,PublicServiceDecoratorRule,NoRouteOverrideInDecoratorsRule}.php`; `core/Framework/Plugin/Command/Scaffolding/stubs/store-api-{abstract-,}route.stub`; `core/Framework/Bundle.php`; `core/Framework/Extensions/Extension.php`; Symfony `DecoratorServicePass`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Die Plugin-Beispiele sind selbst geschrieben (Doku-Muster), nicht in einer laufenden Installation ausgeführt; `bin/console debug:container` nicht ausgeführt. Signaturen von `AbstractProductListingRoute` (`getDecorated`, `load`) am Quellcode bestätigt.
- ⚠ UNGEPRÜFT: `service('.inner')` (Doku) lokal nicht ausgeführt; Reihenfolge bei gleicher Priorität nur aus Symfony-Code abgeleitet, Plugin-Ladereihenfolge nicht geprüft.
- Belegt (Code, nicht ausgeführt): Symfony überträgt die Sichtbarkeit des dekorierten Services auf den Alias (`DecoratorServicePass.php` Z. 73-86, 115); `->public()` am Decorator ist nur Shopware-PHPStan-Konvention.
- ⚠ LÜCKE: `#[AsDecorator]` (Symfony) wird im Core nicht verwendet; Nutzung in Plugins nicht belegt, daher nicht empfohlen. Einführungsversion der Extension-Points nicht verifiziert.
