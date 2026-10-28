# Template: Theme (SwagExampleTheme)

Purpose: Scaffold of a storefront theme for Shopware 6.7.15.1 (theme = plugin whose class implements `ThemeInterface`). Contains `theme.json` with inheritance and config fields, SCSS entry point (`overrides.scss` + `base.scss`), JS entry point `main.js`, a Twig override, storefront and admin snippets.

The scaffold corresponds to the output of `bin/console theme:create SwagExampleTheme --full` (`ThemeCreateCommand`), extended with config fields, Twig override and snippets.

## File tree

```
SwagExampleTheme/
├── composer.json
└── src/
    ├── SwagExampleTheme.php                         # extends Plugin implements ThemeInterface
    └── Resources/
        ├── theme.json                               # views/style/script/asset/config
        ├── snippet/
        │   ├── storefront.de.json
        │   └── storefront.en.json
        ├── views/storefront/page/content/index.html.twig   # Twig override (sw_extends)
        └── app/
            ├── administration/src/app/snippet/
            │   ├── de.json                       # labels of the theme config in the theme manager
            │   └── en.json
            └── storefront/
                ├── src/
                │   ├── main.js                      # JS entry point (main.ts takes precedence if present)
                │   ├── assets/.gitkeep              # theme assets (via "asset" in theme.json)
                │   └── scss/
                │       ├── overrides.scss           # variables, placed BEFORE @Storefront
                │       └── base.scss                # custom styles, placed AFTER @Storefront
                └── dist/storefront/js/swag-example-theme/
                    └── swag-example-theme.js        # placeholder, replaced by the build
```

## Replacing placeholders

| Placeholder | Replace with | Where |
|---|---|---|
| `SwagExampleTheme` | Theme/class name (capital letter at the start, at least 4 characters, `/^[A-Za-z]\w{3,}$/`) | Folder, class, namespace, `theme.json` (`name`, `@SwagExampleTheme`), `composer.json`, snippet key `sw-theme.SwagExampleTheme` |
| `swag-example-theme` | Kebab case of the name (CamelCase -> `-`) | Path `dist/storefront/js/swag-example-theme/swag-example-theme.js` in folder and `theme.json` |
| `swag/example-theme` | Composer name | `composer.json` |
| `Example Author` | Author (required field `author`) | `theme.json` |
| `swag-example-*` | Custom config field names (kebab case, become `$swag-example-*` 1:1) | `theme.json`, `base.scss`, Twig, admin snippets |
| `swagExampleTheme.banner` | Custom storefront snippet key | `snippet/storefront.*.json`, Twig |

## Integration / CLI

```bash
# Copy the plugin to custom/plugins/, then in the Shopware root:
bin/console plugin:refresh
bin/console plugin:install --activate SwagExampleTheme
bin/console theme:change SwagExampleTheme --all --sync   # technical name (class name), not the display name
bin/console theme:compile --sync                         # again after changes to theme.json/SCSS
bin/build-storefront.sh                                  # only needed if main.js contains custom code
bin/console cache:clear
```

- `theme:change` assigns the theme to sales channels and compiles. Without `--sync` compilation may run via the message queue (then a worker must be running).
- Changes to `theme.json` require `plugin:refresh`.
- Admin snippets (labels of the config) are read at runtime by the `SnippetFinder` (all `<locale>.json` under `Resources/app/administration/src`), no admin build needed. File names language-neutral (`de.json`/`en.json`, `storefront.de.json`/`storefront.en.json`): `bin/console translation:lint-filenames` reports regional files (`de-DE.json`) without a language-neutral base file as errors (fix with `--fix`, see [T-18](../../references/18-snippets-translations.md)).

## Important rules (verified against the source code)

