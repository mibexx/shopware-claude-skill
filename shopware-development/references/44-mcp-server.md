# MCP-Server-Erweiterung (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein Plugin eigene Tools, Prompts oder Resources für den Shopware-MCP-Server (Model Context Protocol) bereitstellen soll, damit KI-Clients (Claude Desktop, Cursor usw.) einen Shop bedienen können. **Einordnung: optional (Randthema) und experimentell** (`@experimental stableVersion:v6.8.0`, kein Feature-Flag). Für normale Plugin-, Theme- und Admin-Entwicklung nicht nötig.

Abgrenzung: Service-Registrierung und Tags allgemein in [T-05 Services](./05-services-registrieren.md); App-basierte MCP-Fähigkeiten (`mcp.xml`) nur zur Abgrenzung hier, Details in [T-10 App-System](./10-app-system-abgrenzung.md); Rechte/ACL in [T-23 ACL](./23-acl-rechte-rollen.md); Plugin-Gerüst in [T-02 Plugin-Aufbau](./02-plugin-aufbau.md).

## 2. Konzepte

- **Zwei Server:** Admin-API-Server `POST|GET|DELETE|OPTIONS /api/_mcp` (Route `api.mcp.endpoint`, `McpServerController`) und Store-API-Server `/store-api/_mcp` (Route `store-api.mcp.endpoint`, `StoreApiMcpServerController`).
- **Status:** in 6.7.15.1 immer aktiv (`symfony/mcp-bundle ~0.13.0`, `mcp/sdk ^0.8.1` in `require`). API kann sich bis 6.8.0 ändern.
- **Capabilities:** Tool (`#[McpTool]`), Prompt (`#[McpPrompt]`), Resource (`#[McpResource]`, alle aus `Mcp\Capability\Attribute`). Shopware-Zusatzattribute (`Shopware\Core\Framework\Mcp\Attribute`): `#[McpToolGroup]` (Toolset), `#[McpToolRequires]` (nur deklarativ!), `#[McpToolDependsOn]`.
- **Plugin-Tags:** `shopware.mcp.tool|prompt|resource` (Admin-Server), `shopware.store_api_mcp.tool|prompt|resource` (Store-API-Server). Der `McpToolDiscoveryCompilerPass` mappt sie auf `mcp.*` und weist sie dem Server zu. Das Tag `mcp.tool` direkt ist nur für In-Tree-Bundles (Namespace-Präfixe `Shopware\Core\Framework\Mcp\`, `Shopware\Storefront\Mcp\`).
- **Toolsets:** Die Gruppe aus `#[McpToolGroup('…')]` ist ein Toolset; ohne Gruppe: Fallback-Toolset `other`. Frische Sessions sehen nur Discovery-Tools (`shopware-tool-search`, `shopware-toolsets-list`, `shopware-toolset-enable`); alle anderen sind "deferred", bis das Toolset aktiviert ist (Session-Tool oder URL `?toolsets=a,b` / `?toolsets=all`).
- **Namensschema:** `{vendor}-{plugin}-{name}`, nur `a-zA-Z0-9_-`, keine Punkte; Präfix `shopware-` ist für den Core reserviert.
- **Allow-List, zwei Ebenen:** (1) pro Integration/Benutzer in der DB (`mcp_allowlist` JSON mit `tools`/`resources`/`prompts`; `null` = unbeschränkt, leeres Array = alles gesperrt); (2) global `shopware.mcp.allowed_tools` (Compile-Zeit, entfernt Tool-Services aus dem Container).
- **Authentifizierung Admin-Server:** Header `sw-access-key` + `sw-secret-access-key` (Integration oder Benutzer; andere Key-Origins werfen `McpException::unsupportedKeyType()`) **oder** Bearer-JWT. Store-API-Server: `sw-access-key` (Sales-Channel-Key), optional `sw-context-token`.

Plugin-Struktur:

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Mcp/{Tool/OrderListTool.php, Prompt/ExampleContextPrompt.php, Resource/ExampleConfigResource.php}
    └── Resources/config/services.php
```

Core-Referenz (nicht ändern): `core/Framework/Mcp/` (`AGENTS.md`, `Tool/McpToolResponse.php`, `McpToolsetRegistry.php`), `storefront/Mcp/Tool/ThemeConfigTool.php`.

## 3. Anleitung

1. Plugin anlegen (siehe T-02).
2. Tool-Klasse: `#[McpTool]` an der Klasse (Standard) oder an `__invoke()` (beides wird von `McpToolAttributeReader` gelesen: erst Klasse, dann `__invoke`; `McpToolGroup` erlaubt Klasse oder Methode, `McpToolRequires`/`McpToolDependsOn` nur Klasse), `extends McpToolResponse`, `__invoke()` mit Parametertypen `string|int|float|bool`, Beschreibung der Parameter per `@param`-Docblock; Rückgabe JSON-String über `$this->success($data, $meta)` oder `$this->error($msg)`.
3. Kontext immer über `McpContextProvider::getContext()`, nie `Context::createDefaultContext()`. Rechte zur Laufzeit mit `$this->requirePrivilege($context, '…')` erzwingen (`#[McpToolRequires]` erzwingt nichts).
4. `#[McpToolGroup('mein-plugin')]` setzen; optional `#[McpToolDependsOn('shopware-entity-schema')]` (unbekannte Namen brechen den Container-Build).
5. Service in `services.php` mit Tag `shopware.mcp.tool` registrieren (XML-Services sind in 6.7 deprecated, `Bundle.php`).
6. Installieren und prüfen:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear                       # Attribute sind im Container-Cache eingebacken
bin/console debug:mcp                         # beide Server; Spalten Group/Dependencies/Privileges
bin/console debug:mcp --scope=api
bin/console debug:mcp --native                # Sicht des MCP-Bundles inkl. nicht zugewiesener Capabilities
bin/console debug:mcp swag-example-orders     # Detailansicht
bin/console debug:mcp --integration SWIA...   # gefiltert nach Allow-List einer Integration
```

7. Authentifizieren: Integration anlegen (Administration, Einstellungen, System, Integrationen), Rolle mit nötigen Rechten zuweisen, Key/Secret im MCP-Client eintragen (`https://shop.example/api/_mcp`).
8. Allow-List: pro Integration über Administration "MCP-Allowlist bearbeiten" (Recht `integration_mcp.editor`; `POST /api/_action/integration/{id}/mcp-allowlist`); pro Benutzer `UserMcpAllowlistController`; global:

```yaml
# config/packages/shopware.yaml
shopware:
    mcp:
        allowed_tools:            # leer = alle erlaubt; fehlende Tools werden zur Compile-Zeit entfernt
            - shopware-entity-search
            - swag-example-orders
        app_tool_timeout: 10      # Sekunden für App-Webhook-Tools
```

9. Toolset im Client aktivieren: `https://shop.example/api/_mcp?toolsets=swag-example` oder Tool `shopware-toolset-enable`.
10. Store-API-Capabilities (kundenseitig): Tags `shopware.store_api_mcp.*`, Kontext über `StoreApiMcpContextProvider`; Klassen nicht unter einem Admin-Namespace-Präfix ablegen.

## 4. Beispiele

Dateien nach Doku und Core-Tool `SystemConfigReadTool` abgeglichen. ⚠ UNGEPRÜFT: nicht in einer laufenden Instanz ausgeführt (kein PHP/Docker lokal).

`src/SwagExample.php`

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
}
```

`src/Mcp/Tool/OrderListTool.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Mcp\Attribute\McpToolGroup;
use Shopware\Core\Framework\Mcp\Attribute\McpToolRequires;
use Shopware\Core\Framework\Mcp\Context\McpContextProvider;
use Shopware\Core\Framework\Mcp\Tool\McpToolResponse;

