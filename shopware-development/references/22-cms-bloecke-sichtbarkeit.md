# CMS-Blöcke, Sektionen und Sichtbarkeit (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn ein eigener CMS-Block (Layout-Baustein mit Slots) entstehen soll, wenn Sektionstypen, Layout-Zuweisung oder die **Sichtbarkeit** von Blöcken/Sektionen (Mobil/Tablet/Desktop) betroffen sind, und wenn die Frage nach „regelbasierter Sichtbarkeit" auftaucht.

Abgrenzung: Inhalt der Slots (Elemente, Resolver): [T-21 CMS-Elemente](./21-cms-elemente.md). Twig-Mechanik: [T-14](./14-storefront-twig-templates.md). Rule Builder allgemein: [T-32](./32-rule-builder.md). Snippets: [T-18](./18-snippets-uebersetzungen.md). Build: [T-49](./49-build-deployment.md). Gerüst: [../templates/cms-block/README.md](../templates/cms-block/README.md).

## 2. Konzepte

### Aussage zur regelbasierten Sichtbarkeit (belegt vs. Lücke)

**Belegt (Quellcode 6.7.15.1):** Für CMS-Blöcke und -Sektionen existiert **keine** regelbasierte (Rule-Builder-)Sichtbarkeit.
- `CmsBlockDefinition` und `CmsSectionDefinition` haben nur das JSON-Feld `visibility` (`mobile`, `tablet`, `desktop`, je Bool; `JsonField('visibility', …)`), keine Rule-Assoziation, kein `ruleId`.
- `grep` nach `ruleId`/`rule_id`/`rules` unter `core/Content/Cms` liefert keinen Treffer; die Cms-Definitionen unter `Aggregate/*/` enthalten keinen Rule-Bezug; `core/Framework/Rule` und `core/Content/Rule` enthalten keine CMS-Bezüge.
- Die Admin-Sichtbarkeitssteuerung (`sw-cms-visibility-config`) bietet nur die drei Viewport-Checkboxen.

**Lücke (nicht geprüft):** Kommerzielle Erweiterungen (Shopware Commercial/Extensions), die Sichtbarkeit nach Regeln nachrüsten könnten, sind nicht im installierten Code (nur `core`, `storefront`, `administration`, `elasticsearch`). ⚠ LÜCKE: Aussage gilt nur für den Open-Source-Code 6.7.15.1; 6.5/6.6 nicht gegen Quellcode geprüft.

Konsequenz: Wer „Block nur für Kundengruppe X/Zeitraum/Warenkorb" braucht, muss eine Alternative wählen (siehe Fallstricke): Layout je Kategorie/Landingpage, eigenes Element mit Logik im Resolver, oder Twig-Logik im eigenen Block-Template (z. B. `context.currentCustomerGroup`, `context.customer`).

### Aufbau eines Blocks

- Registrierung in der Administration: `Shopware.Service('cmsService').registerCmsBlock({...})` (`name` = DB-Feld `cms_block.type`).
- Admin-Komponente + Preview-Komponente (Vue).
- Storefront: `storefront/block/cms-block-<name>.html.twig`. Eingebunden dynamisch von `section/cms-section-block-container.html.twig` via `sw_include '@Storefront/storefront/block/cms-block-' ~ block.type ~ '.html.twig' ignore missing` – fehlende Datei = Block still leer.
- Slots: `block.slots.getSlot('<slotName>')`, gerendert über `'@Storefront/storefront/element/cms-element-' ~ element.type ~ '.html.twig'`.

### Sektionen

Typen genau `default` und `sidebar` (`CmsSectionDefinition::TYPE_DEFAULT/TYPE_SIDEBAR`, per `Choice` erzwungen).
- `default`: `section/cms-section-default.html.twig`, eine Spalte; Container-Klasse aus `section.sizingMode` (`boxed` -> `container`, `full_width` -> `full-width`).
- `sidebar`: `section/cms-section-sidebar.html.twig`; `filterBySectionPosition('sidebar'|'main')` (Block-Feld `sectionPosition`); `section.mobileBehavior` setzt Klasse `cms-section-sidebar-mobile-<wert>`.
- Beide rendern Blöcke über `cms-section-block-container.html.twig` (Wrapper-Klassen `cms-block cms-block-<type> pos-<position>`, Margins als `padding`, Hintergrund, Visibility-Klassen).
- Sektions-Wrapper in `page/content/detail.html.twig` (Block `page_content_section`): `cms-section cms-section-<type> pos-<n>`. Include ohne `ignore missing` -> unbekannter Typ = Twig-Fehler. Eigene Sektionstypen sind nicht vorgesehen.

### Sichtbarkeit (Viewport)

