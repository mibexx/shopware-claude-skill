# Storefront-Styling, Assets und Icons (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn SCSS, Bilder, Fonts oder SVG-Icons für die Storefront eines Plugins oder Themes eingebunden werden. Sie erklärt Einstiegspunkte, Reihenfolge der SCSS-Auflösung, Asset-Pfade und den `sw_icon`-Tag.
Abgrenzung: Theme-Anlage und `theme.json` im Ganzen siehe [T-11 Theme-Grundlagen](./11-theme-grundlagen.md), Theme-Config-Felder und SCSS-Variablen siehe [T-12 Theme-Config](./12-theme-config-scss-variablen.md), Twig-Blöcke siehe [T-14 Twig-Templates](./14-storefront-twig-templates.md), JS siehe [T-15 JS-Plugins](./15-storefront-js-plugins.md), Build/Watch siehe [T-49 Build und Deployment](./49-build-deployment.md).

## 2. Konzepte

- **Kein Webpack für SCSS.** SCSS wird serverseitig von `ScssPhpCompiler` (scssphp) in `ThemeCompiler` kompiliert (`bin/console theme:compile`). Nur JS braucht einen Build.
- **Plugin:** Genau die Datei `src/Resources/app/storefront/src/scss/base.scss` wird automatisch erkannt (`StorefrontPluginConfigurationFactory::getScssEntryFileInDir`, nur dieser Dateiname, Tiefe 0). Weitere Dateien müssen per `@import` aus ihr eingebunden werden.
- **Theme:** Einstiegspunkte stehen in `theme.json` unter `style`. Die Vorlage von `theme:create` erzeugt `overrides.scss` → `@Storefront` → `base.scss`. `overrides.scss` muss VOR `@Storefront` stehen, weil Core-Variablen mit `!default` deklariert sind.
- **Auflösung (`ThemeFileResolver`, `ThemeCompiler`):** `style` wird in Reihenfolge abgearbeitet. `@Storefront` (oder `@<ThemeName>`) löst die Style-Dateien dieses Themes rekursiv auf. `@Plugins` fügt die `base.scss` aller Nicht-Theme-Plugins an. `@StorefrontBootstrap` bindet `base.scss` des Storefront-Bundles samt `vendor`-Mapping ein. `@Bundle/pfad/datei.scss` verweist auf eine einzelne Datei eines Bundles. Ein Eintrag kann `resolve` enthalten (z. B. `"vendor": "app/storefront/vendor"`), damit `~vendor/...` aufgelöst wird.
- **Vorab gesetzte Variablen:** `compileStyles` setzt vor den Imports `$sw-features`, danach die Theme-Variablen (`config.fields`-Keys wie `$sw-color-brand-primary`, `$theme-id`, `$sw-asset-<package>-url`). Sie sind in jeder Style-Datei ohne Import verfügbar. Felder mit `"scss": false` werden nicht ausgegeben.
- **Core-SCSS** (`storefront/Resources/app/storefront/src/scss`): `base.scss` importiert nach 7-1-Muster `abstract` (nur Variablen/Mixins/Funktionen), `vendor/bootstrap` (Bootstrap 5.3.8), `base/`, `component/`, `layout/`, `page/`. `skin/shopware/_base.scss` ist ein zweiter Einstieg (Shopware-Optik).
- **Assets:**

| Art | Ablage | Wirkung | Twig | SCSS |
|---|---|---|---|---|
| Theme-Asset | Ordner aus `theme.json` → `asset` | `theme:compile` kopiert nach `public/theme/<theme-id>/assets` | `asset('assets/x.png', 'theme')` | `#{$app-css-relative-asset-path}/x.png` |
| Plugin-Asset | `src/Resources/public` | `bin/console assets:install` nach `public/bundles/<pluginname>/` | `asset('bundles/<name>/x.png', 'asset')` | `#{$sw-asset-public-url}/bundles/<name>/x.png` |

- **BEM:** Der Core hält BEM nicht strikt ein (nur 12 Stellen mit `&__`/`&--`). Empfehlung (Konvention, kein Shopware-Zwang): Bootstrap-5-Utilities und CSS-Variablen (`var(--#{$prefix}...)`) bevorzugen, eigene Klassen mit eigenem Präfix versehen.

Verzeichnisbaum (Theme, so erzeugt von `bin/console theme:create`; Plugin analog ohne `theme.json`):

