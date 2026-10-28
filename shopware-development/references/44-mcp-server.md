# MCP server extension (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin should provide its own tools, prompts or resources for the Shopware MCP server (Model Context Protocol), so that AI clients (Claude Desktop, Cursor, etc.) can operate a shop. **Classification: optional (fringe topic) and experimental** (`@experimental stableVersion:v6.8.0`, no feature flag). Not needed for normal plugin, theme and admin development.

Scope: service registration and tags in general in [T-05 Services](./05-registering-services.md); app-based MCP capabilities (`mcp.xml`) only for delimitation here, details in [T-10 App system](./10-app-system-scope.md); permissions/ACL in [T-23 ACL](./23-acl-permissions-roles.md); plugin scaffold in [T-02 Plugin structure](./02-plugin-structure.md).

## 2. Concepts

- **Two servers:** Admin API server `POST|GET|DELETE|OPTIONS /api/_mcp` (route `api.mcp.endpoint`, `McpServerController`) and Store API server `/store-api/_mcp` (route `store-api.mcp.endpoint`, `StoreApiMcpServerController`).
- **Status:** always active in 6.7.15.1 (`symfony/mcp-bundle ~0.13.0`, `mcp/sdk ^0.8.1` in `require`). The API may change until 6.8.0.
- **Capabilities:** tool (`#[McpTool]`), prompt (`#[McpPrompt]`), resource (`#[McpResource]`, all from `Mcp\Capability\Attribute`). Shopware additional attributes (`Shopware\Core\Framework\Mcp\Attribute`): `#[McpToolGroup]` (toolset), `#[McpToolRequires]` (declarative only!), `#[McpToolDependsOn]`.
- **Plugin tags:** `shopware.mcp.tool|prompt|resource` (admin server), `shopware.store_api_mcp.tool|prompt|resource` (Store API server). `McpToolDiscoveryCompilerPass` maps them to `mcp.*` and assigns them to the server. The tag `mcp.tool` directly is only for in-tree bundles (namespace prefixes `Shopware\Core\Framework\Mcp\`, `Shopware\Storefront\Mcp\`).
- **Toolsets:** The group from `#[McpToolGroup('…')]` is a toolset; without a group: fallback toolset `other`. Fresh sessions only see discovery tools (`shopware-tool-search`, `shopware-toolsets-list`, `shopware-toolset-enable`); all others are "deferred" until the toolset is enabled (session tool or URL `?toolsets=a,b` / `?toolsets=all`).
- **Naming scheme:** `{vendor}-{plugin}-{name}`, only `a-zA-Z0-9_-`, no dots; the prefix `shopware-` is reserved for the core.
- **Allow list, two levels:** (1) per integration/user in the DB (`mcp_allowlist` JSON with `tools`/`resources`/`prompts`; `null` = unrestricted, empty array = everything blocked); (2) global `shopware.mcp.allowed_tools` (compile time, removes tool services from the container).
- **Authentication admin server:** headers `sw-access-key` + `sw-secret-access-key` (integration or user; other key origins throw `McpException::unsupportedKeyType()`) **or** Bearer JWT. Store API server: `sw-access-key` (sales channel key), optionally `sw-context-token`.

Plugin structure:

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Mcp/{Tool/OrderListTool.php, Prompt/ExampleContextPrompt.php, Resource/ExampleConfigResource.php}
    └── Resources/config/services.php
```

Core reference (do not modify): `core/Framework/Mcp/` (`AGENTS.md`, `Tool/McpToolResponse.php`, `McpToolsetRegistry.php`), `storefront/Mcp/Tool/ThemeConfigTool.php`.

## 3. Instructions

1. Create a plugin (see T-02).
2. Tool class: `#[McpTool]` on the class (standard) or on `__invoke()` (both are read by `McpToolAttributeReader`: first class, then `__invoke`; `McpToolGroup` allows class or method, `McpToolRequires`/`McpToolDependsOn` class only), `extends McpToolResponse`, `__invoke()` with parameter types `string|int|float|bool`, parameter descriptions via `@param` docblock; return a JSON string via `$this->success($data, $meta)` or `$this->error($msg)`.
3. Always get the context via `McpContextProvider::getContext()`, never `Context::createDefaultContext()`. Enforce permissions at runtime with `$this->requirePrivilege($context, '…')` (`#[McpToolRequires]` enforces nothing).
4. Set `#[McpToolGroup('my-plugin')]`; optionally `#[McpToolDependsOn('shopware-entity-schema')]` (unknown names break the container build).
5. Register the service in `services.php` with tag `shopware.mcp.tool` (XML services are deprecated in 6.7, `Bundle.php`).
6. Install and check:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/console cache:clear                       # attributes are baked into the container cache
bin/console debug:mcp                         # both servers; columns Group/Dependencies/Privileges
bin/console debug:mcp --scope=api
bin/console debug:mcp --native                # MCP bundle view incl. unassigned capabilities
bin/console debug:mcp swag-example-orders     # detail view
bin/console debug:mcp --integration SWIA...   # filtered by an integration's allow list
```

7. Authenticate: create an integration (Administration, Settings, System, Integrations), assign a role with the required permissions, enter key/secret in the MCP client (`https://shop.example/api/_mcp`).
8. Allow list: per integration via Administration "Edit MCP allowlist" (permission `integration_mcp.editor`; `POST /api/_action/integration/{id}/mcp-allowlist`); per user `UserMcpAllowlistController`; global:

```yaml
# config/packages/shopware.yaml
shopware:
    mcp:
        allowed_tools:            # empty = all allowed; missing tools are removed at compile time
            - shopware-entity-search
            - swag-example-orders
        app_tool_timeout: 10      # seconds for app webhook tools
```

9. Enable the toolset in the client: `https://shop.example/api/_mcp?toolsets=swag-example` or tool `shopware-toolset-enable`.
10. Store API capabilities (customer-facing): tags `shopware.store_api_mcp.*`, context via `StoreApiMcpContextProvider`; do not place classes under an admin namespace prefix.

## 4. Examples

Files cross-checked against the docs and the core tool `SystemConfigReadTool`. ⚠ UNVERIFIED: not executed in a running instance (no PHP/Docker locally).

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
        // optional: result cache from 100 KB (otherwise only applies to core tools)
        ->call('setToolResultCache', [service(ToolResultCacheStorage::class), service('request_stack'), service('logger')])
        ->tag('shopware.mcp.tool');

    $services->set(ExampleContextPrompt::class)->tag('shopware.mcp.prompt');
    $services->set(ExampleConfigResource::class)->tag('shopware.mcp.resource');
};
```

The tag mappings `shopware.mcp.prompt|resource` to `mcp.prompt|resource` are in `McpToolDiscoveryCompilerPass::process()` (verified).

## 5. Pitfalls

- **Attribute location:** `#[McpTool]` works on the class and on `__invoke()` (`McpToolAttributeReader::resolveInfo/resolveAttribute`: first class, then `__invoke`). `#[McpToolRequires]` and `#[McpToolDependsOn]`, in contrast, are only allowed on the class (`TARGET_CLASS`).
- **Wrong tag** `mcp.tool` in the plugin: the class is not assigned to any server and is silently missing; `debug:mcp` shows it in the footer.
- **Invalid names / duplicate names:** only `a-zA-Z0-9_-`; duplicate names per server break the build (`detectToolNameConflicts`); the `shopware-` prefix is skipped for app tools.
- **Changes to name/description do not take effect:** attributes sit in the container cache, `cache:clear`.
- **`McpToolRequires` is informational only:** without `requirePrivilege()` and DAL ACL the tool is open to every allowed caller.
- **Unhandled exceptions** produce an opaque JSON-RPC error `-32603`; return expected errors with `$this->error()`. Build writing tools with `executeWithDryRun()` and `bool $dryRun = true` (`Tool/AGENTS.md`).
- **Many required parameters:** parameters without a PHP default become `required` in the JSON schema; some models then do not call the tool.
- **Large responses:** from 100 KB (`MAX_RESPONSE_SIZE = 100_000` bytes) `success()` stores them in `mcp_tool_result_cache` and returns the resource `shopware://tool-result/{uuid}`, but only if `setToolResultCache()` was called and a session ID (`Mcp-Session-Id`) is present; otherwise the full response comes inline. The core calls the setter via `$services->instanceof(McpToolResponse::class)` in `core/Framework/DependencyInjection/mcp.php`; in Symfony `instanceof` only applies to services of the same configuration file. For plugin tools the cache therefore does not take effect automatically: add `->call('setToolResultCache', [service(ToolResultCacheStorage::class), service('request_stack'), service('logger')])` in the plugin (`Shopware\Core\Framework\Mcp\ToolResultCacheStorage`) or limit the response size yourself (`limit`/`includes`).
- **Clients without `tools/listChanged`** (e.g. claude.ai): use `?toolsets=` in the URL.
- **Rate limit:** admin server 300/60 s and 1000/10 min per OAuth token, Store API server 120/60 s and 600/10 min (`shopware.yaml`); exceeding it returns HTTP 429.
- **Admin flag and allow list:** `AGENTS.md` says `admin = true` does not bypass the allow list; `docs/gaps-user-allowlist.md` says the opposite. ⚠ GAP: `McpAllowlistProvider` not conclusively checked.

