# Admin-API-Routen, Integrationen und Sync-API (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eigene Endpunkte unter `/api/...` bereitstellt, wenn externe Systeme per Integration (OAuth `client_credentials`) auf die Admin-API zugreifen oder wenn Massendaten über die Sync-API (`/api/_action/sync`) geschrieben werden.

Abgrenzung: Store-API-Routen (`/store-api/...`) in [T-30 Store-API-Routen](./30-store-api-routen.md); ACL-Rollen und Privilegien-UI in [T-23 ACL](./23-acl-rechte-rollen.md); Aufruf aus der Administration (`ApiService`) in [T-26 Admin-Datenhandling](./26-admin-datenhandling.md); Controller-Grundlagen der Storefront in [T-16 Controller/Pages](./16-storefront-controller-pages.md); DAL in [T-27](./27-dal-entities.md) und [T-28](./28-dal-lesen-schreiben.md).

## 2. Konzepte

- **Admin-API-Route**: Symfony-Controller (erbt `AbstractController`) mit Klassen-Default `_routeScope = ['api']`. Pfad muss mit `/api/` beginnen (`ApiRouteScope::ALLOWED_PATH = 'api'`). Konvention für Aktionen: `/api/_action/<plugin>/<aktion>`.
- **Auth**: Standard `auth_required = true`. `ApiRouteScope::isAllowed()` verlangt dann eine `AdminApiSource` (User oder Integration). Den Bearer-Token prüft `ApiAuthenticationListener::validateRequest` (`KernelEvents::CONTROLLER`). Nur `defaults: ['auth_required' => false]` (z. B. `/api/oauth/token`) lässt auch `SystemSource` zu und überspringt den Token-Check.
- **Privilegien**: Route-Default `PlatformRequest::ATTRIBUTE_ACL` (`_acl`) = Liste von Privilegien-Strings. `AclAnnotationValidator` prüft jeden String mit `Context::isAllowed()`. `AdminApiSource` mit `admin = true` darf alles, sonst nur Strings aus den Rollen.
- **OAuth** (`POST /api/oauth/token`, `AuthController::token`): Grants `password`, `refresh_token`, `client_credentials`, SSO-Grant (`ShopwareGrantType`), `authorization_code` (`ShopwareAuthCodeGrantType`). Integrationen: `client_credentials` mit `client_id` = Access-Key-ID (`SWIA...`), `client_secret` = Secret Access Key. TTL: Access-Token `PT10M`, Refresh-Token `P1W`, Auth-Code `PT5M`.
- **Generische Endpunkte** erzeugt `ApiRouteLoader` je Entity-Definition: `GET/POST /api/<entity>`, `GET/PATCH/DELETE /api/<entity>/{id}`, `POST /api/search/<entity>`, `/api/search-ids/<entity>`, `/api/aggregate/<entity>`. Entity-Name `snake_case` wird zu Pfad `kebab-case` (`product_manufacturer` -> `/api/product-manufacturer`).
- **Sync-API**: `POST /api/_action/sync` (`SyncController::sync`), Payload = Objekt aus Operationen `{entity, action: upsert|delete, payload|criteria}`.

Verzeichnisbaum (Plugin-relativ):

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Api/DemoController.php
    └── Resources/
        ├── config/
        │   ├── routes.php          # Attribut-Import der Controller
        │   └── services.php        # Controller als public Service
        └── app/administration/src/
            ├── main.js
            └── api/swag-example.api.service.js   # ApiService (-> T-26)