```
custom/plugins/SwagExample/
└── src/
    ├── SwagExample.php                       # Theme: extends Plugin implements ThemeInterface
    └── Resources/
        ├── theme.json
        ├── views/storefront/...
        ├── public/                           # Plugin-Assets für assets:install
        └── app/storefront/
            ├── src/
            │   ├── main.js                   # JS-Einstieg (main.ts hat Vorrang)
            │   ├── assets/                   # Theme-Assets (Bilder, Fonts, Icon-Packs)
            │   │   └── icon-pack/custom-icons/*.svg
            │   └── scss/
            │       ├── overrides.scss        # Variablen-Overrides (vor @Storefront)
            │       ├── base.scss             # Haupt-Einstieg
            │       └── base/_typography.scss
            └── dist/                         # Build-Ausgabe (JS)
```

Die Vorlage nutzt `components/`/`pages/` (Plural), der Core `component/`/`page/`. Beides ist frei wählbar, weil alles per `@import` aus `base.scss` kommt. Unterstrich-Präfix und `.scss`-Endung dürfen im Import fehlen (`ThemeCompiler::getImportFileExtension`).

## 3. Anleitung

1. Theme anlegen: `bin/console theme:create SwagExample` (Gerüst siehe oben), dann `bin/console plugin:refresh && bin/console plugin:install --activate SwagExample`.
2. Variablen-Overrides in `overrides.scss`, eigene Styles in `base.scss` plus Teil-Dateien (`@import 'base/typography';`).
3. Assets nach `src/Resources/app/storefront/src/assets` legen und in `theme.json` unter `asset` eintragen (`@Storefront` zuerst, sonst fehlen Core-Icons/Logos/Fonts).
4. Icon-Pack: SVGs in einen Ordner legen und unter `iconSets` registrieren.
5. Theme einem Sales Channel zuweisen: `bin/console theme:change` (interaktiv).
6. Kompilieren: `bin/console theme:compile` (Optionen u. a. `--sync`, `--active-only`, `--only-themes`, `--keep-assets`). Config neu einlesen: `bin/console theme:refresh`, prüfen: `bin/console theme:dump`.
7. Plugin-Assets (`Resources/public`): `bin/console assets:install` (`--force` ignoriert das Manifest).
8. Live-Entwicklung: `composer run watch:storefront` bzw. `shopware-cli project storefront-watch` (Details und Port siehe [T-49](./49-build-deployment.md)).
9. Icon-Hilfsbefehl: `bin/console theme:prepare-icons <path> <package>` (Optionen `--fillcolor`, `--fillrule`, `--cleanup`) bereitet SVG-Sprites vor.

Icon-Tag (`utilities/icon.html.twig`, `IconTokenParser`):
- Parameter in `style { ... }`: `size`, `color`, `rotation`, `flip`, `class`, `pack`, `namespace`, `ariaHidden`, `ariaLabel`.
- Mit `themeIconConfig[pack]` (aus `iconSets`): Lookup `@<ThemeName>/<path>/<name>.svg`. Sonst `@<namespace>/assets/icon/<pack>/<name>.svg` mit Default `namespace = 'Storefront'`, `pack = 'default'`. Mitgelieferte Packs: `default`, `solid`.
- CSS-Klassen: `icon icon-<name>` bzw. `icon icon-<pack> icon-<pack>-<name>` plus Größen-/Farbklassen.
- Plugin-Icon ohne `iconSets` (Doku-Variante): `{% sw_icon 'done-outline-24px' style { 'namespace': 'SwagExample' } %}`, Datei `src/Resources/app/storefront/dist/assets/icon/default/done-outline-24px.svg`.

Event-Hooks: `ThemeCompilerEnrichScssVariablesEvent` (eigene SCSS-Variablen ergänzen), `ThemeCompilerConcatenatedStylesEvent` (Import-Liste ändern). Details zu Events siehe [T-07](./07-events-subscriber.md).

## 4. Beispiele

Gerüst: [../templates/theme/README.md](../templates/theme/README.md).

