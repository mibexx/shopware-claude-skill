# Overriding and extending Storefront Twig templates (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when Storefront templates are customized via a plugin or theme, new partials are included, icons/thumbnails are rendered, or custom Twig functions/filters are added. It covers only the Twig mechanics and finding blocks.
Scope: snippets and `|trans` see [T-18 Snippets](./18-snippets-translations.md), SCSS/assets see [T-13](./13-storefront-styling-assets.md), JS see [T-15](./15-storefront-js-plugins.md), CMS elements/blocks see [T-21](./21-cms-elements.md) and [T-22](./22-cms-blocks-visibility.md), page data see [T-16 Controllers and pages](./16-storefront-controller-pages.md).

## 2. Concepts

- **`sw_extends` instead of `extends`.** A plugin places a file at the same relative path under `src/Resources/views/storefront/...` and overrides only the desired `{% block %}`. The first line is `{% sw_extends '@Storefront/storefront/<path>.html.twig' %}`. The `TemplateFinder` searches the namespace hierarchy (core Storefront, plugins, theme) and returns the template with the highest priority. This way, several plugins can extend the same template. `@Storefront` is only the starting point.
- **`sw_include` instead of `include`** (tag, also via `TemplateFinder`), so that partials remain overridable as well. `with`, `only` and `ignore missing` are supported (`IncludeTokenParser`). `sw_icon` and `sw_thumbnails` are tags that internally build a `SwInclude` on `utilities/icon.html.twig` or `utilities/thumbnail.html.twig` respectively.
- **Hierarchy:** `base.html.twig` → page template (`page/<area>/index.html.twig`) → building blocks (`layout/`, `component/`, `section/`, `block/`, `element/`, `utilities/`).
- **Bundle order:** `BundleHierarchyBuilder` takes every bundle with a `Resources/views` folder, sorted by `Bundle::getTemplatePriority()` (default `0`, `Framework` `-1`). Theme order from `theme.json` (slots `@Storefront`, `@Plugins`, `@Theme`, `ThemeInheritanceBuilder`). Apps use `template_load_priority`. Namespace in Twig = bundle name (`@SwagExample`).
- **Template scopes:** `ExtendsTokenParser` respects the request attribute `_templateScopes` (default `default`, `TemplateScopeDetector`). Without a matching scope, the file ends after the `extends`.
- **Header/footer via ESI subrequest since 6.7** (`frontend.header`, `frontend.footer`, `NavigationController::header()/footer()`): `page` is NOT available there, only `header` or `footer` respectively. Data only as scalar `headerParameters`/`footerParameters`.
- **`sw_block('name')`** renders a block of the parent inheritance at another place (e.g. `{{ sw_block('base_cookie_permission') }}` in `base.html.twig`). Further multi-inheritance tags: `sw_embed`, `sw_use`, `sw_import`, `sw_from`.
- **Custom functions/filters:** class `extends Twig\Extension\AbstractExtension`, service with tag `twig.extension`.

Plugin directory tree:

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Twig/PriceLabelExtension.php
    └── Resources/
        ├── config/services.php
        └── views/storefront/              # mirrors storefront/ in the core 1:1
            ├── layout/header/logo.html.twig
            ├── layout/footer/footer.html.twig
            └── page/product-detail/...
