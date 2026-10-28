# Storefront-Twig-Templates überschreiben und erweitern (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn Storefront-Templates per Plugin oder Theme angepasst, neue Partials eingebunden, Icons/Thumbnails ausgegeben oder eigene Twig-Funktionen/-Filter ergänzt werden. Hier steht nur die Twig-Mechanik und die Blocksuche.
Abgrenzung: Snippets und `|trans` siehe [T-18 Snippets](./18-snippets-uebersetzungen.md), SCSS/Assets siehe [T-13](./13-storefront-styling-assets.md), JS siehe [T-15](./15-storefront-js-plugins.md), CMS-Elemente/-Blöcke siehe [T-21](./21-cms-elemente.md) und [T-22](./22-cms-bloecke-sichtbarkeit.md), Seiten-Daten siehe [T-16 Controller und Pages](./16-storefront-controller-pages.md).

## 2. Konzepte

- **`sw_extends` statt `extends`.** Ein Plugin legt eine Datei am gleichen relativen Pfad unter `src/Resources/views/storefront/...` ab und überschreibt nur die gewünschten `{% block %}`. Die erste Zeile ist `{% sw_extends '@Storefront/storefront/<pfad>.html.twig' %}`. Der `TemplateFinder` durchsucht die Namespace-Hierarchie (Core-Storefront, Plugins, Theme) und liefert das Template mit der höchsten Priorität. Mehrere Plugins können so dasselbe Template erweitern. `@Storefront` ist nur der Startpunkt.
- **`sw_include` statt `include`** (Tag, ebenfalls über `TemplateFinder`), damit auch Partials überschreibbar bleiben. `with`, `only` und `ignore missing` werden unterstützt (`IncludeTokenParser`). `sw_icon` und `sw_thumbnails` sind Tags, die intern ein `SwInclude` auf `utilities/icon.html.twig` bzw. `utilities/thumbnail.html.twig` bauen.
- **Hierarchie:** `base.html.twig` → Seiten-Template (`page/<bereich>/index.html.twig`) → Bausteine (`layout/`, `component/`, `section/`, `block/`, `element/`, `utilities/`).
- **Bundle-Reihenfolge:** `BundleHierarchyBuilder` nimmt jedes Bundle mit Ordner `Resources/views`, sortiert nach `Bundle::getTemplatePriority()` (Standard `0`, `Framework` `-1`). Theme-Reihenfolge aus `theme.json` (Slots `@Storefront`, `@Plugins`, `@Theme`, `ThemeInheritanceBuilder`). Apps nutzen `template_load_priority`. Namespace in Twig = Bundle-Name (`@SwagExample`).
- **Template-Scopes:** `ExtendsTokenParser` beachtet Request-Attribut `_templateScopes` (Standard `default`, `TemplateScopeDetector`). Ohne passenden Scope endet die Datei nach dem `extends`.
- **Header/Footer seit 6.7 per ESI-Subrequest** (`frontend.header`, `frontend.footer`, `NavigationController::header()/footer()`): Dort ist `page` NICHT verfügbar, nur `header` bzw. `footer`. Daten nur als skalare `headerParameters`/`footerParameters`.
- **`sw_block('name')`** rendert einen Block der Elternvererbung an anderer Stelle (z. B. `{{ sw_block('base_cookie_permission') }}` in `base.html.twig`). Weitere Mehrfachvererbungs-Tags: `sw_embed`, `sw_use`, `sw_import`, `sw_from`.
- **Eigene Funktionen/Filter:** Klasse `extends Twig\Extension\AbstractExtension`, Service mit Tag `twig.extension`.

Verzeichnisbaum Plugin:

```
custom/plugins/SwagExample/
├── composer.json
└── src/
    ├── SwagExample.php
    ├── Twig/PriceLabelExtension.php
    └── Resources/
        ├── config/services.php
        └── views/storefront/              # spiegelt storefront/ im Core 1:1
            ├── layout/header/logo.html.twig
            ├── layout/footer/footer.html.twig
            └── page/product-detail/...
```

Core-Orte: `vendor/shopware/storefront/Resources/views/storefront/` mit `base.html.twig`, `layout/` (header, footer, meta, breadcrumb, navbar, cookie, ...), `page/` (account, checkout, content, error, search, wishlist, ...), `component/`, `section/` (`cms-section-*`), `block/` (`cms-block-*`), `element/` (`cms-element-*`), `utilities/`. Daneben `views/components/` für Twig-UX-Komponenten (`<twig:Sw:Button>`, 6.7.11+ laut Doku).

