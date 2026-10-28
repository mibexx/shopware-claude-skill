# Theme-Konfiguration und SCSS-Variablen (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn Theme-Felder in `theme.json` (`config.fields`) definiert, als SCSS-Variablen oder CSS Custom Properties genutzt, Bootstrap-Variablen/Breakpoints überschrieben oder SCSS-Variablen per Subscriber ergänzt werden sollen; ebenso für `theme_config()` in Twig.

Abgrenzung: Theme-Anlage, Vererbung, `theme.json`-Grundstruktur in [T-11 Theme-Grundlagen](./11-theme-grundlagen.md); SCSS-Dateien/Assets/Build in [T-13](./13-storefront-styling-assets.md); Twig-Blöcke nur in [T-14](./14-storefront-twig-templates.md); Plugin-`config.xml` in [T-19](./19-plugin-konfiguration.md); Events allgemein in [T-07](./07-events-subscriber.md); Build-Skripte in [T-49](./49-build-deployment.md).

## 2. Konzepte

- Theme-Config liegt in `theme.json` unter `config.fields`. Feld-Schlüssel: `type`, `value`, `editable`, `tab`/`block`/`section` (Default je `default`), `order`/`blockOrder`/`sectionOrder`/`tabOrder`, `custom`, `scss`, `fullWidth`. Erlaubt sind nur Schlüssel, für die `ThemeConfigField` einen Setter hat; unbekannte Schlüssel werfen `InvalidThemeConfigException` (`ThemeConfigFieldFactory::create`).
- **Feldtypen**: Der Code kennt keine feste Whitelist. Doku: `color, text, number, fontFamily, media, checkbox, switch, url`. Im Code besonders behandelt: `color`, `fontFamily`, `text`, `media`, `url`, `textarea`, `switch`/`checkbox`. Ein **`select` ist kein Typ**: `type: "text"` + `custom.componentName: "sw-single-select"` bzw. `"sw-multi-select"` + `custom.options`. `custom` wird nur für `sw-single-select`, `sw-multi-select`, `sw-switch-field`, `sw-checkbox-field` an die Komponente durchgereicht.
- **`scss`** (Default `true`): Jedes Feld mit `type` wird beim Kompilieren als `$<feldname>: <wert>;` vor das SCSS geschrieben (`ThemeCompiler::dumpVariables`); `"scss": false` schließt aus. Zusätzlich gibt `theme_css_vars()` (`meta.html.twig`, Block `layout_head_theme_css_vars`) dieselben Felder als CSS Custom Properties `--<feldname>` im `<head>` aus (Media/URL als `url('...')`).
- Wertausgabe (`ThemeCompiler::dumpVariables`):

| Typ | SCSS-Ausgabe |
|---|---|
| `media`, `textarea`, `url` (String) | in `'...'` gequotet |
| `switch`, `checkbox` | `(int)` -> `1`/`0` |
| andere Skalare | unverändert (`#0042a0`, `80`) |
| Array-Wert | wird **nicht** ausgegeben (nur via `theme_config` lesbar) |
| leer/fehlend | `null` |

- **Bootstrap-Variablen**: `overrides.scss` in `theme.json` -> `style` **vor** `@Storefront` (Bootstrap nutzt `!default`); nur Variablen, keine Regeln.
- **Breakpoints**: Felder `sw-breakpoint-xs ... xxl`; Twig `theme_config('breakpoint.md')`, JS liest sie ebenfalls. In SCSS muss `$grid-breakpoints` in `overrides.scss` selbst gebaut werden (Core-SCSS referenziert `$sw-breakpoint-*` nirgends).
- **Config-Vererbung**: `"configInheritance": ["@Storefront", "@BasicTheme"]`; jedes Theme außer `Storefront` erbt per Fallback von `@Storefront` (`ThemeMergedConfigBuilder::getConfigInheritance`). Felder ohne `type` (nur `value`) überschreiben den Wert des geerbten Felds (`array_replace_recursive`).
- **Labels** kommen in 6.7.15 aus Admin-Snippets `sw-theme.<technicalName>.<tab>.<block>.<section>.<field>.label` (`helpText` analog).
- Referenz im Core: `storefront/Resources/theme.json`, `storefront/Resources/app/storefront/src/scss/{base.scss,variables.scss,abstract/variables/_bootstrap.scss,_custom.scss}`.

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php                     # extends Plugin implements ThemeInterface
    ├── Subscriber/ThemeVariableSubscriber.php
    └── Resources/
        ├── theme.json
        ├── config/services.php
        ├── views/storefront/layout/header/logo.html.twig
        └── app/
            ├── administration/src/app/snippet/{de-DE,en-GB}.json   # Labels
            └── storefront/src/scss/{overrides.scss,base.scss}