```

Core locations: `vendor/shopware/storefront/Resources/views/storefront/` with `base.html.twig`, `layout/` (header, footer, meta, breadcrumb, navbar, cookie, ...), `page/` (account, checkout, content, error, search, wishlist, ...), `component/`, `section/` (`cms-section-*`), `block/` (`cms-block-*`), `element/` (`cms-element-*`), `utilities/`. Alongside, `views/components/` for Twig UX components (`<twig:Sw:Button>`, 6.7.11+ according to the docs).

Pages → default templates: category/landing page `page/content/index.html.twig`, product detail `page/content/product-detail.html.twig` (+ `page/product-detail/meta.html.twig`), search `page/search/index.html.twig`, checkout `page/checkout/{cart,confirm/index,finish}`, account `page/account/index.html.twig`, error `page/error/error-404.html.twig`, `error-std.html.twig`, `error-maintenance.html.twig`.

Important Shopware building blocks (selection; full table not documented, see gaps):

| Name | Kind | Class |
|---|---|---|
| `sw_extends`, `sw_include` | tag | `NodeExtension` |
| `sw_icon` / `sw_thumbnails` | tag | `storefront/Framework/Twig/IconExtension` / `ThumbnailExtension` |
| `config()` | function | `ConfigExtension` |
| `theme_config()`, `theme_scripts()`, `import_map()`, `theme_css_vars()` | function | `storefront/Framework/Twig/Extension/ConfigExtension` |
| `seoUrl()`, `rawUrl()`, `entitySeoUrl()`, `category_url()` | function | `SeoUrlFunctionExtension` among others |
| `feature()`, `searchMedia()`, `compare()` | function | `FeatureFlagExtension`, `MediaExtension`, `ComparisonExtension` |
| `sw_sanitize` | filter | `SwSanitizeTwigFilter` |
| `sw_encode_url`, `sw_encode_media_url`, `sw_icon_cache` | filter | storefront extensions |
| `currency`, `sw_convert_unit` | filter | `CurrencyFilter`, `MeasurementConvertUnitTwigFilter` |
| `instanceof` | test | `InstanceOfExtension` |

Global variables (`TemplateDataExtension::getGlobals`): `shopware.navigation`, `shopware.dateFormat`, `shopware.minSearchLength`, `shopware.showStagingBanner`, `context`, `activeRoute`, `formViolations`, `jsonLdFlags`, `themeId`.

## 3. Instructions

1. **Find the core template:** search under `vendor/shopware/storefront/Resources/views/storefront/`, e.g. `grep -rn "layout_header_logo_link" vendor/shopware/storefront/Resources/views`. The block name is the anchor point.
2. Create the **file at the same path** under `src/Resources/views/storefront/...`.
3. First line `{% sw_extends '@Storefront/storefront/<path>.html.twig' %}`, override only the desired blocks.
4. Call `{{ parent() }}` if the original content should stay. The position before/after `parent()` determines the order. Without `parent()` the content is replaced.
5. New partials: `{% sw_include '@SwagExample/storefront/component/foo.html.twig' with { x: 1 } %}`. With `only`, pass through only the given variables.
6. Icons: `{% sw_icon 'cart' style { size: 'sm', class: 'me-1' } %}` (parameters see [T-13](./13-storefront-styling-assets.md)). Images: `{% sw_thumbnails 'my-thumbs' with { media: media } %}`.
7. **Header/footer (6.7):** override content via block in `layout/header/*.twig` or `layout/footer/*.twig`. Additional data: override the block `base_esi_header` in `base.html.twig` and extend `headerParameters`, or via a `StorefrontRenderEvent` subscriber (docs). Scalars only.
8. Activation and cache:
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
   bin/console cache:clear
   bin/console theme:compile        # only needed if SCSS/JS is affected
   ```
   The Twig cache is hashed by template hierarchy (`TemplateFinder::defineCache`). Activation/priority changes produce a new cache.
9. Check syntax: `bin/console lint:twig custom/plugins/SwagExample/src/Resources/views`, list functions: `bin/console debug:twig` (Symfony commands, not executed).

## 4. Examples

Extending the logo block: `src/Resources/views/storefront/layout/header/logo.html.twig`
```twig
{% sw_extends '@Storefront/storefront/layout/header/logo.html.twig' %}

{% block layout_header_logo_link %}
    {{ parent() }}
    <small class="header-claim">{{ 'swagExample.claim'|trans|sw_sanitize }}</small>
{% endblock %}
```
(The snippet key must exist in the snippet files, see [T-18](./18-snippets-translations.md). Block `layout_header_logo_link` is confirmed in `layout/header/logo.html.twig`.)

Custom Twig function: `src/Twig/PriceLabelExtension.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PriceLabelExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('swagPriceLabel', $this->priceLabel(...)),
        ];
    }

    public function priceLabel(float $price): string
    {
        return $price > 100.0 ? 'Premium' : 'Standard';
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Twig\PriceLabelExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(PriceLabelExtension::class)
        ->tag('twig.extension');
};
```
Usage: `{{ swagPriceLabel(page.product.calculatedPrice.unitPrice) }}`. Loading of `Resources/config/services.php` in the plugin is confirmed in `core/Framework/Bundle.php` (XML is deprecated there). For further registration details see [T-05 Services](./05-registering-services.md).

Further scaffolds: [../templates/theme/README.md](../templates/theme/README.md), [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Pitfalls

- `{% extends %}`/`{% include %}` instead of `sw_extends`/`sw_include`: overrides from other plugins/themes do not take effect.
- File at the wrong path (`Resources/views/layout/...` without `storefront/`): the path must mirror `views/storefront/...`.
- Copying the entire core file instead of `sw_extends` + block: updates do not take effect, conflicts.
- Wrong block name: the block is silently not used (no error message). It must exist in the parent template.
- Forgetting `parent()`: content (and calls from other plugins) is lost. Several plugins without `parent()` in the same block: the last one in the hierarchy wins (order controllable via `getTemplatePriority()`).
- Your own new blocks without a plugin prefix collide with core names.
- Header/footer (6.7): `page` not available, no complex objects in `headerParameters` (query parameters). The routes are HTTP-cached (`ATTRIBUTE_HTTP_CACHE`).
- Plugin inactive or without `Resources/views`: does not appear in the hierarchy (`BundleHierarchyBuilder` checks `is_dir`).
- Raw output with `|raw` instead of `|sw_sanitize`: XSS.
- Twig function without tag `twig.extension`: "Unknown function".
- Checkout `_page` templates deliberately override `base_*` blocks as empty (e.g. `base_flashbags`).
- `ExtendsTokenParser`, `IncludeTokenParser`, `TemplateFinder` are `@internal`: do not extend or decorate them.

### Deprecations and version notes (6.5/6.6 → 6.7)

- **Header/footer via ESI since 6.7.0.0** (blocks `base_esi_header`/`base_esi_footer`). In 6.5/6.6, `layout_header`/`layout_footer` were included directly in `base.html.twig` and had access to `page`. Header data now comes via `header` (`HeaderPageletLoadedHook`), footer data via `footer`.
- 6.8 preparation in the code: many `{% if feature('v6.8.0.0') %}` branches (27 files), e.g. breadcrumb rework, `base_cookie_permission` at the start of the body, JSON-LD instead of microdata. `isHMRMode` in `base.html.twig` is deprecated.
- `@deprecated tag:v6.8.0`: `controllerName`/`controllerAction` (→ `activeRoute`), CSS classes `is-ctl-*`/`is-act-*` (→ `is-active-route-*`), `sw_breadcrumb_full*`, `format_currency`/`format_number` (BackwardCompatibleIntlExtension), macros in app scripts (→ `sw_macro_function`), block `base_navigation_styling`.
- New: Twig UX components (`<twig:Sw:…>`, from 6.7.11.0 according to the docs), `sw_convert_unit` (6.7.1.0 according to the docs), `import_map()`/`theme_css_vars()` (Vite), agentic files (`llms.txt`, `agents.md`, extension block `agentic_llms_extensions`).
- Service configuration in the core is PHP instead of XML (XML routes/services deprecated, `Bundle::triggerXmlConfigDeprecation`).

## 6. Checklist

- [ ] File is located under `src/Resources/views/storefront/...` and mirrors the core path.
- [ ] First line is `sw_extends` with `@Storefront/storefront/...`.
- [ ] Block name checked against the core template.
- [ ] `{{ parent() }}` set where the original content should be preserved.
- [ ] Partials via `sw_include`, not `include`.
- [ ] Output with `|sw_sanitize` instead of `|raw`, texts via snippets (T-18).
- [ ] Header/footer customizations do not use `page`.
- [ ] Twig extension registered with tag `twig.extension`.
- [ ] `bin/console cache:clear` executed, plugin active.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/customize-templates.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/twig-function-reference.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/add-custom-twig-function.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/customize-header-footer.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/agentic-files.html

Source code (relative to `vendor/shopware/`): `core/Framework/Adapter/Twig/TokenParser/{ExtendsTokenParser,IncludeTokenParser}.php`, `TemplateFinder.php`, `TemplateScopeDetector.php`, `NamespaceHierarchy/{NamespaceHierarchyBuilder,BundleHierarchyBuilder}.php`, `Extension/{NodeExtension,TwigFeaturesWithInheritanceExtension}.php`, `storefront/Theme/Twig/{ThemeNamespaceHierarchyBuilder,ThemeInheritanceBuilder}.php`, `core/Framework/Bundle.php` (`getTemplatePriority`), `storefront/Framework/Twig/{IconExtension,ThumbnailExtension,TemplateDataExtension}.php`, `storefront/Controller/NavigationController.php` (`header`, `footer`), `storefront/Resources/views/storefront/base.html.twig`, `layout/header/logo.html.twig`, `storefront/Resources/views/components/README.md`, `core/Framework/Plugin/Command/Scaffolding/Generator/StorefrontControllerGenerator.php`.

### Gaps and uncertainties

- ⚠ GAP: the core has no block debugger. The docs name the third-party plugin FroshDevelopmentHelper for this (not checked). Whether the Symfony profiler shows block names is not documented.
- ⚠ UNVERIFIED: `debug:twig`, `lint:twig`, `cache:clear` were not executed (Symfony/docs knowledge).
- ⚠ UNVERIFIED: whether 6.7.15.1 additionally loads `services.xml` for plugins is only supported by the XML deprecation in `Bundle`.
- ⚠ UNVERIFIED: namespace `@SwagExample` = `Bundle::getName()` not separately verified.
- ⚠ GAP: precedence with several plugins/themes: the comment "Lower integer = higher precedence" in `BundleHierarchyBuilder` contradicts the behavior according to the `TemplateFinder` comment (last element wins). Actual resolution not confirmed by a test.
- ⚠ UNVERIFIED: the version statements "components from 6.7.11.0" and "`sw_convert_unit` from 6.7.1.0" come only from the docs (the files exist).
- ⚠ GAP: the table of Twig building blocks was obtained from a grep. Extensions from Elasticsearch/Administration/documents are not fully covered. `json_encode` is Twig core, not a Shopware addition.
