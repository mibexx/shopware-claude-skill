# Store-API-Routen anlegen und überschreiben (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn eine eigene Store-API-Route (`/store-api/...`) entstehen, eine bestehende Core-Route per Dekoration verändert, eine Route im Storefront-Controller wiederverwendet, ein Rate-Limiter vorgeschaltet oder die OpenAPI-Beschreibung geliefert werden soll.

Abgrenzung: Admin-API-Routen (`/api/...`) → [T-31](./31-admin-api-routen.md). Storefront-Controller/Pages → [T-16](./16-storefront-controller-pages.md). Service-Dekoration allgemein → [T-06](./06-service-dekoration.md). Events als Alternative → [T-07](./07-events-subscriber.md). HTTP-Cache/Invalidierung → [T-41](./41-caching.md). Services/Routen-Registrierung → [T-05](./05-services-registrieren.md).

## 2. Konzepte

- Eine Store-API-Route besteht aus vier Teilen: `AbstractXRoute` (mit `getDecorated()` + Methode), `XRoute` (Implementierung, `#[Route]`), `XRouteResponse extends StoreApiResponse`, Service-Registrierung (`->public()`).
- **Route-Scope** per Klassen-Attribut `#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]` (`StoreApiRouteScope::ID = 'store-api'`). Ohne Scope scheitert der `RouteScopeListener`. Referenz: `core/Content/Product/SalesChannel/ProductListRoute.php`.
- Pfad beginnt mit `/store-api/...`, Routenname mit `store-api.`. Argumente `Criteria`, `SalesChannelContext`, `RequestDataBag`, `Request` werden per Argument-Resolver injiziert (`CriteriaValueResolver`).
- **Überschreiben = Dekorieren**: Decorator erweitert `AbstractXRoute`, erhält die Original-Route über `.inner`, delegiert in `load()`; `getDecorated()` gibt die innere Route zurück. Die Basisimplementierung wirft `DecorationPatternException`.
- **Storefront-Nutzung**: Controller injiziert die `AbstractXRoute` (nicht die konkrete) und ruft `->load(...)` direkt in PHP (kein HTTP-Roundtrip), z. B. `FormController` mit `AbstractContactFormRoute`.
- **OpenAPI** in 6.7 per JSON unter `src/Resources/Schema/StoreApi/*.json` bzw. `AdminApi/` (Generator liest `<Bundle>/Resources/Schema/{StoreApi|AdminApi}`, `BundleSchemaPathCollection`). PHP-`#[OA\...]`-Annotationen werden im Core nicht mehr genutzt.
- **Rate-Limiter**: Konfig unter `shopware.api.rate_limiter`, Aufruf `RateLimiter::ensureAccepted('<key>', $clientIp)`; Verstoß → `RateLimitExceededException` (HTTP 429).
- Entscheidungshilfe: Nur Criteria/Daten einer Core-Route anpassen → Event (`ProductListingCriteriaEvent`, `...LoadedEvent`) oft einfacher; Verhalten/Response einer Route ändern → Dekorator; neuer Endpunkt → eigene Route.

Verzeichnisbaum (Plugin `SwagExample`):

```
SwagExample/
└── src/
    ├── SwagExample.php
    ├── Core/Content/Example/SalesChannel/
    │   ├── AbstractExampleRoute.php
    │   ├── ExampleRoute.php
    │   ├── ExampleRouteResponse.php
    │   └── ExampleRouteDecorator.php        # optional
    ├── Storefront/Controller/ExampleController.php   # optional
    └── Resources/
        ├── config/{services.php, routes.php}
        └── Schema/StoreApi/example.json
```

## 3. Anleitung

