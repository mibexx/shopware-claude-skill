# Snippets and translations (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies to UI texts in the storefront and Administration (snippet files, snippet sets, `|trans`, `$t`, language packs and the `translation:*` CLI).
Scope: entity translations (`*_translation` tables, `TranslatedField`) belong to [T-27 DAL entities](./27-dal-entities.md). Twig mechanics see [T-14](./14-storefront-twig-templates.md), Admin modules/components see [T-24](./24-admin-modules.md) and [T-25](./25-admin-components.md), plugin config labels see [T-19](./19-plugin-configuration.md). Rule of thumb: snippets are UI texts shipped with code/plugin/theme. Content data such as product names are entity translations.

## 2. Concepts

- **Two separate systems:**
  - **Storefront:** `<bundle>/Resources/snippet/**/<domain>.<locale>.json`, loaded server-side, used with Twig `|trans` or `TranslatorInterface`.
  - **Administration:** `<plugin>/src/Resources/app/administration/src/**/<locale>.json`. The `SnippetFinder` delivers them as JSON to vue-i18n; `$t` is used (`$tc` deprecated).
- **Storefront file names:** `<domain>.<locale>.json` (2 parts) or `<domain>.<locale>.base.json` (3 parts). Only part 2 counts as the locale (`SnippetFileLoader::loadSnippetFilesInDir`). Other name forms are silently ignored. Locale according to `SnippetPatterns::LOCALE_PATTERN` (`en`, `de`, `en-GB`, `de-DE`).
- **Snippet sets (`snippet_set`):** Each sales channel domain references a set (`sales_channel_domain.snippet_set_id`, the set has `iso`). Via the set, the file snippets of the locale are loaded; afterwards DB snippets (table `snippet`, maintainable in the Admin) override the file values.
- **Storefront order** (`SnippetService::getStorefrontSnippets`): fallback locale, catalog `messages`, snippets of the target locale, finally DB overrides. Fallback = language part of the locale (`de-DE` → `de`, `Translator::getFallbackLocale`) or system language. Region fallback: a file for `de-DE` falls back to `de` (`SnippetFileCollection::getSnippetFilesWithLocaleFallback`).
- **Registration:** not required. `SnippetFileLoader::loadShippedSnippets` iterates all kernel bundles (except Administration) and reads `<bundlePath>/Resources/snippet` (for plugins `src`).
- **Admin files:** file name `<locale>.json` or `administration.json` (finder in `SnippetFinder`). First the language-neutral locale (`de`), then the regional one (`de-DE`). All snippets are merged into one object via `array_replace_recursive`; identical keys override each other.
- **Language packs:** Since 6.7 there is a translation system (`translation:*`) that loads translations from the translations repo (private filesystem). Plugins that ship their own translation for a locale are then skipped (`pluginTranslationExistsForLocale`).
- **Apps:** storefront `<app>/Resources/snippet/` (`AppSnippetFileLoader`), Administration `<app>/Resources/app/administration/snippet/*.json` (table `app_administration_snippet`, `AppLifecycleSubscriber`). More about apps in [T-10](./10-app-system-scope.md).

Directory tree:

```
custom/plugins/SwagExample/src/Resources/
├── snippet/                                  # STOREFRONT (recursive, *.json)
│   ├── swag-example.de.json                  # <domain>.<locale>.json
│   ├── swag-example.en.json
│   └── swag-example.en-GB.json               # optional region-specific, ONLY in addition to the language-neutral file
├── views/storefront/page/product-detail/index.html.twig
└── app/administration/src/module/swag-example/
    └── snippet/{de.json,en.json}             # ADMIN: <locale>.json (language-neutral)
```

Core reference: `storefront/Resources/snippet/storefront.{de,en}.json`, `core/Framework/Resources/snippet/messages.{de,en}.base.json`, `administration/Resources/app/administration/src/app/snippet/{de,en}.json`, per Admin module `src/module/*/snippet/`.

## 3. Instructions