```

## 3. Anleitung

1. Plugin anlegen oder `bin/console theme:create SwagExample --full` (Optionen `--static`, `--full`, `--with-config`, `--with-snippets`, `--with-scss`).
2. `config.fields` füllen; Feldnamen in Kebab-Case (werden 1:1 zu `$feld-name`).
3. Pro Feld `type`, `value`, `editable`, Gruppierung `tab`/`block`/`section`, Sortierung über `*Order`/`order`.
4. Labels als Admin-Snippets unter `sw-theme.<technicalName>....`; kein Admin-Build nötig: der `SnippetFinder` liest Admin-Snippets zur Laufzeit, danach nur `bin/console cache:clear`.
5. Bootstrap-/Breakpoint-Overrides in `overrides.scss`, in `style` vor `@Storefront`.
6. Optional Subscriber für dynamische Variablen (z. B. aus SystemConfig).
7. Aktivieren, zuweisen, kompilieren:
   ```
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample
   bin/console theme:change SwagExample --all        # Optionen: --sales-channel, --no-compile, --sync
   bin/console theme:compile --sync                  # Optionen: --active-only, --only, --skip, --only-themes, --skip-themes, --keep-assets
   bin/console cache:clear
   ```
   `theme.json`-Änderungen brauchen `plugin:refresh` + je nach Änderung `theme:compile`; im Theme-Manager gespeicherte Werte kompilieren automatisch neu (`ThemeService`).
8. In Twig `{{ theme_config('sw-color-brand-primary') }}`; verschachtelte Keys per Punkt (`breakpoint.md`, `assets.css`).
9. Debuggen: `bin/console theme:dump` (`ThemeDumpCommand`); die Variablen-Datei liegt als `theme-variables.scss` bzw. `theme-variables/<themeId>.scss` im temp-Filesystem.
10. Optionale Validierung: Parameter `storefront.theme.validate_on_compile` (Default `false`) schaltet `SCSSValidator` ein (prüft `color`, `fontFamily`, `text`, Switches; ungültig -> Exception bzw. Fallback `#ffffff00`/`inherit`); erlaubte Zusatzmuster: `storefront.theme.allowed_scss_values`.

## 4. Beispiele

`src/SwagExample.php`
```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;

class SwagExample extends Plugin implements ThemeInterface
{
}
```

`src/Resources/theme.json`
```json
{
  "name": "SwagExample",
  "author": "Swag",
  "description": { "en-GB": "Config demo", "de-DE": "Config-Demo" },
  "style": [
    "app/storefront/src/scss/overrides.scss",
    "@Storefront",
    "app/storefront/src/scss/base.scss"
  ],
  "script": ["@Storefront", "app/storefront/dist/storefront/js/swag-example/swag-example.js"],
  "asset": ["app/storefront/src/assets"],
  "configInheritance": ["@Storefront"],
  "config": {
    "fields": {
      "sw-color-brand-primary": {
        "type": "color",
        "value": "#e4002b",
        "editable": true,
        "tab": "colors", "block": "brand", "section": "primary", "order": 100
      },
      "swag-header-height": {
        "type": "number",
        "value": 80,
        "editable": true,
        "tab": "layout", "block": "header", "order": 100
      },
      "swag-sticky-header": {
        "type": "switch",
        "value": true,
        "editable": true,
        "tab": "layout", "block": "header", "order": 200
      },
      "swag-font-size": {
        "type": "text",
        "value": "24",
        "editable": true,
        "custom": {
          "componentName": "sw-single-select",
          "options": [ { "value": "16" }, { "value": "20" }, { "value": "24" } ]
        },
        "tab": "layout", "block": "header", "order": 300
      },
      "swag-hero-image": {
        "type": "media",
        "value": "app/storefront/src/assets/hero.jpg",
        "editable": true,
        "fullWidth": true,
        "tab": "layout", "block": "media", "order": 100
      },
      "swag-internal-flag": {
        "type": "text",
        "value": "x",
        "editable": false,
        "scss": false
      },
      "sw-breakpoint-lg": { "value": 1024 },
      "sw-breakpoint-xl": { "value": 1280 }
    }
  }
}
```

