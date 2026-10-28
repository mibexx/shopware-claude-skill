# Snippets und Übersetzungen (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift bei UI-Texten in Storefront und Administration (Snippet-Dateien, Snippet-Sets, `|trans`, `$t`, Sprachpakete und `translation:*`-CLI).
Abgrenzung: Entity-Übersetzungen (`*_translation`-Tabellen, `TranslatedField`) gehören zu [T-27 DAL-Entities](./27-dal-entities.md). Twig-Mechanik siehe [T-14](./14-storefront-twig-templates.md), Admin-Module/-Komponenten siehe [T-24](./24-admin-module.md) und [T-25](./25-admin-komponenten.md), Plugin-Konfig-Labels siehe [T-19](./19-plugin-konfiguration.md). Faustregel: Snippets sind UI-Texte, die mit Code/Plugin/Theme ausgeliefert werden. Inhaltsdaten wie Produktnamen sind Entity-Übersetzungen.

## 2. Konzepte

- **Zwei getrennte Systeme:**
  - **Storefront:** `<bundle>/Resources/snippet/**/<domain>.<locale>.json`, serverseitig geladen, benutzt mit Twig `|trans` oder `TranslatorInterface`.
  - **Administration:** `<plugin>/src/Resources/app/administration/src/**/<locale>.json`. Der `SnippetFinder` liefert sie als JSON an vue-i18n, benutzt wird `$t` (`$tc` deprecated).
- **Storefront-Dateinamen:** `<domain>.<locale>.json` (2 Teile) oder `<domain>.<locale>.base.json` (3 Teile). Nur Teil 2 zählt als Locale (`SnippetFileLoader::loadSnippetFilesInDir`). Andere Namensformen werden still ignoriert. Locale nach `SnippetPatterns::LOCALE_PATTERN` (`en`, `de`, `en-GB`, `de-DE`).
- **Snippet-Sets (`snippet_set`):** Jede Sales-Channel-Domain verweist auf ein Set (`sales_channel_domain.snippet_set_id`, Set hat `iso`). Über das Set werden Dateisnippets der Locale geladen, danach überschreiben DB-Snippets (Tabelle `snippet`, im Admin pflegbar) die Dateiwerte.
- **Storefront-Reihenfolge** (`SnippetService::getStorefrontSnippets`): Fallback-Locale, Katalog-`messages`, Snippets der Ziel-Locale, zuletzt DB-Overrides. Fallback = Sprachanteil der Locale (`de-DE` → `de`, `Translator::getFallbackLocale`) oder Systemsprache. Region-Fallback: Datei für `de-DE` fällt auf `de` zurück (`SnippetFileCollection::getSnippetFilesWithLocaleFallback`).
- **Registrierung:** nicht nötig. `SnippetFileLoader::loadShippedSnippets` iteriert alle Kernel-Bundles (außer Administration) und liest `<bundlePath>/Resources/snippet` (bei Plugins `src`).
- **Admin-Dateien:** Dateiname `<locale>.json` oder `administration.json` (Finder in `SnippetFinder`). Zuerst die sprachneutrale Locale (`de`), dann die regionale (`de-DE`). Alle Snippets werden per `array_replace_recursive` in ein Objekt gemergt, gleiche Schlüssel überschreiben sich.
- **Sprachpakete:** Seit 6.7 gibt es ein Translation-System (`translation:*`), das Übersetzungen aus dem Translations-Repo lädt (privates Dateisystem). Plugins mit mitgelieferter Übersetzung für eine Locale werden dann übersprungen (`pluginTranslationExistsForLocale`).
- **Apps:** Storefront `<app>/Resources/snippet/` (`AppSnippetFileLoader`), Administration `<app>/Resources/app/administration/snippet/*.json` (Tabelle `app_administration_snippet`, `AppLifecycleSubscriber`). Mehr zu Apps in [T-10](./10-app-system-abgrenzung.md).

Verzeichnisbaum:

```
custom/plugins/SwagExample/src/Resources/
├── snippet/                                  # STOREFRONT (rekursiv, *.json)
│   ├── swag-example.de.json                  # <domain>.<locale>.json
│   ├── swag-example.en.json
│   └── swag-example.en-GB.json               # optional region-spezifisch, NUR zusätzlich zur sprachneutralen Datei
├── views/storefront/page/product-detail/index.html.twig
└── app/administration/src/module/swag-example/
    └── snippet/{de.json,en.json}             # ADMIN: <locale>.json (sprachneutral)
```

Core-Referenz: `storefront/Resources/snippet/storefront.{de,en}.json`, `core/Framework/Resources/snippet/messages.{de,en}.base.json`, `administration/Resources/app/administration/src/app/snippet/{de,en}.json`, pro Admin-Modul `src/module/*/snippet/`.

## 3. Anleitung

1. **Namensraum wählen:** eindeutiger Präfix pro Plugin (`swagExample.*` Storefront, `swag-example.*` Admin). Ohne Präfix werden Core-Schlüssel im Admin überschrieben.
2. **Storefront-Dateien** unter `src/Resources/snippet/<domain>.<locale>.json` anlegen.
3. **Admin-Dateien** unter `src/Resources/app/administration/src/` in beliebiger Tiefe als `<locale>.json` anlegen.
4. **Nutzung:** Storefront `{{ 'swagExample.headline'|trans }}` bzw. `TranslatorInterface::trans()`. Admin `$t('swag-example.…')` im Template, `this.$t(...)` im JS (`$tc` ist deprecated, siehe Abschnitt Deprecations; Doku-Beispiele nutzen noch `$tc`). `$tc` ruft intern dasselbe `i18n.global.t` auf (`vue.adapter.ts`), `$t(key, 3)` liefert daher ebenfalls die Plural-Variante.
5. **Platzhalter:** Storefront Symfony-Stil `%name%`, Admin vue-i18n `{n}`/`{name}` und `|` für Plural.
6. **Cache und Build:**
   ```bash
   bin/console cache:clear
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
   bin/build-administration.sh   # nur bei geändertem Admin-JS, Snippets kommen zur Laufzeit per API
   ```
7. **Texte im Admin überschreiben:** Einstellungen → Shop → Snippets (Snippet-Sets), Werte landen in `snippet` und überschreiben Dateiwerte pro Set.
8. **Sprachen/Sets:** Snippet-Set im Admin anlegen oder per `translation:install`.
9. **CLI (aus Quellcode `core/System/Snippet/Command`):**
   - `translation:validate` (Alias `snippets:validate`, Alias deprecated für 6.8; Option `--fix`): Vollständigkeit und Pluralisierung
   - `translation:list`: konfigurierte Locales
   - `translation:download`: lädt Übersetzungen, legt aber keine Sprachen/Snippet-Sets an
   - `translation:install`: lädt und installiert Locales, überschreibt vorhandene Übersetzungen
   - `translation:update`: aktualisiert installierte Übersetzungen
   - `translation:lint-filenames`: prüft, ob zu regionalen Dateien eine sprachneutrale Basisdatei existiert (z. B. `de.json` zu `de-DE.json`); mit `--fix` werden regionale Dateinamen in sprachneutrale umbenannt

## 4. Beispiele

`src/Resources/snippet/swag-example.de.json`
```json
{
  "swagExample": {
    "headline": "Willkommen im Shop",
    "sold": "Über %count% Produkte in %country% verkauft"
  }
}
```

`src/Resources/snippet/swag-example.en.json`
```json
{
  "swagExample": {
    "headline": "Welcome to the shop",
    "sold": "Sold about %count% products in %country%"
  }
}
```

`src/Resources/views/storefront/page/product-detail/index.html.twig`
```twig
{% sw_extends '@Storefront/storefront/page/product-detail/index.html.twig' %}

{% block page_product_detail %}
    <h2>{{ 'swagExample.headline'|trans }}</h2>
    <p>{{ 'swagExample.sold'|trans({'%count%': 3, '%country%': 'Germany'})|sw_sanitize }}</p>
    {{ parent() }}
{% endblock %}
```

