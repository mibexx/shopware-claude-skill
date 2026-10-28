# Template: Theme (SwagExampleTheme)

Zweck: Gerüst eines Storefront-Themes für Shopware 6.7.15.1 (Theme = Plugin, dessen Klasse `ThemeInterface` implementiert). Enthält `theme.json` mit Vererbung und Config-Feldern, SCSS-Einstieg (`overrides.scss` + `base.scss`), JS-Einstieg `main.js`, ein Twig-Override, Storefront- und Admin-Snippets.

Das Gerüst entspricht der Ausgabe von `bin/console theme:create SwagExampleTheme --full` (`ThemeCreateCommand`), ergänzt um Config-Felder, Twig-Override und Snippets.

## Dateibaum

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
        ├── views/storefront/page/content/index.html.twig   # Twig-Override (sw_extends)
        └── app/
            ├── administration/src/app/snippet/
            │   ├── de.json                       # Labels der Theme-Config im Theme-Manager
            │   └── en.json
            └── storefront/
                ├── src/
                │   ├── main.js                      # JS-Einstieg (main.ts hat Vorrang, falls vorhanden)
                │   ├── assets/.gitkeep              # Theme-Assets (per "asset" in theme.json)
                │   └── scss/
                │       ├── overrides.scss           # Variablen, steht VOR @Storefront
                │       └── base.scss                # Eigene Styles, steht NACH @Storefront
                └── dist/storefront/js/swag-example-theme/
                    └── swag-example-theme.js        # Platzhalter, wird vom Build ersetzt
