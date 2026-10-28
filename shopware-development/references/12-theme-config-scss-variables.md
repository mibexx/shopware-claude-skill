# Theme configuration and SCSS variables (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when theme fields are defined in `theme.json` (`config.fields`), used as SCSS variables or CSS custom properties, Bootstrap variables/breakpoints are overridden, or SCSS variables are added via a subscriber; likewise for `theme_config()` in Twig.

Scope: theme creation, inheritance, basic `theme.json` structure in [T-11 Theme basics](./11-theme-basics.md); SCSS files/assets/build in [T-13](./13-storefront-styling-assets.md); Twig blocks only in [T-14](./14-storefront-twig-templates.md); plugin `config.xml` in [T-19](./19-plugin-configuration.md); events in general in [T-07](./07-events-subscriber.md); build scripts in [T-49](./49-build-deployment.md).

## 2. Concepts

- Theme config lives in `theme.json` under `config.fields`. Field keys: `type`, `value`, `editable`, `tab`/`block`/`section` (default `default` each), `order`/`blockOrder`/`sectionOrder`/`tabOrder`, `custom`, `scss`, `fullWidth`. Only keys for which `ThemeConfigField` has a setter are allowed; unknown keys throw `InvalidThemeConfigException` (`ThemeConfigFieldFactory::create`).
- **Field types**: The code has no fixed whitelist. Docs: `color, text, number, fontFamily, media, checkbox, switch, url`. Specially handled in the code: `color`, `fontFamily`, `text`, `media`, `url`, `textarea`, `switch`/`checkbox`. A **`select` is not a type**: `type: "text"` + `custom.componentName: "sw-single-select"` or `"sw-multi-select"` + `custom.options`. `custom` is passed through to the component only for `sw-single-select`, `sw-multi-select`, `sw-switch-field`, `sw-checkbox-field`.
- **`scss`** (default `true`): Every field with a `type` is written as `$<fieldname>: <value>;` in front of the SCSS during compilation (`ThemeCompiler::dumpVariables`); `"scss": false` excludes it. In addition, `theme_css_vars()` (`meta.html.twig`, block `layout_head_theme_css_vars`) outputs the same fields as CSS custom properties `--<fieldname>` in the `<head>` (media/URL as `url('...')`).
- Value output (`ThemeCompiler::dumpVariables`):

| Type | SCSS output |
|---|---|
| `media`, `textarea`, `url` (string) | quoted in `'...'` |
| `switch`, `checkbox` | `(int)` -> `1`/`0` |
| other scalars | unchanged (`#0042a0`, `80`) |
| array value | is **not** output (only readable via `theme_config`) |
| empty/missing | `null` |

- **Bootstrap variables**: `overrides.scss` in `theme.json` -> `style` **before** `@Storefront` (Bootstrap uses `!default`); variables only, no rules.
- **Breakpoints**: fields `sw-breakpoint-xs ... xxl`; Twig `theme_config('breakpoint.md')`, JS reads them as well. In SCSS, `$grid-breakpoints` must be built yourself in `overrides.scss` (core SCSS does not reference `$sw-breakpoint-*` anywhere).
- **Config inheritance**: `"configInheritance": ["@Storefront", "@BasicTheme"]`; every theme except `Storefront` inherits from `@Storefront` as a fallback (`ThemeMergedConfigBuilder::getConfigInheritance`). Fields without `type` (only `value`) override the value of the inherited field (`array_replace_recursive`).
- **Labels** come in 6.7.15 from admin snippets `sw-theme.<technicalName>.<tab>.<block>.<section>.<field>.label` (`helpText` analogously).
- Reference in the core: `storefront/Resources/theme.json`, `storefront/Resources/app/storefront/src/scss/{base.scss,variables.scss,abstract/variables/_bootstrap.scss,_custom.scss}`.

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                     # extends Plugin implements ThemeInterface
    ├── Subscriber/ThemeVariableSubscriber.php
    └── Resources/
        ├── theme.json
        ├── config/services.php
        ├── views/storefront/layout/header/logo.html.twig
        └── app/
            ├── administration/src/app/snippet/{de-DE,en-GB}.json   # labels
            └── storefront/src/scss/{overrides.scss,base.scss}
