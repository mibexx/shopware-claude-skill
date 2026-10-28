# Eigene CMS-Elemente (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein eigenes Shopping-Experiences-Element (Slot-Inhalt) entstehen soll: Admin-Komponenten (Editor, Konfiguration, Vorschau), Storefront-Twig und optional ein PHP-Resolver, der Daten nachlädt.

Abgrenzung: Blöcke (Layout-Container mit Slots), Sektionen und Sichtbarkeit: [T-22 CMS-Blöcke und Sichtbarkeit](./22-cms-bloecke-sichtbarkeit.md). Twig-Mechanik: [T-14](./14-storefront-twig-templates.md). Snippets: [T-18](./18-snippets-uebersetzungen.md). Admin-Build: [T-49](./49-build-deployment.md). Gerüst: [../templates/cms-element/README.md](../templates/cms-element/README.md).

## 2. Konzepte

Ein CMS-Element besteht aus drei Teilen:

1. **Administration:** drei Vue-Komponenten (Editor-Ansicht `component`, Einstellungen `configComponent`, Vorschau `previewComponent`), registriert mit `Shopware.Service('cmsService').registerCmsElement({...})`, geladen aus `src/Resources/app/administration/src/main.js`.
2. **Storefront:** Twig `src/Resources/views/storefront/element/cms-element-<name>.html.twig`. Der Block-Wrapper bindet es per `sw_include '@Storefront/storefront/element/cms-element-' ~ element.type ~ '.html.twig' ignore missing` ein (z. B. `block/cms-block-text.html.twig:7`). Im Template: `element` (= `CmsSlotEntity`), `element.fieldConfig.elements` (Config), `element.data` (vom Resolver gesetztes Struct).
3. **Optional PHP-Resolver:** implementiert `CmsElementResolverInterface` (üblich `extends AbstractCmsElementResolver`) mit `getType()`, `collect()`, `enrich()`. Typ = `name` des Elements. Tag `shopware.cms.data_resolver` setzt der Container per Autoconfiguration (`AutoconfigureCompilerPass.php:149-151`); explizites Tag schadet nicht.

Ohne Resolver bleibt `element.data` leer; reine Config-Werte (`element.fieldConfig.elements.<key>.value`) funktionieren trotzdem.

Namensgleichheit: `name` in `registerCmsElement` = `getType()` = Suffix im Twig-Dateinamen.

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
Pfad `module/sw-cms/elements/<name>/` ist Konvention (Core: `administration/Resources/app/administration/src/module/sw-cms/elements/`), kein Zwang.

Entscheidungshilfe: nur statische Eingaben -> kein Resolver. Entities/Medien/Produkte nachladen -> Resolver mit `collect()` (Criteria bündeln). Für Änderungen an Core-Elementen Extension-Points/Dekoration statt eigenem Resolver mit Core-Typ ([T-08](./08-extension-points.md)).

## 3. Anleitung

1. Plugin anlegen ([T-01](./01-projektstruktur-dev-umgebung.md), [T-02](./02-plugin-aufbau.md)); Resolver, Struct, `services.php` schreiben.
2. Admin-Dateien unter `src/Resources/app/administration/src/` anlegen; `main.js` importiert das Element-Modul.
3. Storefront-Twig `cms-element-<name>.html.twig` ablegen.
4. CLI (im Shopware-Root):
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/build-administration.sh      # Admin-Bundle neu bauen (T-49)
   bin/console cache:clear
   ```
   Iteration: `bin/watch-administration.sh`. Änderungen nur an Twig/PHP: `cache:clear` genügt.
5. Admin: Inhalte → Shopping Experiences → Layout öffnen → Block mit Slot hinzufügen → im Slot-Dialog das Element wählen. Ein Block kann den Slot-Typ fest vorgeben (`slots: { … : 'swag-quote' }`, siehe T-22).
6. `registerCmsElement`-Optionen (Typ `CmsElementConfig`, `cms.service.ts`): `name`, `label`, `component`, `configComponent`, `previewComponent`, `defaultConfig` (Pflicht: `name`, `component`); optional `allowedPageTypes`, `defaultData`, `hidden`, `removable`, `flag`, `collect`, `enrich`, `appData`, `hover`, `disabledConfigInfoTextKey`.

## 4. Beispiele

Element „swag-quote": Zitat, Autor, Bild (Media-ID); der Resolver lädt die Media-Entity.

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
        // Key muss über alle Slots eindeutig sein (sonst CmsException::duplicateCriteriaKey)
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
        $slot->setData($struct); // immer ein Struct setzen

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
Alternativ `services.xml` ([T-05](./05-services-registrieren.md)): XML ist in 6.7 deprecated, Entfernung in 6.8.

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
    label: 'swag-quote.elements.quote.label',   // Snippet-Schlüssel (T-18)
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

`…/component/index.js` und `sw-cms-el-swag-quote.html.twig`
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

`…/config/index.js` und `sw-cms-el-config-swag-quote.html.twig`
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

`…/preview/index.js` und `sw-cms-el-preview-swag-quote.html.twig`
```js
import template from './sw-cms-el-preview-swag-quote.html.twig';