- DB/DAL: JSON-Feld `visibility {mobile, tablet, desktop}` an `cms_block` und `cms_section`. `null` = alle `true`. Pro `false` fügt Twig eine Klasse hinzu: `hidden-mobile|hidden-tablet|hidden-desktop`.
- CSS: `storefront/Resources/app/storefront/src/scss/component/_visibility.scss` – `hidden-mobile` unter 768 px (`media-breakpoint-down(md)`, xs/sm), `hidden-tablet` 768–991,98 px (`media-breakpoint-only(md)`), `hidden-desktop` ab 992 px (`media-breakpoint-up(lg)`). Grenzen gelten für die Bootstrap-Standard-Breakpoints; geänderte Theme-Breakpoints verschieben sie (siehe [T-12](./12-theme-config-scss-variablen.md)).
- **Der HTML-Code wird trotzdem ausgeliefert** (`display: none`), kein Sicherheits- oder Performance-Mittel; Bilder können trotzdem laden.
- Admin: `sw-cms-visibility-config` (Checkboxen), `sw-cms-visibility-toggle` (Vorschau-Umschalter). Neue Blöcke erhalten alle drei `true`.

### Layout-Zuweisung

`cms_page_id` an `category`, `product` (vererbbar), `landing_page`; Seitentypen `landingpage`, `page`, `product_list`, `product_detail`.

Verzeichnisbaum:
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
Vorbild im Core: `…/module/sw-cms/blocks/text-image/image-text/` und `storefront/Resources/views/storefront/block/cms-block-image-text.html.twig`.

## 3. Anleitung