`src/Resources/theme.json`
```json
{
  "name": "SwagExample",
  "author": "Example",
  "views": ["@Storefront", "@Plugins", "@SwagExample"],
  "style": [
    "app/storefront/src/scss/overrides.scss",
    "@Storefront",
    "app/storefront/src/scss/base.scss"
  ],
  "script": [
    "@Storefront",
    "app/storefront/dist/storefront/js/swag-example/swag-example.js"
  ],
  "asset": [
    "@Storefront",
    "app/storefront/src/assets"
  ],
  "iconSets": {
    "custom-icons": "app/storefront/src/assets/icon-pack/custom-icons"
  },
  "config": {
    "fields": {
      "sw-color-brand-primary": {
        "type": "color", "value": "#c4003c", "editable": true,
        "block": "themeColors", "order": 100
      }
    }
  }
}
```

`src/Resources/app/storefront/src/scss/overrides.scss`
```scss
// Muss VOR @Storefront stehen, weil Core-Variablen mit !default deklariert sind.
$font-size-base: 1rem;
$border-radius: 0.25rem;
```

`src/Resources/app/storefront/src/scss/base.scss`
```scss
@import 'base/typography';

@font-face {
    font-family: 'MyFont';
    src: url('#{$app-css-relative-asset-path}/font/MyFont.woff2') format('woff2');
    font-display: swap;
}

.swag-example-hero {
    background-image: url('#{$app-css-relative-asset-path}/hero.jpg');
    padding: $spacer-lg;

    &__title { font-family: 'MyFont', sans-serif; }
    &--dark  { color: $white; }
}
```

`src/Resources/app/storefront/src/scss/base/_typography.scss`
```scss
body { -webkit-font-smoothing: antialiased; }
```

`src/Resources/views/storefront/page/content/index.html.twig`
```twig
{% sw_extends '@Storefront/storefront/page/content/index.html.twig' %}

{% block base_main_inner %}
    <img src="{{ asset('assets/hero.jpg', 'theme') }}" alt="" class="swag-example-hero">
    {% sw_icon 'heart' style { 'pack': 'custom-icons', 'size': 'lg', 'color': 'primary' } %}
    {{ parent() }}
{% endblock %}
```
Voraussetzung: `src/assets/icon-pack/custom-icons/heart.svg` existiert. Der Block `base_main_inner` ist in `base.html.twig` und `page/content/index.html.twig` belegt.

Plugin ohne Theme: `src/Resources/app/storefront/src/scss/base.scss`
```scss
.swag-example-badge {
    background-image: url('#{$sw-asset-public-url}/bundles/swagexample/badge.png');
}
```
Datei: `src/Resources/public/badge.png`, danach `bin/console assets:install`.

## 5. Fallstricke

- `overrides.scss` nach `@Storefront`: Overrides wirken nicht (`!default`-Variablen schon gesetzt). Das gilt auch für `$sw-*` aus `skin/shopware/abstract/variables`.
- Theme-Asset nach Änderung nicht sichtbar: `theme:compile` fehlt, Ordner nicht unter `asset` registriert oder `@Storefront` in `asset` vergessen.
- Plugin-Asset 404: `assets:install` nicht gelaufen oder falscher Bundle-Name. Er ist der kleingeschriebene Plugin-Name ohne Suffix `bundle` (`AssetService::getTargetDirectory`). Package `asset` statt `theme` nutzen.
- Falsches Asset-Package im Twig: `'theme'` für Theme-Dateien, `'asset'`/`'public'` für `bundles/...`. `ThemeAssetPackage::prependThemePath` hängt für Pfade mit `/assets` `/theme/<themeId>` an.
- Plugin ohne `base.scss` bekommt keinen SCSS-Eintrag. `scss/sub/base.scss` wird NICHT gefunden.
- Fonts per `url()` mit absolutem Pfad brechen pro Theme-ID. Immer `$app-css-relative-asset-path` nutzen (`'../../' + $theme-id + '/assets'`).
- Icon-Pack ohne `iconSets` und ohne `namespace`: Suche in `@Storefront/assets/icon/<pack>/` liefert leer (`ignore_missing`). Laut Doku sind Custom Assets nicht Teil der Theme-Vererbung. Bei Apps ist `iconSets` zwingend.
- Core-Dateien direkt ändern (geht bei Updates verloren), Inline-Styles/`!important` statt Variablen: Anti-Pattern.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Bootstrap in 6.7.15.1: **5.3.8** (`package.json`). Der Kommentar in `vendor/_bootstrap.scss` verlinkt noch 5.2-Doku (veraltet). Der Core nutzt CSS-Variablen/`$prefix` (z. B. `_product-box.scss`).
- Neu im Resolver (6.7.15.1 belegt): `@StorefrontBootstrap`, `@Bundle/pfad`-Einzeldatei-Referenzen, rekursive Auflösung mit Dedup. Zudem `ThemeRuntimeConfig`/`ThemeRuntimeConfigService` (Icon-Sets in `prepareIconSets`).
- `theme:compile --no-cleanup`: `[DEPRECATED]` ohne Wirkung. Aufräumen macht die geplante Aufgabe `theme.delete_files`.
- JS-Einstieg: `main.ts` wird zusätzlich zu `main.js` erkannt (`getEntryFile`). Das Skript-Layout ist „async“ (`dist/storefront/js/<name>/<name>.js`).
- `shopware-cli project storefront-build` ist ein CLI-Tool, kein Shopware-Befehl.