PHP: `src/Service/GreetingService.php`
```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

class GreetingService
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function greet(): string
    {
        return $this->translator->trans('swagExample.headline');
    }
}
```

`src/Resources/config/services.php`
```php
<?php declare(strict_types=1);

use SwagExample\Service\GreetingService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(GreetingService::class)
        ->args([service('translator')]);
};
```

Admin: `src/Resources/app/administration/src/module/swag-example/snippet/de.json`
```json
{
  "swag-example": {
    "general": { "mainMenuItemGeneral": "Snippet-Beispiel", "items": "1 Eintrag | {n} Einträge" }
  }
}
```
`en.json` analog. Nutzung in Admin-Twig:
```twig
{{ $t('swag-example.general.mainMenuItemGeneral') }}
{{ $t('swag-example.general.items', 3) }}
```
Modul-Registrierung (`index.js`) siehe [T-24](./24-admin-module.md). Hinweis: Das Admin-Modul-Scaffold legt `src/Resources/app/administration/src/snippet/en.json` an (`AdminModuleGenerator`).

## 5. Fallstricke

- Dateiname mit anderer Teilezahl (`my.plugin.de.json`): Datei wird still ignoriert.
- Cache nicht geleert: Der Katalog ist gecacht (`translation.catalog.*`, Tag `Translator::tag($snippetSetId)`), `bin/console cache:clear` nutzen.
- DB-Overrides gewinnen immer: Ändert sich der Dateiwert nicht, das Admin-Snippet-Set auf einen gleichnamigen Eintrag prüfen.
- Schlüssel-Kollision im Admin ohne Plugin-Präfix (Merge per `array_replace_recursive`).
- App-Admin-Snippets können laut Doku keine bestehenden Schlüssel aus Core/Plugins überschreiben (nicht am Code geprüft). Der Code sanitisiert App-Snippets per HtmlSanitizer (`SnippetFinder::sanitizeAppSnippets`).
- Locale-Format: `de_DE` wird vom Pattern toleriert, Konvention ist der Bindestrich.
- Defekte Admin-Snippet-JSON: Im Debug Exception, sonst Log und Überspringen (`SnippetFinder::parseFiles`).
- Entity-Inhalte als Snippet pflegen: falsches Werkzeug (siehe T-27).
- Theme-Snippets: `ThemeSnippetsSubscriber` schließt Snippets nicht genutzter Themes aus, das Basis-Theme `Storefront` wird immer geladen.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- Core-Snippets liegen in 6.7.15.1 sprachneutral vor (`storefront.de.json`, `storefront.en.json`) mit Region-Fallback. Die Dateikonvention `en-GB` für Plugins funktioniert weiter.
- Neues Translation-System: Commands `translation:*`, Services `TranslationLoader`, `TranslationUpdater`, `SnippetFileLoader::loadTranslationSnippets`. `snippets:validate` ist ein deprecated Alias (Entfernung 6.8).
- Admin `$tc` ist in `vue.adapter.ts` `@deprecated tag:v6.8.0`, Ersatz `$t` (unter Flag `V6_8_0_0` Konsolen-Warnung).
- `SnippetFinder::ALLOWED_INTERSECTING_FIRST_LEVEL_SNIPPET_KEYS` ist für 6.8 deprecated.
- `SnippetFinder` sucht zusätzlich in `Resources/app/meteor-app` (siehe [T-25](./25-admin-komponenten.md)).
- **Sprachneutrale Dateien sind der Standard** (`de.json`/`en.json`, Storefront `<domain>.de.json`/`<domain>.en.json`). Doku-Beispiele nutzen teils `de-DE.json`/`en-GB.json`. Solche regionalen Dateien werden zwar gefunden (sprachneutral zuerst, dann regional), `bin/console translation:lint-filenames` meldet für sie aber den Fehler „Every country-specific translation file must have a corresponding agnostic file“, wenn die sprachneutrale Basisdatei fehlt. Reparatur: `bin/console translation:lint-filenames --fix` benennt in die sprachneutralen Namen um (bei mehreren regionalen Kandidaten für eine Basisdatei muss man manuell wählen; weitere Optionen `--all`, `--extensions=`, `--ignore=`, `--dir=`; `LintTranslationFilesCommand`).

