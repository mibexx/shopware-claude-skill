# Theme basics (reference, Shopware 6.7.15.1)

## 1. Purpose

Applies when creating, configuring, inheriting and activating a Storefront theme (`theme.json`, `ThemeInterface`, `theme:create`, `theme:change`, `theme:compile`). Configuration fields and SCSS variables: [T-12](./12-theme-config-scss-variables.md). SCSS/assets/icons: [T-13](./13-storefront-styling-assets.md). Twig overrides: [T-14](./14-storefront-twig-templates.md). Scaffold: [../templates/theme/README.md](../templates/theme/README.md).

**Verification status:** The central statements of this reference were cross-checked against the installed source code (`storefront/…`) when it was written (see section 7). The docs are only available as a summary; `theme-base-guide` and `add-theme-inheritance-without-resources` were not read (⚠ gaps below).

## 2. Concepts

- A **theme** is a plugin (or an app) whose bootstrap class implements `Shopware\Storefront\Framework\ThemeInterface` (empty marker interface, confirmed in the source code) and which contains a `Resources/theme.json`. Plugin: `src/Resources/theme.json`. App: `<App>/Resources/theme.json`.
- **Required fields** of `theme.json`: `name` and `author` (`StorefrontPluginConfigurationFactory::createFromThemeJson` reads `$data['name']`/`$data['author']` directly; parse/read errors → `ThemeException::themeCompileException`).
- **Further keys** (read in the factory code): `description` (per locale), `views`, `style`, `script`, `asset`, `previewMedia`, `config`, `configInheritance`, `iconSets`.
- **Inheritance** via `@` placeholders: `@Storefront`, `@Plugins`, `@StorefrontBootstrap` (style only) and `@<TechnicalName>` (another theme/plugin; unknown name → `ThemeException::couldNotFindThemeByName`).
- **`@ParentTheme`:** The code has **no special handling** for it (searching for `ParentTheme` only finds `getParentThemes`/`parentThemeId`). Always use the real technical name of the parent theme (`@MyParentTheme`).
- **Theme plugin vs. theme app:** A plugin ships PHP (services, migrations) and is loaded as a bundle (`createFromBundle`, `$bundle instanceof ThemeInterface`). An app ships only files (`createFromApp`, `ThemeAppLifecycleHandler` for activate/update/deactivate/delete/uninstall; `selfManaged` apps are skipped in `StorefrontPluginRegistry::addAppConfigs`). For the plugin vs. app decision: [T-10](./10-app-system-scope.md).
- **Twig paths:** `src/Resources/views` of a bundle is used automatically as a Twig path (`BundleHierarchyBuilder`: `$bundlePath . '/Resources/views'`, verified). However, `theme:create` does not create the folder.

Directory tree after `theme:create SwagExampleTheme --full`:

```
custom/plugins/SwagExampleTheme/
├── composer.json
└── src/
    ├── SwagExampleTheme.php                 # extends Plugin implements ThemeInterface
    └── Resources/
        ├── theme.json
        ├── config/config.xml                # --with-config / --full
        ├── snippet/storefront.{de-DE,en-GB}.json   # --with-snippets / --full
        ├── views/storefront/...             # create manually (T-14)
        └── app/storefront/
            ├── src/main.js                  # entry (main.ts takes precedence, see pitfalls)
            ├── src/assets/.gitkeep
            ├── src/scss/overrides.scss      # variables before Bootstrap/Storefront
            ├── src/scss/base.scss           # entry (with --with-scss 7-1 structure)
            └── dist/storefront/js/swag-example-theme/swag-example-theme.js  # empty, replaced by the build
```

## 3. Instructions

```bash
bin/console theme:create SwagExampleTheme --full   # [--static] creates it under custom/static-plugins
bin/console plugin:refresh
bin/console plugin:install --activate SwagExampleTheme
bin/console theme:change SwagExampleTheme --all --sync   # or -s <salesChannelId>
bin/console theme:compile --sync                         # recompile after changes
bin/console cache:clear
```

**Options verified against the source code:**
- `theme:create [theme-name] [--static] [--full] [--with-config] [--with-snippets] [--with-scss]`. The name must match `/^[A-Za-z]\w{3,}$/` (letter at the start, at least 4 characters).
- `theme:change [theme-name] [-s|--sales-channel=ID | --all] [--no-compile] [--sync]`. Argument = **technical name**. Interactive without arguments. `--sales-channel` and `--all` are mutually exclusive.
- `theme:compile [-k|--keep-assets] [-a|--active-only] [-o|--only=SC-ID] [-s|--skip=SC-ID] [-O|--only-themes=ID] [-S|--skip-themes=ID] [--sync]`. Without `--sync`, compilation may run asynchronously via the message queue (worker required).
- Further commands in `storefront/Theme/Command/`: `ThemeDumpCommand`, `ThemeRefreshCommand`, `ThemePrepareIconsCommand`.

