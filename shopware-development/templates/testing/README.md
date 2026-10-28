# Template: testing (PHPUnit fuer PHP-Plugins)

Shopware 6.7.15.1. PHPUnit-Gerüst für ein Plugin: `phpunit.xml.dist`, Test-Bootstrap, ein Unit-Test, zwei Integrationstests (DAL und Store-API). Referenz: ../../references/46-tests-phpunit.md. Verwandte Vorlagen: ../plugin/README.md (Plugin-Wurzel), ../quality/README.md (PHPStan, Code-Style), ../testing-js/README.md (Jest/Vitest für Admin und Storefront).

Praktisch geprüft im Docker-Setup des Projekts (PHP 8.4.27, Composer 2.10.3, MariaDB 11.8): alle drei Tests liefen grün, mit PHPUnit 11.5.57 und 13.4.5. Details und Grenzen siehe "Geprüft / Lücken" unten.

## Dateibaum

```
SwagExample/
├── composer.json.snippet                    # Ausschnitt: autoload-dev (Plugin), require-dev + scripts (Projekt)
├── phpunit.xml.dist                         # Suites unit + integration, APP_ENV=test (force)
├── src/Service/PriceFormatter.php           # nur Testobjekt der Beispiele
└── tests/
    ├── TestBootstrap.php                    # TestBootstrapper + addPsr4 für den Test-Namespace
    ├── Unit/Service/PriceFormatterTest.php  # ohne Kernel/DB (StaticSystemConfigService)
    └── Integration/
        ├── Repository/ProductRepositoryTest.php   # DAL: ProductBuilder + product.repository
        └── Route/ProductStoreApiTest.php          # Store-API mit getSalesChannelBrowser()
```

Ordnerstruktur nach Symfony-/Shopware-Konvention: `tests/` spiegelt `src/` (`src/Service/X.php` -> `tests/Unit/Service/XTest.php`), Test-Namespace `SwagExample\Tests\`. Wurzel-Gerüst des Plugins (composer.json, Plugin-Klasse, services.php): ../plugin/README.md. Die Registrierung von `PriceFormatter` in `services.php` ist nur nötig, wenn ein Integrationstest den Service aus dem Container holt (dann `->public()`, siehe Fallstricke; Beispiel in ../plugin/README.md).

## Platzhalter

| Platzhalter | Bedeutung | Ersetzen durch |
|---|---|---|
| `SwagExample` / `SwagExample\Tests\` | Plugin-Name und Test-Namespace; `addCallingPlugin()` leitet den Plugin-Namen aus `extra.shopware-plugin-class` der composer.json ab | eigener Plugin-Name |
| `SwagExample.config.suffix` | Systemconfig-Schlüssel des Beispiels | eigener Schlüssel |
| `PriceFormatter`, `ProductBuilder`-Daten | Beispielobjekte | eigene Klassen/Fixtures |

## Einrichtung

1. Dev-Tools in das Projekt (nicht ins Plugin): das `require-dev` eines Plugins installiert der Projekt-Composer nicht.
   Variante A (Standard, im Projekt): `docker compose exec web composer require --dev shopware/dev-tools phpunit/phpunit symfony/browser-kit`. `shopware/dev-tools` 1.6.0 verlangt selbst `phpunit/phpunit` (`^9.6 | ^10 | ^11.4 | ^12 | ^13`) und `symfony/browser-kit` (`^5.4 | ^6 | ^7`), `composer require --dev shopware/dev-tools` allein zieht also beides nach. Das ist per `composer require --dev --dry-run` im Projekt aufgelöst (PHPUnit 13.4.5, browser-kit 7.4.21, dom-crawler 8.1.5, dev-tools 1.6.0), aber NICHT real installiert (das ändert `composer.json` und `composer.lock` des Projekts; ⚠ UNGEPRÜFT, ob die Läufe dann identisch sind).
   Variante B (ohne Eingriff ins Projekt, praktisch erprobt): separates Verzeichnis im Container:
   ```bash
   docker compose exec web sh -c 'mkdir -p /tmp/qa && cd /tmp/qa && echo "{}" > composer.json \
     && composer require --no-interaction phpunit/phpunit symfony/browser-kit:^7.4'
   ```
   `/tmp` ist nicht im Volume und geht bei neu erzeugtem Container verloren.
2. `composer.json.snippet` in die Plugin-`composer.json` übernehmen (`autoload-dev`, `php`), `phpunit.xml.dist` und `tests/` ins Plugin kopieren.
3. Test-Bootstrap: `tests/TestBootstrap.php` lässt `TestBootstrapper` die Test-DB aufbauen (`<DB>_test`) und das Plugin installieren; `addPsr4` registriert den Test-Namespace (das `autoload-dev` des Plugins wertet der Projekt-Composer nicht aus).
4. DB-Rechte: siehe nächster Abschnitt, das ist im Docker-Setup die häufigste Hürde.

## Ausführen im Docker-Setup

Im Projektverzeichnis (`/Users/.../shopware`, enthält `compose.yaml`); im Container ist das Arbeitsverzeichnis `/var/www/html`. Alle Pfade relativ dazu.

Der Compose-User `shopware` darf nur auf die Datenbank `shopware` zugreifen (`GRANT ALL PRIVILEGES ON shopware.*`). Der Bootstrapper braucht `CREATE`/`DROP` auf `shopware_test`; mit dem Standard-User bricht er ab:

```
SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user 'shopware'@'%' to database 'shopware_test'
```

Lösung ohne Änderung an DB-User und Hauptdatenbank: `DATABASE_URL` nur für den Testlauf auf den Root-Account setzen (Compose-Dev-Passwort `root`). Der Bootstrapper hängt `_test` an den DB-Namen: es wird `shopware_test` genutzt, die Datenbank `shopware` wird nicht angefasst.

```bash
# Variante A: Tools im Projekt (vendor/bin/phpunit)  ⚠ UNGEPRÜFT (nur Variante B real gelaufen)
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist

# Variante B: Tools in /tmp/qa (erprobt). auto_prepend_file ist nötig, damit Shopware\Core\TestBootstrapper gefunden wird
docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
  php -d auto_prepend_file=/var/www/html/vendor/autoload.php /tmp/qa/vendor/bin/phpunit \
  --configuration custom/plugins/SwagExample/phpunit.xml.dist

# Nur Unit / nur Integration / einzelner Test (an Variante A oder B anhängen)
  --testsuite unit
  --testsuite integration
  --filter ProductStoreApiTest

# Deprecations gegen 6.8 (belegt als Umgebungsvariable, lief grün)
docker compose exec -e V6_8_0_0=1 -e DATABASE_URL=... web ...
```

Verwendete Umgebung im Container (belegt): `APP_ENV=dev`, `DATABASE_URL=mysql://shopware:shopware@database:3306/shopware`. Der Bootstrapper lädt `.env` mit `Dotenv::bootEnv`, das bereits gesetzte Variablen nicht überschreibt. Deshalb setzt `phpunit.xml.dist` `APP_ENV=test` mit `force="true"` (`<server>` und `<env>`). Ohne das bricht jeder Integrationstest ab mit `RuntimeException: Unable to run tests against kernel without test.service_container`.

Jeder Lauf (auch `--testsuite unit`) führt den Bootstrap aus, der die Test-DB braucht: `system:install --create-database --force --drop-database --basic-setup` beim ersten Mal (Test-DB fehlt) und danach pro Lauf `plugin:refresh` + `plugin:install --activate --reinstall` (wegen `setForceInstallPlugins(true)`). `FORCE_INSTALL=1` erzwingt die Neuinstallation der Test-DB.

CI und Release: dieselben Befehle ohne `docker compose exec`, mit eigener Test-DB und eigenem DB-User mit CREATE/DROP-Recht; Pipeline-Einbindung und Build in ../../references/49-build-deployment.md. Dev-Pakete nicht ins ausgelieferte Plugin-ZIP und nicht ins Produktiv-Deployment (`composer install --no-dev`).

## Fallstricke (alle in dieser Umgebung aufgetreten, sofern nicht anders vermerkt)

- `Class "Shopware\Core\TestBootstrapper" not found`: PHPUnit aus `/tmp/qa` kennt den Projekt-Autoloader nicht (`auto_prepend_file`, siehe oben). Bei Installation im Projekt tritt das nicht auf (⚠ UNGEPRÜFT).
- `Access denied ... shopware_test`: DB-User ohne Rechte, siehe oben. Alternative: einmalig als Root `GRANT ALL ON shopware_test.* TO 'shopware'@'%'` (⚠ UNGEPRÜFT, nicht ausgeführt, um den DB-User nicht zu ändern).
- `Unable to run tests against kernel without test.service_container`: `APP_ENV` nicht `test` (Container setzt `dev`).
- `The "...Service" service or alias has been removed or inlined when the container was compiled`: private Services, die sonst niemand referenziert, entfernt der Container; `getContainer()->get()` findet sie nicht. Über öffentliche Einstiegspunkte testen (Repository, Store-API, Route) oder den Service in `services.php` mit `->public()` registrieren.
- Test-Plugin-Klassen nicht gefunden: `addPsr4` im Bootstrap fehlt.
- Store-/Admin-API-Tests ohne `symfony/browser-kit`: Fatal Error (aus dem Quellcode abgeleitet, hier nicht ausgelöst).
- Code Coverage: im Container ist weder Xdebug noch PCOV geladen (`php -m`), `--coverage-*` liefert daher keine Daten (⚠ UNGEPRÜFT, nicht versucht).
- `ProductBuilder`, `IdsCollection` sind `@internal`/`@final`: keine BC-Garantie.
- PHPUnit-Cache `.phpunit.cache/` entsteht im Plugin-Ordner: in `.gitignore` aufnehmen.

## Geprüft / Lücken

- Geprüft (28.10.2026): `unit` (1 Test), `integration` (2 Tests, DAL + Store-API), `--filter`, `V6_8_0_0=1`, PHPUnit 11.5.57 und 13.4.5, mit Symfony-Komponenten des Projekts (7.4) plus `symfony/dom-crawler` 8.1.5 aus `/tmp/qa`.
- ⚠ UNGEPRÜFT: Installation der Tools direkt im Projekt (`vendor/bin/phpunit`), PHPUnit 12, `->public()`-Variante, Coverage, `GRANT`-Lösung, Unit-Läufe ohne DB (eigener schlanker Bootstrap ohne `TestBootstrapper`).
- ⚠ LÜCKE: von Shopware empfohlene PHPUnit-Version für 6.7.15.1 nicht belegt (nur: `shopware/dev-tools` 1.6.0 erlaubt `^9.6` bis `^13`).