1. **Choose a namespace:** unique prefix per plugin (`swagExample.*` storefront, `swag-example.*` Admin). Without a prefix, core keys in the Admin get overridden.
2. **Storefront files:** create under `src/Resources/snippet/<domain>.<locale>.json`.
3. **Admin files:** create under `src/Resources/app/administration/src/` at any depth as `<locale>.json`.
4. **Usage:** storefront `{{ 'swagExample.headline'|trans }}` or `TranslatorInterface::trans()`. Admin `$t('swag-example.…')` in the template, `this.$t(...)` in JS (`$tc` is deprecated, see the Deprecations section; docs examples still use `$tc`). `$tc` internally calls the same `i18n.global.t` (`vue.adapter.ts`), so `$t(key, 3)` also returns the plural variant.
5. **Placeholders:** storefront Symfony style `%name%`, Admin vue-i18n `{n}`/`{name}` and `|` for plurals.
6. **Cache and build:**
   ```bash
   bin/console cache:clear
   bin/console plugin:refresh && bin/console plugin:install --activate SwagExample
   bin/build-administration.sh   # only if Admin JS changed, snippets are delivered at runtime via API
   ```
7. **Overriding texts in the Admin:** Settings → Shop → Snippets (snippet sets); values end up in `snippet` and override file values per set.
8. **Languages/sets:** create a snippet set in the Admin or via `translation:install`.
9. **CLI (from source code `core/System/Snippet/Command`):**
   - `translation:validate` (alias `snippets:validate`, alias deprecated for 6.8; option `--fix`): completeness and pluralization
   - `translation:list`: configured locales
   - `translation:download`: downloads translations, but does not create languages/snippet sets
   - `translation:install`: downloads and installs locales, overwrites existing translations
   - `translation:update`: updates installed translations
   - `translation:lint-filenames`: checks whether a language-neutral base file exists for regional files (e.g. `de.json` for `de-DE.json`); with `--fix`, regional file names are renamed to language-neutral ones

## 4. Examples

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
`en.json` analogously. Usage in Admin Twig:
```twig
{{ $t('swag-example.general.mainMenuItemGeneral') }}
{{ $t('swag-example.general.items', 3) }}
```
Module registration (`index.js`) see [T-24](./24-admin-modules.md). Note: the Admin module scaffold creates `src/Resources/app/administration/src/snippet/en.json` (`AdminModuleGenerator`).

## 5. Pitfalls

- File name with a different number of parts (`my.plugin.de.json`): the file is silently ignored.
- Cache not cleared: the catalog is cached (`translation.catalog.*`, tag `Translator::tag($snippetSetId)`), use `bin/console cache:clear`.
- DB overrides always win: if the file value does not change, check the Admin snippet set for an entry with the same name.
- Key collision in the Admin without a plugin prefix (merge via `array_replace_recursive`).
- According to the docs, app Admin snippets cannot override existing keys from core/plugins (not checked against the code). The code sanitizes app snippets via HtmlSanitizer (`SnippetFinder::sanitizeAppSnippets`).
- Locale format: `de_DE` is tolerated by the pattern, the convention is the hyphen.
- Broken Admin snippet JSON: exception in debug, otherwise logged and skipped (`SnippetFinder::parseFiles`).
- Maintaining entity content as snippets: wrong tool (see T-27).
- Theme snippets: `ThemeSnippetsSubscriber` excludes snippets of unused themes; the base theme `Storefront` is always loaded.

### Deprecations and version notes (6.5/6.6 → 6.7)