1. Plugin anlegen (T-02): `bin/console plugin:create SwagExample` (Dev-Container: `docker compose exec web bin/console ...`).
2. Klassen aus Abschnitt 4 anlegen; die Basis-Route wirft in `getDecorated()` `DecorationPatternException`.
3. Route in `services.php` registrieren, `->public()` (Core macht das bei allen Routen, z. B. `core/Content/DependencyInjection/contact_form.php`).
4. `routes.php` mit Attribut-Import anlegen, danach `cache:clear`.
5. Schreibende Routen: `PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true` in `defaults` (Beispiel `ProductReviewSaveRoute`), `RequestDataBag` als Argument, Validierung über `DataValidator` + `DataValidationDefinition`.
6. Cachebare Lese-Routen: `PlatformRequest::ATTRIBUTE_HTTP_CACHE => true` (wie `ProductListRoute`); Invalidierung siehe [T-41](./41-caching.md).
7. OpenAPI-JSON unter `src/Resources/Schema/StoreApi/` ablegen (Format wie `core/Framework/Api/ApiDefinition/Generator/Schema/StoreApi/paths/contact-form.json`, `paths`-Schlüssel ohne `/store-api`-Präfix).
8. Installieren und prüfen:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear
bin/console debug:router | grep swag-example
curl -X POST http://localhost/store-api/swag-example -H 'sw-access-key: <KEY>' -H 'Content-Type: application/json' -d '{"limit":2}'
```

9. Dekorieren: Decorator mit `->decorate(ExampleRoute::class)` und `service(Decorator::class . '.inner')`. Core-Vorbild: `ResolvedCriteriaProductSuggestRoute` (dekoriert `ProductSuggestRoute`, Priorität `-2000`, `core/Content/DependencyInjection/product.php`). Core-Decorator tragen kein eigenes `#[Route]`; die Doku (`override-existing-route`) zeigt eines am Decorator, nötig ist es bei unverändertem Pfad nicht (Annahme, siehe Lücken).
10. Rate-Limiter: Konfiguration unter `shopware.api.rate_limiter` (siehe Beispiele), Key im Code per `ensureAccepted` verwenden; `ensureAcceptedIfConfigured()`/`resetIfConfigured()` sind tolerante Varianten. Policies: `time_backoff`, `system_config` (Shopware-eigen, `RateLimiterFactory::create`), sonst Symfony (`fixed_window`, `sliding_window`, `token_bucket`). Im Storefront-Controller `RateLimitExceededException` fangen (`getWaitTime()`), Muster `FormController`.
11. Admin-API-Schemas analog unter `src/Resources/Schema/AdminApi/*.json` (Loader mergen alle `*.json`: `paths` per `array_replace_recursive`, `components`/`tags` per `array_merge_recursive`, `OpenApiFileLoader`). Entity-Schemas entstehen automatisch; Felder brauchen für die Store-API das Flag `ApiAware`.

## 4. Beispiele

`src/Core/Content/Example/SalesChannel/AbstractExampleRoute.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

abstract class AbstractExampleRoute
{
    abstract public function getDecorated(): AbstractExampleRoute;

    abstract public function load(Criteria $criteria, SalesChannelContext $context): ExampleRouteResponse;
}
```

`src/Core/Content/Example/SalesChannel/ExampleRouteResponse.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<EntitySearchResult<ProductCollection>>
 */
class ExampleRouteResponse extends StoreApiResponse
{
    public function getProducts(): ProductCollection
    {
        return $this->object->getEntities();
    }
}
```

`src/Core/Content/Example/SalesChannel/ExampleRoute.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\StoreApiRouteScope;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]])]
class ExampleRoute extends AbstractExampleRoute
{
    /**
     * @param SalesChannelRepository<ProductCollection> $productRepository
     */
    public function __construct(private readonly SalesChannelRepository $productRepository)
    {
    }

    public function getDecorated(): AbstractExampleRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/swag-example',
        name: 'store-api.swag-example.search',
        methods: [Request::METHOD_GET, Request::METHOD_POST],
        defaults: [PlatformRequest::ATTRIBUTE_ENTITY => ProductDefinition::ENTITY_NAME],
    )]
    public function load(Criteria $criteria, SalesChannelContext $context): ExampleRouteResponse
    {
        return new ExampleRouteResponse($this->productRepository->search($criteria, $context));
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Core\Content\Example\SalesChannel\ExampleRoute;
use SwagExample\Core\Content\Example\SalesChannel\ExampleRouteDecorator;
use SwagExample\Storefront\Controller\ExampleController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ExampleRoute::class)
        ->public()
        ->args([service('sales_channel.product.repository')]);

    // optional: Dekorator
    $services->set(ExampleRouteDecorator::class)
        ->decorate(ExampleRoute::class)
        ->public()
        ->args([service(ExampleRouteDecorator::class . '.inner')]);

    // optional: Storefront-Controller (Muster aus storefront/DependencyInjection/controller.php)
    $services->set(ExampleController::class)
        ->public()
        ->args([service(ExampleRoute::class)])
        ->call('setContainer', [service('service_container')]);
};
```

`src/Resources/config/routes.php`

```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Core/**/*Route.php', 'attribute');
    $routes->import('../../Storefront/Controller/*Controller.php', 'attribute');
};
```

`src/Core/Content/Example/SalesChannel/ExampleRouteDecorator.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\SalesChannel;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ExampleRouteDecorator extends AbstractExampleRoute
{
    public function __construct(private readonly AbstractExampleRoute $decorated)
    {
    }

    public function getDecorated(): AbstractExampleRoute
    {
        return $this->decorated;
    }

    public function load(Criteria $criteria, SalesChannelContext $context): ExampleRouteResponse
    {
        $criteria->addAssociation('manufacturer');   // Pre-Processing

        return $this->decorated->load($criteria, $context);
    }
}
```

