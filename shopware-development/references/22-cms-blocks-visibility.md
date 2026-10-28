# CMS blocks, sections and visibility (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a custom CMS block (layout building block with slots) is to be built, when section types, layout assignment or the **visibility** of blocks/sections (mobile/tablet/desktop) are involved, and when the question of "rule-based visibility" comes up.

Scope: slot content (elements, resolvers): [T-21 CMS elements](./21-cms-elements.md). Twig mechanics: [T-14](./14-storefront-twig-templates.md). Rule Builder in general: [T-32](./32-rule-builder.md). Snippets: [T-18](./18-snippets-translations.md). Build: [T-49](./49-build-deployment.md). Scaffold: [../templates/cms-block/README.md](../templates/cms-block/README.md).

## 2. Concepts

### Statement on rule-based visibility (documented vs. gap)

**Documented (source code 6.7.15.1):** There is **no** rule-based (Rule Builder) visibility for CMS blocks and sections.
- `CmsBlockDefinition` and `CmsSectionDefinition` only have the JSON field `visibility` (`mobile`, `tablet`, `desktop`, each a bool; `JsonField('visibility', …)`), no rule association, no `ruleId`.
- `grep` for `ruleId`/`rule_id`/`rules` under `core/Content/Cms` yields no hit; the Cms definitions under `Aggregate/*/` contain no rule reference; `core/Framework/Rule` and `core/Content/Rule` contain no CMS references.
- The admin visibility control (`sw-cms-visibility-config`) offers only the three viewport checkboxes.

**Gap (not checked):** Commercial extensions (Shopware Commercial/Extensions) that could retrofit rule-based visibility are not in the installed code (only `core`, `storefront`, `administration`, `elasticsearch`). ⚠ GAP: the statement applies only to the open-source code 6.7.15.1; 6.5/6.6 not checked against the source code.

Consequence: anyone who needs "block only for customer group X/time period/cart" must choose an alternative (see Pitfalls): layout per category/landing page, custom element with logic in the resolver, or Twig logic in the custom block template (e.g. `context.currentCustomerGroup`, `context.customer`).

### Structure of a block

- Registration in the Administration: `Shopware.Service('cmsService').registerCmsBlock({...})` (`name` = DB field `cms_block.type`).
- Admin component + preview component (Vue).
- Storefront: `storefront/block/cms-block-<name>.html.twig`. Included dynamically by `section/cms-section-block-container.html.twig` via `sw_include '@Storefront/storefront/block/cms-block-' ~ block.type ~ '.html.twig' ignore missing` – missing file = block silently empty.
- Slots: `block.slots.getSlot('<slotName>')`, rendered via `'@Storefront/storefront/element/cms-element-' ~ element.type ~ '.html.twig'`.

### Sections

Types are exactly `default` and `sidebar` (`CmsSectionDefinition::TYPE_DEFAULT/TYPE_SIDEBAR`, enforced via `Choice`).
- `default`: `section/cms-section-default.html.twig`, one column; container class from `section.sizingMode` (`boxed` -> `container`, `full_width` -> `full-width`).
- `sidebar`: `section/cms-section-sidebar.html.twig`; `filterBySectionPosition('sidebar'|'main')` (block field `sectionPosition`); `section.mobileBehavior` sets the class `cms-section-sidebar-mobile-<value>`.
- Both render blocks via `cms-section-block-container.html.twig` (wrapper classes `cms-block cms-block-<type> pos-<position>`, margins as `padding`, background, visibility classes).
- Section wrapper in `page/content/detail.html.twig` (block `page_content_section`): `cms-section cms-section-<type> pos-<n>`. Include without `ignore missing` -> unknown type = Twig error. Custom section types are not intended.

### Visibility (viewport)

