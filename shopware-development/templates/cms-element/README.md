# Template: CMS-Element (swag-example-element)

Zweck: Eigenes Shopping-Experiences-Element mit Admin-Registrierung (`registerCmsElement` mit `component`, `configComponent`, `previewComponent`), Storefront-Twig und PHP-DataResolver. Shopware 6.7.15.1. Das Element zeigt eine Überschrift und einen HTML-Inhalt (im Resolver per `HtmlSanitizer` bereinigt).

## Dateibaum

```
SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── DataResolver/SwagExampleElementCmsElementResolver.php   # getType/collect/enrich
    ├── Struct/SwagExampleElementStruct.php                     # landet in element.data
    └── Resources/
        ├── config/services.php                                 # Tag shopware.cms.data_resolver
        ├── views/storefront/element/cms-element-swag-example-element.html.twig
        └── app/administration/src/
            ├── main.js                                         # importiert das Element
            ├── snippet/{de,en}.json                      # Label-Snippets (Prefix swag-example)
            └── module/sw-cms/elements/swag-example-element/
                ├── index.js                                    # Component.register + registerCmsElement
                ├── component/{index.js,sw-cms-el-swag-example-element.html.twig}
                ├── config/{index.js,sw-cms-el-config-swag-example-element.html.twig}
                └── preview/{index.js,sw-cms-el-preview-swag-example-element.html.twig}
```

## Platzhalter

| Platzhalter | Ersetzen durch | Wo |
|---|---|---|
| `swag-example-element` | Elementname (Kebab-Case, mit Vendor-Präfix) | `name` in `registerCmsElement`, `getType()` im Resolver, Dateiname `cms-element-<name>.html.twig`, `initElementConfig('<name>')`, Komponentennamen, Ordner |
| `SwagExampleElement...` | PascalCase des Elementnamens | Resolver-/Struct-Klasse |
| `SwagExample` / `swag/example` | Plugin-Name | Klasse, Namespace, `composer.json` |
| `swag-example.elements.swagExampleElement.*` | Eigene Snippet-Schlüssel | `index.js`, Config-Template, Snippet-Dateien |
| `cms_swag_example_element` | API-Alias des Structs | `getApiAlias()` |

Wichtig: `name` (Admin) = `getType()` (PHP) = Suffix des Storefront-Templates. Sonst bleibt das Element leer (der Block-Wrapper nutzt `sw_include ... ignore missing`, ein fehlendes Template erzeugt keinen Fehler).

## Einbindung / CLI

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SwagExample
bin/build-administration.sh        # Admin-Bundle bauen (bzw. bin/watch-administration.sh)
bin/console cache:clear
```

Im Admin: Inhalte -> Shopping Experiences -> Layout -> Block mit Slot -> im Slot-Dialog das Element wählen. Nur Twig/PHP-Änderungen brauchen nur `cache:clear`.

## Regeln (am Quellcode belegt)

- Resolver: `CmsElementResolverInterface` mit `getType()`, `collect(CmsSlotEntity, ResolverContext): ?CriteriaCollection`, `enrich(CmsSlotEntity, ResolverContext, ElementDataCollection): void`. Üblich `extends AbstractCmsElementResolver`.
- `enrich()` setzt immer ein Struct (`$slot->setData()`), sonst bleibt `element.data` leer.
- Daten gehören in `collect()` (Criteria werden gebündelt), nicht per Repository in `enrich()` (N+1). `CriteriaCollection::add($key, $definition, $criteria)`, Key eindeutig über alle Slots.
- Service-Tag `shopware.cms.data_resolver` setzt der Core per Autokonfiguration, ein explizites Tag schadet nicht.
- Plugin-Service-Dateien: `Resources/config/services.php` wird vom Bundle geladen. XML ist deprecated (6.8).
- Admin: `createdComponent()` muss `initElementConfig('<name>')` aufrufen (merged `defaultConfig`), die Config-Komponente muss `element-update` emittieren (`emits: ['element-update']`).
- `registerCmsElement` gibt `false` zurück und registriert nichts, wenn `name` oder `component` fehlt.
- Eigener Resolver mit dem Typ eines Core-Elements überschreibt den Core-Resolver. Vendor-Präfix verwenden.
- Ungesäuberten HTML-Inhalt nur gesäubert mit `|raw` ausgeben.

## Deprecations vermeiden

- `AbstractCmsElementResolver::resolveDefinitionField()` und `resolveCriteriaForLazyLoadedRelations()` sind `@deprecated tag:v6.8.0`, nicht verwendet.
- Composable `useCmsElement` ist in 6.7.15.1 `@experimental`/`@private`, daher das stabile Mixin `cms-element`.

## Lücken und Hinweise

- ⚠ UNGEPRÜFT: Gerüst nicht gebaut/installiert (kein PHP, kein Admin-Build). JS per `node --check`, JSON per `JSON.parse`. PHP-Namespaces, Signaturen (`CmsElementResolverInterface`, `AbstractCmsElementResolver::resolveEntityValueToString`, `HtmlSanitizer::sanitize`, `CriteriaCollection`) am Quellcode gegengelesen, Vorbild: `TextCmsElementResolver`, `HtmlCmsElementResolver`, Core-Element `html`.
- ⚠ UNGEPRÜFT: Meteor-Komponenten `mt-text-field`/`mt-textarea` mit `v-model` + `@update:model-value` (beide in `vue.adapter.ts` global registriert, Nutzungsmuster aus anderen Core-Modulen).
- ⚠ LÜCKE: Medien-Auswahl und Daten-Mapping-UI (`sw-cms-mapping-field`, `sw-cms-inherit-wrapper`) sind nicht Teil des Gerüsts. Vorbild: Core-Elemente `image`, `text` unter `administration/Resources/app/administration/src/module/sw-cms/elements/`. Eine Media-Variante bräuchte `collect()` mit `MediaDefinition` und `defaultConfig.media.entity`.
- ⚠ LÜCKE: Das Admin-Snippet-Laden basiert auf dem `SnippetFinder` (alle `<locale>.json` unter `Resources/app/administration/src`), nicht auf einem `snippets`-Eintrag. Siehe Referenz 18.
- Block mit festem Slot-Typ `swag-example-element`: siehe [cms-block](../cms-block/README.md).

## Referenzen

- [21 CMS-Elemente](../../references/21-cms-elemente.md)
- [22 CMS-Blöcke & Sichtbarkeit](../../references/22-cms-bloecke-sichtbarkeit.md)
- [05 Services registrieren](../../references/05-services-registrieren.md)
- [18 Snippets & Übersetzungen](../../references/18-snippets-uebersetzungen.md)
- [14 Storefront-Twig-Templates](../../references/14-storefront-twig-templates.md)
- [49 Build & Deployment](../../references/49-build-deployment.md)
- Weitere Templates: [cms-block](../cms-block/README.md), [plugin](../plugin/README.md)

- Snippet-Dateien sind sprachneutral benannt (`de.json`/`en.json` bzw. `storefront.de.json`/`storefront.en.json`); regionale Namen (`de-DE.json`) beanstandet `bin/console translation:lint-filenames` ohne sprachneutrale Basisdatei, Reparatur mit `--fix` (siehe [T-18](../../references/18-snippets-uebersetzungen.md)).