```

Entscheidungshilfe: Eigene Logik = eigener Controller unter `/api/_action/<plugin>/...`. Reine Datenpflege einer Entity = generische Endpunkte bzw. Sync-API, kein Controller nötig. Verbraucher sind Kunden/Storefront = Store-API ([T-30](./30-store-api-routen.md)).

## 3. Anleitung

1. **Controller** anlegen: Klassen-Route mit `_routeScope = ['api']`, Methoden-Routen mit Pfad `/api/_action/<plugin>/...`, eindeutigem `name` (`api.action.<plugin>.<x>`) und `methods`.
2. **Berechtigung** per `_acl` setzen: Entity-Privilegien (`product:read`, `product:update`) oder eigener String (Core-Beispiele: `api_send_email` in `MailActionController`, `integration:create` in `IntegrationController`). Eigene Strings müssen in der Rollenverwaltung über `sw-privileges` angeboten werden ([T-23](./23-acl-rechte-rollen.md)). Admin-Nutzer (`admin=true`) umgehen alles.
3. **`routes.php`** und **`services.php`** anlegen (Controller `public`, `setContainer`). PHP-Format ist belegt: `Bundle::configureRoutes` lädt `Resources/config/routes.php` bzw. `routes/*.php`.
4. Installieren und prüfen:
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample && bin/console cache:clear
   bin/console debug:router | grep swag_example
   ```
5. **Integration anlegen**: Administration -> Einstellungen -> System -> Integrationen (liefert Access-Key-ID und Secret). Backend: `IntegrationController` (`POST /api/integration`, `PATCH /api/integration/{id}`); Keys per `GET /api/_action/access-key/intergration` (Tippfehler im Pfad ist so im Code, `AccessKeyController`). Rollen über `aclRoles` oder `admin=true` (nur durch Admin setzbar, sonst `PermissionDeniedException`).
6. **Token holen**: `POST /api/oauth/token` mit JSON `grant_type=client_credentials`, `client_id`, `client_secret`. Rate-Limit `RateLimiter::OAUTH` (Konfig `shopware.api.rate_limiter`).
7. **Aufrufen** mit `Authorization: Bearer <token>`. Nützliche Header (`PlatformRequest`): `sw-language-id`, `sw-version-id`, `sw-include-seo-urls`, `sw-expect-packages`, `indexing-behavior`, `indexing-skip`, `indexing-only` (Sync).
8. **Aus der Administration**: `ApiService` mit `apiEndpoint = '_action/swag-example'` (ohne `/api`, da `httpClient.baseURL` = `/api`), siehe [T-26](./26-admin-datenhandling.md).
9. **API-Doku lokal**: `/api/_info/stoplightio.html`, OpenAPI über `api.info.openapi3` (`InfoController`), Entity-Schema `/api/_info/entity-schema.json`.

## 4. Beispiele

`src/Api/DemoController.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Api;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID]])]
class DemoController extends AbstractController
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection> $productRepository
     */
    public function __construct(private readonly EntityRepository $productRepository)
    {
    }

    #[Route(
        path: '/api/_action/swag-example/greet',
        name: 'api.action.swag_example.greet',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['product:read']],
        methods: [Request::METHOD_POST]
    )]
    public function greet(Request $request, Context $context): JsonResponse
    {
        $name = $request->request->getString('name', 'World');
        $count = $this->productRepository->searchIds(new Criteria(), $context)->getTotal();

        return new JsonResponse(['message' => \sprintf('Hello %s', $name), 'products' => $count]);
    }
}
```

`Context` wird per Argument-Resolver injiziert (wie in `SyncController::sync(Request, Context)`). JSON-Bodies landen über `JsonRequestTransformerListener` in `$request->request`.

`src/Resources/config/routes.php`

```php
<?php declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('../../Api/**/*Controller.php', 'attribute');
};
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use SwagExample\Api\DemoController;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()->set(DemoController::class)
        ->public()
        ->args([service('product.repository')])
        ->call('setContainer', [service('service_container')]);
};
```

Die Plugin-Basisklasse lädt `Resources/config/services.*` über `Bundle::registerContainerFile` (PHP/YAML/XML-Loader; XML deprecated; in `build()` aufgerufen). Das Muster `public()` + `setContainer` entspricht den Core-Controllern.

Aufruf mit Integration:

```bash
BASE=http://localhost:8000
TOKEN=$(curl -s $BASE/api/oauth/token -H 'Content-Type: application/json' \
  -d '{"grant_type":"client_credentials","client_id":"SWIA...","client_secret":"..."}' | jq -r .access_token)

