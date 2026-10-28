# Theme-Grundlagen (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Greift beim Anlegen, Konfigurieren, Vererben und Aktivieren eines Storefront-Themes (`theme.json`, `ThemeInterface`, `theme:create`, `theme:change`, `theme:compile`). Konfigurationsfelder und SCSS-Variablen: [T-12](./12-theme-config-scss-variablen.md). SCSS/Assets/Icons: [T-13](./13-storefront-styling-assets.md). Twig-Overrides: [T-14](./14-storefront-twig-templates.md). Gerüst: [../templates/theme/README.md](../templates/theme/README.md).

**Verifikationsstand:** Die zentralen Aussagen dieser Referenz wurden beim Erstellen am installierten Quellcode (`storefront/…`) gegengeprüft (siehe Abschnitt 7). Die Doku liegt nur als Zusammenfassung vor, `theme-base-guide` und `add-theme-inheritance-without-resources` wurden nicht gelesen (⚠ Lücken unten).

## 2. Konzepte

- Ein **Theme** ist ein Plugin (oder eine App), dessen Bootstrap-Klasse `Shopware\Storefront\Framework\ThemeInterface` implementiert (leeres Marker-Interface, am Quellcode bestätigt) und das eine `Resources/theme.json` enthält. Plugin: `src/Resources/theme.json`. App: `<App>/Resources/theme.json`.
- **Pflichtfelder** der `theme.json`: `name` und `author` (`StorefrontPluginConfigurationFactory::createFromThemeJson` liest `$data['name']`/`$data['author']` direkt; Parse-/Lesefehler → `ThemeException::themeCompileException`).
- **Weitere Schlüssel** (im Factory-Code gelesen): `description` (pro Locale), `views`, `style`, `script`, `asset`, `previewMedia`, `config`, `configInheritance`, `iconSets`.
- **Vererbung** über `@`-Platzhalter: `@Storefront`, `@Plugins`, `@StorefrontBootstrap` (nur style) und `@<TechnischerName>` (anderes Theme/Plugin; unbekannter Name → `ThemeException::couldNotFindThemeByName`).
- **`@ParentTheme`:** Im Code gibt es dafür **keine Sonderbehandlung** (Suche nach `ParentTheme` findet nur `getParentThemes`/`parentThemeId`). Immer den echten technischen Namen des Eltern-Themes verwenden (`@MeinElternTheme`).
- **Theme-Plugin vs. Theme-App:** Plugin bringt PHP (Services, Migrationen) mit und wird als Bundle geladen (`createFromBundle`, `$bundle instanceof ThemeInterface`). App bringt nur Dateien (`createFromApp`, `ThemeAppLifecycleHandler` für activate/update/deactivate/delete/uninstall; `selfManaged`-Apps werden in `StorefrontPluginRegistry::addAppConfigs` übersprungen). Zur Plugin-vs.-App-Entscheidung: [T-10](./10-app-system-abgrenzung.md).
- **Twig-Pfade:** `src/Resources/views` eines Bundles wird automatisch als Twig-Pfad verwendet (`BundleHierarchyBuilder`: `$bundlePath . '/Resources/views'`, verifiziert). `theme:create` legt den Ordner aber nicht an.

Verzeichnisbaum nach `theme:create SwagExampleTheme --full`:

```
custom/plugins/SwagExampleTheme/
├── composer.json
└── src/
    ├── SwagExampleTheme.php                 # extends Plugin implements ThemeInterface
    └── Resources/
        ├── theme.json
        ├── config/config.xml                # --with-config / --full
        ├── snippet/storefront.{de-DE,en-GB}.json   # --with-snippets / --full
        ├── views/storefront/...             # manuell anlegen (T-14)
        └── app/storefront/
            ├── src/main.js                  # Entry (main.ts hat Vorrang, siehe Fallstricke)
            ├── src/assets/.gitkeep
            ├── src/scss/overrides.scss      # Variablen vor Bootstrap/Storefront
            ├── src/scss/base.scss           # Entry (mit --with-scss 7-1-Struktur)
            └── dist/storefront/js/swag-example-theme/swag-example-theme.js  # leer, vom Build ersetzt
```