- DB/DAL: JSON field `visibility {mobile, tablet, desktop}` on `cms_block` and `cms_section`. `null` = all `true`. For each `false`, Twig adds a class: `hidden-mobile|hidden-tablet|hidden-desktop`.
- CSS: `storefront/Resources/app/storefront/src/scss/component/_visibility.scss` – `hidden-mobile` below 768 px (`media-breakpoint-down(md)`, xs/sm), `hidden-tablet` 768–991.98 px (`media-breakpoint-only(md)`), `hidden-desktop` from 992 px (`media-breakpoint-up(lg)`). The limits apply to the Bootstrap default breakpoints; changed theme breakpoints shift them (see [T-12](./12-theme-config-scss-variables.md)).
- **The HTML is still delivered** (`display: none`), not a security or performance measure; images may still load.
- Admin: `sw-cms-visibility-config` (checkboxes), `sw-cms-visibility-toggle` (preview toggle). New blocks get all three `true`.

### Layout assignment

`cms_page_id` on `category`, `product` (inheritable), `landing_page`; page types `landingpage`, `page`, `product_list`, `product_detail`.

Directory tree:
```
custom/plugins/SwagExample/
└── src/Resources/
    ├── app/administration/src/
    │   ├── main.js
    │   └── module/sw-cms/blocks/text/swag-demo-teaser/
    │       ├── index.js
    │       ├── component/{index.js, sw-cms-block-swag-demo-teaser.html.twig}
    │       └── preview/{index.js, sw-cms-preview-swag-demo-teaser.html.twig, sw-cms-preview-swag-demo-teaser.scss}
    └── views/storefront/block/cms-block-swag-demo-teaser.html.twig
```
Model in the core: `…/module/sw-cms/blocks/text-image/image-text/` and `storefront/Resources/views/storefront/block/cms-block-image-text.html.twig`.

## 3. Instructions

1. Provide the plugin ([T-02](./02-plugin-structure.md)); create admin files according to the tree, `main.js` imports the block module.
2. Create the storefront Twig `block/cms-block-<name>.html.twig` (file name = `name`).
3. Install and build:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/build-administration.sh
   bin/console cache:clear
   bin/console theme:compile        # only if storefront SCSS/JS was added
   ```
   Dev: `bin/watch-administration.sh`.
4. Admin: Content → Shopping Experiences → create layout → block menu → category "Text" → insert block.
5. Visibility: select block → settings → checkboxes mobile/tablet/desktop; section likewise in the section tab (`sw-cms-section-config`).
6. Assign layout: "Layout assignment" in the layout editor (`sw-cms-layout-assignment-modal`) or in category/product/landing page (`cmsPageId`).
7. Restrict page type (optional): `allowedPageTypes: [Shopware.Constants.CMS.PAGE_TYPES.LISTING]` (example `blocks/sidebar/sidebar-filter/index.ts`; check `CmsService::isBlockAllowedInPageType`). If not specified: all types.
8. Further `CmsBlockConfig` options: `configComponent`, `previewImage`, `appName`, `category`, `label`, `flag`, `hidden` (hide from the block menu), `removable` (`false` prevents deleting/duplicating, e.g. `product-listing`), `defaultConfig`, `slots`. `registerCmsBlock` returns `false` and registers nothing if `name`/`component` is missing or `flag === false`.
9. A custom block category needs the snippet `apps.sw-cms.detail.label.blockCategory.<category>`; the category `app` is added automatically for app blocks.

## 4. Examples

Two slots (text on the left, image on the right); the core elements `text` and `image` are reused.

`src/Resources/app/administration/src/main.js`
```js
import './module/sw-cms/blocks/text/swag-demo-teaser';
```

`…/blocks/text/swag-demo-teaser/index.js`
```js
import './component';
import './preview';

Shopware.Service('cmsService').registerCmsBlock({
    name: 'swag-demo-teaser',
    label: 'Demo Teaser',               // in the core: snippet key
    category: 'text',
    component: 'sw-cms-block-swag-demo-teaser',
    previewComponent: 'sw-cms-preview-swag-demo-teaser',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
        sizingMode: 'boxed',
    },
    slots: {
        left: 'text',
        right: {
            type: 'image',
            default: {
                config: {
                    displayMode: { source: 'static', value: 'cover' },
                },
            },
        },
    },
});
```

`…/component/index.js`
```js
import template from './sw-cms-block-swag-demo-teaser.html.twig';