Seiten → Standard-Templates: Kategorie/Landingpage `page/content/index.html.twig`, Produktdetail `page/content/product-detail.html.twig` (+ `page/product-detail/meta.html.twig`), Suche `page/search/index.html.twig`, Checkout `page/checkout/{cart,confirm/index,finish}`, Konto `page/account/index.html.twig`, Fehler `page/error/error-404.html.twig`, `error-std.html.twig`, `error-maintenance.html.twig`.

Wichtige Shopware-Bausteine (Auswahl; vollständige Tabelle nicht belegt, siehe Lücken):

| Name | Art | Klasse |
|---|---|---|
| `sw_extends`, `sw_include` | Tag | `NodeExtension` |
| `sw_icon` / `sw_thumbnails` | Tag | `storefront/Framework/Twig/IconExtension` / `ThumbnailExtension` |
| `config()` | Funktion | `ConfigExtension` |
| `theme_config()`, `theme_scripts()`, `import_map()`, `theme_css_vars()` | Funktion | `storefront/Framework/Twig/Extension/ConfigExtension` |
| `seoUrl()`, `rawUrl()`, `entitySeoUrl()`, `category_url()` | Funktion | `SeoUrlFunctionExtension` u. a. |
| `feature()`, `searchMedia()`, `compare()` | Funktion | `FeatureFlagExtension`, `MediaExtension`, `ComparisonExtension` |
| `sw_sanitize` | Filter | `SwSanitizeTwigFilter` |
| `sw_encode_url`, `sw_encode_media_url`, `sw_icon_cache` | Filter | storefront Extensions |
| `currency`, `sw_convert_unit` | Filter | `CurrencyFilter`, `MeasurementConvertUnitTwigFilter` |
| `instanceof` | Test | `InstanceOfExtension` |

Globale Variablen (`TemplateDataExtension::getGlobals`): `shopware.navigation`, `shopware.dateFormat`, `shopware.minSearchLength`, `shopware.showStagingBanner`, `context`, `activeRoute`, `formViolations`, `jsonLdFlags`, `themeId`.

## 3. Anleitung

1. **Core-Template finden:** unter `vendor/shopware/storefront/Resources/views/storefront/` suchen, z. B. `grep -rn "layout_header_logo_link" vendor/shopware/storefront/Resources/views`. Der Block-Name ist der Ankerpunkt.
2. **Datei am gleichen Pfad** unter `src/Resources/views/storefront/...` anlegen.
3. Erste Zeile `{% sw_extends '@Storefront/storefront/<pfad>.html.twig' %}`, nur gewünschte Blöcke überschreiben.
4. `{{ parent() }}` aufrufen, wenn der Originalinhalt bleiben soll. Position vor/nach `parent()` bestimmt die Reihenfolge. Ohne `parent()` wird ersetzt.
5. Neue Partials: `{% sw_include '@SwagExample/storefront/component/foo.html.twig' with { x: 1 } %}`. Mit `only` nur die übergebenen Variablen durchreichen.
6. Icons: `{% sw_icon 'cart' style { size: 'sm', class: 'me-1' } %}` (Parameter siehe [T-13](./13-storefront-styling-assets.md)). Bilder: `{% sw_thumbnails 'my-thumbs' with { media: media } %}`.
7. **Header/Footer (6.7):** Inhalt per Block in `layout/header/*.twig` bzw. `layout/footer/*.twig` überschreiben. Zusätzliche Daten: in `base.html.twig` den Block `base_esi_header` überschreiben und `headerParameters` erweitern, oder per `StorefrontRenderEvent`-Subscriber (Doku). Nur Skalare.
8. Aktivieren und Cache:
   ```bash
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
   bin/console cache:clear
   bin/console theme:compile        # nur nötig, wenn SCSS/JS betroffen
   ```
   Der Twig-Cache wird nach Template-Hierarchie gehasht (`TemplateFinder::defineCache`). Bei Aktivierung/Prioritätsänderung entsteht ein neuer Cache.