`src/Resources/app/storefront/src/scss/overrides.scss` (nur Variablen, keine Regeln)
```scss
$border-radius: 0;
$modal-backdrop-bg: rgba(255, 0, 0, 0.5);

$grid-breakpoints: (
    xs: 0,
    sm: $sw-breakpoint-sm * 1px,
    md: $sw-breakpoint-md * 1px,
    lg: $sw-breakpoint-lg * 1px,
    xl: $sw-breakpoint-xl * 1px,
    xxl: $sw-breakpoint-xxl * 1px
);
```
Wichtig: `$sw-breakpoint-*` sind Zahlen ohne Einheit (`value: 1024`). Ohne `* 1px` entsteht ungültiges CSS (`@media (min-width: 1024)`). `xs` bleibt als `xs: 0`; `xs: $sw-breakpoint-xs * 1px` bricht den SCSS-Compile ab (Review-Runde 2, live verifiziert).

`src/Resources/app/storefront/src/scss/base.scss`
```scss
.header-main {
    min-height: #{$swag-header-height}px;
    font-size: #{$swag-font-size}px;

    @if $swag-sticky-header == 1 {
        position: sticky;
        top: 0;
    }
}
```
`switch` wird als `1`/`0` ausgegeben (`ThemeCompiler`), also `== 1`, nicht `== true`.

`src/Subscriber/ThemeVariableSubscriber.php` (Namespace `Shopware\Storefront\Theme\Event\...`)
```php
<?php declare(strict_types=1);

namespace SwagExample\Subscriber;

use Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ThemeVariableSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ThemeCompilerEnrichScssVariablesEvent::class => 'onAddVariables',
        ];
    }

    public function onAddVariables(ThemeCompilerEnrichScssVariablesEvent $event): void
    {
        // $swag-env-label: 'Staging';  ($sanitize = true setzt Quotes + addslashes)
        $event->addVariable('swag-env-label', 'Staging', true);
        // Ohne Sanitize 1:1: $swag-gap: 12px;  (das "$" setzt Shopware selbst davor)
        $event->addVariable('swag-gap', '12px');
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Subscriber\ThemeVariableSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(ThemeVariableSubscriber::class)
        ->tag('kernel.event_subscriber');
};
```

`src/Resources/app/administration/src/app/snippet/en.json` (Struktur nach `storefront/Resources/app/administration/src/modules/sw-theme-manager/snippet/en.json`)
```json
{
  "sw-theme": {
    "SwagExample": {
      "layout": {
        "label": "Layout",
        "header": {
          "label": "Header",
          "default": {
            "swag-header-height": { "label": "Header height (px)", "helpText": "Minimum height" },
            "swag-sticky-header": { "label": "Sticky header" }
          }
        }
      }
    }
  }
}
```

`src/Resources/views/storefront/layout/header/logo.html.twig` (Zugriff auf `theme_config`; Block `layout_header_logo_inner` im Core-Template `storefront/layout/header/logo.html.twig` vorhanden, am Quellcode geprüft)
```twig
{% sw_extends '@Storefront/storefront/layout/header/logo.html.twig' %}

{% block layout_header_logo_inner %}
    <div class="swag-header-logo{% if theme_config('swag-sticky-header') %} swag-header-logo-sticky{% endif %}">
        {{ parent() }}
    </div>
{% endblock %}
```

Gerüste: [../templates/theme/README.md](../templates/theme/README.md), [../templates/config/README.md](../templates/config/README.md), [../templates/event-subscriber/README.md](../templates/event-subscriber/README.md).

## 5. Fallstricke