## 3. Anleitung

```bash
bin/console theme:create SwagExampleTheme --full   # [--static] legt unter custom/static-plugins an
bin/console plugin:refresh
bin/console plugin:install --activate SwagExampleTheme
bin/console theme:change SwagExampleTheme --all --sync   # oder -s <salesChannelId>
bin/console theme:compile --sync                         # nach Änderungen erneut kompilieren
bin/console cache:clear
```

**Optionen am Quellcode verifiziert:**
- `theme:create [theme-name] [--static] [--full] [--with-config] [--with-snippets] [--with-scss]`. Name muss `/^[A-Za-z]\w{3,}$/` erfüllen (Buchstabe am Anfang, mind. 4 Zeichen).
- `theme:change [theme-name] [-s|--sales-channel=ID | --all] [--no-compile] [--sync]`. Argument = **technischer Name**. Ohne Argumente interaktiv. `--sales-channel` und `--all` schließen sich aus.
- `theme:compile [-k|--keep-assets] [-a|--active-only] [-o|--only=SC-ID] [-s|--skip=SC-ID] [-O|--only-themes=ID] [-S|--skip-themes=ID] [--sync]`. Ohne `--sync` kann asynchron über die Message Queue kompiliert werden (Worker nötig).
- Weitere Commands in `storefront/Theme/Command/`: `ThemeDumpCommand`, `ThemeRefreshCommand`, `ThemePrepareIconsCommand`.

**Vererbung auflösen (Reihenfolge):**
- `views`: Reihenfolge = Priorität aufsteigend, der **letzte Eintrag gewinnt**. Fehlt `@Plugins`, wird es hinter `@Storefront` eingefügt (`ThemeInheritanceBuilder::injectPluginWildcard`). Nicht genannte Plugins landen in `@Plugins`. Fehlt `views`, gilt `@Storefront`, `@Plugins`, dann alle aktiven Themes.
- `style`/`script`: `ThemeFileResolver` löst `@Plugins`, `@StorefrontBootstrap`, `@<Name>` (rekursiv, dedupliziert) auf. Direkte Pfade sind relativ zu `Resources/`. Einträge mit `"resolve": {"vendor": "…"}` definieren SCSS-Importpfade.
- `configInheritance`: von allgemein zu spezifisch. Fehlt es, erbt jedes Theme außer Storefront implizit von `@Storefront`. `parentThemeId` = letzter Eintrag, der weder `@Storefront` noch das Theme selbst ist.
- Kind-Theme erweitert Eltern-Theme: `"views": ["@Storefront","@Plugins","@ElternTheme","@KindTheme"]`, in `style`/`script`/`asset` `@ElternTheme` einfügen, `"configInheritance": ["@Storefront","@ElternTheme"]`.

## 4. Beispiele

`src/SwagExampleTheme.php`:

```php
<?php declare(strict_types=1);

namespace SwagExampleTheme;

use Shopware\Core\Framework\Plugin;
use Shopware\Storefront\Framework\ThemeInterface;

class SwagExampleTheme extends Plugin implements ThemeInterface
{
}
```

`src/Resources/theme.json`:

```json
{
  "name": "SwagExampleTheme",
  "author": "Example Author",
  "description": {
    "en-GB": "Minimal example theme",
    "de-DE": "Minimales Beispiel-Theme"
  },
  "views": ["@Storefront", "@Plugins", "@SwagExampleTheme"],
  "style": [
    "app/storefront/src/scss/overrides.scss",
    "@Storefront",
    "app/storefront/src/scss/base.scss"
  ],
  "script": [
    "@Storefront",
    "app/storefront/dist/storefront/js/swag-example-theme/swag-example-theme.js"
  ],
  "asset": ["@Storefront", "app/storefront/src/assets"],
  "previewMedia": "app/storefront/src/assets/preview.jpg"
}
```

`src/Resources/views/storefront/layout/footer/footer.html.twig` (Blockname `layout_footer_inner_container` existiert in `storefront/Resources/views/storefront/layout/footer/footer.html.twig`, verifiziert):