### Deprecations and version notes (6.5/6.6 → 6.7)

- 6.5/6.6: no MCP support in the core confirmed (only 6.7.15.1 checked locally).
- 6.7.x: MCP new, experimental, **no** feature flag (earlier docs mentioned `MCP_SERVER`, which no longer exists). Developed within 6.7: toolsets, Store API server, per-user allow list, tool result cache; `scan_dirs` dropped (mcp-bundle 0.12), registration only via DI tags.
- Planned 6.8.0: stabilization. No `@deprecated` found in `core/Framework/Mcp/` (not fully confirmed via grep). Generally deprecated: XML service definitions (`@deprecated tag:v6.8.0`).

## 6. Checklist

- [ ] Necessity clarified (fringe topic, experimental, API may break until 6.8.0)
- [ ] `#[McpTool]` on the class (or on `__invoke()`), name `{vendor}-{plugin}-{name}` without dots, no `shopware-` prefix
- [ ] `extends McpToolResponse`, return via `success()`/`error()`
- [ ] Context via `McpContextProvider`, `requirePrivilege()` called
- [ ] `#[McpToolGroup]` set, toolset enabled in the client (`?toolsets=`)
- [ ] Tag `shopware.mcp.tool|prompt|resource` (not `mcp.tool`) in `services.php`
- [ ] Writing tools with `dryRun` default
- [ ] `cache:clear` and `bin/console debug:mcp` checked
- [ ] Allow list (integration/user/global) and integration permissions aligned