- Core snippets in 6.7.15.1 are language-neutral (`storefront.de.json`, `storefront.en.json`) with region fallback. The file convention `en-GB` for plugins still works.
- New translation system: commands `translation:*`, services `TranslationLoader`, `TranslationUpdater`, `SnippetFileLoader::loadTranslationSnippets`. `snippets:validate` is a deprecated alias (removal in 6.8).
- Admin `$tc` is `@deprecated tag:v6.8.0` in `vue.adapter.ts`, replacement `$t` (console warning under flag `V6_8_0_0`).
- `SnippetFinder::ALLOWED_INTERSECTING_FIRST_LEVEL_SNIPPET_KEYS` is deprecated for 6.8.
- `SnippetFinder` additionally searches in `Resources/app/meteor-app` (see [T-25](./25-admin-components.md)).
- **Language-neutral files are the standard** (`de.json`/`en.json`, storefront `<domain>.de.json`/`<domain>.en.json`). Docs examples partly use `de-DE.json`/`en-GB.json`. Such regional files are found (language-neutral first, then regional), but `bin/console translation:lint-filenames` reports the error "Every country-specific translation file must have a corresponding agnostic file" for them if the language-neutral base file is missing. Fix: `bin/console translation:lint-filenames --fix` renames to the language-neutral names (with several regional candidates for one base file you have to choose manually; further options `--all`, `--extensions=`, `--ignore=`, `--dir=`; `LintTranslationFilesCommand`).

## 6. Checklist

- [ ] Storefront files are named `<domain>.<locale>.json` (exactly 2 or 3 parts), language-neutral (`.de.`/`.en.`); `translation:lint-filenames` without errors.
- [ ] Keys carry a plugin prefix (`swagExample.*`, `swag-example.*`).
- [ ] A file exists for every language used; for regional files also the language-neutral base (`translation:lint-filenames`).
- [ ] `translation:validate` runs without errors.
- [ ] Admin snippets are language-neutral `<locale>.json` files (`de.json`/`en.json`) under `app/administration/src/`.
- [ ] Texts with variables use `%name%` (storefront) or `{name}` (Admin).
- [ ] Cache cleared; for changes on the shop, DB overrides in the snippet set also checked.
- [ ] New Admin texts use `$t` instead of `$tc` (6.8 deprecation).

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-translations.html
- https://developer.shopware.com/docs/guides/plugins/plugins/administration/templates-styling/adding-snippets.html
- https://developer.shopware.com/docs/concepts/translations/ (only an overview of entity translations)

Source code (relative to `vendor/shopware/`): `core/System/Snippet/Files/{SnippetFileLoader,AbstractSnippetFile,GenericSnippetFile,RemoteSnippetFile,SnippetFileCollection,AppSnippetFileLoader}.php`, `core/System/Snippet/SnippetService.php` (`getStorefrontSnippets`), `SnippetPatterns.php`, `Aggregate/SnippetSet/SnippetSetDefinition.php`, `SnippetDefinition.php`, `Command/*`, `core/Framework/Adapter/Translation/Translator.php`, `storefront/Theme/Subscriber/ThemeSnippetsSubscriber.php`, `administration/Snippet/{SnippetFinder,AppLifecycleSubscriber,AppAdministrationSnippetPersister}.php`, `administration/Resources/app/administration/src/app/adapter/view/vue.adapter.ts`, `core/Framework/Plugin/Command/Scaffolding/Generator/AdminModuleGenerator.php`.

### Gaps and uncertainties

- ⚠ GAP: The docs mention private files under `files/snippets/storefront/` and `files/snippets/administration/` "from 6.7.16.0". In 6.7.15.1 only the translation filesystems (`TranslationFilesystemFactory`, `use_local_filesystem`) are confirmed, not the path and behavior.
- ⚠ UNVERIFIED: Earlier names in 6.5/6.6 (`storefront.de-DE.json`) from memory.
- ⚠ UNVERIFIED: Autowiring of the `translator` service not checked, hence explicitly `service('translator')` in the example.
- ⚠ UNVERIFIED: `|trans` is the Symfony Twig `TranslationExtension` filter. No Shopware-specific `trans` filter was found; confirmed is `Translator::trans` as a decorator of the Symfony translator.
- ⚠ UNVERIFIED: Module registration in the Admin with `snippets: {...}` not checked against the code (see T-24).
- ⚠ GAP: Exact priority order core/plugin/app for identical keys not traced in detail. The docs name app and private filesystem as the lowest priority.
- ⚠ UNVERIFIED: CLI commands only read in the code, not executed in the container.