- **Falscher Event-Namespace in der Doku** ("add-scss-variables-via-subscriber" zeigt `Shopware\Storefront\Event\ThemeCompilerEnrichScssVariablesEvent`): die Klasse existiert in 6.7.15.1 nicht. Richtig `Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent`.
- `overrides.scss` nach `@Storefront` eingetragen: wirkungslos (`!default`); dort keine CSS-Regeln.
- `type: "select"` verwenden: existiert nicht, stattdessen `text` + `custom.componentName`.
- Unbekannter Feld-Schlüssel (z. B. `"description"`): `InvalidThemeConfigException`.
- Switch in SCSS als Boolean vergleichen: ist `1`/`0`.
- Feldname in CamelCase/Underscore: wird 1:1 `$camelCase`; Kebab-Case verwenden (auch bei `<css>`-Tag in `config.xml`).
- `addVariable('x', 'Staging')` ohne `$sanitize` ergibt `$x: Staging;` (Bareword); für echte Strings `true`. Sanitize nutzt nur `addslashes`, keine Validierung.
- Arrays als Feldwert werden nicht als SCSS ausgegeben.
- `label`/`helpText` in `theme.json` im 6.7-Admin nur Fallback mit `[DEPRECATED]`-Warnung; immer Snippets nutzen.
- Plugin-Config-Variablen (`<css>` in `config.xml`) wirken global über alle Themes/Sales Channels und brauchen nach Admin-Änderung manuelles `theme:compile` (Doku; technisch `ThemeCompilerEnrichScssVarSubscriber`, siehe [T-19](./19-plugin-konfiguration.md)).
- Breakpoints nur in `theme.json` ändern, aber `$grid-breakpoints` nicht anpassen: Bootstrap-CSS nutzt weiter die Defaults.
- `theme_config()` braucht den `themeId`-Kontext (`ConfigExtension::getThemeId`); in Mail-Templates/außerhalb des Storefront-Renderings liefert es `null`.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `label`/`helpText` in `theme.json` (und `labels` in der DB): `@deprecated tag:v6.8.0` (`ThemeConfigField::$label`, `$helpText` und Getter/Setter). Neu `labelSnippetKey`/`helpTextSnippetKey`, im Admin über `sw-theme.<technicalName>....` aufgelöst. Bei aktivem `v6.8.0.0` werden `label`/`helpText` im Factory verworfen.
- `theme:migrate-translations ... --strip` laut Doku erst ab 6.7.16.0; in 6.7.15.1 nicht vorhanden (`storefront/Theme/Command/` enthält nur Change, Compile, Create, Dump, PrepareIcons, Refresh).
- `theme_css_vars()` und `import_map()` als Twig-Funktionen in der 6.7.x-Linie vorhanden (⚠ UNGEPRÜFT: Einführung, nicht in 6.5/6.6).
- Breakpoints über `sw-breakpoint-*`-Felder: laut Doku ab 6.7.8.0; das hartkodierte `breakpoint`-Array in `ThemeConfigValueAccessor::getThemeConfig` ist ab 6.8 obsolet (`@deprecated tag:v6.8.0`).
- `ThemeConfigField::$value` wird in 6.8 nativ getypt (`array|bool|float|int|string|null`); andere Typen lösen schon jetzt eine Deprecation aus.
- Twig-Funktion `config()` (`ConfigExtension::config`) deprecated für 6.8; `theme_config()` bleibt.
- `isHMRMode`/Webpack-HMR im Template deprecated 6.8 (Vite-Dev-Server).
- `configInheritance` seit 6.4.8.0 (Doku); in 6.7.x erbt der Fallback für DB-Kopien von Themes die Config des Parent-Themes.
- `theme:compile --no-cleanup` wirkungslos/deprecated (Aufräumen via Scheduled Task `theme.delete_files`).
- XML-Service-Definitionen im Plugin deprecated (6.8): `services.php` ([T-05](./05-services-registrieren.md)).

## 6. Checkliste