**Resolving inheritance (order):**
- `views`: order = ascending priority, the **last entry wins**. If `@Plugins` is missing, it is inserted after `@Storefront` (`ThemeInheritanceBuilder::injectPluginWildcard`). Plugins not listed end up in `@Plugins`. If `views` is missing, `@Storefront`, `@Plugins`, then all active themes apply.
- `style`/`script`: `ThemeFileResolver` resolves `@Plugins`, `@StorefrontBootstrap`, `@<Name>` (recursively, deduplicated). Direct paths are relative to `Resources/`. Entries with `"resolve": {"vendor": "…"}` define SCSS import paths.
- `configInheritance`: from general to specific. If it is missing, every theme except Storefront implicitly inherits from `@Storefront`. `parentThemeId` = last entry that is neither `@Storefront` nor the theme itself.
- Child theme extends parent theme: `"views": ["@Storefront","@Plugins","@MyParentTheme","@MyChildTheme"]`, insert `@MyParentTheme` in `style`/`script`/`asset`, `"configInheritance": ["@Storefront","@MyParentTheme"]`.

## 4. Examples

`src/SwagExampleTheme.php`:

```php
<?php declare(strict_types=1);

namespace SwagExampleTheme;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;

class SwagExampleTheme extends Plugin implements ThemeInterface
{
}
```

`src/Resources/theme.json`:

```json
{
  "name": "SwagExampleTheme",
  "author": "Example Author",
  "description": {
    "en-GB": "Minimal example theme",
    "de-DE": "Minimales Beispiel-Theme"
  },
  "views": ["@Storefront", "@Plugins", "@SwagExampleTheme"],
  "style": [
    "app/storefront/src/scss/overrides.scss",
    "@Storefront",
    "app/storefront/src/scss/base.scss"
  ],
  "script": [
    "@Storefront",
    "app/storefront/dist/storefront/js/swag-example-theme/swag-example-theme.js"
  ],
  "asset": ["@Storefront", "app/storefront/src/assets"],
  "previewMedia": "app/storefront/src/assets/preview.jpg"
}
```

`src/Resources/views/storefront/layout/footer/footer.html.twig` (block name `layout_footer_inner_container` exists in `storefront/Resources/views/storefront/layout/footer/footer.html.twig`, verified):

```twig
{% sw_extends '@Storefront/storefront/layout/footer/footer.html.twig' %}

{% block layout_footer_inner_container %}
    {{ parent() }}
    <p class="text-center">My Theme</p>
{% endblock %}
```

**Child theme without its own resources** (only `theme.json`, no own SCSS/JS/Twig files; compiled and verified live in review round 2): `src/Resources/theme.json`

```json
{
  "name": "SwagExampleChildTheme",
  "author": "Example Author",
  "views": ["@Storefront", "@Plugins", "@MyParentTheme", "@SwagExampleChildTheme"],
  "style": ["@MyParentTheme"],
  "script": ["@MyParentTheme"],
  "asset": ["@MyParentTheme"],
  "configInheritance": ["@Storefront", "@MyParentTheme"],
  "config": {
    "fields": {
      "sw-color-brand-primary": { "value": "#c4003c" }
    }
  }
}
```

