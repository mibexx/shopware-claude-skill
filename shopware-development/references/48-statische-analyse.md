# Statische Analyse und Code-Style (Referenz, Shopware 6.7.15.1)

## 1. Zweck

Diese Referenz greift, wenn für ein Plugin PHPStan, Code-Style (php-cs-fixer), Rector, ESLint/Stylelint oder die Store-/Extension-Validierung eingerichtet werden. PHPStan und php-cs-fixer wurden im Docker-Setup des Projekts praktisch ausgeführt (siehe Abschnitt 7); `shopware-cli`, Rector, ESLint/Stylelint und Store-Validierung nicht (⚠ markiert).

Abgrenzung: Konventionen/Regelinhalte in [T-45](./45-coding-guidelines.md); Tests [T-46](./46-tests-phpunit.md), [T-47](./47-tests-jest.md); Build/ZIP/`shopware-cli` in [T-49](./49-build-deployment.md); Plugin-Gerüst [T-02](./02-plugin-aufbau.md).

Lauffähige Vorlage (phpstan.neon.dist, .php-cs-fixer.dist.php, Docker-Befehle, Fallstricke): [../templates/quality/README.md](../templates/quality/README.md); PHPUnit-Vorlage: [../templates/testing/README.md](../templates/testing/README.md); JS-Tests: [../templates/testing-js/README.md](../templates/testing-js/README.md).

## 2. Konzepte

