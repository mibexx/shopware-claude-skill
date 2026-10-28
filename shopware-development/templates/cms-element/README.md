# Template: CMS element (swag-example-element)

Purpose: Custom Shopping Experiences element with admin registration (`registerCmsElement` with `component`, `configComponent`, `previewComponent`), storefront Twig and PHP DataResolver. Shopware 6.7.15.1. The element shows a heading and HTML content (sanitized in the resolver via `HtmlSanitizer`).

## File tree

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── DataResolver/SwagExampleElementCmsElementResolver.php   # getType/collect/enrich
    ├── Struct/SwagExampleElementStruct.php                     # ends up in element.data
    └── Resources/
        ├── config/services.php                                 # tag shopware.cms.data_resolver
        ├── views/storefront/element/cms-element-swag-example-element.html.twig
        └── app/administration/src/
            ├── main.js                                         # imports the element
            ├── snippet/{de,en}.json                      # label snippets (prefix swag-example)
            └── module/sw-cms/elements/swag-example-element/
                ├── index.js                                    # Component.register + registerCmsElement
                ├── component/{index.js,sw-cms-el-swag-example-element.html.twig}
                ├── config/{index.js,sw-cms-el-config-swag-example-element.html.twig}
                └── preview/{index.js,sw-cms-el-preview-swag-example-element.html.twig}
```

## Placeholders

| Placeholder | Replace with | Where |
|---|---|---|
| `swag-example-element` | Element name (kebab case, with vendor prefix) | `name` in `registerCmsElement`, `getType()` in the resolver, file name `cms-element-<name>.html.twig`, `initElementConfig('<name>')`, component names, folder |
| `SwagExampleElement...` | PascalCase of the element name | Resolver/struct class |
| `SwagExample` / `swag/example` | Plugin name | Class, namespace, `composer.json` |
| `swag-example.elements.swagExampleElement.*` | Custom snippet keys | `index.js`, config template, snippet files |
| `cms_swag_example_element` | API alias of the struct | `getApiAlias()` |

Important: `name` (admin) = `getType()` (PHP) = suffix of the storefront template. Otherwise the element stays empty (the block wrapper uses `sw_include ... ignore missing`, a missing template produces no error).

## Integration / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh        # build the admin bundle (or bin/watch-administration.sh)
bin/console cache:clear
```

In the admin: Content -> Shopping Experiences -> Layout -> block with slot -> choose the element in the slot dialog. Twig/PHP-only changes need only `cache:clear`.

## Rules (verified against the source code)

- Resolver: `CmsElementResolverInterface` with `getType()`, `collect(CmsSlotEntity, ResolverContext): ?CriteriaCollection`, `enrich(CmsSlotEntity, ResolverContext, ElementDataCollection): void`. Usually `extends AbstractCmsElementResolver`.
- `enrich()` always sets a struct (`$slot->setData()`), otherwise `element.data` stays empty.
- Data belongs in `collect()` (criteria are bundled), not loaded via repository in `enrich()` (N+1). `CriteriaCollection::add($key, $definition, $criteria)`, key unique across all slots.
- The core sets the service tag `shopware.cms.data_resolver` via autoconfiguration; an explicit tag does no harm.
- Plugin service files: `Resources/config/services.php` is loaded by the bundle. XML is deprecated (6.8).
- Admin: `createdComponent()` must call `initElementConfig('<name>')` (merges `defaultConfig`), the config component must emit `element-update` (`emits: ['element-update']`).
- `registerCmsElement` returns `false` and registers nothing if `name` or `component` is missing.
- A custom resolver with the type of a core element overrides the core resolver. Use a vendor prefix.
- Output unsanitized HTML content with `|raw` only after sanitizing it.

## Avoiding deprecations

- `AbstractCmsElementResolver::resolveDefinitionField()` and `resolveCriteriaForLazyLoadedRelations()` are `@deprecated tag:v6.8.0`, not used.
- The composable `useCmsElement` is `@experimental`/`@private` in 6.7.15.1, hence the stable mixin `cms-element`.

## Gaps and notes

- ⚠ UNVERIFIED: Scaffold not built/installed (no PHP, no admin build). JS via `node --check`, JSON via `JSON.parse`. PHP namespaces, signatures (`CmsElementResolverInterface`, `AbstractCmsElementResolver::resolveEntityValueToString`, `HtmlSanitizer::sanitize`, `CriteriaCollection`) cross-checked against the source code, model: `TextCmsElementResolver`, `HtmlCmsElementResolver`, core element `html`.
- ⚠ UNVERIFIED: Meteor components `mt-text-field`/`mt-textarea` with `v-model` + `@update:model-value` (both registered globally in `vue.adapter.ts`, usage pattern from other core modules).
- ⚠ GAP: Media selection and data mapping UI (`sw-cms-mapping-field`, `sw-cms-inherit-wrapper`) are not part of the scaffold. Model: core elements `image`, `text` under `administration/Resources/app/administration/src/module/sw-cms/elements/`. A media variant would need `collect()` with `MediaDefinition` and `defaultConfig.media.entity`.
- ⚠ GAP: Admin snippet loading is based on the `SnippetFinder` (all `<locale>.json` under `Resources/app/administration/src`), not on a `snippets` entry. See reference 18.
- Block with a fixed slot type `swag-example-element`: see [cms-block](../cms-block/README.md).

## References

- [21 CMS elements](../../references/21-cms-elements.md)
- [22 CMS blocks & visibility](../../references/22-cms-blocks-visibility.md)
- [05 Registering services](../../references/05-registering-services.md)
- [18 Snippets & translations](../../references/18-snippets-translations.md)
- [14 Storefront Twig templates](../../references/14-storefront-twig-templates.md)
- [49 Build & deployment](../../references/49-build-deployment.md)
- More templates: [cms-block](../cms-block/README.md), [plugin](../plugin/README.md)

- Snippet files are named language-neutrally (`de.json`/`en.json` or `storefront.de.json`/`storefront.en.json`); `bin/console translation:lint-filenames` flags regional names (`de-DE.json`) without a language-neutral base file, fix with `--fix` (see [T-18](../../references/18-snippets-translations.md)).