## 6. Checkliste

- [ ] `base.scss` liegt exakt unter `src/Resources/app/storefront/src/scss/base.scss`.
- [ ] Theme: `overrides.scss` steht in `theme.json` vor `@Storefront`.
- [ ] `asset` in `theme.json` enthält `@Storefront` vor dem eigenen Ordner.
- [ ] Fonts/Bilder in SCSS über `$app-css-relative-asset-path` (Theme) bzw. `$sw-asset-public-url` (Plugin).
- [ ] Twig nutzt `asset(..., 'theme')` bzw. `asset('bundles/...', 'asset')` passend zum Ablageort.
- [ ] Eigene Icon-Packs sind unter `iconSets` registriert (bei Apps Pflicht).
- [ ] `bin/console theme:compile` bzw. `assets:install` nach Änderungen ausgeführt.
- [ ] Eigene CSS-Klassen tragen ein Plugin-Präfix, keine Core-Dateien kopiert.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-custom-styling.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-custom-assets.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-icons.html
- https://developer.shopware.com/docs/guides/plugins/themes/styling/add-css-js-to-theme.html
- https://developer.shopware.com/docs/guides/plugins/themes/assets/add-assets-to-theme.html
- https://developer.shopware.com/docs/guides/plugins/themes/assets/add-icons.html

Quellcode (relativ zu `vendor/shopware/`): `storefront/Theme/ThemeCompiler.php` (`compileStyles`, `dumpVariables`, `getAssets`, `getResolveImportPathsCallback`), `ThemeFileResolver.php`, `ScssPhpCompiler.php`, `ThemeAssetPackage.php`, `StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php` (`getScssEntryFileInDir`, `getEntryFile`), `ThemeRuntimeConfigService.php`, `Command/{ThemeCreateCommand,ThemeCompileCommand,ThemeChangeCommand,ThemeDumpCommand,ThemeRefreshCommand,ThemePrepareIconsCommand}.php`, `Framework/Twig/TokenParser/IconTokenParser.php`, `Resources/views/storefront/utilities/icon.html.twig`, `core/Framework/Adapter/Asset/AssetInstallCommand.php`, `core/Framework/Plugin/Util/AssetService.php`, `storefront/Resources/app/storefront/src/scss/abstract/variables/_custom.scss` (`$app-css-relative-asset-path`).

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Aussagen zu 6.5/6.6 stammen nicht aus dem Code (nur 6.7.15.1 installiert).
- ⚠ UNGEPRÜFT: Ob scssphp alle Sass-Features von Bootstrap 5.3 unterstützt, wurde nicht geprüft; belegt ist nur, dass scssphp der Compiler ist.
- ⚠ UNGEPRÜFT: `composer run watch:storefront` und Port 9998 stammen aus der Doku, nicht aus dem Skript (siehe T-49).
- ⚠ UNGEPRÜFT: Dass `plugin:install`/`plugin:update` automatisch `assets:install` ausführen, ist eine Annahme.
- ⚠ UNGEPRÜFT: Größen-Werte von `size` (`sm`, `lg` …) und das `solid`-Pack stammen aus der Doku, die CSS-Klassen wurden nicht einzeln geprüft.
- ⚠ UNGEPRÜFT: `SCSSValidator` kann Config-Werte mit Sonderzeichen verwerfen (nur `validate`-Modus, Details nicht geprüft).
- ⚠ LÜCKE: Die BEM-Aussage stützt sich auf Grep-Zählung und Stichproben; eine offizielle BEM-Regel wurde nicht gefunden.
- Befehle sind aus `AsCommand`/`addOption` abgelesen, nicht ausgeführt.