1. Plugin bereitstellen ([T-02](./02-plugin-aufbau.md)); Admin-Dateien nach Baum anlegen, `main.js` importiert das Block-Modul.
2. Storefront-Twig `block/cms-block-<name>.html.twig` anlegen (Dateiname = `name`).
3. Installieren und bauen:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/build-administration.sh
   bin/console cache:clear
   bin/console theme:compile        # nur falls Storefront-SCSS/JS dazukam
   ```
   Dev: `bin/watch-administration.sh`.
4. Admin: Inhalte → Shopping Experiences → Layout anlegen → Block-Menü → Kategorie „Text" → Block einfügen.
5. Sichtbarkeit: Block auswählen → Einstellungen → Checkboxen Mobil/Tablet/Desktop; Sektion analog im Sektions-Tab (`sw-cms-section-config`).
6. Layout zuweisen: „Layout-Zuweisung" im Layout-Editor (`sw-cms-layout-assignment-modal`) oder in Kategorie/Produkt/Landingpage (`cmsPageId`).
7. Seitentyp einschränken (optional): `allowedPageTypes: [Shopware.Constants.CMS.PAGE_TYPES.LISTING]` (Beispiel `blocks/sidebar/sidebar-filter/index.ts`; Prüfung `CmsService::isBlockAllowedInPageType`). Ohne Angabe: alle Typen.
8. Weitere `CmsBlockConfig`-Optionen: `configComponent`, `previewImage`, `appName`, `category`, `label`, `flag`, `hidden` (aus Block-Menü ausblenden), `removable` (`false` verhindert Löschen/Duplizieren, z. B. `product-listing`), `defaultConfig`, `slots`. `registerCmsBlock` gibt `false` zurück und registriert nichts, wenn `name`/`component` fehlt oder `flag === false`.
9. Eigene Block-Kategorie braucht das Snippet `apps.sw-cms.detail.label.blockCategory.<category>`; Kategorie `app` wird für App-Blöcke automatisch ergänzt.

## 4. Beispiele

Zwei Slots (links Text, rechts Bild), die Core-Elemente `text` und `image` werden wiederverwendet.

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
    label: 'Demo Teaser',               // im Core: Snippet-Key
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
(Core `cms-block-image-text` setzt `{% set element = … %}` und bindet ohne `with` ein; bei zwei Slots ist `with { element: … }` robuster.)

Beispiel Alternative zur Regel-Sichtbarkeit: Twig-Logik im eigenen Block (⚠ UNGEPRÜFT: Kundengruppen-Zugriff nach üblichem `context`-Muster, siehe [T-20](./20-sales-channel-unterscheidung.md); beachte Cache, [T-41](./41-caching.md)):
```twig
{% block block_swag_demo_teaser_member %}
    {% if context.customer is not null %}
        {# nur für eingeloggte Kunden #}
    {% endif %}
{% endblock %}
```

## 5. Fallstricke

- Twig-Dateiname passt nicht zu `name`/`type`: Block bleibt wegen `ignore missing` stumm leer.
- Blockname kollidiert mit Core-Namen (`text`, `image`): `registerCmsBlock` überschreibt `blockRegistry[name]` einfach; Vendor-Präfix vergeben.
- Slot-Name in `slots`, Admin-`<slot name>` und Storefront-`getSlot('…')` müssen identisch sein; sonst `getSlot` -> `null` und Fehler bei `.type`.
- `component`-String ≠ registrierter Komponentenname, Import in `main.js` oder Admin-Build fehlt: Block erscheint nicht.
- Eigene Sektionstypen (`cms-section-foo`) scheitern an `Choice` in `CmsSectionDefinition` und Include ohne `ignore missing`.
- **Annahme „regelbasierte Sichtbarkeit" ist falsch** (siehe Abschnitt 2); Alternativen: Layout je Kategorie/Landingpage, eigenes Element, Twig-Logik im Block-Template.
- Sichtbarkeit per Viewport ist nur CSS: nicht für sensible Inhalte nutzen.
- Slot-Typ ohne vorhandenes Element: Elementtemplate fehlt, `ignore missing` verschluckt es.
- Twig-Blöcke weglassen (`{% block block_<name> %}`): Dritte können nicht per `sw_extends` erweitern. Core-Block-Twigs per `sw_extends`/Block-Overrides ändern (T-14), nicht überschreiben.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Komponenten werden im Core mit `Component.register('name', () => import('./x'))` (lazy) und Vue-3-Stil registriert; für Plugins bleibt die Objektform `register(name, {template})` (Doku-Stil) – ⚠ UNGEPRÜFT, siehe Lücken.
- Core-Block-Registrierung liegt in `index.ts` (`CmsBlockConfig`); `allowedPageTypes` für Blöcke wird in `isBlockAllowedInPageType` ausgewertet.
- App-Blöcke: `CmsExtensions` (`core/Framework/App/Cms/`, Schema `cms-1.0.xsd`), Admin-Komponente `sw-cms-block-app-renderer` ([T-10](./10-app-system-abgrenzung.md)).
- `Category.cmsPageIdSwitched` deprecated (`@Deprecated('v6.7.9.0', 'v6.8.0.0')`).
- 6.5/6.6-Unterschiede nicht am Code belegbar (nur 6.7 installiert).

## 6. Checkliste

- [ ] Blockname mit Vendor-Präfix, identisch in `registerCmsBlock`, Twig-Dateiname und Komponenten.
- [ ] Admin-Komponente + Preview-Komponente registriert und in `main.js` importiert.
- [ ] Slot-Namen in Registrierung, Admin-Template und Storefront-Twig identisch.
- [ ] Slot-Elemente existieren (Core oder T-21) inklusive Storefront-Template.
- [ ] Storefront-Twig mit eigenen `{% block %}`-Wrappern.
- [ ] Sichtbarkeit nur als Viewport-Hilfe verstanden; keine Regel-Sichtbarkeit angenommen.
- [ ] `allowedPageTypes`/`removable`/`hidden` bewusst gesetzt.
- [ ] Eigene Kategorie mit Snippet `apps.sw-cms.detail.label.blockCategory.<category>`.
- [ ] Admin gebaut, Cache geleert, Block im Layout-Editor und Storefront geprüft.

## 7. Quellen

- https://developer.shopware.com/docs/guides/plugins/plugins/content/cms/add-cms-block.html
- https://developer.shopware.com/docs/concepts/commerce/content/shopping-experiences-cms.html (nicht abgerufen)
- Admin: `module/sw-cms/service/cms.service.ts` (`CmsBlockConfig`, `registerCmsBlock`, `isBlockAllowedInPageType`), `module/sw-cms/blocks/**`, `component/sw-cms-sidebar/index.ts`, `sw-cms-visibility-config/`, `sw-cms-visibility-toggle/`, `sw-cms-layout-assignment-modal/`, `constant/sw-cms.constant.ts`, `shared/CmsVisibility.ts`.
- Core: `core/Content/Cms/Aggregate/CmsBlock/CmsBlockDefinition.php`, `CmsSection/CmsSectionDefinition.php`, `CmsSlot/CmsSlotDefinition.php`, `core/Content/Cms/CmsPageDefinition.php`, `SalesChannel/SalesChannelCmsPageLoader.php`, `core/Content/Category/CategoryDefinition.php`, `ProductDefinition.php`, `core/Content/LandingPage/LandingPageDefinition.php`, `core/Framework/App/Cms/`.
- Storefront: `views/storefront/page/content/{index,detail}.html.twig`, `section/cms-section-{default,sidebar,block-container}.html.twig`, `block/cms-block-*.html.twig`, `scss/component/_visibility.scss`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: „Keine regelbasierte Sichtbarkeit" gilt für den installierten Open-Source-Code (`cms_block`/`cms_section`/`cms_slot` nur `visibility`-Bool); kommerzielle Erweiterungen nicht geprüft.
- ⚠ UNGEPRÜFT: Beispiel nicht im Container gebaut; Struktur folgt Doku und Core-Block `image-text`. `label` im Beispiel Klartext, im Core Snippet-Keys.
- ⚠ UNGEPRÜFT: Objektform `Component.register(name, {template})` in 6.7 für Plugins nicht separat verifiziert (Core nutzt Lazy-Import).
- ⚠ LÜCKE: Doku zu eigenen Sektionstypen existiert nicht; „nicht vorgesehen" folgt aus `Choice` und fehlendem `ignore missing`. Doku-URL `shopping-experiences-cms.html` nicht abgerufen.
- ⚠ UNGEPRÜFT: 6.5/6.6-Unterschiede nur aus 6.7-Code und Doku abgeleitet.