- **PHPStan-Extension** im Core (`core/DevOps/StaticAnalyze/PHPStan/`); README nennt das Split-Paket `shopware/phpstan-extension`. Dateien: `extension.neon` (Typ-Extensions), `rules.neon` (Regeln für Projekte/Plugins), `core-rules.neon` (laut README "intended for shopware core (& plugin) development", für generische Projekte nicht geeignet und deshalb nicht automatisch eingebunden, per `includes:` bewusst einbindbar; enthält u. a. `DecorationPatternRule`), `common.neon` (Core-Referenz: Level 8, bleedingEdge, strictRules, type_perfect). Parameter `shopware:` in `Configuration.php`.
- **Bootstrap:** `core/DevOps/StaticAnalyze/phpstan-bootstrap.php` bootet `StaticAnalyzeKernel` (Env `phpstan_dev`, Cache `var/cache/static_phpstan_dev`), liest `.env`, nutzt `ComposerPluginLoader`.
- **Plugin-relevante Regeln** (`rules.neon`): `PublicServiceDecoratorRule`, `NoRouteOverrideInDecoratorsRule` (`shopware.routeDecorator`), `RouteScopeRule` (`shopware.routeScope`), `NoDALAutoload`, `NoUnserializeUsageRule`, `NoFileExistsRule`, `NoNativeTimeFunctionRule`/`NoNativeTimeClassRule`, `UseHasherRule`, `PropertyNativeTypeRule`. `rules.neon` referenziert zusätzlich Klassen aus `symplify/phpstan-rules` (`Symplify\PHPStanRules\Rules\…`, u. a. `RequireUniqueEnumConstantRule`, `UppercaseConstantRule`; ein Service `NoReturnSetterMethodRule` wird dekoriert) und taggt Services mit `phpat.test` (`NotExtendFlowEventAwareRule`, Paket `phpat/phpat`). Ohne `symplify/phpstan-rules` bricht PHPStan beim Laden der Regeln ab; `phpat/phpat` wird für die `phpat.test`-Regeln benötigt. Praktisch belegt (PHPStan 2.3.3): `rules.neon` verlangt zusätzlich den Service `…\PHPStan\Configuration` samt Parameter `shopware:` (kommt nur aus `common.neon`), `phpstan/phpstan-symfony` (`PHPStan\Symfony\ParameterMap`) und die Symplify-Hilfsservices (`symplify/phpstan-rules/config/services/services.neon`). Das Split-Paket `shopware/phpstan-extension` ist auf Packagist NICHT auffindbar (`composer require` im Container: "could not be found in any version"); die Dateien liegen unter `vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/`.
- **Nur in `core-rules.neon`** (laut README "intended for shopware core (& plugin) development", nicht für generische Projekte, wird vom `extension-installer` nicht automatisch eingebunden): `DecorationPatternRule` (`shopware.decorationPattern`), `DomainExceptionRule`, `PackageAnnotationRule`, `InternalClassRule`/`InternalMethodRule`, Deprecation-/Migration-Regeln u. a. Die Datei nutzt `%symfony.containerXmlPath%` (setzt `phpstan/phpstan-symfony` voraus) und `phpat.test`-Services. Wer `DecorationPatternRule` für sein Plugin will, bindet `core-rules.neon` bewusst ein oder listet die Regel unter `rules:`.
- **Code-Style PHP:** offizieller Weg `shopware-cli extension format` (php-cs-fixer, "Shopware Coding Standard"; eigene `.php-cs-fixer.dist.php` im Root wird bevorzugt). Die von shopware-cli mitgelieferte Konfiguration (GitHub `shopware/shopware-cli`, `internal/verifier/php/configs/php-cs-fixer.dist.php`, main-Branch 28.10.2026): `@Symfony` plus Anpassungen; als Vorlage übernommen (`../templates/quality/SwagExample/.php-cs-fixer.dist.php`) und mit php-cs-fixer 3.95.27 im Container ausgeführt. In `vendor/shopware/*` und im Projekt liegt keine `.php-cs-fixer*`/`ecs.php`; ein ECS-Setup ist nicht belegt. Prettier nutzt nur die CLI-Konfiguration (eigene `.prettierrc` wird ignoriert).
- **Rector:** über `shopware-cli extension fix` / `project fix` ("Shopware Rector", versionsbewusst); ein Composer-Paket `shopware/rector` ist nicht belegt.
- **ESLint/Stylelint:** Storefront (`eslint.config.js` Flat Config ESLint 9, `stylelint.config.js`) und Administration (`eslint.config.ts`, `.stylelintrc`, Prettier) je eigene Konfiguration. Für Admin-Plugins gibt es Extension-Tooling (`administration:setup-extension-tooling`, `administration:check-extensions`). ⚠ EXPERIMENTELL/`@internal`: die Commands sind `@internal` und `@experimental stableVersion:v6.8.0 feature:ADMIN_EXTENSION_TOOLING`; die Beschreibung lautet "[EXPERIMENTAL] … Command name, options and generated output can change in any release" (`administration/Command/{SetupExtensionToolingCommand,AbstractExtensionToolingCommand,CheckExtensionsCommand}.php`). Nicht als stabile API behandeln, in CI mit Versionsbindung einsetzen.
- **Verifier/Store:** `shopware-cli extension validate` führt Builtin-Checker, PHPStan, ESLint, Stylelint und Storefront-Twig-Linter aus; `store_compliance: true` fordert passende Quell-/Kompilat-Assets. Erfolg garantiert keine Store-Freigabe (Doku).

Plugin-Struktur (Annahme):

```
custom/plugins/SwagExample/
├── composer.json                    # php-Constraint; die Dev-Pakete gehören in die composer.json des PROJEKTS (require-dev eines Plugins installiert der Projekt-Composer nicht)
├── phpstan.neon.dist
├── .php-cs-fixer.dist.php           # optional
├── .config/shopware-extension.yml   # validation: php_version, store_compliance, ignore
└── src/Resources/app/administration/{eslint.config.mjs, tsconfig.json, .shopware/}
```

## 3. Anleitung