```

## 3. Instructions

1. Create a plugin or run `bin/console theme:create SwagExample --full` (options `--static`, `--full`, `--with-config`, `--with-snippets`, `--with-scss`).
2. Fill `config.fields`; field names in kebab-case (they become `$field-name` 1:1).
3. Per field: `type`, `value`, `editable`, grouping `tab`/`block`/`section`, sorting via `*Order`/`order`.
4. Labels as admin snippets under `sw-theme.<technicalName>....`; no admin build required: the `SnippetFinder` reads admin snippets at runtime, afterwards only `bin/console cache:clear`.
5. Bootstrap/breakpoint overrides in `overrides.scss`, in `style` before `@Storefront`.
6. Optionally a subscriber for dynamic variables (e.g. from SystemConfig).
7. Activate, assign, compile:
   ```
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/console theme:change SwagExample --all        # options: --sales-channel, --no-compile, --sync
   bin/console theme:compile --sync                  # options: --active-only, --only, --skip, --only-themes, --skip-themes, --keep-assets
   bin/console cache:clear
   ```
   `theme.json` changes require `plugin:refresh` + `theme:compile` depending on the change; values saved in the theme manager recompile automatically (`ThemeService`).
8. In Twig `{{ theme_config('sw-color-brand-primary') }}`; nested keys via dot (`breakpoint.md`, `assets.css`).
9. Debugging: `bin/console theme:dump` (`ThemeDumpCommand`); the variables file is located in the temp filesystem as `theme-variables.scss` or `theme-variables/<themeId>.scss`.
10. Optional validation: the parameter `storefront.theme.validate_on_compile` (default `false`) enables `SCSSValidator` (checks `color`, `fontFamily`, `text`, switches; invalid -> exception or fallback `#ffffff00`/`inherit`); allowed additional patterns: `storefront.theme.allowed_scss_values`.

## 4. Examples

`src/SwagExample.php`
```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;

class SwagExample extends Plugin implements ThemeInterface
{
}
```

`src/Resources/theme.json`
```json
{
  "name": "SwagExample",
  "author": "Swag",
  "description": { "en-GB": "Config demo", "de-DE": "Config-Demo" },
  "style": [
    "app/storefront/src/scss/overrides.scss",
    "@Storefront",
    "app/storefront/src/scss/base.scss"
  ],
  "script": ["@Storefront", "app/storefront/dist/storefront/js/swag-example/swag-example.js"],
  "asset": ["app/storefront/src/assets"],
  "configInheritance": ["@Storefront"],
  "config": {
    "fields": {
      "sw-color-brand-primary": {
        "type": "color",
        "value": "#e4002b",
        "editable": true,
        "tab": "colors", "block": "brand", "section": "primary", "order": 100
      },
      "swag-header-height": {
        "type": "number",
        "value": 80,
        "editable": true,
        "tab": "layout", "block": "header", "order": 100
      },
      "swag-sticky-header": {
        "type": "switch",
        "value": true,
        "editable": true,
        "tab": "layout", "block": "header", "order": 200
      },
      "swag-font-size": {
        "type": "text",
        "value": "24",
        "editable": true,
        "custom": {
          "componentName": "sw-single-select",
          "options": [ { "value": "16" }, { "value": "20" }, { "value": "24" } ]
        },
        "tab": "layout", "block": "header", "order": 300
      },
      "swag-hero-image": {
        "type": "media",
        "value": "app/storefront/src/assets/hero.jpg",
        "editable": true,
        "fullWidth": true,
        "tab": "layout", "block": "media", "order": 100
      },
      "swag-internal-flag": {
        "type": "text",
        "value": "x",
        "editable": false,
        "scss": false
      },
      "sw-breakpoint-lg": { "value": 1024 },
      "sw-breakpoint-xl": { "value": 1280 }
    }
  }
}
```

`src/Resources/app/storefront/src/scss/overrides.scss` (variables only, no rules)
```scss
$border-radius: 0;
$modal-backdrop-bg: rgba(255, 0, 0, 0.5);

$grid-breakpoints: (
    xs: 0,
    sm: $sw-breakpoint-sm * 1px,
    md: $sw-breakpoint-md * 1px,
    lg: $sw-breakpoint-lg * 1px,
    xl: $sw-breakpoint-xl * 1px,
    xxl: $sw-breakpoint-xxl * 1px
);
```
Important: `$sw-breakpoint-*` are unitless numbers (`value: 1024`). Without `* 1px` the result is invalid CSS (`@media (min-width: 1024)`). `xs` stays as `xs: 0`; `xs: $sw-breakpoint-xs * 1px` aborts the SCSS compile (review round 2, verified live).

`src/Resources/app/storefront/src/scss/base.scss`
```scss
.header-main {
    min-height: #{$swag-header-height}px;
    font-size: #{$swag-font-size}px;

    @if $swag-sticky-header == 1 {
        position: sticky;
        top: 0;
    }
}
```
`switch` is output as `1`/`0` (`ThemeCompiler`), so `== 1`, not `== true`.

