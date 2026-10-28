# Template: CMS block (swag-example-block)

Purpose: Custom block for Shopping Experiences with two slots (text on the left, image on the right), admin registration (`registerCmsBlock`), block component, preview component and storefront block template. Shopware 6.7.15.1. The slot elements are core elements (`text`, `image`), no custom element is needed. For a custom element see [cms-element](../cms-element/README.md).

## File tree

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    └── Resources/
        ├── views/storefront/block/cms-block-swag-example-block.html.twig
        └── app/administration/src/
            ├── main.js                                          # imports the block
            ├── snippet/{de,en}.json                       # block label
            └── module/sw-cms/blocks/text/swag-example-block/
                ├── index.js                                     # Component.register + registerCmsBlock
                ├── component/{index.js,*.html.twig,*.scss}      # editor rendering with <slot>
                └── preview/{index.js,*.html.twig,*.scss}        # preview in the block menu
```

## Placeholders

| Placeholder | Replace with | Where |
|---|---|---|
| `swag-example-block` | Block name (kebab case, with vendor prefix) | `name` in `registerCmsBlock`, file name `cms-block-<name>.html.twig`, component names, folder |
| `text` (folder/`category`) | Block category | `category` in `registerCmsBlock` (core categories among others `text`, `image`, `video`, `text-image`, `commerce`, `sidebar`, `form`, `html`) |
| `left` / `right` | Slot names | `slots` in `index.js`, `<slot name>` in the component, `getSlot('...')` in the storefront Twig |
| `SwagExample` / `swag/example` | Plugin name | Class, namespace, `composer.json` |
| `swag-example.blocks.swagExampleBlock.label` | Custom snippet key | `index.js`, snippet files |

The block name (`name`) corresponds to the field `cms_block.type` and must match the file name of the storefront template. Otherwise the block stays empty, because `section/cms-section-block-container.html.twig` includes it via `sw_include ... ignore missing`.

## Integration / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh        # or bin/watch-administration.sh
bin/console cache:clear
```

In the admin: Content -> Shopping Experiences -> create layout -> block menu -> category "Text" -> insert block. Assign the layout via the layout assignment or in category/product/landing page (`cmsPageId`).

## Rules (verified against the source code)

- `registerCmsBlock({ name, component, defaultConfig, ... })`: returns `false` and registers nothing if `name` or `component` is missing or `flag === false`. Optional: `label`, `category`, `previewComponent`, `previewImage`, `configComponent`, `slots`, `allowedPageTypes`, `hidden`, `removable`.
- `slots`: mapping slot name -> element type (string) or `{ type, default: { config, data } }`.
- Wrapper in the storefront: the block Twig is rendered inside `div.cms-block-container-row.row`, hence column classes such as `col-md-6`.
- Section types are only `default` and `sidebar`. Visibility (`visibility` mobile/tablet/desktop) is controlled via the CSS classes `hidden-*`; the HTML is delivered anyway. Rule-based visibility (Rule Builder) does not exist for CMS blocks/sections in 6.7.15.1.
- Custom block categories need the admin snippet `apps.sw-cms.detail.label.blockCategory.<category>`. That is why the scaffold uses the core category `text`.
- Choose a block name with a vendor prefix; a core name (e.g. `text`) would override the registration.
- Every block Twig starts with `{% block block_<name> %}` so that third parties can extend it via `sw_extends`.

## Gaps and notes

- ⚠ UNVERIFIED: Scaffold not built/installed (no PHP, no admin build). JS via `node --check`, JSON via `JSON.parse`. Pattern derived 1:1 from the core block `image-text` (`module/sw-cms/blocks/text-image/image-text/`) and `storefront/block/cms-block-image-text.html.twig`.
- ⚠ UNVERIFIED: Whether the object form `Shopware.Component.register(name, {...})` works for plugins in 6.7.15.1 equivalently to the lazy form used here (`() => import(...)`, core standard) was not checked.
- ⚠ GAP: Block configuration component (`configComponent`) and preview image (`previewImage`) are not included.
- ⚠ GAP: The label (`label`) is a snippet key. Snippets are read by the `SnippetFinder` from `Resources/app/administration/src/**/<locale>.json` (see reference 18).

## References

- [22 CMS blocks & visibility](../../references/22-cms-blocks-visibility.md)
- [21 CMS elements](../../references/21-cms-elements.md)
- [14 Storefront Twig templates](../../references/14-storefront-twig-templates.md)
- [18 Snippets & translations](../../references/18-snippets-translations.md)
- [49 Build & deployment](../../references/49-build-deployment.md)
- More templates: [cms-element](../cms-element/README.md), [plugin](../plugin/README.md)

- Snippet files are named language-neutrally (`de.json`/`en.json` or `storefront.de.json`/`storefront.en.json`); `bin/console translation:lint-filenames` flags regional names (`de-DE.json`) without a language-neutral base file, fix with `--fix` (see [T-18](../../references/18-snippets-translations.md)).