curl -s -X POST $BASE/api/_action/swag-example/greet \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Mike"}'
```

Generische Endpunkte:

```bash
curl -H "Authorization: Bearer $TOKEN" "$BASE/api/product?limit=10&total-count-mode=1"
curl -X POST $BASE/api/search/product -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"limit":5,"filter":[{"type":"equals","field":"active","value":true}],"associations":{"manufacturer":{}},"total-count-mode":1}'
# weitere: POST /api/search-ids/product, POST /api/aggregate/product,
# GET /api/product/{id}/manufacturer, POST /api/_action/clone/product/{id}, POST /api/_action/version/product/{id}
```

Sync-API:

```bash
curl -X POST $BASE/api/_action/sync -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'indexing-behavior: use-queue-indexing' \
  -d '{
    "write-manufacturers": {"entity":"product_manufacturer","action":"upsert",
      "payload":[{"id":"0189...32hex","name":"ACME"}]},
    "del-old": {"entity":"product","action":"delete",
      "criteria":[{"type":"equals","field":"productNumber","value":"OLD-1"}]}
  }'
```

Struktur (`SyncOperation::createFromArray`): `entity` (Pflicht), `action` in {`upsert`,`delete`} (Pflicht), `payload` ODER `criteria` (mindestens eins nicht leer), optional `key` (sonst der JSON-Schlüssel). Antwort `SyncResult`: `data` (geschriebene IDs je Entity), `notFound`, `deleted`. `criteria` wirkt als Filterliste, ACL-Prüfung über `AclCriteriaValidator`.

Weitere Beispiele: Vorlagen für Plugin-Gerüst unter [../templates/plugin/README.md](../templates/plugin/README.md); Admin-seitiger Aufruf siehe [T-24 Admin-Module](./24-admin-module.md) und [T-26](./26-admin-datenhandling.md).

## 5. Fallstricke

- **`_routeScope` fehlt**: Request wird abgelehnt (`RoutingException::invalidRouteScope`). `AbstractRouteScope::$allowedPaths` begrenzt den `api`-Scope auf Pfade `/api/...`.
- **`_acl` vergessen**: Jeder authentifizierte Admin-API-Client (auch Integration mit beliebiger Rolle) darf die Route aufrufen. UI-Privilegien (`meta.privilege`) sichern nichts.
- **`auth_required => false` auf Admin-Routen**: Route ist ohne Token erreichbar. Nur für den Token-Endpunkt gedacht.
- **Controller nicht public / ohne `setContainer`**: `AbstractController`-Helfer schlagen fehl bzw. Route nicht auflösbar.
- **Generische Pfade**: `POST /api/product` mit vorhandener ID schlägt fehl; Upsert/Idempotenz nur per Sync-API oder PATCH.
- **Sync ohne `payload`/`criteria`** oder ungültige `action`: `ApiException::invalidSyncOperationException`. `payload` muss Liste von Assoc-Arrays sein (sonst 400 "Invalid payload").
- **Große Sync-Importe**: Indexierung kostet Zeit. `indexing-behavior: use-queue-indexing` oder `disable-indexing` (`EntityIndexerRegistry::USE_INDEXING_QUEUE/DISABLE_INDEXING`), `indexing-skip: product.indexer,...`.
- **`limit` > `shopware.api.max_limit`** (Default 500) wird begrenzt.
- **Token 10 min gültig**: Integrationen müssen bei 401 neu per Client Credentials anfordern.
- **Schreibschutz**: `integration.admin` ist `WriteProtected(SYSTEM_SCOPE)->allowWriteThroughAdminApi()`; direktes DAL-Schreiben scheitert, nur über Controller.
- **Pfad-Tippfehler**: `/api/_action/access-key/intergration` heißt wirklich so.

### Deprecations und Versionshinweise (6.5/6.6 -> 6.7)

- Attribute `#[Route]` (`Symfony\Component\Routing\Attribute\Route`); `@Route`/`@Acl`-Annotationen gibt es nicht mehr (6.5-Doku zeigt sie teils noch). `_acl` ist Route-Default.
- **XML-Routen/-Services/-Packages sind deprecated** (`@deprecated tag:v6.8.0`, `Bundle::triggerXmlConfigDeprecation`, in 6.8 nicht mehr geladen). PHP-Format verwenden.
- `/api/v1/...`-Prefix nicht mehr üblich; nur `/api/v1/_info/version` bleibt als Alt-Route (`InfoController`).
- `GET /api/_info/queue.json` ist `@deprecated tag:v6.8.0` (-> `message-stats.json`).
- Neu im 6.7-Code: SSO-Grants (`Shopware\Core\Framework\Sso`), `authorization_code` mit PKCE für Public-Clients (`shopware.api.oauth_clients`), Scope `user-verified`, `integration.mcp_allowlist` (JSON, siehe [T-44 MCP-Server](./44-mcp-server.md)). Ab welcher Minor-Version genau: nicht belegt.