Um Core-Routen zu erweitern: `decorate(\Shopware\Core\...\ProductListRoute::class)` und die Abstract-Klasse des Core-Routen-Vertrags erweitern. Gerüst: [../templates/decorator/README.md](../templates/decorator/README.md).

`src/Storefront/Controller/ExampleController.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Storefront\Controller;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use SwagExample\Core\Content\Example\SalesChannel\AbstractExampleRoute;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class ExampleController extends StorefrontController
{
    public function __construct(private readonly AbstractExampleRoute $exampleRoute)
    {
    }

    #[Route(path: '/swag-example', name: 'frontend.swag-example.list', methods: ['GET'], defaults: ['_httpCache' => true])]
    public function list(SalesChannelContext $context): Response
    {
        $products = $this->exampleRoute->load(new Criteria(), $context)->getProducts();

        return $this->renderStorefront('@SwagExample/storefront/page/example.html.twig', ['products' => $products]);
    }
}
```

Hinweis: In `services.php` wird hier die konkrete `ExampleRoute` injiziert; mit Decorator wird die dekorierte Instanz unter der ID `ExampleRoute::class` geliefert. Controller-Details siehe [T-16](./16-storefront-controller-pages.md).

`src/Resources/Schema/StoreApi/example.json`

```json
{
    "openapi": "3.2.0",
    "info": [],
    "paths": {
        "/swag-example": {
            "post": {
                "tags": ["Product"],
                "summary": "Example product listing",
                "operationId": "readSwagExample",
                "parameters": [{ "$ref": "#/components/parameters/swLanguageId" }],
                "responses": {
                    "200": { "description": "Entity search result containing products." }
                }
            }
        }
    }
}
```

Rate-Limiter, Konfiguration (Projekt-Ebene, `config/packages/shopware.yaml`; Struktur wie `core/Framework/Resources/config/packages/shopware.yaml` unter `shopware.api.rate_limiter`):

```yaml
shopware:
    api:
        rate_limiter:
            swag_example_route:
                enabled: true
                policy: 'time_backoff'
                reset: '24 hours'
                limits:
                    - limit: 3
                      interval: '30 seconds'
                    - limit: 5
                      interval: '60 seconds'
```

Nutzung in `ExampleRoute::load()` (Service-Argumente `service('shopware.rate_limiter')` und `service('request_stack')` ergänzen):

```php
$ip = $this->requestStack->getMainRequest()?->getClientIp();
if ($ip !== null) {
    $this->rateLimiter->ensureAccepted('swag_example_route', $ip);   // wirft RateLimitExceededException (429)
}
// bei Erfolg optional: $this->rateLimiter->reset('swag_example_route', $ip);
```

Klasse `Shopware\Core\Framework\RateLimiter\RateLimiter`. Die Doku beschreibt für Plugins zusätzlich `rate_limiter.yaml` plus eigenen Compiler-Pass (Registrierung in `SwagExample::build()` mit `PassConfig::TYPE_BEFORE_OPTIMIZATION`, Priorität >= 500, damit er vor dem Core-`RateLimiterCompilerPass` läuft). ⚠ UNGEPRÜFT: dieser Doku-Weg wurde nicht am Code nachvollzogen.

⚠ UNGEPRÜFT: Beispiele nicht ausgeführt (kein PHP im Host-Shell).

## 5. Fallstricke

- Kein/anderer Route-Scope: Scope-Fehler/404/Zugriff verweigert; Scope als Klassen-`#[Route(defaults: ...)]` setzen.
- Route-Service nicht `public` oder Decorator ohne `.inner`: Container-/Zirkelfehler.
- `routes.php` fehlt oder Glob trifft nicht: Route fehlt in `debug:router`; nach Änderung `cache:clear`.
- Konkrete Klasse statt Abstract im Controller injiziert: Dekoratoren werden umgangen. (Mit Decorator hinter der ID der konkreten Klasse greift er trotzdem; besser Abstract-Typ im Konstruktor, wie der Core.)
- `getDecorated()` der Basis-Route aufrufen: `DecorationPatternException`.
- Felder ohne `ApiAware` erscheinen nicht in der Store-API-Antwort.
- PHP-OpenAPI-Annotationen schreiben: werden in 6.7 nicht mehr ausgewertet, JSON-Schema nutzen.
- Rate-Limiter-Key nicht konfiguriert: `ensureAccepted` wirft `RateLimiterException::factoryNotFound`; konfigurieren oder `ensureAcceptedIfConfigured`.
- Compiler-Pass mit zu niedriger Priorität: Config wird nicht gemerged (Core-Pass lief schon).
- `_httpCache => true` ohne korrekte Invalidierung: veraltete Daten.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Routen-Attribut `Symfony\Component\Routing\Attribute\Route` (statt `Annotation\Route`); Scope per `defaults` `_routeScope`, ältere `@Route(... defaults={"_routeScope"=...})`-Annotationen entfallen.
- OpenAPI früher `@OA\Get`-PHP-Annotationen; in 6.7 JSON (`Generator/Schema/StoreApi|AdminApi`), OpenAPI 3.2.0 (`core/Framework/Api/ApiDefinition/README.md`). Plugin-Schemaordner `Resources/Schema/{StoreApi,AdminApi}`.
- Routing/Services in PHP (`services.php`, `routes.php`); `routes.xml` ist `@deprecated` für v6.8.0 (`core/Framework/Bundle.php::configureRoutes`), in 6.8 wirkungslos.
- Rate-Limiter-Service-ID `shopware.rate_limiter`.
- `ATTRIBUTE_CONTEXT_TOKEN_REQUIRED`, `_noStore` (`PlatformRequest`): ⚠ UNGEPRÜFT, nicht vertieft.