**PHPStan**
1. Pakete ins Projekt: `docker compose exec web composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpat/phpat symplify/phpstan-rules friendsofphp/php-cs-fixer`. Per `--dry-run` im Projekt aufgelöst: PHPStan 2.3.3, phpstan-symfony 2.1.1, phpat 0.12.5, symplify/phpstan-rules 14.19.3, php-cs-fixer 3.95.27 (Composer 2.10.3, PHP 8.4.27), real ins Projekt nicht installiert (⚠ UNGEPRÜFT). Praktisch gelaufen ist die Variante mit separatem Verzeichnis `/tmp/qa` im Container (kein Eingriff ins Projekt, Details in [../templates/quality/README.md](../templates/quality/README.md)). `phpstan/extension-installer` NICHT verwenden: Konflikt `Multiple services of type Symplify\PHPStanRules\Rules\NoReturnSetterMethodRule`.
2. `phpstan.neon.dist` aus der Vorlage anlegen (Abschnitt 4): Includes `extension.neon`, `rules.neon` aus `vendor/shopware/core/…`, dazu phpstan-symfony, phpat und die Symplify-`services.neon`, außerdem Parameter `shopware:`, Service `Configuration` und `parametersSchema` (aus `common.neon`), Bootstrap `phpstan-bootstrap.php`.
3. Ausführen im Docker-Setup, IMMER aus dem Projekt-Root (`/var/www/html`; aus dem Plugin-Ordner scheitert der Lauf mit `Class …McpToolResponseRule not found`, weil der Projekt-Autoloader fehlt):

```bash
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress custom/plugins/SwagExample/src/Service
docker compose exec web vendor/bin/phpstan clear-result-cache -c custom/plugins/SwagExample/phpstan.neon.dist
docker compose exec web rm -rf var/cache/static_phpstan_dev   # nach Änderungen an Services/Container
```

   Gelaufen (Variante `/tmp/qa`, gleiche Argumente): `[OK] No errors` in ca. 2,6 s; absichtliche Verstöße wurden gemeldet (`shopware.unserializeUsage`, `symplify.multipleClassLikeInFile`). Keine Datenbank nötig. PHPStan-Ergebnis-Cache liegt in `/tmp/phpstan` im Container, der Kernel-Cache in `var/cache/static_phpstan_dev`. CI/Release: dieselben Befehle ohne `docker compose exec` (siehe [T-49](./49-build-deployment.md)).
4. Einzelne Regeln auch unter `rules:` auflisten (README, z. B. `NoEnvironmentHelperInsideCompilerPassRule`).

**php-cs-fixer direkt im Docker-Setup** (praktisch gelaufen, 3.95.27; Konfiguration aus der Vorlage; `check` liefert Exit-Code ≠ 0 bei Abweichungen):

```bash
docker compose exec web vendor/bin/php-cs-fixer check --diff --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
docker compose exec web vendor/bin/php-cs-fixer fix --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
```

**Code-Style und Rector über `shopware-cli`** (⚠ UNGEPRÜFT: `shopware-cli` ist im Docker-Setup nicht installiert; Doku `products/tools/cli/formatter.html`, `automatic-refactoring.html`)

```bash
shopware-cli extension format custom/plugins/SwagExample --dry-run   # prüfen
shopware-cli extension format custom/plugins/SwagExample             # anwenden
shopware-cli extension format . --only php-cs-fixer
shopware-cli extension fix custom/plugins/SwagExample --only rector  # vorher sauberen Git-Stand, danach git diff
shopware-cli project fix --only rector
shopware-cli project validate --only phpstan /path                   # Breaking-Change-Check ohne Änderung
```

**Administration (Plugin)** (⚠ EXPERIMENTELL und `@internal`, `@experimental stableVersion:v6.8.0`; Commands/Optionen können sich in jedem Release ändern)

```bash
( cd vendor/shopware/administration/Resources/app/administration && npm ci )
bin/console bundle:dump
bin/console administration:setup-extension-tooling
bin/console administration:check-extensions -- --only=SwagExample
bin/console administration:check-extensions -- --only=SwagExample --fix
bin/console administration:check-extensions -- --all --fail-on-skipped   # CI
```

