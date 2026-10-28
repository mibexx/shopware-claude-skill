# Plugin configuration in the backend (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a plugin should be configurable in the Admin (`config.xml`), when configuration values are read/set (PHP, Twig, CLI, API) or when values should differ per sales channel. It covers the mechanics of sales channel inheritance.

Scope: how code detects the current sales channel and branches on it: [T-20 Sales channel distinction](./20-sales-channel-distinction.md). Plugin scaffold and lifecycle: [T-02](./02-plugin-structure.md), [T-03](./03-plugin-lifecycle.md). Events in general: [T-07](./07-events-subscriber.md). Theme configuration (`theme.json`): [T-12](./12-theme-config-scss-variables.md). Scaffold: [../templates/config/README.md](../templates/config/README.md).

## 2. Concepts

- **File:** `src/Resources/config/config.xml`, validated by the `ConfigReader` against `core/System/SystemConfig/Schema/config.xsd`. Additional files via `getConfigFromBundle($bundle, 'name')` (`Resources/config/name.xml`).
- **Structure:** `<config>` → `<card>`+ → `<title lang>`, optional `<subtitle>`, `<name>`, `<ai-badge>`, `<flag>` → `<input-field type="…">` or `<component name="…">`.
- **Allowed `type` values (XSD 6.7.15.1):** `text` (default), `textarea`, `text-editor`, `url`, `password`, `int`, `float`, `bool`, `checkbox`, `datetime`, `date`, `time`, `colorpicker`, `single-select`, `multi-select`, `price`. `sw-entity-single-select` is **not** a `type`, but `<component name="sw-entity-single-select">` with `<entity>`.
- **Storage:** table `system_config`, key `<PluginName>.config.<fieldName>`, value as JSON `{"_value": …}`, `sales_channel_id` NULL = global. Plugin name = class name of the plugin.
- **Inheritance:** the sales channel value overrides the global one. **Empty** SC values (null, `''`, `0`, `[]`; booleans excepted) do NOT override (`SystemConfigLoader::getSubArray`).
- **Defaults** (`<defaultValue>`): written on install (override) and update (only missing keys); deleted on uninstall without `keepUserData`, including all SC overrides.
- **Loader chain:** `SystemConfigLoader` (DB) → `CachedSystemConfigLoader` (key `system-config-<scId>`) → `ConfiguredSystemConfigLoader` (YAML overrides) → `MemoizedSystemConfigLoader` (outermost layer, request memo). The order results from the decorator priorities in `core/System/DependencyInjection/configuration.php` (-1000 / -1500 / -2000; `SystemConfigService` calls the outermost decorator). Inactive plugins are filtered out.

Directory tree:

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Resources/config/config.xml        # Keys: SwagExample.config.*
    ├── Resources/config/services.php
    ├── Service/PriceLabelService.php
    └── Subscriber/ConfigSubscriber.php
