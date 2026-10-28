# Template: quality (PHPStan, Code-Style)

Shopware 6.7.15.1. Konfiguration für statische Analyse (PHPStan mit den Shopware-Regeln aus `shopware/core`) und Code-Style (php-cs-fixer, "Shopware Coding Standard"). Referenzen: ../../references/48-statische-analyse.md, ../../references/45-coding-guidelines.md, Build/Release ../../references/49-build-deployment.md. Verwandte Vorlagen: ../plugin/README.md, ../testing/README.md (PHPUnit), ../testing-js/README.md (Jest/Vitest).

Praktisch geprüft im Docker-Setup des Projekts (PHP 8.4.27): PHPStan 2.3.3 lief mit `phpstan.neon.dist` grün und meldete absichtlich eingebaute Verstöße der Shopware- und Symplify-Regeln; php-cs-fixer 3.95.27 lief mit `.php-cs-fixer.dist.php`. Details unten.

## Dateibaum

```
SwagExample/
├── phpstan.neon.dist             # Level 8, Shopware-Regeln, Bootstrap des StaticAnalyzeKernel
├── .php-cs-fixer.dist.php        # Regeln aus dem shopware-cli-Standard
├── .config/shopware-extension.yml  # nur für shopware-cli validate (⚠ UNGEPRÜFT)
└── composer.json.snippet         # Ausschnitt: php-Constraint (Plugin), require-dev + scripts (Projekt)
```

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample` (in `phpstan.neon.dist`: `allowedUnitTestClassNamespaces`, `.config/...`) | Plugin-Name / Test-Namespace | eigener Plugin-Name |
| `../../../vendor/...` | Pfad zum Projekt-`vendor` relativ zur Neon-Datei (Plugin liegt in `custom/plugins/<Name>`) | anpassen, wenn das Plugin an anderer Stelle liegt |
| `level: 8`, `phpVersion: 80200` | wie `common.neon` des Cores | nach Bedarf |

## Einrichtung

Dev-Tools gehören ins Projekt, nicht ins Plugin: das `require-dev` eines Plugins installiert der Projekt-Composer nicht.

Benötigte Pakete (alle auf Packagist; per `composer require --dev --dry-run` im Projekt auflösbar, real nicht installiert): `phpstan/phpstan` (2.3.3), `phpstan/phpstan-symfony` (2.1.1), `phpat/phpat` (0.12.5), `symplify/phpstan-rules` (14.19.3), `friendsofphp/php-cs-fixer` (3.95.27).

- Das Paket `shopware/phpstan-extension` (in der README der Extension genannt) ist auf Packagist nicht auffindbar (`composer require` im Container: "could not be found in any version"). Die Dateien `extension.neon`/`rules.neon` liegen in `vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/`, die Vorlage bindet sie von dort ein.
- `rules.neon` verlangt zusätzlich: den Service `Shopware\...\PHPStan\Configuration` samt Parameter `shopware:` (kommt nur aus `common.neon`, daher in der Vorlage übernommen), `phpstan/phpstan-symfony` (`ParameterMap`), die Hilfsservices von Symplify (`config/services/services.neon`) und `phpat/phpat`.
- `phpstan/extension-installer` NICHT verwenden: er bindet die komplette Symplify-Konfiguration ein; zusammen mit `rules.neon` entsteht `Multiple services of type Symplify\PHPStanRules\Rules\NoReturnSetterMethodRule found`.

Variante A (Standard, im Projekt): `docker compose exec web composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpat/phpat symplify/phpstan-rules friendsofphp/php-cs-fixer` (ändert `composer.json`/`composer.lock`; ⚠ UNGEPRÜFT real, nur Dry-Run).
Variante B (erprobt, ohne Eingriff ins Projekt): `docker compose exec web sh -c 'mkdir -p /tmp/qa && cd /tmp/qa && echo "{}" > composer.json && composer require --no-interaction phpstan/phpstan phpstan/phpstan-symfony phpat/phpat symplify/phpstan-rules friendsofphp/php-cs-fixer'`. Dann in `phpstan.neon.dist` die drei Includes `../../../vendor/phpstan/...`, `.../phpat/...`, `.../symplify/...` auf `/tmp/qa/vendor/...` umstellen (so wurde getestet). `/tmp` ist nicht im Volume.

## Ausführen im Docker-Setup

Immer aus dem Projektverzeichnis (dort liegt `compose.yaml`); im Container ist das Arbeitsverzeichnis `/var/www/html`.

```bash
# PHPStan (Variante A: vendor/bin/phpstan; Variante B: /tmp/qa/vendor/bin/phpstan)
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress custom/plugins/SwagExample/src/Service
docker compose exec web vendor/bin/phpstan clear-result-cache -c custom/plugins/SwagExample/phpstan.neon.dist