Das `--`-Trennzeichen bei Composer-Scripts nicht vergessen (sonst werden Optionen verschluckt). Gescaffoldete `eslint.config.mjs`: `import shopware from './.shopware/eslint.mjs'; export default [ ...shopware ];`. Die Factory `shopwareAdminExtension(options)` (`extension-tooling/eslint.mjs`) bringt Vue-, TS-typed-, `sw-core-rules`, `sw-deprecation-rules`, `plugin-rules` und verbietet Imports von Admin-Interna ("Use the global Shopware object …").

**Platform-Skripte** (Admin `package.json`): `lint`, `lint:types` (tsc), `lint:scss`, `format`/`format:fix` (Prettier), `lint:twig`, `lint:snippets`, `lint:deprecations`, `lint:all`; `npm run extension-tooling:setup|check`.

**Storefront** (`package.json`): `npm run lint` (ESLint `./src` + Stylelint `./src/scss`), `lint:js`, `lint:scss`, `lint-fix`. ESLint: `@eslint/js`, `typescript-eslint` recommended+typeChecked, 4er-Einrückung, `quotes: single`, `semi`, `no-console` außer warn/error, `consistent-type-imports`, JSON-Snippets `sw-core-rules/require-global-default-use`. Stylelint: `stylelint-config-recommended-scss` + `stylelint-prettier`, Klassenmuster `^[a-z0-9\-]+$`, `max-nesting-depth` 3 (Warnung).

**Verifier/Store**

```bash
shopware-cli extension validate custom/plugins/SwagExample
shopware-cli extension validate /path --only phpstan,eslint
shopware-cli extension validate /path --exclude stylelint --format github
shopware-cli extension package /path --release --output-directory dist --filename extension.zip
shopware-cli extension validate dist/extension.zip
```

## 4. Beispiele

`phpstan.neon.dist` (an `common.neon` angelehnt; Ausführung aus dem Projekt-Root, Plugin in `custom/plugins/SwagExample`; vollständige, getestete Fassung: [../templates/quality/SwagExample/phpstan.neon.dist](../templates/quality/README.md)). Gekürzter Auszug:

```neon
includes:
    - ../../../vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/extension.neon
    - ../../../vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/rules.neon
    - ../../../vendor/phpstan/phpstan-symfony/extension.neon
    - ../../../vendor/phpat/phpat/extension.neon
    - ../../../vendor/symplify/phpstan-rules/config/services/services.neon

parameters:
    level: 8
    phpVersion: 80200
    treatPhpDocTypesAsCertain: false
    inferPrivatePropertyTypeFromConstructor: true
    reportUnmatchedIgnoredErrors: true
    paths: [src, tests]
    excludePaths: [src/Resources]
    bootstrapFiles:
        - ../../../vendor/shopware/core/DevOps/StaticAnalyze/phpstan-bootstrap.php
    symfony:
        containerXmlPath: ../../../var/cache/static_phpstan_dev/Shopware_Core_DevOps_StaticAnalyze_StaticAnalyzeKernelPhpstan_devDebugContainer.xml
    shopware:                       # Pflicht für rules.neon, siehe Vorlage (plus services: Configuration, parametersSchema)
        allowedNonDomainExceptions: []
        allowedStorefrontRouteNamespaces: [Shopware\Storefront]
        allowedUnitTestClassNamespaces: [SwagExample\Tests\Unit\]
        createMockWithoutExpectationsEnabledNamespaces: []
```

Pfade sind relativ zur Neon-Datei. Getestet wurde die Fassung mit `/tmp/qa/vendor/...` statt `../../../vendor/...` für die drei Fremdpakete (Variante `/tmp/qa`); die `../../../vendor/…`-Fassung für Installation im Projekt ist daher ⚠ UNGEPRÜFT. Ein echter Befund des Laufs: `$this->container->get()` in `uninstall()` der Plugin-Vorlage meldete `method.nonObject` (nullable Container); die Vorlage ist korrigiert.

`src/Service/GreetingService.php` (Laufzeit-Check statt `@var`, wie die Doku "Writing code for static analysis" fordert)