Shopware.Component.register('sw-cms-block-swag-demo-teaser', {
    template,
});
```

`…/component/sw-cms-block-swag-demo-teaser.html.twig`
```twig
{% block sw_cms_block_swag_demo_teaser %}
    <div class="sw-cms-block-swag-demo-teaser">
        <slot name="left">
            {% block sw_cms_block_swag_demo_teaser_slot_left %}{% endblock %}
        </slot>
        <slot name="right">
            {% block sw_cms_block_swag_demo_teaser_slot_right %}{% endblock %}
        </slot>
    </div>
{% endblock %}
```

`…/preview/index.js`
```js
import template from './sw-cms-preview-swag-demo-teaser.html.twig';
import './sw-cms-preview-swag-demo-teaser.scss';

Shopware.Component.register('sw-cms-preview-swag-demo-teaser', {
    template,
});
```

`…/preview/sw-cms-preview-swag-demo-teaser.html.twig`
```twig
{% block sw_cms_block_swag_demo_teaser_preview %}
    <div class="sw-cms-preview-swag-demo-teaser">
        <div class="sw-cms-preview-swag-demo-teaser__text">
            <h2>Lorem ipsum</h2>
            <p>Lorem ipsum dolor sit amet.</p>
        </div>
        <div class="sw-cms-preview-swag-demo-teaser__image"></div>
    </div>
{% endblock %}
```

`…/preview/sw-cms-preview-swag-demo-teaser.scss`
```scss
.sw-cms-preview-swag-demo-teaser {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding: 15px;

    &__image {
        background: #d1d9e0;
        min-height: 60px;
    }
}
```

`src/Resources/views/storefront/block/cms-block-swag-demo-teaser.html.twig`
```twig
{% block block_swag_demo_teaser %}
    {% set left = block.slots.getSlot('left') %}
    {% set right = block.slots.getSlot('right') %}

    <div class="col-md-6" data-cms-element-id="{{ left.id }}">
        {% block block_swag_demo_teaser_left %}
            {% sw_include '@Storefront/storefront/element/cms-element-' ~ left.type ~ '.html.twig' with { element: left } %}
        {% endblock %}
    </div>
    <div class="col-md-6" data-cms-element-id="{{ right.id }}">
        {% block block_swag_demo_teaser_right %}
            {% sw_include '@Storefront/storefront/element/cms-element-' ~ right.type ~ '.html.twig' with { element: right } %}
        {% endblock %}
    </div>
{% endblock %}
```
(Core `cms-block-image-text` sets `{% set element = … %}` and includes without `with`; with two slots, `with { element: … }` is more robust.)

Example of an alternative to rule-based visibility: Twig logic in the custom block (⚠ UNVERIFIED: customer group access following the usual `context` pattern, see [T-20](./20-sales-channel-distinction.md); mind the cache, [T-41](./41-caching.md)):
```twig
{% block block_swag_demo_teaser_member %}
    {% if context.customer is not null %}
        {# only for logged-in customers #}
    {% endif %}
{% endblock %}
```

## 5. Pitfalls

- Twig file name does not match `name`/`type`: block silently stays empty because of `ignore missing`.
- Block name collides with core names (`text`, `image`): `registerCmsBlock` simply overwrites `blockRegistry[name]`; use a vendor prefix.
- The slot name in `slots`, admin `<slot name>` and storefront `getSlot('…')` must be identical; otherwise `getSlot` -> `null` and an error on `.type`.
- `component` string ≠ registered component name, import in `main.js` or admin build missing: block does not appear.
- Custom section types (`cms-section-foo`) fail due to `Choice` in `CmsSectionDefinition` and the include without `ignore missing`.
- **The assumption of "rule-based visibility" is wrong** (see section 2); alternatives: layout per category/landing page, custom element, Twig logic in the block template.
- Viewport visibility is CSS only: do not use it for sensitive content.
- Slot type without an existing element: element template is missing, `ignore missing` swallows it.
- Omitting Twig blocks (`{% block block_<name> %}`): third parties cannot extend via `sw_extends`. Change core block Twigs via `sw_extends`/block overrides (T-14), do not overwrite them.

### Deprecations and version notes (6.5/6.6 → 6.7)

- In the core, components are registered with `Component.register('name', () => import('./x'))` (lazy) and in Vue 3 style; for plugins the object form `register(name, {template})` (docs style) remains – ⚠ UNVERIFIED, see gaps.
- Core block registration lives in `index.ts` (`CmsBlockConfig`); `allowedPageTypes` for blocks is evaluated in `isBlockAllowedInPageType`.
- App blocks: `CmsExtensions` (`core/Framework/App/Cms/`, schema `cms-1.0.xsd`), admin component `sw-cms-block-app-renderer` ([T-10](./10-app-system-scope.md)).
- `Category.cmsPageIdSwitched` deprecated (`@Deprecated('v6.7.9.0', 'v6.8.0.0')`).
- 6.5/6.6 differences cannot be verified in the code (only 6.7 installed).

## 6. Checklist

- [ ] Block name with vendor prefix, identical in `registerCmsBlock`, Twig file name and components.
- [ ] Admin component + preview component registered and imported in `main.js`.
- [ ] Slot names identical in registration, admin template and storefront Twig.
- [ ] Slot elements exist (core or T-21) including storefront template.
- [ ] Storefront Twig with its own `{% block %}` wrappers.
- [ ] Visibility understood only as a viewport aid; no rule-based visibility assumed.
- [ ] `allowedPageTypes`/`removable`/`hidden` set deliberately.
- [ ] Custom category with snippet `apps.sw-cms.detail.label.blockCategory.<category>`.
- [ ] Admin built, cache cleared, block checked in the layout editor and storefront.

## 7. Sources

- https://developer.shopware.com/docs/guides/plugins/plugins/content/cms/add-cms-block.html
- https://developer.shopware.com/docs/concepts/commerce/content/shopping-experiences-cms.html (not retrieved)
- Admin: `module/sw-cms/service/cms.service.ts` (`CmsBlockConfig`, `registerCmsBlock`, `isBlockAllowedInPageType`), `module/sw-cms/blocks/**`, `component/sw-cms-sidebar/index.ts`, `sw-cms-visibility-config/`, `sw-cms-visibility-toggle/`, `sw-cms-layout-assignment-modal/`, `constant/sw-cms.constant.ts`, `shared/CmsVisibility.ts`.
- Core: `core/Content/Cms/Aggregate/CmsBlock/CmsBlockDefinition.php`, `CmsSection/CmsSectionDefinition.php`, `CmsSlot/CmsSlotDefinition.php`, `core/Content/Cms/CmsPageDefinition.php`, `SalesChannel/SalesChannelCmsPageLoader.php`, `core/Content/Category/CategoryDefinition.php`, `ProductDefinition.php`, `core/Content/LandingPage/LandingPageDefinition.php`, `core/Framework/App/Cms/`.
- Storefront: `views/storefront/page/content/{index,detail}.html.twig`, `section/cms-section-{default,sidebar,block-container}.html.twig`, `block/cms-block-*.html.twig`, `scss/component/_visibility.scss`.

### Gaps and uncertainties

- ⚠ GAP: "No rule-based visibility" applies to the installed open-source code (`cms_block`/`cms_section`/`cms_slot` only `visibility` bool); commercial extensions not checked.
- ⚠ UNVERIFIED: example not built in the container; structure follows the docs and the core block `image-text`. `label` in the example is plain text, in the core snippet keys.
- ⚠ UNVERIFIED: object form `Component.register(name, {template})` in 6.7 not separately verified for plugins (core uses lazy import).
- ⚠ GAP: docs on custom section types do not exist; "not intended" follows from `Choice` and the missing `ignore missing`. Docs URL `shopping-experiences-cms.html` not retrieved.
- ⚠ UNVERIFIED: 6.5/6.6 differences derived only from 6.7 code and docs.