#[McpTool(
    name: 'swag-example-orders',
    title: 'Order List',
    description: 'List the most recent orders of a customer by e-mail address.'
)]
#[McpToolGroup('swag-example')]
#[McpToolRequires('order:read')]
class OrderListTool extends McpToolResponse
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     *
     * @internal
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly McpContextProvider $contextProvider,
    ) {
    }

    /**
     * @param string $email customer e-mail address
     * @param int $limit number of orders, newest first
     */
    public function __invoke(string $email, int $limit = 10): string
    {
        $context = $this->contextProvider->getContext();

        if ($error = $this->requirePrivilege($context, 'order:read')) {
            return $error;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderCustomer.email', $email));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(max(1, min($limit, 50)));

        $orders = $this->orderRepository->search($criteria, $context);

        $data = [];
        foreach ($orders->getEntities() as $order) {
            $data[] = ['id' => $order->getId(), 'orderNumber' => $order->getOrderNumber()];
        }

        return $this->success($data, ['total' => $orders->getTotal()]);
    }
}
```

`src/Mcp/Prompt/ExampleContextPrompt.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Mcp\Prompt;

use Mcp\Capability\Attribute\McpPrompt;

#[McpPrompt(
    name: 'swag-example-context',
    title: 'Example Context',
    description: 'Explains how to use the SwagExample tools.'
)]
class ExampleContextPrompt
{
    /**
     * @return list<array{role: string, content: string}>
     */
    public function __invoke(): array
    {
        return [
            ['role' => 'user', 'content' => 'Use swag-example-orders to look up recent orders by customer e-mail.'],
        ];
    }
}
```

`src/Mcp/Resource/ExampleConfigResource.php`

```php
<?php declare(strict_types=1);