```php
<?php declare(strict_types=1);

namespace SwagExample\Service;

final class GreetingService
{
    public function greet(?string $name): string
    {
        if ($name === null) {
            throw new \InvalidArgumentException('Name must not be null');
        }

        return \sprintf('Hello %s', $name);
    }
}
```

(Hinweis: Domain-Exceptions statt `\InvalidArgumentException` im Core-Domänencode, siehe [T-45](./45-coding-guidelines.md); im Plugin hier nur zur Illustration.)

`.config/shopware-extension.yml`

```yaml
validation:
  php_version: '8.4'
  store_compliance: true
  ignore:
    - identifier: 'Shopware.XXXXXX'
      path: 'src/Foo.php'
```

## 5. Fallstricke

- `phpstan-bootstrap.php` fehlt/fehlerhaft: Container-Klassen werden nicht aufgelöst, Folgefehler; Cache `var/cache/static_phpstan_dev` leeren.
- Lauf aus dem Plugin-Ordner statt aus dem Projekt-Root: `Class … not found` (belegt).
- Ladefehler beim Start (alle belegt): `Service of type …\PHPStan\Configuration not found` (Parameter `shopware:`/Service fehlen), `Service of type PHPStan\Symfony\ParameterMap not found` (phpstan-symfony fehlt), `Service of type Symplify\…\EnumAnalyzer not found` (Symplify-`services.neon` fehlt), `Multiple services of type …NoReturnSetterMethodRule` (`phpstan/extension-installer` aktiv).
- `core-rules.neon` unreflektiert einbinden: laut README für Core- und Plugin-Entwicklung gedacht, für generische Projekte nicht geeignet (daher nicht automatisch über den `extension-installer`); für Plugins bewusst per `includes:` möglich; braucht `phpstan-symfony` (`containerXmlPath`) und `phpat`; enthält `DecorationPatternRule`, die in `rules.neon` fehlt.
- Decorator mit `#[Route]`: `shopware.routeDecorator`.
- Typen per `@var` erzwingen statt Laufzeit-Check/`assert`; Casts verstecken Ursachen; `list<T>` statt `array<T>` für Listen.
- `reportUnmatchedIgnoredErrors: true` lässt veraltete Ignores selbst fehlschlagen.
- Eigene `.prettierrc` im Plugin wird von `extension format` ignoriert.
- Extension ohne Admin-Konfig wird "skipped" statt grün; in CI `--fail-on-skipped`.
- "validate grün = Store-Freigabe" ist laut Doku falsch.

### Deprecations und Versionshinweise (6.5/6.6 → 6.7)

- ⚠ UNGEPRÜFT: Der Vergleich zu 6.5/6.6 ist nicht aus Quellcode belegt.
- 6.7.15.1: ESLint 9 Flat Config in Admin und Storefront; Stylelint 14 (Admin) vs. 16 (Storefront).
- `NoRouteOverrideInDecoratorsRule` prüft auch die deprecated Annotation-`Route`; Annotation-Routen sind deprecated.
- Admin-Extension-Tooling in dieser Version vorhanden, aber ⚠ `@internal` und `@experimental stableVersion:v6.8.0 feature:ADMIN_EXTENSION_TOOLING`; Einführungsversion unbekannt. Doku nennt `shopware-cli` als aktuellen Standard für validate/format/fix.

## 6. Checkliste

- [ ] `phpstan.neon.dist` mit Level, Bootstrap und Shopware-Extension/-Regeln (`core-rules.neon` nur bewusst, siehe Abschnitt 2); `phpstan-symfony`/`symplify/phpstan-rules`/`phpat/phpat` installiert
- [ ] PHPStan läuft aus dem Projekt-Root; Cache bei Änderungen geleert
- [ ] `shopware-cli extension format --dry-run` sauber
- [ ] Rector nur mit sauberem Git-Stand, Diff geprüft
- [ ] Admin-Plugin (⚠ experimentell): `administration:setup-extension-tooling` und `check-extensions` grün (`--fail-on-skipped` in CI)
- [ ] Storefront: `npm run lint` (ESLint + Stylelint) grün
- [ ] `shopware-cli extension validate` auf Plugin und ZIP; `.config/shopware-extension.yml` gepflegt
- [ ] Keine veralteten PHPStan-Ignores

