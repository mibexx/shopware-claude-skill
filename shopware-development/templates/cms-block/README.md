# Template: CMS-Block (swag-example-block)

Zweck: Eigener Block für Shopping Experiences mit zwei Slots (links Text, rechts Bild), Admin-Registrierung (`registerCmsBlock`), Block-Komponente, Preview-Komponente und Storefront-Block-Template. Shopware 6.7.15.1. Die Slot-Elemente sind Core-Elemente (`text`, `image`), es ist kein eigenes Element nötig. Für ein eigenes Element siehe [cms-element](../cms-element/README.md).

## Dateibaum

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    └── Resources/
        ├── views/storefront/block/cms-block-swag-example-block.html.twig
        └── app/administration/src/
            ├── main.js                                          # importiert den Block
            ├── snippet/{de,en}.json                       # Block-Label
            └── module/sw-cms/blocks/text/swag-example-block/
                ├── index.js                                     # Component.register + registerCmsBlock
                ├── component/{index.js,*.html.twig,*.scss}      # Editor-Darstellung mit <slot>
                └── preview/{index.js,*.html.twig,*.scss}        # Vorschau im Block-Menü
```

## Platzhalter

| Platzhalter | Ersetzen durch | Wo |
|---|---|---|
| `swag-example-block` | Blockname (Kebab-Case, mit Vendor-Präfix) | `name` in `registerCmsBlock`, Dateiname `cms-block-<name>.html.twig`, Komponentennamen, Ordner |
| `text` (Ordner/`category`) | Block-Kategorie | `category` in `registerCmsBlock` (Core-Kategorien u. a. `text`, `image`, `video`, `text-image`, `commerce`, `sidebar`, `form`, `html`) |
| `left` / `right` | Slot-Namen | `slots` in `index.js`, `<slot name>` in der Komponente, `getSlot('...')` im Storefront-Twig |
| `SwagExample` / `swag/example` | Plugin-Name | Klasse, Namespace, `composer.json` |
| `swag-example.blocks.swagExampleBlock.label` | Eigener Snippet-Schlüssel | `index.js`, Snippet-Dateien |

Der Blockname (`name`) entspricht dem Feld `cms_block.type` und muss dem Dateinamen des Storefront-Templates entsprechen. Sonst bleibt der Block leer, weil `section/cms-section-block-container.html.twig` per `sw_include ... ignore missing` einbindet.

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh        # bzw. bin/watch-administration.sh
bin/console cache:clear
```

Im Admin: Inhalte -> Shopping Experiences -> Layout anlegen -> Block-Menü -> Kategorie "Text" -> Block einfügen. Layout zuweisen über die Layout-Zuweisung oder in Kategorie/Produkt/Landingpage (`cmsPageId`).

## Regeln (am Quellcode belegt)

- `registerCmsBlock({ name, component, defaultConfig, ... })`: gibt `false` zurück und registriert nichts, wenn `name` oder `component` fehlt oder `flag === false`. Optional: `label`, `category`, `previewComponent`, `previewImage`, `configComponent`, `slots`, `allowedPageTypes`, `hidden`, `removable`.
- `slots`: Zuordnung Slot-Name -> Elementtyp (String) oder `{ type, default: { config, data } }`.
- Wrapper im Storefront: der Block-Twig wird innerhalb `div.cms-block-container-row.row` gerendert, daher Spaltenklassen wie `col-md-6`.
- Sektionstypen sind nur `default` und `sidebar`. Sichtbarkeit (`visibility` mobil/tablet/desktop) wird per CSS-Klassen `hidden-*` gesteuert, der HTML-Code wird trotzdem ausgeliefert. Regelbasierte Sichtbarkeit (Rule Builder) gibt es für CMS-Blöcke/Sektionen in 6.7.15.1 nicht.
- Eigene Block-Kategorien brauchen das Admin-Snippet `apps.sw-cms.detail.label.blockCategory.<category>`. Deshalb nutzt das Gerüst die Core-Kategorie `text`.
- Blockname mit Vendor-Präfix wählen, ein Core-Name (z. B. `text`) würde die Registrierung überschreiben.
- Jeder Block-Twig beginnt mit `{% block block_<name> %}`, damit Dritte per `sw_extends` erweitern können.

## Lücken und Hinweise

- ⚠ UNGEPRÜFT: Gerüst nicht gebaut/installiert (kein PHP, kein Admin-Build). JS per `node --check`, JSON per `JSON.parse`. Muster 1:1 vom Core-Block `image-text` (`module/sw-cms/blocks/text-image/image-text/`) und `storefront/block/cms-block-image-text.html.twig` abgeleitet.
- ⚠ UNGEPRÜFT: Ob die Objektform `Shopware.Component.register(name, {...})` in 6.7.15.1 für Plugins gleichwertig zur hier genutzten Lazy-Form (`() => import(...)`, Core-Standard) funktioniert, wurde nicht geprüft.
- ⚠ LÜCKE: Block-Konfigurationskomponente (`configComponent`) und Vorschaubild (`previewImage`) sind nicht enthalten.
- ⚠ LÜCKE: Das Label (`label`) ist ein Snippet-Schlüssel. Snippets werden vom `SnippetFinder` aus `Resources/app/administration/src/**/<locale>.json` gelesen (siehe Referenz 18).

## Referenzen

- [22 CMS-Blöcke & Sichtbarkeit](../../references/22-cms-bloecke-sichtbarkeit.md)
- [21 CMS-Elemente](../../references/21-cms-elemente.md)
- [14 Storefront-Twig-Templates](../../references/14-storefront-twig-templates.md)
- [18 Snippets & Übersetzungen](../../references/18-snippets-uebersetzungen.md)
- [49 Build & Deployment](../../references/49-build-deployment.md)
- Weitere Templates: [cms-element](../cms-element/README.md), [plugin](../plugin/README.md)

- Snippet-Dateien sind sprachneutral benannt (`de.json`/`en.json` bzw. `storefront.de.json`/`storefront.en.json`); regionale Namen (`de-DE.json`) beanstandet `bin/console translation:lint-filenames` ohne sprachneutrale Basisdatei, Reparatur mit `--fix` (siehe [T-18](../../references/18-snippets-uebersetzungen.md)).