9. Syntax prüfen: `bin/console lint:twig custom/plugins/SwagExample/src/Resources/views`, Funktionen auflisten: `bin/console debug:twig` (Symfony-Kommandos, nicht ausgeführt).

## 4. Beispiele

Logo-Block erweitern: `src/Resources/views/storefront/layout/header/logo.html.twig`
```twig
{% sw_extends '@Storefront/storefront/layout/header/logo.html.twig' %}

{% block layout_header_logo_link %}
    {{ parent() }}
    <small class="header-claim">{{ 'swagExample.claim'|trans|sw_sanitize }}</small>
{% endblock %}
```
(Der Snippet-Schlüssel muss in den Snippet-Dateien existieren, siehe [T-18](./18-snippets-uebersetzungen.md). Block `layout_header_logo_link` ist in `layout/header/logo.html.twig` belegt.)

Eigene Twig-Funktion: `src/Twig/PriceLabelExtension.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class PriceLabelExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('swagPriceLabel', $this->priceLabel(...)),
        ];
    }

    public function priceLabel(float $price): string
    {
        return $price > 100.0 ? 'Premium' : 'Standard';
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Twig\PriceLabelExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(PriceLabelExtension::class)
        ->tag('twig.extension');
};
```
Verwendung: `{{ swagPriceLabel(page.product.calculatedPrice.unitPrice) }}`. Das Laden von `Resources/config/services.php` im Plugin ist in `core/Framework/Bundle.php` belegt (XML ist dort deprecated). Weitere Registrierungsdetails siehe [T-05 Services](./05-services-registrieren.md).

Weitere Gerüste: [../templates/theme/README.md](../templates/theme/README.md), [../templates/plugin/README.md](../templates/plugin/README.md).

## 5. Fallstricke

- `{% extends %}`/`{% include %}` statt `sw_extends`/`sw_include`: Überschreibungen anderer Plugins/Themes greifen nicht.
- Datei am falschen Pfad (`Resources/views/layout/...` ohne `storefront/`): Pfad muss `views/storefront/...` spiegeln.
- Ganze Core-Datei kopieren statt `sw_extends` + Block: Updates greifen nicht, Konflikte.
- Block-Name falsch: Block wird still nicht verwendet (keine Fehlermeldung). Er muss im Eltern-Template existieren.
- `parent()` vergessen: Inhalt (und Aufrufe anderer Plugins) fällt weg. Mehrere Plugins ohne `parent()` im selben Block: das letzte in der Hierarchie gewinnt (Reihenfolge über `getTemplatePriority()` steuerbar).
- Eigene neue Blöcke ohne Plugin-Präfix kollidieren mit Core-Namen.
- Header/Footer (6.7): `page` nicht verfügbar, keine komplexen Objekte in `headerParameters` (Query-Parameter). Die Routen sind HTTP-gecacht (`ATTRIBUTE_HTTP_CACHE`).
- Plugin inaktiv oder ohne `Resources/views`: erscheint nicht in der Hierarchie (`BundleHierarchyBuilder` prüft `is_dir`).
- Rohe Ausgabe mit `|raw` statt `|sw_sanitize`: XSS.
- Twig-Funktion ohne Tag `twig.extension`: „Unknown function“.
- Checkout-`_page`-Templates überschreiben `base_*`-Blöcke bewusst leer (z. B. `base_flashbags`).
- `ExtendsTokenParser`, `IncludeTokenParser`, `TemplateFinder` sind `@internal`: nicht ableiten oder dekorieren.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- **Header/Footer per ESI seit 6.7.0.0** (Blöcke `base_esi_header`/`base_esi_footer`). In 6.5/6.6 waren `layout_header`/`layout_footer` direkt in `base.html.twig` inkludiert und hatten Zugriff auf `page`. Header-Daten kommen jetzt über `header` (`HeaderPageletLoadedHook`), Footer über `footer`.
- 6.8-Vorbereitung im Code: viele `{% if feature('v6.8.0.0') %}`-Zweige (27 Dateien), z. B. Breadcrumb-Rework, `base_cookie_permission` am Body-Anfang, JSON-LD statt Microdata. `isHMRMode` in `base.html.twig` ist deprecated.
- `@deprecated tag:v6.8.0`: `controllerName`/`controllerAction` (→ `activeRoute`), CSS-Klassen `is-ctl-*`/`is-act-*` (→ `is-active-route-*`), `sw_breadcrumb_full*`, `format_currency`/`format_number` (BackwardCompatibleIntlExtension), Makros in App-Scripts (→ `sw_macro_function`), Block `base_navigation_styling`.
- Neu: Twig-UX-Komponenten (`<twig:Sw:…>`, ab 6.7.11.0 laut Doku), `sw_convert_unit` (6.7.1.0 laut Doku), `import_map()`/`theme_css_vars()` (Vite), Agentic Files (`llms.txt`, `agents.md`, Erweiterungsblock `agentic_llms_extensions`).
- Service-Konfiguration im Core ist PHP statt XML (XML-Routen/-Services deprecated, `Bundle::triggerXmlConfigDeprecation`).