## 7. Quellen

Doku: https://developer.shopware.com/docs/resources/guidelines/code/core/writing-code-for-static-analysis.html (200), https://developer.shopware.com/docs/resources/guidelines/code/, https://developer.shopware.com/docs/products/tools/cli/validation.html, `…/formatter.html`, `…/automatic-refactoring.html`, https://developer.shopware.com/docs/products/cli/, https://github.com/shopware/shopware-cli. Plan-URLs `/docs/guides/development/tooling/` und `/docs/guides/plugins/plugins/testing/`: 404/nicht belegt.

Quellcode (unter `vendor/shopware/`): `core/DevOps/StaticAnalyze/PHPStan/{README.md,common.neon,extension.neon,rules.neon,core-rules.neon,Configuration.php}`, `core/DevOps/StaticAnalyze/{phpstan-bootstrap.php,StaticAnalyzeKernel.php}`, `…/PHPStan/Rules/{DecorationPatternRule,PublicServiceDecoratorRule,NoRouteOverrideInDecoratorsRule,RouteScopeRule,NoDALAutoload,DomainExceptionRule}.php`, `…/PHPStan/Type/*`, `storefront/Resources/app/storefront/{eslint.config.js,stylelint.config.js,package.json,eslint-rules/core-rules/}`, `administration/Resources/app/administration/{eslint.config.ts,.stylelintrc,.prettierrc.js,package.json,extension-tooling/README.md,extension-tooling/eslint.mjs}`.

Praxisprüfung (28.10.2026, Docker-Setup des Projekts, Container `web`: PHP 8.4.27, Composer 2.10.3, Packagist erreichbar): PHPStan 2.3.3, php-cs-fixer 3.95.27 aus `/tmp/qa` gegen ein temporäres Plugin; Projekt-`composer.json`/`composer.lock` unverändert (nur `--dry-run`). shopware-cli-Standardkonfiguration: https://github.com/shopware/shopware-cli (`internal/verifier/php/configs/php-cs-fixer.dist.php`, `internal/verifier/phpcsfixer.go`).

### Lücken und Unsicherheiten

- Praktisch belegt: PHPStan-Lauf mit Shopware-/Symplify-Regeln (Level 8) und php-cs-fixer-Lauf im Docker-Setup; Ladefehler und Lösungen (Abschnitt 5).
- ⚠ UNGEPRÜFT: reale Installation der Tools ins Projekt (nur Dry-Run); `../../../vendor/…`-Pfadfassung der Neon-Datei; Composer-Script-Hüllen; `core-rules.neon`; ECS, Rector, `shopware-cli` (nicht installiert).
- ⚠ LÜCKE: `shopware/phpstan-extension` ist auf Packagist nicht auffindbar (README-Angabe nicht bestätigt); die Konfiguration nutzt die Dateien aus `shopware/core`. Welche Version des shopware-cli-Coding-Standards zu 6.7.15.1 passt, ist nicht belegt (Konfiguration vom main-Branch).
- ⚠ EXPERIMENTELL: Admin-Extension-Tooling (`administration:setup-extension-tooling`, `administration:check-extensions`, `extension-tooling:*`) ist `@internal`/`@experimental`, stabil frühestens ab 6.8 angekündigt (`stableVersion:v6.8.0`).
- ⚠ LÜCKE: `shopware/rector`, ECS-/Coding-Standard-Paket nicht belegt (GitHub-URLs `shopware/rector`, `shopware/extension-verifier`, `shopwareLabs/store-plugin-codestyle` lieferten 404); Gültigkeit älterer ECS-Setups unbekannt.
- ⚠ LÜCKE: Welche Verifier-Regeln der Store tatsächlich erzwingt, nennt die Doku nicht abschließend.