- `theme.json`: `name` and `author` are required. Order in `views` = ascending priority, your own theme comes last.
- `overrides.scss` must come BEFORE `@Storefront` (core variables use `!default`), only variables, no rules.
- Config fields are written to SCSS as `$<fieldname>`. `switch`/`checkbox` yields `1`/`0` (compare with `== 1`). Unknown keys in a field throw `InvalidThemeConfigException`.
- A "select" is `type: "text"` plus `custom.componentName: "sw-single-select"` and `custom.options`.
- Labels: admin snippets under `sw-theme.<technicalName>.<tab>.<block>.<section>.<field>.label` (tab `default`, section `default`). Options of a select: `...<field>.<optionIndex>.label`. `label`/`helpText` directly in `theme.json` are deprecated as of 6.8 and are not used here. The field `sw-color-brand-primary` with block `themeColors` gets its label via inheritance from the storefront theme.
- Twig: `{% sw_extends %}` instead of `{% extends %}`, only override blocks, set `{{ parent() }}` deliberately. The block name `page_content` exists in `storefront/page/content/index.html.twig`. `theme_config('<field>')` works in the main rendering.
- Since 6.7 header and footer run as ESI subrequests (`frontend.header`/`frontend.footer`), where `page` is not available. For header/footer overrides use blocks in `layout/header/*.twig` or `layout/footer/*.twig` respectively (e.g. `layout_header_logo_link`, `layout_footer_inner_container`, `layout_footer_bottom`, all checked against the core).

## Extending a parent theme

In the child theme's `theme.json`, insert the technical name of the parent theme (not `@ParentTheme`, which is not a special value in the code):

```json
"views": ["@Storefront", "@Plugins", "@MyParentTheme", "@SwagExampleTheme"],
"style":  ["app/storefront/src/scss/overrides.scss", "@Storefront", "@MyParentTheme", "app/storefront/src/scss/base.scss"],
"script": ["@Storefront", "@MyParentTheme", "app/storefront/dist/storefront/js/swag-example-theme/swag-example-theme.js"],
"asset":  ["@Storefront", "@MyParentTheme", "app/storefront/src/assets"],
"configInheritance": ["@Storefront", "@MyParentTheme"]
```

## Gaps and notes

- ⚠ GAP: `previewMedia` is not set in `theme.json` because no preview image is shipped. Create your own image (e.g. `app/storefront/src/assets/preview.jpg`) and add `"previewMedia": "app/storefront/src/assets/preview.jpg"`.
- ⚠ UNVERIFIED: The scaffold was not installed/compiled (no PHP/Shopware run). JSON and JS were checked syntactically, block names, paths and snippet key structure against the source code.
- ⚠ UNVERIFIED: `theme_config()` inside the ESI subrequests for header/footer (themeId comes from a request attribute, behavior in the subrequest not checked). The example therefore deliberately uses the main page.
- ⚠ UNVERIFIED: Option labels of the select (`<field>.<optionIndex>.label`) derived from `ThemeMergedConfigBuilder::buildCustom`, not tested in the admin.
- `theme:create --with-config` creates an optional plugin config (`src/Resources/config/config.xml`, domain `SwagExampleTheme.config`). See `../config/README.md`.
- `theme:change --no-cleanup` is deprecated (removal in 6.8). `theme:migrate-translations` does not exist in 6.7.15.1.
- Dist placeholder: `theme:create` also creates the empty file `swag-example-theme.js` (`script` in `theme.json` refers to it). ⚠ UNVERIFIED whether a missing file causes errors. It therefore stays in the scaffold until the build replaces it.

## References

- [11 Theme basics](../../references/11-theme-basics.md)
- [12 Theme config & SCSS variables](../../references/12-theme-config-scss-variables.md)
- [13 Storefront styling & assets](../../references/13-storefront-styling-assets.md)
- [14 Storefront Twig templates](../../references/14-storefront-twig-templates.md)
- [18 Snippets & translations](../../references/18-snippets-translations.md)
- [49 Build & deployment](../../references/49-build-deployment.md)
- More templates: [storefront-js-plugin](../storefront-js-plugin/README.md), [config](../config/README.md), [plugin](../plugin/README.md)