- [ ] Feldnamen in Kebab-Case, nur erlaubte Feld-Schlüssel, `type` gesetzt (sonst kein SCSS-Export)
- [ ] Auswahlfelder als `text` + `custom.componentName`/`options`
- [ ] `overrides.scss` vor `@Storefront` in `style`, ohne CSS-Regeln
- [ ] `$grid-breakpoints` in `overrides.scss` gebaut, wenn Breakpoints geändert werden
- [ ] Switches in SCSS als `1`/`0` geprüft
- [ ] Labels/Hilfetexte als Admin-Snippets (`sw-theme.<technicalName>...`), nicht in `theme.json`
- [ ] Subscriber nutzt `Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent`, `$sanitize = true` bei Strings
- [ ] `plugin:refresh`, `theme:change`, `theme:compile`, `cache:clear` ausgeführt
- [ ] Nach Snippet-Änderungen `cache:clear` ausgeführt (kein Admin-Build nötig, `SnippetFinder` liest zur Laufzeit)
- [ ] `theme_config()` nur im Storefront-Rendering mit Theme-Kontext verwendet

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/themes/configuration/theme-configuration.html
- https://developer.shopware.com/docs/guides/plugins/themes/styling/override-bootstrap-variables-in-a-theme.html
- https://developer.shopware.com/docs/guides/plugins/themes/styling/override-theme-breakpoints.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-scss-variables.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/styling/add-scss-variables-via-subscriber.html

Quellcode (`vendor/shopware/`):
- `storefront/Theme/{ThemeConfigField,ThemeConfigFieldFactory,ThemeCompiler,ThemeMergedConfigBuilder,ThemeConfigValueAccessor,ThemeFileResolver}.php` (`ThemeCompiler::dumpVariables`/`formatVariables`/`compileStyles`)
- `storefront/Theme/Event/ThemeCompilerEnrichScssVariablesEvent.php` (`addVariable`, `getVariables`, `getSalesChannelId`, `getContext`)
- `storefront/Theme/Subscriber/ThemeCompilerEnrichScssVarSubscriber.php`, `storefront/Theme/Validator/SCSSValidator.php`
- `storefront/Theme/StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php`
- `storefront/Framework/Twig/Extension/ConfigExtension.php` (`theme_config`, `theme_scripts`, `import_map`, `theme_css_vars`), `storefront/Framework/Twig/TemplateConfigAccessor.php`
- `storefront/Theme/Command/{ThemeCompileCommand,ThemeChangeCommand,ThemeCreateCommand,ThemeDumpCommand}.php`, `storefront/DependencyInjection/Configuration.php:33-37`
- `storefront/Resources/theme.json`, `storefront/Resources/views/storefront/layout/meta.html.twig:139`
- Admin: `storefront/Resources/app/administration/src/modules/sw-theme-manager/page/sw-theme-manager-detail/index.js` (`getBind`, `getSnippet`, `mappedFields`), `.../snippet/en.json`

### Lücken und Unsicherheiten

- ⚠ UNGEPRÜFT: Typenliste: Der Code erzwingt keine Whitelist; ob der Admin `textarea`/`number` sauber rendert (Fallback `sw-form-field-renderer`), nicht per Laufzeittest geprüft.
- ⚠ UNGEPRÜFT: Exakte Verschachtelung der Admin-Snippet-Schlüssel für Tab-/Section-Labels aus `buildSnippetKey` und Doku abgeleitet, nicht per Laufzeittest verifiziert; die sprachneutralen Dateinamen `de.json`/`en.json` im Pfad `src/Resources/app/administration/src/app/snippet/` folgen der Core-Konvention (Doku nutzt `de-DE.json`/`en-GB.json`, was `translation:lint-filenames` beanstandet, siehe [T-18](./18-snippets-uebersetzungen.md)).
- ⚠ UNGEPRÜFT: Einführungsversion von `theme_css_vars()`.
- ⚠ UNGEPRÜFT: Beispielplugin nicht installiert/getestet (nur Code gelesen); Twig-Block `layout_header_logo_link` wurde gegen das Core-Template geprüft (vorhanden).
- ⚠ LÜCKE: Die Doku enthält kein `theme_config`-Twig-Beispiel; Verwendung aus `ConfigExtension` und Core-Templates belegt.
- Bootstrap-Defaults für `$grid-breakpoints` stammen aus `storefront/Resources/app/storefront/vendor/bootstrap` (nicht weiter geprüft).