namespace SwagExample\Mcp\Resource;

use Mcp\Capability\Attribute\McpResource;
use Shopware\Core\Framework\Util\Json;

#[McpResource(
    uri: 'swag-example://config',
    name: 'swag-example-config',
    description: 'Static example configuration.'
)]
class ExampleConfigResource
{
    /**
     * @return array{uri: string, mimeType: string, text: string}
     */
    public function __invoke(): array
    {
        return [
            'uri' => 'swag-example://config',
            'mimeType' => 'application/json',
            'text' => Json::encode(['key' => 'value']),
        ];
    }
}
```

`src/Resources/config/services.php`

```php
<?php declare(strict_types=1);

use Shopware\Core\Framework\Mcp\Context\McpContextProvider;
use Shopware\Core\Framework\Mcp\ToolResultCacheStorage;
use SwagExample\Mcp\Prompt\ExampleContextPrompt;
use SwagExample\Mcp\Resource\ExampleConfigResource;
use SwagExample\Mcp\Tool\OrderListTool;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(OrderListTool::class)
        ->args([service('order.repository'), service(McpContextProvider::class)])
        // optional: Ergebnis-Cache ab 100 KB (greift sonst nur fuer Core-Tools)
        ->call('setToolResultCache', [service(ToolResultCacheStorage::class), service('request_stack'), service('logger')])
        ->tag('shopware.mcp.tool');

    $services->set(ExampleContextPrompt::class)->tag('shopware.mcp.prompt');
    $services->set(ExampleConfigResource::class)->tag('shopware.mcp.resource');
};
```

Die Tag-Mappings `shopware.mcp.prompt|resource` auf `mcp.prompt|resource` stehen in `McpToolDiscoveryCompilerPass::process()` (verifiziert).

## 5. Fallstricke

- **Attributort:** `#[McpTool]` funktioniert an der Klasse und an `__invoke()` (`McpToolAttributeReader::resolveInfo/resolveAttribute`: erst Klasse, dann `__invoke`). `#[McpToolRequires]` und `#[McpToolDependsOn]` sind dagegen nur an der Klasse zulässig (`TARGET_CLASS`).
- **Falsches Tag** `mcp.tool` im Plugin: Klasse wird keinem Server zugewiesen und fehlt still; `debug:mcp` zeigt es in der Fußzeile.
- **Ungültige Namen / Doppelnamen:** nur `a-zA-Z0-9_-`; doppelte Namen pro Server brechen den Build (`detectToolNameConflicts`); `shopware-`-Präfix für App-Tools wird übersprungen.
- **Änderungen an Name/Beschreibung greifen nicht:** Attribute stecken im Container-Cache, `cache:clear`.
- **`McpToolRequires` ist nur informativ:** ohne `requirePrivilege()` und DAL-ACL ist das Tool für jeden erlaubten Aufrufer offen.
- **Unbehandelte Exceptions** ergeben einen undurchsichtigen JSON-RPC-Fehler `-32603`; erwartbare Fehler mit `$this->error()` zurückgeben. Schreibende Tools mit `executeWithDryRun()` und `bool $dryRun = true` bauen (`Tool/AGENTS.md`).
- **Viele Pflichtparameter:** Parameter ohne PHP-Default werden `required` im JSON-Schema; manche Modelle rufen das Tool dann nicht auf.
- **Große Antworten:** ab 100 KB (`MAX_RESPONSE_SIZE = 100_000` Bytes) legt `success()` sie in `mcp_tool_result_cache` ab und liefert die Resource `shopware://tool-result/{uuid}`, aber nur, wenn `setToolResultCache()` aufgerufen wurde und eine Session-ID (`Mcp-Session-Id`) vorliegt, sonst kommt die volle Antwort inline. Den Setter ruft der Core per `$services->instanceof(McpToolResponse::class)` in `core/Framework/DependencyInjection/mcp.php` auf; `instanceof` gilt in Symfony nur für Services derselben Konfigurationsdatei. Für Plugin-Tools greift der Cache daher nicht automatisch: im Plugin `->call('setToolResultCache', [service(ToolResultCacheStorage::class), service('request_stack'), service('logger')])` ergänzen (`Shopware\Core\Framework\Mcp\ToolResultCacheStorage`) oder die Antwortgröße selbst begrenzen (`limit`/`includes`).
- **Clients ohne `tools/listChanged`** (z. B. claude.ai): `?toolsets=` in der URL nutzen.
- **Rate Limit:** Admin-Server 300/60 s und 1000/10 min pro OAuth-Token, Store-API-Server 120/60 s und 600/10 min (`shopware.yaml`); Überschreitung gibt HTTP 429.
- **Admin-Flag und Allow-List:** `AGENTS.md` sagt, `admin = true` umgehe die Allow-List nicht; `docs/gaps-user-allowlist.md` sagt das Gegenteil. ⚠ LÜCKE: `McpAllowlistProvider` nicht abschließend geprüft.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- 6.5/6.6: keine MCP-Unterstützung im Core belegt (nur 6.7.15.1 lokal geprüft).
- 6.7.x: MCP neu, experimentell, **kein** Feature-Flag (frühere Doku nannte `MCP_SERVER`, existiert nicht mehr). Innerhalb 6.7 entwickelt: Toolsets, Store-API-Server, Per-User-Allow-List, Tool-Result-Cache; `scan_dirs` entfällt (mcp-bundle 0.12), Registrierung nur über DI-Tags.
- Geplant 6.8.0: Stabilisierung. Keine `@deprecated` in `core/Framework/Mcp/` gefunden (nicht vollständig per grep belegt). Allgemein deprecated: XML-Service-Definitionen (`@deprecated tag:v6.8.0`).