```

## Platzhalter ersetzen

| Platzhalter | Ersetzen durch | Wo |
|---|---|---|
| `SwagExampleTheme` | Theme-/Klassenname (Großbuchstabe am Anfang, mind. 4 Zeichen, `/^[A-Za-z]\w{3,}$/`) | Ordner, Klasse, Namespace, `theme.json` (`name`, `@SwagExampleTheme`), `composer.json`, Snippet-Schlüssel `sw-theme.SwagExampleTheme` |
| `swag-example-theme` | Kebab-Case des Namens (CamelCase -> `-`) | Pfad `dist/storefront/js/swag-example-theme/swag-example-theme.js` in Ordner und `theme.json` |
| `swag/example-theme` | Composer-Name | `composer.json` |
| `Example Author` | Autor (Pflichtfeld `author`) | `theme.json` |
| `swag-example-*` | Eigene Config-Feldnamen (Kebab-Case, werden 1:1 zu `$swag-example-*`) | `theme.json`, `base.scss`, Twig, Admin-Snippets |
| `swagExampleTheme.banner` | Eigener Storefront-Snippet-Schlüssel | `snippet/storefront.*.json`, Twig |

## Einbindung / CLI

```bash
# Plugin nach custom/plugins/ kopieren, dann im Shopware-Root:
bin/console plugin:refresh
bin/console plugin:install --activate SwagExampleTheme
bin/console theme:change SwagExampleTheme --all --sync   # technischer Name (Klassenname), nicht der Anzeigename
bin/console theme:compile --sync                         # nach Änderungen an theme.json/SCSS erneut
bin/build-storefront.sh                                  # nur nötig, wenn main.js eigenen Code enthält
bin/console cache:clear
```

- `theme:change` weist das Theme Sales Channels zu und kompiliert. Ohne `--sync` kann die Kompilierung über die Message Queue laufen (dann muss ein Worker laufen).
- Änderungen an `theme.json` brauchen `plugin:refresh`.
- Admin-Snippets (Labels der Config) werden zur Laufzeit vom `SnippetFinder` gelesen (alle `<locale>.json` unter `Resources/app/administration/src`), kein Admin-Build nötig. Dateinamen sprachneutral (`de.json`/`en.json`, `storefront.de.json`/`storefront.en.json`): `bin/console translation:lint-filenames` meldet regionale Dateien (`de-DE.json`) ohne sprachneutrale Basisdatei als Fehler (Reparatur `--fix`, siehe [T-18](../../references/18-snippets-uebersetzungen.md)).

## Wichtige Regeln (am Quellcode belegt)

- `theme.json`: `name` und `author` sind Pflicht. Reihenfolge in `views` = Priorität aufsteigend, das eigene Theme steht zuletzt.
- `overrides.scss` muss VOR `@Storefront` stehen (Core-Variablen nutzen `!default`), nur Variablen, keine Regeln.
- Config-Felder werden als `$<feldname>` in SCSS geschrieben. `switch`/`checkbox` ergibt `1`/`0` (vergleichen mit `== 1`). Unbekannte Schlüssel in einem Feld werfen `InvalidThemeConfigException`.
- Ein "select" ist `type: "text"` plus `custom.componentName: "sw-single-select"` und `custom.options`.
- Labels: Admin-Snippets unter `sw-theme.<technicalName>.<tab>.<block>.<section>.<feld>.label` (Tab `default`, Section `default`). Optionen eines Selects: `...<feld>.<optionIndex>.label`. `label`/`helpText` direkt in `theme.json` sind ab 6.8 deprecated und werden hier nicht verwendet. Das Feld `sw-color-brand-primary` mit Block `themeColors` bekommt sein Label über die Vererbung vom Storefront-Theme.
- Twig: `{% sw_extends %}` statt `{% extends %}`, nur Blöcke überschreiben, `{{ parent() }}` bewusst setzen. Blockname `page_content` ist in `storefront/page/content/index.html.twig` vorhanden. `theme_config('<feld>')` funktioniert im Haupt-Rendering.
- Seit 6.7 laufen Header und Footer als ESI-Subrequest (`frontend.header`/`frontend.footer`), dort ist `page` nicht verfügbar. Für Header/Footer-Overrides Blöcke in `layout/header/*.twig` bzw. `layout/footer/*.twig` nutzen (z. B. `layout_header_logo_link`, `layout_footer_inner_container`, `layout_footer_bottom`, alle am Core geprüft).

## Eltern-Theme erweitern

In `theme.json` des Kind-Themes den technischen Namen des Eltern-Themes einfügen (kein `@ParentTheme`, das ist im Code kein Sonderwert):

```json
"views": ["@Storefront", "@Plugins", "@ElternTheme", "@SwagExampleTheme"],
"style":  ["app/storefront/src/scss/overrides.scss", "@Storefront", "@ElternTheme", "app/storefront/src/scss/base.scss"],
"script": ["@Storefront", "@ElternTheme", "app/storefront/dist/storefront/js/swag-example-theme/swag-example-theme.js"],
"asset":  ["@Storefront", "@ElternTheme", "app/storefront/src/assets"],
"configInheritance": ["@Storefront", "@ElternTheme"]
```

## Lücken und Hinweise

- ⚠ LÜCKE: `previewMedia` ist in `theme.json` nicht gesetzt, weil kein Vorschaubild mitgeliefert wird. Eigenes Bild anlegen (z. B. `app/storefront/src/assets/preview.jpg`) und `"previewMedia": "app/storefront/src/assets/preview.jpg"` ergänzen.
- ⚠ UNGEPRÜFT: Gerüst wurde nicht installiert/kompiliert (kein PHP/Shopware-Lauf). JSON und JS wurden syntaktisch geprüft, Blocknamen, Pfade und Snippet-Schlüsselstruktur am Quellcode.
- ⚠ UNGEPRÜFT: `theme_config()` innerhalb der ESI-Subrequests für Header/Footer (themeId kommt aus einem Request-Attribut, Verhalten im Subrequest nicht geprüft). Das Beispiel nutzt deshalb bewusst die Hauptseite.
- ⚠ UNGEPRÜFT: Optionen-Labels des Selects (`<feld>.<optionIndex>.label`) aus `ThemeMergedConfigBuilder::buildCustom` abgeleitet, nicht im Admin getestet.
- Optionale Plugin-Config (`src/Resources/config/config.xml`, Domain `SwagExampleTheme.config`) legt `theme:create --with-config` an. Siehe `../config/README.md`.
- `theme:change --no-cleanup` ist deprecated (Entfernung 6.8). `theme:migrate-translations` existiert in 6.7.15.1 nicht.
- Dist-Platzhalter: `theme:create` legt die leere Datei `swag-example-theme.js` ebenfalls an (`script` in `theme.json` verweist darauf). ⚠ UNGEPRÜFT, ob das Fehlen der Datei Fehler verursacht. Sie bleibt daher im Gerüst, bis der Build sie ersetzt.

## Referenzen

- [11 Theme-Grundlagen](../../references/11-theme-grundlagen.md)
- [12 Theme-Config & SCSS-Variablen](../../references/12-theme-config-scss-variablen.md)
- [13 Storefront-Styling & Assets](../../references/13-storefront-styling-assets.md)
- [14 Storefront-Twig-Templates](../../references/14-storefront-twig-templates.md)
- [18 Snippets & Übersetzungen](../../references/18-snippets-uebersetzungen.md)
- [49 Build & Deployment](../../references/49-build-deployment.md)
- Weitere Templates: [storefront-js-plugin](../storefront-js-plugin/README.md), [config](../config/README.md), [plugin](../plugin/README.md)