# Code-Style: prüfen (Exit-Code != 0 bei Abweichungen) bzw. anwenden
docker compose exec web vendor/bin/php-cs-fixer check --diff --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
docker compose exec web vendor/bin/php-cs-fixer fix --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
```

PHPStan braucht keine Datenbank. Der Bootstrap (`phpstan-bootstrap.php`) bootet den `StaticAnalyzeKernel` und legt `var/cache/static_phpstan_dev/` im Projekt an; PHPStan selbst cached nach `/tmp/phpstan` im Container. Nach Änderungen an Services/Container `var/cache/static_phpstan_dev` löschen und `clear-result-cache` ausführen.

Beispielergebnis (Variante B): `[OK] No errors` (ca. 2,6 s). Absichtlich eingefügter Verstoß `unserialize()` in einer Klasse wurde gemeldet als `shopware.unserializeUsage`; zwei Klassen in einer Datei als `symplify.multipleClassLikeInFile`.

Die Composer-Script-Hüllen aus `composer.json.snippet` sind ⚠ UNGEPRÜFT (die Einzelbefehle wurden ausgeführt).

CI und Release: dieselben Befehle ohne `docker compose exec`; zusätzlich Store-/Extension-Validierung mit `shopware-cli extension validate` (siehe ../../references/48-statische-analyse.md und ../../references/49-build-deployment.md). `shopware-cli` ist im Docker-Setup nicht installiert, nichts davon wurde ausgeführt.

## Code-Style: was belegt ist

- Offizieller Weg laut Doku: `shopware-cli extension format <pfad>` (php-cs-fixer, "Shopware Coding Standard"). `shopware-cli` bevorzugt eine `.php-cs-fixer.dist.php` im Plugin-Root vor seiner mitgelieferten Konfiguration.
- Die Regeln der Vorlage stammen 1:1 aus der mitgelieferten Konfiguration von shopware-cli (`internal/verifier/php/configs/php-cs-fixer.dist.php` im GitHub-Repo `shopware/shopware-cli`, main-Branch am 28.10.2026): `@Symfony` plus Anpassungen (u. a. `ordered_class_elements`, `yoda_style` aus, `single_quote`, `concat_space` one). Die Finder-Ausschlüsse (`vendor`, `node_modules`) sind eine Ergänzung der Vorlage.
- Weder `vendor/shopware/*` noch das Projekt enthalten eine `.php-cs-fixer*`- oder `ecs.php`-Datei; ein ECS-Setup ist nicht belegt. Lokale Prüfung: ein Plugin mit `\Namespace\Klasse::class`-Schreibweise in `services.php` wurde von der Konfiguration beanstandet (führenden Backslash entfernt); die Plugin-Vorlagen dieses Skills sind NICHT darauf hin formatiert (⚠ UNGEPRÜFT).
- php-cs-fixer meldet ohne `php`-Constraint in der `composer.json`: "No PHP version requirement found" (Snippet setzt `php: >=8.2`).

## Fallstricke (in dieser Umgebung aufgetreten)

- PHPStan aus dem Plugin-Ordner gestartet: `Class ...McpToolResponseRule not found`, weil der Projekt-Autoloader nicht gefunden wird. Immer aus dem Projekt-Root starten.
- `Service of type ...\PHPStan\Configuration ... not found`: `shopware:`-Parameter und Service fehlen (Include von `rules.neon` allein genügt nicht).
- `Service of type PHPStan\Symfony\ParameterMap ... not found`: `phpstan-symfony` nicht eingebunden.
- `Service of type Symplify\...\EnumAnalyzer ... not found`: `symplify/phpstan-rules/config/services/services.neon` nicht eingebunden.
- `Multiple services of type ...NoReturnSetterMethodRule`: `phpstan/extension-installer` aktiv, siehe oben.
- Echter Befund in der Plugin-Vorlage: `$this->container->get()` in `uninstall()` meldete `method.nonObject` (Container ist nullable); Vorlage ../plugin/SwagExample/src/SwagExample.php ist entsprechend korrigiert (Laufzeitprüfung statt `@var`).
- `core-rules.neon` (u. a. `DecorationPatternRule`) ist NICHT eingebunden und nicht getestet (⚠ UNGEPRÜFT).

## ESLint / Stylelint (Admin, Storefront)

Nur Verweis: siehe ../../references/48-statische-analyse.md (Abschnitt Administration/Storefront) und ../testing-js/README.md. Das Admin-Extension-Tooling ist als `@internal`/experimentell markiert; nichts davon wurde hier ausgeführt (⚠ UNGEPRÜFT).

## Lücken

- ⚠ LÜCKE: Version des shopware-cli-Standards zum Release 6.7.15.1 nicht belegt (Konfiguration vom main-Branch).
- ⚠ UNGEPRÜFT: Installation der Tools direkt im Projekt; `shopware-cli`; `.config/shopware-extension.yml`; Rector (`shopware-cli extension fix`, ein Composer-Paket ist nicht belegt).
