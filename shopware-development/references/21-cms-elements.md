# Custom CMS elements (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when a custom Shopping Experiences element (slot content) is to be built: admin components (editor, configuration, preview), storefront Twig and optionally a PHP resolver that loads additional data.

Scope: blocks (layout containers with slots), sections and visibility: [T-22 CMS blocks and visibility](./22-cms-blocks-visibility.md). Twig mechanics: [T-14](./14-storefront-twig-templates.md). Snippets: [T-18](./18-snippets-translations.md). Admin build: [T-49](./49-build-deployment.md). Scaffold: [../templates/cms-element/README.md](../templates/cms-element/README.md).

## 2. Concepts

A CMS element consists of three parts:

1. **Administration:** three Vue components (editor view `component`, settings `configComponent`, preview `previewComponent`), registered with `Shopware.Service('cmsService').registerCmsElement({...})`, loaded from `src/Resources/app/administration/src/main.js`.
2. **Storefront:** Twig `src/Resources/views/storefront/element/cms-element-<name>.html.twig`. The block wrapper includes it via `sw_include '@Storefront/storefront/element/cms-element-' ~ element.type ~ '.html.twig' ignore missing` (e.g. `block/cms-block-text.html.twig:7`). In the template: `element` (= `CmsSlotEntity`), `element.fieldConfig.elements` (config), `element.data` (struct set by the resolver).
3. **Optional PHP resolver:** implements `CmsElementResolverInterface` (usually `extends AbstractCmsElementResolver`) with `getType()`, `collect()`, `enrich()`. Type = `name` of the element. The container sets the tag `shopware.cms.data_resolver` via autoconfiguration (`AutoconfigureCompilerPass.php:149-151`); an explicit tag does no harm.

Without a resolver, `element.data` stays empty; plain config values (`element.fieldConfig.elements.<key>.value`) still work.

Name identity: `name` in `registerCmsElement` = `getType()` = suffix in the Twig file name.

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── DataResolver/QuoteCmsElementResolver.php
    ├── Struct/QuoteStruct.php
    └── Resources/
        ├── config/services.php
        ├── views/storefront/element/cms-element-swag-quote.html.twig
        └── app/administration/src/
            ├── main.js
            └── module/sw-cms/elements/swag-quote/
                ├── index.js
                ├── component/{index.js, sw-cms-el-swag-quote.html.twig}
                ├── config/{index.js, sw-cms-el-config-swag-quote.html.twig}
                └── preview/{index.js, sw-cms-el-preview-swag-quote.html.twig}
```
The path `module/sw-cms/elements/<name>/` is a convention (core: `administration/Resources/app/administration/src/module/sw-cms/elements/`), not a requirement.

Decision guide: static inputs only -> no resolver. Loading entities/media/products -> resolver with `collect()` (bundle criteria). For changes to core elements, use extension points/decoration instead of a custom resolver with a core type ([T-08](./08-extension-points.md)).

## 3. Instructions

1. Create the plugin ([T-01](./01-project-structure-dev-environment.md), [T-02](./02-plugin-structure.md)); write resolver, struct, `services.php`.
2. Create admin files under `src/Resources/app/administration/src/`; `main.js` imports the element module.
3. Place the storefront Twig `cms-element-<name>.html.twig`.
4. CLI (in the Shopware root):
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/build-administration.sh      # rebuild admin bundle (T-49)
   bin/console cache:clear
   ```
   Iteration: `bin/watch-administration.sh`. Changes to Twig/PHP only: `cache:clear` is enough.
5. Admin: Content → Shopping Experiences → open layout → add a block with a slot → choose the element in the slot dialog. A block can fix the slot type (`slots: { … : 'swag-quote' }`, see T-22).
6. `registerCmsElement` options (type `CmsElementConfig`, `cms.service.ts`): `name`, `label`, `component`, `configComponent`, `previewComponent`, `defaultConfig` (required: `name`, `component`); optional `allowedPageTypes`, `defaultData`, `hidden`, `removable`, `flag`, `collect`, `enrich`, `appData`, `hover`, `disabledConfigInfoTextKey`.

## 4. Examples

Element "swag-quote": quote, author, image (media ID); the resolver loads the media entity.

`composer.json`
```json
{
    "name": "swag/example",
    "description": "Quote CMS element",
    "type": "shopware-platform-plugin",
    "version": "1.0.0",
    "license": "MIT",
    "autoload": { "psr-4": { "SwagExample\\": "src/" } },
    "extra": {
        "shopware-plugin-class": "SwagExample\\SwagExample",
        "label": { "de-DE": "Zitat-Element", "en-GB": "Quote element" }
    },
    "require": { "shopware/core": "~6.7.0" }
}
```

`src/SwagExample.php`
```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
}
```

`src/Struct/QuoteStruct.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Struct;

use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Struct\Struct;

class QuoteStruct extends Struct
{
    protected ?MediaEntity $media = null;

    public function getMedia(): ?MediaEntity
    {
        return $this->media;
    }

    public function setMedia(?MediaEntity $media): void
    {
        $this->media = $media;
    }

    public function getApiAlias(): string
    {
        return 'cms_swag_quote';
    }
}
```