## 6. Checkliste

- [ ] Klassen-Route mit `_routeScope = ['api']`, Pfad beginnt mit `/api/`
- [ ] Route-Name eindeutig (`api.action.<plugin>.<x>`), `methods` gesetzt
- [ ] `_acl` mit passenden Privilegien gesetzt
- [ ] Controller `public` + `setContainer` in `services.php`
- [ ] `routes.php` (PHP, nicht XML) importiert den Controller
- [ ] `debug:router` zeigt die Route nach Install und `cache:clear`
- [ ] Integration mit minimal nötiger Rolle angelegt (nicht `admin=true`)
- [ ] Bei Sync-Importen Indexing-Header bewusst gewählt
- [ ] Aufruf aus der Administration ohne `/api`-Prefix im `apiEndpoint`

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/integrations-api/
- https://developer.shopware.com/docs/guides/development/integrations-api/search-criteria.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/services-utilities/making-api-requests.html

Quellcode (`core/`):
- `Framework/Routing/ApiRouteScope.php`, `PlatformRequest.php`
- `Framework/Api/Controller/{ApiController,SyncController,AuthController,IntegrationController,AccessKeyController,InfoController}.php`
- `Framework/Api/Route/ApiRouteLoader.php`, `Framework/Api/Sync/{SyncService,SyncOperation,SyncResult}.php`
- `Framework/Api/OAuth/GrantTypeFactory.php`, `Framework/Api/EventListener/Authentication/ApiAuthenticationListener.php`
- `Framework/Api/Acl/{AclAnnotationValidator,AclCriteriaValidator}.php`, `Framework/Api/Context/AdminApiSource.php`
- `Framework/Bundle.php` (`configureRoutes`, `registerFilesystem`), `System/Integration/IntegrationDefinition.php`
- `Content/MailTemplate/Api/MailActionController.php` (Vorbild mit `_acl`), `Framework/Resources/config/packages/shopware.yaml` (`shopware.api.*`)

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Alle Beispiele wurden nicht gegen eine laufende Instanz getestet; Signaturen sind am Quellcode abgeglichen.
- Autoconfigure greift in Plugins mit `->defaults()->autowire()->autoconfigure()` (live bestätigt für Subscriber/Decorator). ⚠ UNGEPRÜFT: ob es für Controller statt `public` + `setContainer` reicht, wurde nicht gezielt geprüft.
- ⚠ UNGEPRÜFT: Der Import-Glob `../../Api/**/*Controller.php` in `routes.php` ist analog zu T-16 übernommen, nicht ausgeführt.
- ⚠ UNGEPRÜFT: Passwort-Grant (`client_id=administration`, `scopes=write`) stammt aus Wissen, nicht aus gelesenem Code. Ob `client_credentials` einen Refresh-Token liefert und ob Schreibzugriffe den Scope `write` je HTTP-Methode erzwingen, ist nicht geprüft.
- ⚠ LÜCKE: `ApiRouteInfoResolver` / OpenAPI-Verhalten für Plugin-Routen nur in der Existenz belegt.
- ⚠ LÜCKE: Sync-FK-Resolver-Syntax (`SyncFkResolver`) nur oberflächlich gelesen und daher nicht dokumentiert.
- ⚠ LÜCKE: Doku-Detailseiten zu Auth/Sync/Custom-API waren nicht abrufbar (404); Aussagen stammen aus dem Code.