`src/Subscriber/ThemeVariableSubscriber.php` (namespace `Shopware\Storefront\Theme\Event\...`)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ThemeVariableSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ThemeCompilerEnrichScssVariablesEvent::class => 'onAddVariables',
        ];
    }

    public function onAddVariables(ThemeCompilerEnrichScssVariablesEvent $event): void
    {
        // $swag-env-label: 'Staging';  ($sanitize = true adds quotes + addslashes)
        $event->addVariable('swag-env-label', 'Staging', true);
        // Without sanitize 1:1: $swag-gap: 12px;  (Shopware prepends the "$" itself)
        $event->addVariable('swag-gap', '12px');
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Subscriber\ThemeVariableSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(ThemeVariableSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

`src/Resources/app/administration/src/app/snippet/en.json` (structure following `storefront/Resources/app/administration/src/modules/sw-theme-manager/snippet/en.json`)
```json
{
  "sw-theme": {
    "SwagExample": {
      "layout": {
        "label": "Layout",
        "header": {
          "label": "Header",
          "default": {
            "swag-header-height": { "label": "Header height (px)", "helpText": "Minimum height" },
            "swag-sticky-header": { "label": "Sticky header" }
          }
        }
      }
    }
  }
}
```

`src/Resources/views/storefront/layout/header/logo.html.twig` (access to `theme_config`; block `layout_header_logo_inner` exists in the core template `storefront/layout/header/logo.html.twig`, verified against the source code)
```twig
{% sw_extends '@Storefront/storefront/layout/header/logo.html.twig' %}

{% block layout_header_logo_inner %}
    <div class="swag-header-logo{% if theme_config('swag-sticky-header') %} swag-header-logo-sticky{% endif %}">
        {{ parent() }}
    </div>
{% endblock %}
```

Scaffolds: [../templates/theme/README.md](../templates/theme/README.md), [../templates/config/README.md](../templates/config/README.md), [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Pitfalls

- **Wrong event namespace in the docs** ("add-scss-variables-via-subscriber" shows `Shopware\Storefront\Event\ThemeCompilerEnrichScssVariablesEvent`): the class does not exist in 6.7.15.1. Correct: `Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent`.
- `overrides.scss` registered after `@Storefront`: no effect (`!default`); no CSS rules there.
- Using `type: "select"`: does not exist, use `text` + `custom.componentName` instead.
- Unknown field key (e.g. `"description"`): `InvalidThemeConfigException`.
- Comparing a switch as a boolean in SCSS: it is `1`/`0`.
- Field name in camelCase/underscore: becomes `$camelCase` 1:1; use kebab-case (also for the `<css>` tag in `config.xml`).
- `addVariable('x', 'Staging')` without `$sanitize` results in `$x: Staging;` (bareword); use `true` for real strings. Sanitize only uses `addslashes`, no validation.
- Arrays as field values are not output as SCSS.
- `label`/`helpText` in `theme.json` are only a fallback with a `[DEPRECATED]` warning in the 6.7 admin; always use snippets.
- Plugin config variables (`<css>` in `config.xml`) apply globally across all themes/sales channels and require a manual `theme:compile` after a change in the admin (docs; technically `ThemeCompilerEnrichScssVarSubscriber`, see [T-19](./19-plugin-configuration.md)).
- Changing breakpoints only in `theme.json` without adjusting `$grid-breakpoints`: the Bootstrap CSS keeps using the defaults.
- `theme_config()` needs the `themeId` context (`ConfigExtension::getThemeId`); in mail templates/outside Storefront rendering it returns `null`.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `label`/`helpText` in `theme.json` (and `labels` in the DB): `@deprecated tag:v6.8.0` (`ThemeConfigField::$label`, `$helpText` and getters/setters). New: `labelSnippetKey`/`helpTextSnippetKey`, resolved in the admin via `sw-theme.<technicalName>....`. With `v6.8.0.0` active, `label`/`helpText` are discarded in the factory.
- `theme:migrate-translations ... --strip` only from 6.7.16.0 according to the docs; not present in 6.7.15.1 (`storefront/Theme/Command/` only contains Change, Compile, Create, Dump, PrepareIcons, Refresh).
- `theme_css_vars()` and `import_map()` exist as Twig functions in the 6.7.x line (⚠ UNVERIFIED: introduction, not in 6.5/6.6).
- Breakpoints via `sw-breakpoint-*` fields: from 6.7.8.0 according to the docs; the hard-coded `breakpoint` array in `ThemeConfigValueAccessor::getThemeConfig` is obsolete from 6.8 (`@deprecated tag:v6.8.0`).
- `ThemeConfigField::$value` will be natively typed in 6.8 (`array|bool|float|int|string|null`); other types already trigger a deprecation now.
- Twig function `config()` (`ConfigExtension::config`) deprecated for 6.8; `theme_config()` remains.
- `isHMRMode`/webpack HMR in the template deprecated in 6.8 (Vite dev server).
- `configInheritance` since 6.4.8.0 (docs); in 6.7.x the fallback for DB copies of themes inherits the config of the parent theme.
- `theme:compile --no-cleanup` has no effect/deprecated (cleanup via scheduled task `theme.delete_files`).
- XML service definitions in the plugin deprecated (6.8): `services.php` ([T-05](./05-registering-services.md)).

## 6. Checklist

- [ ] Field names in kebab-case, only allowed field keys, `type` set (otherwise no SCSS export)
- [ ] Selection fields as `text` + `custom.componentName`/`options`
- [ ] `overrides.scss` before `@Storefront` in `style`, without CSS rules
- [ ] `$grid-breakpoints` built in `overrides.scss` if breakpoints are changed
- [ ] Switches checked as `1`/`0` in SCSS
- [ ] Labels/help texts as admin snippets (`sw-theme.<technicalName>...`), not in `theme.json`
- [ ] Subscriber uses `Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent`, `$sanitize = true` for strings
- [ ] `plugin:refresh`, `theme:change`, `theme:compile`, `cache:clear` executed
- [ ] `cache:clear` executed after snippet changes (no admin build required, `SnippetFinder` reads at runtime)
- [ ] `theme_config()` only used in Storefront rendering with theme context

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/themes/configuration/theme-configuration.html
- https://developer.shopware.com/docs/guides/plugins/themes/styling/override-bootstrap-variables-in-a-theme.html
- https://developer.shopware.com/docs/guides/plugins/themes/styling/override-theme-breakpoints.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-scss-variables.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-scss-variables-via-subscriber.html

Source code (`vendor/shopware/`):
- `storefront/Theme/{ThemeConfigField,ThemeConfigFieldFactory,ThemeCompiler,ThemeMergedConfigBuilder,ThemeConfigValueAccessor,ThemeFileResolver}.php` (`ThemeCompiler::dumpVariables`/`formatVariables`/`compileStyles`)
- `storefront/Theme/Event/ThemeCompilerEnrichScssVariablesEvent.php` (`addVariable`, `getVariables`, `getSalesChannelId`, `getContext`)
- `storefront/Theme/Subscriber/ThemeCompilerEnrichScssVarSubscriber.php`, `storefront/Theme/Validator/SCSSValidator.php`
- `storefront/Theme/StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php`
- `storefront/Framework/Twig/Extension/ConfigExtension.php` (`theme_config`, `theme_scripts`, `import_map`, `theme_css_vars`), `storefront/Framework/Twig/TemplateConfigAccessor.php`
- `storefront/Theme/Command/{ThemeCompileCommand,ThemeChangeCommand,ThemeCreateCommand,ThemeDumpCommand}.php`, `storefront/DependencyInjection/Configuration.php:33-37`
- `storefront/Resources/theme.json`, `storefront/Resources/views/storefront/layout/meta.html.twig:139`
- Admin: `storefront/Resources/app/administration/src/modules/sw-theme-manager/page/sw-theme-manager-detail/index.js` (`getBind`, `getSnippet`, `mappedFields`), `.../snippet/en.json`

### Gaps and uncertainties

- ⚠ UNVERIFIED: type list: the code does not enforce a whitelist; whether the admin renders `textarea`/`number` properly (fallback `sw-form-field-renderer`) has not been checked with a runtime test.
- ⚠ UNVERIFIED: exact nesting of the admin snippet keys for tab/section labels derived from `buildSnippetKey` and the docs, not verified with a runtime test; the language-neutral file names `de.json`/`en.json` in the path `src/Resources/app/administration/src/app/snippet/` follow the core convention (the docs use `de-DE.json`/`en-GB.json`, which `translation:lint-filenames` flags, see [T-18](./18-snippets-translations.md)).
- ⚠ UNVERIFIED: version in which `theme_css_vars()` was introduced.
- ⚠ UNVERIFIED: example plugin not installed/tested (code only read); Twig block `layout_header_logo_link` was checked against the core template (exists).
- ⚠ GAP: the docs contain no `theme_config` Twig example; usage backed by `ConfigExtension` and core templates.
- Bootstrap defaults for `$grid-breakpoints` come from `storefront/Resources/app/storefront/vendor/bootstrap` (not checked further).