## 6. Checkliste

- [ ] Storefront-Dateien heißen `<domain>.<locale>.json` (genau 2 oder 3 Teile), sprachneutral (`.de.`/`.en.`); `translation:lint-filenames` fehlerfrei.
- [ ] Schlüssel tragen einen Plugin-Präfix (`swagExample.*`, `swag-example.*`).
- [ ] Für jede genutzte Sprache existiert eine Datei, bei regionalen Dateien auch die sprachneutrale Basis (`translation:lint-filenames`).
- [ ] `translation:validate` läuft ohne Fehler.
- [ ] Admin-Snippets liegen als sprachneutrale `<locale>.json` (`de.json`/`en.json`) unter `app/administration/src/`.
- [ ] Texte mit Variablen nutzen `%name%` (Storefront) bzw. `{name}` (Admin).
- [ ] Cache geleert, bei Änderung am Shop auch DB-Overrides im Snippet-Set geprüft.
- [ ] Neue Admin-Texte mit `$t` statt `$tc` (6.8-Deprecation).

## 7. Quellen

Doku:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-translations.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/templates-styling/adding-snippets.html
- https://developer.shopware.com/docs/concepts/translations/ (nur Überblick zu Entity-Übersetzungen)

Quellcode (relativ zu `vendor/shopware/`): `core/System/Snippet/Files/{SnippetFileLoader,AbstractSnippetFile,GenericSnippetFile,RemoteSnippetFile,SnippetFileCollection,AppSnippetFileLoader}.php`, `core/System/Snippet/SnippetService.php` (`getStorefrontSnippets`), `SnippetPatterns.php`, `Aggregate/SnippetSet/SnippetSetDefinition.php`, `SnippetDefinition.php`, `Command/*`, `core/Framework/Adapter/Translation/Translator.php`, `storefront/Theme/Subscriber/ThemeSnippetsSubscriber.php`, `administration/Snippet/{SnippetFinder,AppLifecycleSubscriber,AppAdministrationSnippetPersister}.php`, `administration/Resources/app/administration/src/app/adapter/view/vue.adapter.ts`, `core/Framework/Plugin/Command/Scaffolding/Generator/AdminModuleGenerator.php`.

### Lücken und Unsicherheiten

- ⚠ LÜCKE: Die Doku nennt „ab 6.7.16.0“ private Dateien unter `files/snippets/storefront/` und `files/snippets/administration/`. In 6.7.15.1 sind nur die Translation-Dateisysteme (`TranslationFilesystemFactory`, `use_local_filesystem`) belegt, Pfad und Verhalten nicht.
- ⚠ UNGEPRÜFT: Frühere Namen in 6.5/6.6 (`storefront.de-DE.json`) aus dem Gedächtnis.
- ⚠ UNGEPRÜFT: Autowiring des `translator`-Services nicht geprüft, daher im Beispiel explizit `service('translator')`.
- ⚠ UNGEPRÜFT: `|trans` ist der Symfony-Twig-`TranslationExtension`-Filter. Ein Shopware-eigener `trans`-Filter wurde nicht gefunden, belegt ist `Translator::trans` als Dekorator des Symfony-Translators.
- ⚠ UNGEPRÜFT: Modul-Registrierung im Admin mit `snippets: {...}` nicht am Code geprüft (siehe T-24).
- ⚠ LÜCKE: Genaue Prioritätsreihenfolge Core/Plugin/App bei gleichen Schlüsseln nicht im Detail verfolgt. Die Doku nennt App und privates Dateisystem als niedrigste Priorität.
- ⚠ UNGEPRÜFT: CLI-Befehle nur im Code gelesen, nicht im Container ausgeführt.