```

Decision guide: fixed, read-only values per environment -> YAML `shopware.system_config`; changeable by the merchant -> `config.xml`; complex structure/custom UI -> custom Admin module ([T-24](./24-admin-modules.md)).

## 3. Instructions

1. Create `src/Resources/config/config.xml` (at least one `card` with `title` and one field).
2. Install: `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`. After changing the config.xml of an already installed plugin: `bin/console plugin:update SwagExample` (writes only **missing** default keys; `PluginLifecycleService`: `savePluginConfiguration($plugin, true)` on install, `savePluginConfiguration($plugin)` on update).
3. Admin: Extensions → My extensions → plugin → "Configure" (route `config/:namespace`, page `sw-extension-config`, renders `sw-system-config`). At the top a sales channel switcher ("All Sales Channels" = global, otherwise override with inherit icon).
4. Read via CLI: `bin/console system:config:get SwagExample.config.badgeText [-s <salesChannelId>]`.
5. Set via CLI: `bin/console system:config:set SwagExample.config.maxItems 10 --json [-s <salesChannelId>] [--silent]` (`--json` for bool/int/float).
6. In code: `SystemConfigService::get|getString|getInt|getFloat|getBool($key, ?string $salesChannelId = null)`; the ID from `$salesChannelContext->getSalesChannelId()`.
7. Fixed value per environment (read-only in the Admin): `config/packages/*.yaml`
   ```yaml
   shopware:
       system_config:
           default:                                # or sales channel UUID
               'SwagExample.config.mode': simple
   ```
   The Admin cannot change these keys (`setMultiple` throws `systemConfigKeyIsManagedBySystems`, unless the value is identical).
8. HTTP API: `GET /api/_action/system-config?domain=SwagExample.config&salesChannelId=…`, `POST /api/_action/system-config`, `POST …/batch`, `GET …/schema`, `GET …/check`.

### Field options
- Translatable (`lang`, fallback `en-GB`): `label`, `placeholder`, `helpText`.
- Boolean: `copyable`, `disabled`, `required`. Also `options` (selects only) and `defaultValue` (typed: bool/checkbox→bool, int, float, multi-select→array, otherwise string).
- Further child elements (`min`, `max`, `minLength`, `maxLength`, `entity`, …) are passed through unchanged by the PHP reader as a `config` option (`xs:any lax`); their evaluation happens in the Admin component.
- Attribute `cache-relevant="true"` on `input-field`/`component` (reader: `cacheRelevant` in the field `config`): if the Admin changes at least one field marked this way when saving (`sw-system-config` `saveAll`), it sends `silent=false` to `/api/_action/system-config/batch`, i.e. `SystemConfigService` invalidates the cache (`CacheInvalidationSubscriber`; with `silent=true` the invalidation is skipped). Without a marked change it sends no `silent` parameter; the default applies (currently `false`, with `CACHE_REWORK`/from 6.8 `true`). The marking only takes effect when saving in the Admin, not for `set()` via code. Docs: "since 6.7.12.0".
- Card: `<flag>` (feature flag), `<ai-badge>` (boolean, only confirmed in the XSD).
- Custom component: `<component name="sw-…">`, core examples `sw-entity-single-select`, `sw-entity-multi-id-select`, `sw-cms-page-select`, `sw-system-config-media-upload`. Custom components must be registered in the Admin ([T-25](./25-admin-components.md)).
- Field name: regex `[a-zA-Z][a-zA-Z0-9]*`, unique per file for `input-field`.

## 4. Examples

`src/Resources/config/config.xml`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="https://raw.githubusercontent.com/shopware/shopware/trunk/src/Core/System/SystemConfig/Schema/config.xsd">
    <card>
        <title>General</title>
        <title lang="de-DE">Allgemein</title>

        <input-field type="bool">
            <name>enabled</name>
            <label>Enable feature</label>
            <label lang="de-DE">Funktion aktivieren</label>
            <defaultValue>true</defaultValue>
        </input-field>

        <input-field type="text">
            <name>badgeText</name>
            <label>Badge text</label>
            <label lang="de-DE">Badge-Text</label>
            <placeholder>New</placeholder>
            <defaultValue>New</defaultValue>
            <helpText>Shown on product boxes.</helpText>
        </input-field>

        <input-field type="int">
            <name>maxItems</name>
            <label>Max items</label>
            <defaultValue>5</defaultValue>
            <min>1</min>
            <max>20</max>
        </input-field>

        <input-field type="single-select">
            <name>mode</name>
            <label>Mode</label>
            <defaultValue>simple</defaultValue>
            <options>
                <option><id>simple</id><name>Simple</name><name lang="de-DE">Einfach</name></option>
                <option><id>extended</id><name>Extended</name><name lang="de-DE">Erweitert</name></option>
            </options>
        </input-field>

        <input-field type="password">
            <name>apiKey</name>
            <label>API key</label>
        </input-field>

        <component name="sw-entity-single-select">
            <name>defaultCountryId</name>
            <entity>country</entity>
            <label>Country</label>
        </component>
    </card>
</config>
```

`src/Service/PriceLabelService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class PriceLabelService
{
    private const DOMAIN = 'SwagExample.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function label(?string $salesChannelId): string
    {
        if (!$this->systemConfigService->getBool(self::DOMAIN . 'enabled', $salesChannelId)) {
            return '';
        }

        return $this->systemConfigService->getString(self::DOMAIN . 'badgeText', $salesChannelId);
    }
}
```

`src/Resources/config/services.php` (`services.xml` is deprecated in 6.7, see [T-05](./05-registering-services.md))
```php
<?php declare(strict_types=1);

use Shopware\Core\System\SystemConfig\SystemConfigService;
use SwagExample\Service\PriceLabelService;
use SwagExample\Subscriber\ConfigSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(PriceLabelService::class)
        ->args([service(SystemConfigService::class)]);

    $services->set(ConfigSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

`src/Subscriber/ConfigSubscriber.php` (normalize a value before saving, react to a change)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConfigSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSystemConfigChangedEvent::class => 'normalize',
            SystemConfigChangedEvent::class => 'changed',
        ];
    }

    public function normalize(BeforeSystemConfigChangedEvent $event): void
    {
        if ($event->getKey() === 'SwagExample.config.badgeText' && \is_string($event->getValue())) {
            $event->setValue(trim($event->getValue()));
        }
    }

    public function changed(SystemConfigChangedEvent $event): void
    {
        if ($event->getKey() !== 'SwagExample.config.mode') {
            return;
        }
        // $event->getValue(), $event->getSalesChannelId() (null = global); also on deletion (value null)
    }
}
```

Twig (storefront; the sales channel is determined from the context):
```twig
{% if config('SwagExample.config.enabled') %}
    <span class="badge">{{ config('SwagExample.config.badgeText') }}</span>
{% endif %}
```
Setting via code: `$this->systemConfigService->set('SwagExample.config.mode', 'extended', $salesChannelId);`

Further events: `BeforeSystemConfigMultipleChangedEvent`, `SystemConfigMultipleChangedEvent`, `SystemConfigDomainLoadedEvent`, hook `SystemConfigChangedHook` (`app.config.changed`, for apps/webhooks).

## 5. Pitfalls

- Key without `.config.` or wrong plugin name: correct is `SwagExample.config.enabled`.
- `<input-field type="sw-entity-single-select">` -> XSD error; use `<component name="…">` instead.
- Field name with hyphen/underscore or a digit at the start -> XSD error; duplicate `input-field` names violate `uniqueInputFieldName`.
- New field with default missing after update: run `plugin:update` (defaults only for non-existing keys).
- Forcing a per-SC override to "empty/0/''" is not possible (inherits global). Boolean `false`, however, does override.
- `get()` returns `null` if never saved and without `defaultValue`; `getString()`=`''`, `getInt()`=0, `getBool()`=false. Additionally guard defaults in code.
- `getString/getInt/…` throw `SystemConfigException::invalidSettingValueException` for array values (multi-select) -> use `get()`.
- `all()` and `getDomain()` are `@internal`, do not use them in the storefront/Store API (cache tags).
- `$salesChannelId` forgotten: global configuration only, SC overrides do not apply.
- Twig `config()` without a SalesChannelContext (e.g. mail templates without context) returns the global value.
- Configuration not visible in the Admin: plugin not active, XML invalid or cache (`bin/console cache:clear`).
- `SystemConfigChangedEvent` only has getters; change before saving via `BeforeSystemConfigChangedEvent::setValue()`.
- Uninstall without "Keep data" deletes values including all SC overrides.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `SystemConfigService::set/setMultiple/delete`: optional parameter `bool $silent`, default currently `false` (cache invalidation), from 6.8 / feature `CACHE_REWORK` `true` (`#[NewOptionalParameter(v6.8.0)]`).
- `SystemConfigService::trace()` / `getTrace()` `@deprecated tag:v6.8.0` (internally replaced by `CacheTagCollector`).
- Twig `config()`: static keys `seo.descriptionMaxLength`, `cms.revocationNoticeCmsPageId`, `cms.taxCmsPageId`, `cms.tosCmsPageId`, `confirm.revocationNotice` deprecated (v6.8.0); replacement e.g. `core.basicInformation.revocationPage` / `tosPage`.
- `cache-relevant` according to the docs since 6.7.12.0; present in the code (effect see the Field options section).
- `services.xml` and `routes.xml` in plugins are deprecated (removal v6.8.0.0, `Bundle.php`) -> `services.php`/`routes.php`.
- XSD types `price`, `textarea`, `text-editor`, `url`, `datetime`, `time`, `checkbox` and `ai-badge` exist in 6.7.15.1; introduction version not checked.
- The Admin partly renders config fields with Meteor components (`isMeteorComponent` in `sw-system-config/index.js`). In 6.5 the page was called `sw-plugin-config`, in 6.7 `sw-extension-config` (6.5 detail from memory, not checked against the code).

## 6. Checklist

- [ ] `config.xml` under `src/Resources/config/` and XSD-compliant (types from the list, names `[a-zA-Z][a-zA-Z0-9]*`).
- [ ] Entity selects as `<component name="sw-entity-single-select">`, not as `type`.
- [ ] Keys used everywhere as `<PluginName>.config.<field>`.
- [ ] `<defaultValue>` set for every field, additionally guarded in code.
- [ ] `$salesChannelId` passed on every read access (from the context).
- [ ] Array values (multi-select) read via `get()`, not `getString()`.
- [ ] After a config.xml change, `plugin:update` and `cache:clear` executed.
- [ ] No reliance on "empty overrides global" per sales channel.
- [ ] Secrets (API keys) as a `password` field, not output in Twig/frontend.
- [ ] Custom `<component>` types registered in the Admin and built.

## 7. Sources

- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/add-plugin-configuration.html
- https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/use-plugin-configuration.html
- Code (`core/System/SystemConfig/`): `SystemConfigService`, `Util/ConfigReader`, `Schema/config.xsd`, `SystemConfigLoader`, `CachedSystemConfigLoader`, `MemoizedSystemConfigLoader`, `ConfiguredSystemConfigLoader` (wiring: `core/System/DependencyInjection/configuration.php`), `Event/*`, `Command/ConfigGet`, `ConfigSet`, `Api/SystemConfigController`.
- `core/Framework/Adapter/Twig/Extension/ConfigExtension.php`; `core/Framework/Plugin/PluginLifecycleService.php`; `core/System/Resources/config/cart.xml`, `basicInformation.xml`; `core/Framework/DependencyInjection/Configuration.php` (`system_config`).
- `administration/Resources/app/administration/src/module/sw-settings/component/sw-system-config/index.js`, `sw-extension/page/sw-extension-config/`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: Examples not run in a live installation, only checked against the XSD/code.
- ⚠ GAP: The docs state `name` with "4+ characters", the XSD only `[a-zA-Z][a-zA-Z0-9]*`; the code is authoritative, no minimum length found in the code.
- ⚠ GAP: Evaluation of `min`/`max`/`minLength`/`maxLength`/`entity` in the Admin components not checked individually; effect of `<ai-badge>` not examined in depth.
- ⚠ UNVERIFIED: Statements about 6.5/6.6 (page name, introduction version of the types) cannot be confirmed against the code (only 6.7.15.1 installed).
- ⚠ UNVERIFIED: Order of events in `setMultiple` (per key `SystemConfigChangedEvent`, then once `SystemConfigMultipleChangedEvent`) read from the code, not checked at runtime.