`src/DataResolver/QuoteCmsElementResolver.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\DataResolver;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use SwagExample\Struct\QuoteStruct;

class QuoteCmsElementResolver extends AbstractCmsElementResolver
{
    public function getType(): string
    {
        return 'swag-quote';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        $mediaConfig = $slot->getFieldConfig()->get('media');
        if ($mediaConfig === null || $mediaConfig->isMapped() || $mediaConfig->getValue() === null) {
            return null;
        }

        $collection = new CriteriaCollection();
        // Key must be unique across all slots (otherwise CmsException::duplicateCriteriaKey)
        $collection->add(
            'media_' . $slot->getUniqueIdentifier(),
            MediaDefinition::class,
            new Criteria([$mediaConfig->getStringValue()])
        );

        return $collection;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $struct = new QuoteStruct();
        $slot->setData($struct); // always set a struct

        $mediaConfig = $slot->getFieldConfig()->get('media');
        if ($mediaConfig === null || $mediaConfig->isMapped() || $mediaConfig->getValue() === null) {
            return;
        }

        $media = $result->get('media_' . $slot->getUniqueIdentifier())?->get($mediaConfig->getStringValue());
        if ($media instanceof MediaEntity) {
            $struct->setMedia($media);
        }
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\DataResolver\QuoteCmsElementResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(QuoteCmsElementResolver::class)
        ->tag('shopware.cms.data_resolver');
};
```
Alternatively `services.xml` ([T-05](./05-registering-services.md)): XML is deprecated in 6.7, removal in 6.8.

`src/Resources/views/storefront/element/cms-element-swag-quote.html.twig`
```twig
{% block element_swag_quote %}
    {% set config = element.fieldConfig.elements %}

    <blockquote class="cms-element-{{ element.type }}">
        {% block element_swag_quote_media %}
            {% if element.data.media %}
                {% sw_thumbnails 'cms-swag-quote-image' with {
                    media: element.data.media,
                    sizes: { 'default': '200px' }
                } %}
            {% endif %}
        {% endblock %}

        {% block element_swag_quote_text %}
            <p class="swag-quote-text">{{ config.quote.value }}</p>
            <footer class="swag-quote-author">{{ config.author.value }}</footer>
        {% endblock %}
    </blockquote>
{% endblock %}
```

`src/Resources/app/administration/src/main.js`
```js
import './module/sw-cms/elements/swag-quote';
```

`…/elements/swag-quote/index.js`
```js
Shopware.Component.register('sw-cms-el-preview-swag-quote', () => import('./preview'));
Shopware.Component.register('sw-cms-el-config-swag-quote', () => import('./config'));
Shopware.Component.register('sw-cms-el-swag-quote', () => import('./component'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'swag-quote',
    label: 'swag-quote.elements.quote.label',   // snippet key (T-18)
    component: 'sw-cms-el-swag-quote',
    configComponent: 'sw-cms-el-config-swag-quote',
    previewComponent: 'sw-cms-el-preview-swag-quote',
    defaultConfig: {
        quote: { source: 'static', value: '' },
        author: { source: 'static', value: '' },
        media: { source: 'static', value: null, entity: { name: 'media' } },
    },
});
```

`…/component/index.js` and `sw-cms-el-swag-quote.html.twig`
```js
import template from './sw-cms-el-swag-quote.html.twig';

export default {
    template,

    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('swag-quote');
        },
    },
};
```
```twig
{% block sw_cms_element_swag_quote %}
    <blockquote class="sw-cms-el-swag-quote">
        <p>{{ element.config.quote.value }}</p>
        <footer>{{ element.config.author.value }}</footer>
    </blockquote>
{% endblock %}
```

`…/config/index.js` and `sw-cms-el-config-swag-quote.html.twig`
```js
import template from './sw-cms-el-config-swag-quote.html.twig';

export default {
    template,

    emits: ['element-update'],

    mixins: [Shopware.Mixin.getByName('cms-element')],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('swag-quote');
        },

        onChange() {
            this.$emit('element-update', this.element);
        },
    },
};
```
```twig
{% block sw_cms_el_config_swag_quote %}
    <div class="sw-cms-el-config-swag-quote">
        <mt-textarea v-model="element.config.quote.value" label="Quote" @update:model-value="onChange" />
        <mt-text-field v-model="element.config.author.value" label="Author" @update:model-value="onChange" />
    </div>
{% endblock %}
```

`…/preview/index.js` and `sw-cms-el-preview-swag-quote.html.twig`
```js
import template from './sw-cms-el-preview-swag-quote.html.twig';

export default { template };
```
```twig
{% block sw_cms_el_preview_swag_quote %}
    <div class="sw-cms-el-preview-swag-quote">“ Quote ”</div>
{% endblock %}
```

Media selection in the config dialog is missing from the minimal example (core: `sw-cms-mapping-field` or `sw-media-upload-v2`, see `elements/image/config/`); otherwise the media ID would only come via default/import. Reference elements in the core: `elements/youtube-video/` (index.ts, component/, config/, preview/) and `elements/html/`.