The theme compiles and inherits styles, scripts and config of the parent theme; a field only needs `value` (without `type`/`editable`) to override the inherited value. **Note:** During compilation an `ERROR` is logged because the theme has no own folder `Resources/app/storefront/dist/storefront/js/<name>` (`ThemeCompiler::getScriptDistFiles` catches the finder's `DirectoryNotFoundException` and logs it via `logger->error`). Functionally without consequences; if you want to keep the log clean, create the empty folder (`theme:create` creates it with an empty `<name>.js`).

`composer.json` and further files: [../templates/theme/README.md](../templates/theme/README.md); plugin basics: [T-02](./02-plugin-structure.md).

## 5. Pitfalls

- Theme does not appear: `plugin:refresh` forgotten or plugin not active (`plugin:list`).
- `theme:change` with the display name instead of the technical name → "Invalid theme name".
- `name`/`author` missing or JSON invalid → `themeCompileException`.
- `@Name` in `style`/`script` refers to a theme that is not installed/active → `couldNotFindThemeByName`.
- `views` without your own theme as the last entry → overrides do not take effect.
- `overrides.scss` placed after `@Storefront` → because of `!default`, variables only take effect before it.
- Asynchronous compilation without a worker → changes do not appear; use `--sync`.
- `src/Resources/views` missing (not created by `theme:create`).
- Using `@ParentTheme` as a literal value (not implemented, see concepts).
- The docs directory tree shows `src/theme.json`; **the code reads `Resources/theme.json`** (plugin: `src/Resources/theme.json`). The code wins.
- Entry file: `main.ts` is supported alongside `main.js` (`StorefrontPluginConfigurationFactory::getEntryFile` checks `main.ts`, verified); for priority/details see the source code.

### Deprecations and version notes (6.5/6.6 → 6.7)

- `--no-cleanup` on `theme:change` and `theme:compile` is **deprecated** (removal in v6.8.0.0, has no effect); cleanup is handled by the scheduled task `theme.delete_files` (verified against the source code).
- `ThemeLifecycleService::refreshTheme` gets a required parameter in v6.8.0 (`#[NewOptionalParameter]`).
- The `theme:create` template requires `shopware/core ~6.7.0`. Version in which the options `--full`/`--with-*` and `--static` were introduced in 6.5/6.6: ⚠ not documented.
- `configInheritance` exists since 6.4.8.0 according to the docs.
- Runtime config classes (`ThemeRuntimeConfigService`, `ThemeRuntimeConfigStorage`), `main.ts`, `@StorefrontBootstrap`: present in 6.7.15.1; version of introduction ⚠ not documented.

## 6. Checklist

- [ ] Class extends `Plugin` and implements `ThemeInterface`.
- [ ] `src/Resources/theme.json` with `name` and `author`, valid JSON.
- [ ] `views` with `@Storefront`, `@Plugins`, your own theme last; for a child theme, `@MyParentTheme` before it.
- [ ] `style`: `overrides.scss` before `@Storefront`, `base.scss` after it.
- [ ] `configInheritance` set if the parent theme configuration is to be inherited.
- [ ] `previewMedia` file exists.
- [ ] `plugin:refresh`, `plugin:install --activate`, `theme:change`, `theme:compile --sync`, `cache:clear` executed.
- [ ] No use of the deprecated option `--no-cleanup`.

## 7. Sources

Docs (evaluated only via summary):
- https://developer.shopware.com/docs/guides/plugins/themes/create-a-theme.html
- https://developer.shopware.com/docs/guides/plugins/themes/inheritance/add-theme-inheritance.html
- https://developer.shopware.com/docs/guides/plugins/themes/configuration/theme-inheritance-configuration.html
- Not retrieved: `…/themes/theme-base-guide.html`, `…/themes/inheritance/add-theme-inheritance-without-resources.html` (content replaced by live test and `ThemeCompiler`/`StorefrontPluginConfigurationFactory`)

Source code (`vendor/shopware/storefront/`):
- `Framework/ThemeInterface.php` (`ThemeInterface`, empty marker interface)
- `Resources/theme.json` (reference theme.json)
- `Theme/Command/ThemeCreateCommand.php`, `ThemeChangeCommand.php`, `ThemeCompileCommand.php`, `ThemeDumpCommand.php`, `ThemeRefreshCommand.php`, `ThemePrepareIconsCommand.php`
- `Theme/StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php` (`createFromBundle`, `createFromApp`, `createFromThemeJson`, `getEntryFile`)
- `Theme/StorefrontPluginRegistry.php`, `Theme/ThemeFileResolver.php`, `Theme/Twig/ThemeInheritanceBuilder.php`, `Theme/ThemeMergedConfigBuilder.php`, `Theme/ThemeLifecycleService.php`, `Theme/ThemeAppLifecycleHandler.php`, `Theme/ThemeService.php`
- `core/Framework/Adapter/Twig/NamespaceHierarchy/BundleHierarchyBuilder.php` (Twig path `Resources/views`)

### Gaps and uncertainties

- ⚠ GAP: `@ParentTheme` is mentioned in the docs but not implemented in the code. Whether it is only an example name in the docs has not been checked against the original text.
- Theme without its own resources: backed by a live test (review round 2) and the source code, see examples. ⚠ The docs page `add-theme-inheritance-without-resources` itself was not read.
- ⚠ UNVERIFIED: docs details (e.g. inheritance examples) only from a summary.
- ⚠ UNVERIFIED: `iconSets` is read, not examined further (see [T-13](./13-storefront-styling-assets.md)).
- Verified live (review rounds 2–3): `plugin:install`, `theme:change --all --sync` and `theme:compile --sync` were executed by the reviewers and run without errors. ⚠ UNVERIFIED remain the options that were not used in the process (e.g. `--static`, `--only`, `--skip`); they come from the source code.
- ⚠ GAP: versions in which individual options/features were introduced in 6.5/6.6 are not documented.