## 6. Checkliste

- [ ] Abstract-Route, Route, Response und Service-Registrierung (`->public()`) vorhanden
- [ ] Klassen-`#[Route]` mit `ATTRIBUTE_ROUTE_SCOPE => [StoreApiRouteScope::ID]`
- [ ] Pfad `/store-api/...`, Name `store-api.*`
- [ ] `routes.php` importiert die Routen (kein `routes.xml`), `cache:clear` ausgeführt
- [ ] `debug:router` zeigt die Route, Test per curl mit `sw-access-key`
- [ ] Schreibende Routen: Login-Pflicht und Validierung gesetzt
- [ ] Felder für die Antwort mit `ApiAware`
- [ ] Controller injizieren die `AbstractXRoute`
- [ ] Rate-Limiter-Key konfiguriert, falls verwendet
- [ ] OpenAPI-JSON unter `Resources/Schema/StoreApi/`
- [ ] `_httpCache` nur mit durchdachter Invalidierung

## 7. Quellen

Doku (https://developer.shopware.com/docs/guides/plugins/plugins/framework/): `store-api/add-store-api-route.html`, `store-api/override-existing-route.html`, `rate-limiter/add-rate-limiter-to-api-route.html`. `/docs/resources/guidelines/code/store-api.html` wurde nicht abgerufen.

Quellcode (`core/`): `Framework/Routing/{StoreApiRouteScope,ApiRouteScope,RouteScopeListener,RouteScopeRegistry}.php`, `Framework/Routing/Annotation/CriteriaValueResolver.php`, `PlatformRequest.php`, `System/SalesChannel/{StoreApiResponse,NoContentResponse}.php`, `Content/Product/SalesChannel/{AbstractProductListRoute,ProductListRoute,ProductListResponse}.php`, `Review/ProductReviewSaveRoute.php`, `Suggest/ResolvedCriteriaProductSuggestRoute.php`, `Content/ContactForm/SalesChannel/ContactFormRoute.php`, `Content/DependencyInjection/contact_form.php`, `Framework/Plugin/Exception/DecorationPatternException.php`, `Framework/Bundle.php` (`configureRoutes`), `Framework/Api/ApiDefinition/Generator/{StoreApiGenerator,BundleSchemaPathCollection,OpenApiFileLoader}.php`, `Framework/RateLimiter/{RateLimiter,RateLimiterFactory}.php`, `Framework/DependencyInjection/CompilerPass/RateLimiterCompilerPass.php`, `Framework/Resources/config/packages/shopware.yaml`. Storefront: `Controller/FormController.php`, `DependencyInjection/controller.php`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Beispiele nicht ausgeführt, an Core-Code angelehnt.
- ⚠ LÜCKE: Doku `override-existing-route` zeigt `#[Route]` am Decorator, Core-Decorator haben keins; Funktion ohne `#[Route]` aus dem Core-Muster abgeleitet, nicht getestet.
- ⚠ UNGEPRÜFT: Doku-Variante Rate-Limiter (`rate_limiter.yaml` + Plugin-Compiler-Pass) nicht am Code verifiziert; belegt sind nur Parameter `shopware.api.rate_limiter` und der Core-Pass.
- ⚠ UNGEPRÜFT: OpenAPI-Plugin-Schema (Pflichtfelder `info`/Tags) nicht validiert; Endpoint `/store-api/_info/openapi3.json` aus Erinnerung.
- Storefront-Controller-Registrierung belegt: `storefront/DependencyInjection/controller.php` existiert im Paket und setzt je Controller `->call('setContainer', [service('service_container')])` (u. a. Z. 156, 175, 187); das Beispiel folgt diesem Muster (Plugin-spezifische Ausführung nicht getestet).
- ⚠ UNGEPRÜFT: Service-ID `sales_channel.product.repository` aus Core-Konvention angenommen (in `seo.php` belegt, siehe T-28).