```twig
{% sw_extends '@Storefront/storefront/layout/footer/footer.html.twig' %}

{% block layout_footer_inner_container %}
    {{ parent() }}
    <p class="text-center">Mein Theme</p>
{% endblock %}
```

**Kind-Theme ohne eigene Ressourcen** (nur `theme.json`, keine eigenen SCSS/JS/Twig-Dateien; in Review-Runde 2 live kompiliert und verifiziert): `src/Resources/theme.json`

```json
{
  "name": "SwagExampleChildTheme",
  "author": "Example Author",
  "views": ["@Storefront", "@Plugins", "@ElternTheme", "@SwagExampleChildTheme"],
  "style": ["@ElternTheme"],
  "script": ["@ElternTheme"],
  "asset": ["@ElternTheme"],
  "configInheritance": ["@Storefront", "@ElternTheme"],
  "config": {
    "fields": {
      "sw-color-brand-primary": { "value": "#c4003c" }
    }
  }
}
```

Das Theme kompiliert und erbt Styles, Scripts und Config des Eltern-Themes; ein Feld braucht nur `value` (ohne `type`/`editable`), um den geerbten Wert zu überschreiben. **Hinweis:** Beim Kompilieren wird ein `ERROR` protokolliert, weil das Theme keinen eigenen Ordner `Resources/app/storefront/dist/storefront/js/<name>` hat (`ThemeCompiler::getScriptDistFiles` fängt die `DirectoryNotFoundException` des Finders und loggt sie per `logger->error`). Funktional folgenlos; wer das Log sauber halten will, legt den leeren Ordner an (`theme:create` legt ihn mit leerer `<name>.js` an).

`composer.json` und weitere Dateien: [../templates/theme/README.md](../templates/theme/README.md); Plugin-Grundlagen: [T-02](./02-plugin-aufbau.md).

## 5. Fallstricke

- Theme erscheint nicht: `plugin:refresh` vergessen oder Plugin nicht aktiv (`plugin:list`).
- `theme:change` mit Anzeigenamen statt technischem Namen → „Invalid theme name“.
- `name`/`author` fehlt oder JSON ungültig → `themeCompileException`.
- `@Name` in `style`/`script` verweist auf nicht installiertes/aktives Theme → `couldNotFindThemeByName`.
- `views` ohne eigenes Theme als letzten Eintrag → Overrides greifen nicht.
- `overrides.scss` nach `@Storefront` platziert → Variablen wirken wegen `!default` nur davor.
- Asynchrone Kompilierung ohne Worker → Änderungen erscheinen nicht; `--sync` nutzen.
- `src/Resources/views` fehlt (wird von `theme:create` nicht angelegt).
- `@ParentTheme` als wörtlicher Wert verwenden (nicht implementiert, siehe Konzepte).
- Doku-Verzeichnisbaum zeigt `src/theme.json`; **der Code liest `Resources/theme.json`** (Plugin: `src/Resources/theme.json`). Der Code gilt.
- Entry-Datei: `main.ts` wird neben `main.js` unterstützt (`StorefrontPluginConfigurationFactory::getEntryFile` prüft `main.ts`, verifiziert); Priorität/Details siehe Quellcode.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- `--no-cleanup` bei `theme:change` und `theme:compile` ist **deprecated** (Entfernung v6.8.0.0, ohne Wirkung); Aufräumen übernimmt der Scheduled Task `theme.delete_files` (am Quellcode verifiziert).
- `ThemeLifecycleService::refreshTheme` bekommt in v6.8.0 einen Pflichtparameter (`#[NewOptionalParameter]`).
- `theme:create`-Template verlangt `shopware/core ~6.7.0`. Einführungsversion der Optionen `--full`/`--with-*` und `--static` in 6.5/6.6: ⚠ nicht belegt.
- `configInheritance` gibt es laut Doku seit 6.4.8.0.
- Runtime-Config-Klassen (`ThemeRuntimeConfigService`, `ThemeRuntimeConfigStorage`), `main.ts`, `@StorefrontBootstrap`: in 6.7.15.1 vorhanden; Einführungsversion ⚠ nicht belegt.