## 5. Pitfalls

- `name` ≠ `getType()` ≠ template suffix: no resolver or an empty element (`ignore missing` silently swallows a missing template).
- Duplicate criteria key in `collect()` -> `CmsException::duplicateCriteriaKey`; suffix the key with `$slot->getUniqueIdentifier()`.
- `enrich()` without `$slot->setData()`: `element.data` stays empty.
- `createdComponent()` without `initElementConfig('<name>')`: `defaultConfig` is not merged -> `Cannot read properties of undefined`.
- Config component without `emits: ['element-update']`/emit: changes are not applied.
- Admin build forgotten (only `cache:clear`): element does not appear.
- `registerCmsElement` without `name`/`component` or with `flag: false` returns `false` and does not register.
- Custom resolver with the type of a core element: the one iterated later overwrites the earlier one (`CmsSlotsDataResolver::__construct`).
- `source: 'mapped'` fields must be resolved via `resolveEntityValue()` (model: `TextCmsElementResolver`).
- Outputting unsanitized HTML content: core uses `HtmlSanitizer`; `|raw` only with sanitized data.
- Querying data in `enrich()` via repository instead of `collect()`: prevents `optimizeCriteriaObjects`, causes N+1.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Admin is Vue 3: config components declare `emits: ['element-update']`, `v-model:value` instead of `.sync`.
- `Shopware.Component.register(name, () => import(...))` (lazy) is standard in core elements.
- `cms.service.ts` and `elements/*/index.ts` are TypeScript; `CmsElementConfig` is typed.
- `AbstractCmsElementResolver::resolveDefinitionField()` and `resolveCriteriaForLazyLoadedRelations()` are `@deprecated tag:v6.8.0` – do not use.
- Extension points `CmsSlotsDataCollectExtension`, `CmsSlotsDataEnrichExtension`, `CmsSlotsDataResolveExtension` (`core/Content/Cms/Extension/`).
- The composable `useCmsElement` exists in 6.7.15.1, but is `@experimental stableVersion:v6.9.0 feature:ADMIN_MIXIN_COMPOSABLES` and `@private`; the docs say "since 6.7.16.0" (code takes precedence). For plugins in 6.7: use the mixin `cms-element`; `use-cms-element-deprecated.ts` is `@deprecated tag:v6.9.0`.

## 6. Checklist

- [ ] `name` = `getType()` = Twig suffix, with vendor prefix (`swag-quote`).
- [ ] `component`, `configComponent`, `previewComponent` registered and imported via `main.js`.
- [ ] `initElementConfig('<name>')` in editor and config component.
- [ ] Config component emits `element-update`.
- [ ] Resolver: criteria keys unique, `enrich()` always sets a struct, data loaded only in `collect()`.
- [ ] Storefront Twig with its own `{% block %}` wrappers for extensibility.
- [ ] Mapped fields and HTML output (sanitizing) handled.
- [ ] Admin rebuilt (`bin/build-administration.sh`), cache cleared.
- [ ] Label as snippet key (de + en).

## 7. Sources

- https://developer.shopware.com/docs/guides/plugins/plugins/content/cms/add-cms-element.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/cms/add-data-to-cms-elements.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/single-file-components/api-reference/composables/use-cms-element.html
- Code: `core/Content/Cms/DataResolver/Element/CmsElementResolverInterface`, `AbstractCmsElementResolver`, `TextCmsElementResolver`, `ElementDataCollection`; `core/Content/Cms/DataResolver/CmsSlotsDataResolver`, `CriteriaCollection`, `FieldConfig(Collection)`; `core/Content/Cms/Aggregate/CmsSlot/CmsSlotEntity`; `core/Content/Product/Cms/ManufacturerLogoCmsElementResolver`; `core/Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`.
- Admin: `module/sw-cms/service/cms.service.ts` (`registerCmsElement`), `module/sw-cms/mixin/sw-cms-element.mixin.ts`, `module/sw-cms/elements/youtube-video/`, `html/`, `app/composables/use-cms-element.ts`.
- Storefront: `storefront/Resources/views/storefront/element/cms-element-*.html.twig`, `block/cms-block-text.html.twig`.

### Gaps and uncertainties

- ⚠ UNVERIFIED: minimal example not built/run; follows the docs and core patterns.
- ⚠ UNVERIFIED: `services.php` in the plugin: the docs mention it; whether 6.7.15.1 loads both formats (php/xml) in the plugin was not checked against the plugin loader.
- ⚠ UNVERIFIED: `sw_thumbnails … with {media, sizes}` adopted from core patterns (`cms-element-image.html.twig`); `mt-textarea`/`mt-text-field` not individually looked up in the core.
- ⚠ UNVERIFIED: registration via object form (`Component.register(name, {…})`) not re-verified in 6.7; entry point `main.js` vs. `main.ts` not checked separately.
- ⚠ GAP: whether `CmsSlots*Extension` existed in 6.5/6.6 is not documented; discrepancy between docs (6.7.16.0) and code for `useCmsElement` not resolved.
