# Template: config (plugin configuration)

Shopware 6.7.15.1. `config.xml` with all field types allowed according to the XSD and the common components. The admin UI (Extensions > My extensions > Configure) is generated from it.

## File tree

```
SwagExample/
└── src/
    └── Resources/
        └── config/
            └── config.xml
```

Root scaffold (composer.json, plugin class, services.php): ../plugin/README.md

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample` | Plugin name = bundle name; determines the key `SwagExample.config.<field>` | your own plugin name |
| `<name>` per field | Regex `[a-zA-Z][a-zA-Z0-9]*`, unique across all `input-field` elements of the file | your own field names |
| Demo fields (`enabled`, `badgeText`, ...) | examples only | delete/replace |

## Structure (from `config.xsd`)

`<config>` > `<card>`+ > `<title lang>`, optional `<subtitle>`, `<name>`, `<ai-badge>`, `<flag>`, then `<input-field type="...">` or `<component name="...">`.

Allowed `type` values (6.7.15.1): `text` (default), `textarea`, `text-editor`, `url`, `password`, `int`, `float`, `bool`, `checkbox`, `datetime`, `date`, `time`, `colorpicker`, `single-select`, `multi-select`, `price`. All are included in the template. `sw-entity-single-select` is NOT a `type`, but `<component name="sw-entity-single-select">` with `<entity>`.

Field options: translatable (`lang`, fallback `en-GB`): `label`, `placeholder`, `helpText`; boolean: `copyable`, `disabled`, `required`; `options` (select only); `defaultValue` (typed: bool/checkbox, int, float, multi-select as array, otherwise string). Further child elements (`min`, `max`, `minLength`, `maxLength`, `entity`, ...) are passed through unchanged as options. Attribute `cache-relevant="true"` on `input-field`/`component` (according to the docs since 6.7.12.0).

Core component examples: `sw-entity-single-select`, `sw-entity-multi-id-select`, `sw-cms-page-select`, `sw-system-config-media-upload` (see `core/System/Resources/config/*.xml`). Custom components must be registered in the admin.

## Integration / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate -c SwagExample   # writes defaults (override = true)
bin/console plugin:update SwagExample                  # writes only MISSING default keys
bin/console system:config:get SwagExample.config.badgeText [-s <salesChannelId>]
bin/console system:config:set SwagExample.config.maxItems 10 --json [-s <salesChannelId>]
```

New fields with a default appear in existing installations only after `plugin:update` (existing values are kept). Without activation/with invalid XML the configuration is not visible in the admin.

## Reading in code

Excerpt for `src/Resources/config/services.php` (service only as an example, not included in this template):

```php
$services->set(\SwagExample\Service\PriceLabelService::class)
    ->args([service(\Shopware\Core\System\SystemConfig\SystemConfigService::class)]);
```

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

Twig (storefront, sales channel automatically from the context): `{{ config('SwagExample.config.badgeText') }}`.

Intercepting changes: `BeforeSystemConfigChangedEvent` (`getKey()`, `setValue()`) and `SystemConfigChangedEvent` (getters only) in a subscriber, for registration see ../event-subscriber/README.md.

## Important rules

- Inheritance: the sales channel value overrides the global value; empty values (`null`, `''`, `0`, `[]`) inherit the global value, booleans do not.
- `getString/getInt/...` throw on array values (e.g. `multi-select`): use `get()` there.
- Always pass `$salesChannelId`, otherwise only global values.
- `all()` and `getDomain()` are `@internal` (do not use in storefront/Store API).
- Uninstall without `keepUserData` deletes all configuration values including overrides.
- Fixed values per environment: `shopware.system_config.default` in `config/packages/*.yaml` (then not changeable in the admin).

## References

- ../../references/19-plugin-configuration.md
- ../../references/18-snippets-translations.md
- ../../references/03-plugin-lifecycle.md
- Root scaffold: ../plugin/README.md; events: ../event-subscriber/README.md

## Notes and gaps

- Checked: `config.xml` validated with `xmllint --schema` against `core/System/SystemConfig/Schema/config.xsd` (6.7.15.1) and against a core example (`cart.xml`).
- Deprecation: Twig `config()` with static keys such as `seo.descriptionMaxLength`, `cms.tosCmsPageId` is deprecated in 6.7 (replacement according to the reference e.g. `core.basicInformation.tosPage`).
- ⚠ UNVERIFIED: The evaluation of `min`/`max`, `copyable`, `price` and `datetime` by the admin components was not checked individually (PHP only passes the options through). The docs state "4+ characters" for `name`; the XSD does not require this.
- ⚠ UNVERIFIED: The behavior of `<ai-badge>` and `<flag>` is only established in the XSD and is not included in the template.
- ⚠ GAP: Custom admin components for `<component name="...">` (registration in the admin plugin JS) are not part of this template; see ../admin-module/README.md for admin scaffolds.