## 7. Sources

Docs: https://developer.shopware.com/docs/guides/plugins/plugins/mcp-server.html (exists); https://developer.shopware.com/docs/guides/plugins/apps/mcp-server.html (app variant); https://developer.shopware.com/docs/products/tools/mcp-server/ (overview; subpages `configuration`, `extending`, `mcp-concepts`, `best-practices`); example plugin https://github.com/shopwareLabs/SwagMcpAdminUsers. `/docs/concepts/framework/mcp.html` returns 404.

Source code (under `vendor/shopware/`): `core/Framework/Mcp/AGENTS.md` (+ subfolder `AGENTS.md`), `Tool/McpToolResponse.php` (`success`, `error`, `requirePrivilege`, `executeWithDryRun`, `decodeJsonOrError`), `Tool/SystemConfigReadTool.php`, `Attribute/McpToolGroup|McpToolRequires|McpToolDependsOn.php`, `McpToolsetRegistry.php`, `AllowList/McpAllowlistProvider.php`, `Authentication/McpAuthenticationListener.php`, `Context/McpContextProvider.php`, `Controller/McpServerController.php`, `Command/DebugMcpCommand.php`, `core/Framework/DependencyInjection/CompilerPass/McpToolDiscoveryCompilerPass.php`, `McpToolAnalysisCompilerPass.php`, `core/Framework/DependencyInjection/Configuration.php` (`createMcpSection`), `core/Framework/Resources/config/packages/mcp.php`, `storefront/Mcp/Tool/ThemeConfigTool.php`, migrations `core/Migration/V6_7/Migration1772626151AppMcpTool.php` among others.

### Gaps and uncertainties

- ⚠ GAP: Introduction version not confirmed; only state "present in 6.7.15.1, experimental" (V6_7 migrations suggest introduction within 6.7).
- ⚠ UNVERIFIED: Examples not executed; `orderCustomer.email`/`OrderCollection` not checked against the definition.
- ⚠ GAP: Contradictions in `AGENTS.md` (the code is authoritative): "no Store API MCP endpoint" is outdated; the header requirement does not mention Bearer JWT; `custom/bundles/SwagMcpExampleBundle` does not exist; SDK version information outdated; fallback "unrestricted" for `client_id = administration` possibly older than the per-user allow list.
- ⚠ UNVERIFIED: Docs subpages `extending`/`configuration`/`mcp-concepts`, example plugin `SwagMcpAdminUsers`, admin UI of the allow list, `McpToolsetSessionStorage`.