export default { template };
```
```twig
{% block sw_cms_el_preview_swag_quote %}
    <div class="sw-cms-el-preview-swag-quote">“ Quote ”</div>
{% endblock %}
```

Medienauswahl im Config-Dialog fehlt im Minimalbeispiel (Core: `sw-cms-mapping-field` bzw. `sw-media-upload-v2`, siehe `elements/image/config/`); die Media-ID käme sonst nur über Default/Import. Referenzelemente im Core: `elements/youtube-video/` (index.ts, component/, config/, preview/) und `elements/html/`.

## 5. Fallstricke

- `name` ≠ `getType()` ≠ Template-Suffix: kein Resolver bzw. leeres Element (`ignore missing` verschluckt fehlendes Template stumm).
- Doppelter Criteria-Key in `collect()` -> `CmsException::duplicateCriteriaKey`; Key mit `$slot->getUniqueIdentifier()` suffixen.
- `enrich()` ohne `$slot->setData()`: `element.data` bleibt leer.
- `createdComponent()` ohne `initElementConfig('<name>')`: `defaultConfig` wird nicht gemergt -> `Cannot read properties of undefined`.
- Config-Komponente ohne `emits: ['element-update']`/Emit: Änderungen werden nicht übernommen.
- Admin-Build vergessen (nur `cache:clear`): Element erscheint nicht.
- `registerCmsElement` ohne `name`/`component` oder mit `flag: false` gibt `false` zurück und registriert nicht.
- Eigener Resolver mit Typ eines Core-Elements: der später iterierte überschreibt den früheren (`CmsSlotsDataResolver::__construct`).
- `source: 'mapped'`-Felder müssen über `resolveEntityValue()` aufgelöst werden (Vorbild `TextCmsElementResolver`).
- Ungesäuberten HTML-Inhalt ausgeben: Core nutzt `HtmlSanitizer`; `|raw` nur mit gesäuberten Daten.
- Datenabfrage im `enrich()` per Repository statt `collect()`: verhindert `optimizeCriteriaObjects`, erzeugt N+1.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Admin ist Vue 3: Config-Komponenten deklarieren `emits: ['element-update']`, `v-model:value` statt `.sync`.
- `Shopware.Component.register(name, () => import(...))` (lazy) ist in Core-Elementen Standard.
- `cms.service.ts` und `elements/*/index.ts` sind TypeScript; `CmsElementConfig` ist typisiert.
- `AbstractCmsElementResolver::resolveDefinitionField()` und `resolveCriteriaForLazyLoadedRelations()` `@deprecated tag:v6.8.0` – nicht verwenden.
- Extension-Points `CmsSlotsDataCollectExtension`, `CmsSlotsDataEnrichExtension`, `CmsSlotsDataResolveExtension` (`core/Content/Cms/Extension/`).
- Composable `useCmsElement` ist in 6.7.15.1 vorhanden, aber `@experimental stableVersion:v6.9.0 feature:ADMIN_MIXIN_COMPOSABLES` und `@private`; Doku nennt „seit 6.7.16.0" (Code gilt). Für Plugins in 6.7: Mixin `cms-element` verwenden; `use-cms-element-deprecated.ts` ist `@deprecated tag:v6.9.0`.

## 6. Checkliste

- [ ] `name` = `getType()` = Twig-Suffix, mit Vendor-Präfix (`swag-quote`).
- [ ] `component`, `configComponent`, `previewComponent` registriert und per `main.js` importiert.
- [ ] `initElementConfig('<name>')` in Editor- und Config-Komponente.
- [ ] Config-Komponente emittiert `element-update`.
- [ ] Resolver: Criteria-Keys eindeutig, `enrich()` setzt immer ein Struct, Daten nur in `collect()` geladen.
- [ ] Storefront-Twig mit eigenen `{% block %}`-Wrappern für Erweiterbarkeit.
- [ ] Mapped-Felder und HTML-Ausgabe (Sanitizing) behandelt.
- [ ] Admin neu gebaut (`bin/build-administration.sh`), Cache geleert.
- [ ] Label als Snippet-Schlüssel (de + en).

## 7. Quellen

- https://developer.shopware.com/docs/guides/plugins/plugins/content/cms/add-cms-element.html
- https://developer.shopware.com/docs/guides/plugins/plugins/content/cms/add-data-to-cms-elements.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/single-file-components/api-reference/composables/use-cms-element.html
- Code: `core/Content/Cms/DataResolver/Element/CmsElementResolverInterface`, `AbstractCmsElementResolver`, `TextCmsElementResolver`, `ElementDataCollection`; `core/Content/Cms/DataResolver/CmsSlotsDataResolver`, `CriteriaCollection`, `FieldConfig(Collection)`; `core/Content/Cms/Aggregate/CmsSlot/CmsSlotEntity`; `core/Content/Product/Cms/ManufacturerLogoCmsElementResolver`; `core/Framework/DependencyInjection/CompilerPass/AutoconfigureCompilerPass.php`.
- Admin: `module/sw-cms/service/cms.service.ts` (`registerCmsElement`), `module/sw-cms/mixin/sw-cms-element.mixin.ts`, `module/sw-cms/elements/youtube-video/`, `html/`, `app/composables/use-cms-element.ts`.
- Storefront: `storefront/Resources/views/storefront/element/cms-element-*.html.twig`, `block/cms-block-text.html.twig`.

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Minimalbeispiel nicht gebaut/ausgeführt; folgt Doku und Core-Mustern.
- ⚠ UNGEPRÜFT: `services.php` im Plugin: Doku nennt es, ob 6.7.15.1 beide Formate (php/xml) im Plugin lädt, am Plugin-Loader nicht geprüft.
- ⚠ UNGEPRÜFT: `sw_thumbnails … with {media, sizes}` nach Core-Mustern (`cms-element-image.html.twig`) übernommen; `mt-textarea`/`mt-text-field` im Core nicht einzeln nachgesehen.
- ⚠ UNGEPRÜFT: Registrierung per Objekt-Form (`Component.register(name, {…})`) in 6.7 nicht erneut verifiziert; Einstieg `main.js` vs. `main.ts` nicht separat geprüft.
- ⚠ LÜCKE: Ob `CmsSlots*Extension` in 6.5/6.6 existierten, nicht belegt; Diskrepanz Doku (6.7.16.0) vs. Code für `useCmsElement` nicht aufgeklärt.