## 6. Checkliste

- [ ] Nötigkeit geklärt (Randthema, experimentell, API kann bis 6.8.0 brechen)
- [ ] `#[McpTool]` an der Klasse (oder an `__invoke()`), Name `{vendor}-{plugin}-{name}` ohne Punkte, kein `shopware-`-Präfix
- [ ] `extends McpToolResponse`, Rückgabe über `success()`/`error()`
- [ ] Kontext über `McpContextProvider`, `requirePrivilege()` aufgerufen
- [ ] `#[McpToolGroup]` gesetzt, Toolset im Client aktiviert (`?toolsets=`)
- [ ] Tag `shopware.mcp.tool|prompt|resource` (nicht `mcp.tool`) in `services.php`
- [ ] Schreibende Tools mit `dryRun`-Default
- [ ] `cache:clear` und `bin/console debug:mcp` geprüft
- [ ] Allow-List (Integration/Benutzer/global) und Rechte der Integration abgestimmt

## 7. Quellen

Doku: https://developer.shopware.com/docs/guides/plugins/plugins/mcp-server.html (existiert); https://developer.shopware.com/docs/guides/plugins/apps/mcp-server.html (App-Variante); https://developer.shopware.com/docs/products/tools/mcp-server/ (Übersicht; Unterseiten `configuration`, `extending`, `mcp-concepts`, `best-practices`); Beispiel-Plugin https://github.com/shopwareLabs/SwagMcpAdminUsers. `/docs/concepts/framework/mcp.html` liefert 404.

Quellcode (unter `vendor/shopware/`): `core/Framework/Mcp/AGENTS.md` (+ Unterordner-`AGENTS.md`), `Tool/McpToolResponse.php` (`success`, `error`, `requirePrivilege`, `executeWithDryRun`, `decodeJsonOrError`), `Tool/SystemConfigReadTool.php`, `Attribute/McpToolGroup|McpToolRequires|McpToolDependsOn.php`, `McpToolsetRegistry.php`, `AllowList/McpAllowlistProvider.php`, `Authentication/McpAuthenticationListener.php`, `Context/McpContextProvider.php`, `Controller/McpServerController.php`, `Command/DebugMcpCommand.php`, `core/Framework/DependencyInjection/CompilerPass/McpToolDiscoveryCompilerPass.php`, `McpToolAnalysisCompilerPass.php`, `core/Framework/DependencyInjection/Configuration.php` (`createMcpSection`), `core/Framework/Resources/config/packages/mcp.php`, `storefront/Mcp/Tool/ThemeConfigTool.php`, Migrationen `core/Migration/V6_7/Migration1772626151AppMcpTool.php` u. a.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Einführungsversion nicht belegt; nur "vorhanden in 6.7.15.1, experimentell" aussagen (V6_7-Migrationen deuten auf Einführung innerhalb 6.7).
- ⚠ UNGEPRÜFT: Beispiele nicht ausgeführt; `orderCustomer.email`/`OrderCollection` nicht gegen die Definition geprüft.
- ⚠ LÜCKE: Widersprüche in `AGENTS.md` (Code gilt): "kein Store-API-MCP-Endpoint" ist veraltet; Header-Pflicht nennt Bearer-JWT nicht; `custom/bundles/SwagMcpExampleBundle` existiert nicht; SDK-Versionsangaben veraltet; Fallback "unbeschränkt" bei `client_id = administration` evtl. älter als Per-User-Allow-List.
- ⚠ UNGEPRÜFT: Doku-Unterseiten `extending`/`configuration`/`mcp-concepts`, Beispiel-Plugin `SwagMcpAdminUsers`, Admin-UI der Allow-List, `McpToolsetSessionStorage`.