## 6. Checkliste

- [ ] Datei liegt unter `src/Resources/views/storefront/...` und spiegelt den Core-Pfad.
- [ ] Erste Zeile ist `sw_extends` mit `@Storefront/storefront/...`.
- [ ] Block-Name gegen das Core-Template geprüft.
- [ ] `{{ parent() }}` gesetzt, wo der Originalinhalt erhalten bleiben soll.
- [ ] Partials per `sw_include`, nicht `include`.
- [ ] Ausgaben mit `|sw_sanitize` statt `|raw`, Texte über Snippets (T-18).
- [ ] Header/Footer-Anpassungen nutzen kein `page`.
- [ ] Twig-Extension mit Tag `twig.extension` registriert.
- [ ] `bin/console cache:clear` ausgeführt, Plugin aktiv.

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/customize-templates.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/twig-function-reference.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/add-custom-twig-function.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/customize-header-footer.html
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/templates/agentic-files.html

Quellcode (relativ zu `vendor/shopware/`): `core/Framework/Adapter/Twig/TokenParser/{ExtendsTokenParser,IncludeTokenParser}.php`, `TemplateFinder.php`, `TemplateScopeDetector.php`, `NamespaceHierarchy/{NamespaceHierarchyBuilder,BundleHierarchyBuilder}.php`, `Extension/{NodeExtension,TwigFeaturesWithInheritanceExtension}.php`, `storefront/Theme/Twig/{ThemeNamespaceHierarchyBuilder,ThemeInheritanceBuilder}.php`, `core/Framework/Bundle.php` (`getTemplatePriority`), `storefront/Framework/Twig/{IconExtension,ThumbnailExtension,TemplateDataExtension}.php`, `storefront/Controller/NavigationController.php` (`header`, `footer`), `storefront/Resources/views/storefront/base.html.twig`, `layout/header/logo.html.twig`, `storefront/Resources/views/components/README.md`, `core/Framework/Plugin/Command/Scaffolding/Generator/StorefrontControllerGenerator.php`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Der Core hat keinen Block-Debugger. Die Doku nennt dafür das Drittplugin FroshDevelopmentHelper (nicht geprüft). Ob der Symfony-Profiler Block-Namen zeigt, ist nicht belegt.
- ⚠ UNGEPRÜFT: `debug:twig`, `lint:twig`, `cache:clear` wurden nicht ausgeführt (Symfony/Doku-Wissen).
- ⚠ UNGEPRÜFT: Ob 6.7.15.1 für Plugins zusätzlich `services.xml` lädt, ist nur über die XML-Deprecation in `Bundle` gestützt.
- ⚠ UNGEPRÜFT: Namespace `@SwagExample` = `Bundle::getName()` nicht gesondert verifiziert.
- ⚠ LÜCKE: Vorrang bei mehreren Plugins/Themes: Der Kommentar „Lower integer = higher precedence“ in `BundleHierarchyBuilder` widerspricht dem Verhalten laut `TemplateFinder`-Kommentar (letztes Element gewinnt). Reale Auflösung nicht per Test bestätigt.
- ⚠ UNGEPRÜFT: Versionsangaben „Components ab 6.7.11.0“ und „`sw_convert_unit` ab 6.7.1.0“ stammen nur aus der Doku (Dateien existieren).
- ⚠ LÜCKE: Die Tabelle der Twig-Bausteine ist aus einem Grep gewonnen. Erweiterungen aus Elasticsearch/Administration/Dokumenten sind nicht vollständig behandelt. `json_encode` ist Twig-Core, kein Shopware-Zusatz.