## 6. Checkliste

- [ ] Klasse extends `Plugin` und implements `ThemeInterface`.
- [ ] `src/Resources/theme.json` mit `name` und `author`, gültiges JSON.
- [ ] `views` mit `@Storefront`, `@Plugins`, eigenem Theme zuletzt; bei Kind-Theme `@ElternTheme` davor.
- [ ] `style`: `overrides.scss` vor `@Storefront`, `base.scss` danach.
- [ ] `configInheritance` gesetzt, wenn Eltern-Theme-Konfiguration geerbt werden soll.
- [ ] `previewMedia`-Datei existiert.
- [ ] `plugin:refresh`, `plugin:install --activate`, `theme:change`, `theme:compile --sync`, `cache:clear` ausgeführt.
- [ ] Keine Verwendung der deprecated Option `--no-cleanup`.

## 7. Quellen

Doku (nur per Zusammenfassung ausgewertet):
- https://developer.shopware.com/docs/guides/plugins/themes/create-a-theme.html
- https://developer.shopware.com/docs/guides/plugins/themes/inheritance/add-theme-inheritance.html
- https://developer.shopware.com/docs/guides/plugins/themes/configuration/theme-inheritance-configuration.html
- Nicht abgerufen: `…/themes/theme-base-guide.html`, `…/themes/inheritance/add-theme-inheritance-without-resources.html` (Inhalt durch Live-Test und `ThemeCompiler`/`StorefrontPluginConfigurationFactory` ersetzt)

Quellcode (`vendor/shopware/storefront/`):
- `Framework/ThemeInterface.php` (`ThemeInterface`, leeres Marker-Interface)
- `Resources/theme.json` (Referenz-theme.json)
- `Theme/Command/ThemeCreateCommand.php`, `ThemeChangeCommand.php`, `ThemeCompileCommand.php`, `ThemeDumpCommand.php`, `ThemeRefreshCommand.php`, `ThemePrepareIconsCommand.php`
- `Theme/StorefrontPluginConfiguration/StorefrontPluginConfigurationFactory.php` (`createFromBundle`, `createFromApp`, `createFromThemeJson`, `getEntryFile`)
- `Theme/StorefrontPluginRegistry.php`, `Theme/ThemeFileResolver.php`, `Theme/Twig/ThemeInheritanceBuilder.php`, `Theme/ThemeMergedConfigBuilder.php`, `Theme/ThemeLifecycleService.php`, `Theme/ThemeAppLifecycleHandler.php`, `Theme/ThemeService.php`
- `core/Framework/Adapter/Twig/NamespaceHierarchy/BundleHierarchyBuilder.php` (Twig-Pfad `Resources/views`)

### Lücken und Unsicherheiten

- ⚠ LÜCKE: `@ParentTheme` wird in der Doku genannt, im Code nicht implementiert. Ob es in der Doku nur ein Beispielname ist, ist nicht am Originaltext geprüft.
- Theme ohne eigene Ressourcen: durch Live-Test (Review-Runde 2) und Quellcode belegt, siehe Beispiele. ⚠ Die Doku-Seite `add-theme-inheritance-without-resources` selbst wurde nicht gelesen.
- ⚠ UNGEPRÜFT: Doku-Details (z. B. Vererbungsbeispiele) nur aus Zusammenfassung.
- ⚠ UNGEPRÜFT: `iconSets` wird gelesen, nicht tiefer untersucht (siehe [T-13](./13-storefront-styling-assets.md)).
- Live bestätigt (Review-Runden 2–3): `plugin:install`, `theme:change --all --sync` und `theme:compile --sync` wurden von den Reviewern ausgeführt und laufen fehlerfrei. ⚠ UNGEPRÜFT bleiben die Optionen, die dabei nicht benutzt wurden (z. B. `--static`, `--only`, `--skip`); sie stammen aus dem Quellcode.
- ⚠ LÜCKE: Einführungsversionen einzelner Optionen/Features in 6.5/6.6 nicht belegt.
