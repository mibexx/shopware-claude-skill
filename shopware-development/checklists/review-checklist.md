# Review-Checkliste (Shopware 6.7.15.1)

Zweck: Code-Review eines Plugins/Themes vor dem Merge. Abgehakte Punkte nur, wenn am Code geprüft. Details stehen in der jeweils verlinkten Referenz.

## 1. Grundprinzipien
- [ ] Keine Änderungen unter `vendor/shopware/**` oder am Core ([T-45](../references/45-coding-guidelines.md)).
- [ ] Erweiterungsweg passend: Event/Subscriber → Extension Point → Dekoration; kein Ersetzen von Core-Service-IDs ([T-06](../references/06-service-dekoration.md), [T-07](../references/07-events-subscriber.md), [T-08](../references/08-extension-points.md)).
- [ ] Nur Nicht-`@internal`-API genutzt, `@final`-Klassen nicht erweitert; dekorierte Services haben `Abstract…` + `getDecorated()`.
- [ ] Eigener Namespace (kein `Shopware\…`), Tabellen mit Vendor-Präfix (`swag_…`).
- [ ] `declare(strict_types=1)`, native Typen, Konstruktor-Injection (`private readonly`).
- [ ] Fehler als Domain-Exceptions, keine `\RuntimeException` im Domänencode.
- [ ] Keine neuen Deprecations; keine Nutzung deprecated APIs (z. B. `--no-cleanup`, XML-Services, Vuex, `CookieProviderInterface`, `HttpClient`/`DomAccess` im Storefront-JS) – siehe jeweilige Referenz, Abschnitt „Deprecations“.

## 2. Plugin-Struktur und Lifecycle ([T-02](../references/02-plugin-aufbau.md), [T-03](../references/03-plugin-lifecycle.md))
- [ ] `composer.json`: `type`, `extra.shopware-plugin-class`, `extra.label`, `autoload.psr-4`, `require shopware/core`-Constraint.
- [ ] Lifecycle-Methoden idempotent; `uninstall()` prüft `keepUserData()`; in `install()` keine eigenen Services angenommen.
- [ ] Sales-Channel- und Kontextlogik über `SalesChannelContext`/Channel-ID, nicht über globale Annahmen ([T-20](../references/20-sales-channel-unterscheidung.md)).

## 3. Datenbank und DAL ([T-04](../references/04-datenbank-migrationen.md), [T-27](../references/27-dal-entities.md), [T-28](../references/28-dal-lesen-schreiben.md), [T-29](../references/29-entity-extensions-custom-fields.md))
- [ ] Schema nur per Migration (`src/Migration/`), idempotent, rückwärtskompatibel (Expand/Contract); destruktive Änderungen nur in `updateDestructive()`.
- [ ] Entity registriert (`shopware.entity.definition`), Flags/Felder korrekt, Translation/Assoziationen mit passenden Migrationen.
- [ ] Kein `EntityRepository`-Zugriff in Storefront-Controllern/PageLoadern (laut [T-16](../references/16-storefront-controller-pages.md)); `SalesChannelRepository` für Sales-Channel-gefilterte Daten.
- [ ] Wahl Custom Field vs. EntityExtension begründet.

## 4. Services, Events, Routen ([T-05](../references/05-services-registrieren.md), [T-30](../references/30-store-api-routen.md), [T-31](../references/31-admin-api-routen.md))
- [ ] `services.php` (kein neues XML); Tags vollständig (`kernel.event_subscriber`, `console.command`, `messenger.message_handler`, …); Controller `public` + `setContainer`.
- [ ] Routen: richtiger `_routeScope` (`storefront`/`store-api`/`api`), Admin-Routen mit `_acl`.
- [ ] Store-API-Route mit Abstract/Route/Response; Änderungen bestehender Routen per Dekoration.
- [ ] Cachebare Routen/Tags/Invalidierung bewusst gesetzt ([T-41](../references/41-caching.md)).

## 5. Konfiguration und Rechte ([T-19](../references/19-plugin-konfiguration.md), [T-23](../references/23-acl-rechte-rollen.md))
- [ ] `config.xml` XSD-konform; Config-Keys `<Plugin>.config.<feld>`; Defaults gesetzt; Lesezugriff mit `salesChannelId`.
- [ ] Secrets nicht in Twig/Frontend/Logs.
- [ ] ACL: Backend-Schutz (`_acl`) zusätzlich zu UI-Gating; Privilegien mit `viewer`/`editor`/`creator`/`deleter` und Abhängigkeiten; Snippets für Privilegien.

## 6. Storefront und Theme ([T-11](../references/11-theme-grundlagen.md)–[T-15](../references/15-storefront-js-plugins.md))
- [ ] Twig: `sw_extends`/`sw_include`, Blocknamen gegen Core geprüft, `parent()` bewusst, `|sw_sanitize` statt `|raw`, Texte als Snippets ([T-18](../references/18-snippets-uebersetzungen.md)).
- [ ] `theme.json`: `name`/`author`, `views`/`style`/`script`/`asset`-Reihenfolge, `overrides.scss` vor `@Storefront`, kein `@ParentTheme`.
- [ ] JS-Plugin: `main.js` registriert, `destroy()` räumt Listener auf, `window`/`document`-Zugriffe über Helper.
- [ ] Cookies/Consent für Tracking-Skripte berücksichtigt ([T-42](../references/42-cookies-consent.md)).

## 7. Administration ([T-24](../references/24-admin-module.md)–[T-26](../references/26-admin-datenhandling.md), [T-21](../references/21-cms-elemente.md), [T-22](../references/22-cms-bloecke-sichtbarkeit.md))
- [ ] Modul-ID mit Bindestrich; Navigation mit `parent`; Routen mit `meta.privilege`.
- [ ] Komponenten per Twig-Block-Override (stabiler Weg in 6.7.15.1); SFC/Composition-Erweiterungen nur bewusst (experimentell laut [T-25](../references/25-admin-komponenten.md)).
- [ ] Pinia statt Vuex; Fehlerbehandlung und Notifications mit `message`.
- [ ] CMS-Element: `name`, `getType()` und Twig-Suffix stimmen überein; Sichtbarkeit nur als Viewport-Flags (keine regelbasierte Sichtbarkeit im installierten Code).
- [ ] Admin-Snippets für Deutsch und Englisch, Dateien sprachneutral (`de.json`/`en.json`, Storefront `<domain>.de.json`/`.en.json`); `bin/console translation:lint-filenames` fehlerfrei ([T-18](../references/18-snippets-uebersetzungen.md)).

## 8. Asynchronität und Fachlogik ([T-32](../references/32-rule-builder.md)–[T-38](../references/38-order-statemachine-dokumente.md))
- [ ] Scheduled Task: Task + Handler (`#[AsMessageHandler]`), registriert; Message-Marker für Routing; kein Blockieren im Request.
- [ ] Rule: kein DB-Zugriff in `match()`.
- [ ] Zahlung: `AbstractPaymentHandler`, Status nur über `OrderTransactionStateHandler`.
- [ ] Zustandswechsel nur über `StateMachineRegistry::transition()`.

## 9. Tests und statische Analyse ([T-46](../references/46-tests-phpunit.md), [T-47](../references/47-tests-jest.md), [T-48](../references/48-statische-analyse.md); Gerüste: [testing](../templates/testing/README.md), [testing-js](../templates/testing-js/README.md), [quality](../templates/quality/README.md))
- [ ] Neue/geänderte Logik hat Tests: Unit-Tests ohne Kernel (`#[CoversClass]`, Stubs) und Integrationstests mit `IntegrationTestBehaviour` (+ `SalesChannelApiTestBehaviour` bei Store-API); Tests sind unabhängig voneinander (keine ungeschlossenen Transaktionen, kein DDL im Test).
- [ ] `phpunit.xml.dist` mit Suites `unit` und `integration`, `APP_ENV=test` (`force="true"`), `tests/TestBootstrap.php` mit `addCallingPlugin()`, `setForceInstallPlugins(true)` und `addPsr4` für den Test-Namespace; Dev-Abhängigkeiten nur unter `require-dev`.
- [ ] Tests lokal im Docker-Setup gelaufen: `docker compose exec … vendor/bin/phpunit --configuration custom/plugins/<Plugin>/phpunit.xml.dist` (Test-DB `shopware_test`, DB-User mit CREATE/DROP) und grün; zusätzlich `V6_8_0_0=1` ohne neue Deprecations.
- [ ] Admin-Komponenten und Storefront-JS-Plugins mit Jest getestet (Host-Node oder Node-Container; der `web`-Container hat kein Node); Playwright/Acceptance nur als Abgrenzung.
- [ ] PHPStan (`phpstan.neon.dist`, Level 8, Shopware-Regeln) aus dem Projekt-Root ohne neue Befunde; keine neuen Einträge in `ignoreErrors`, kein `phpstan/extension-installer`.
- [ ] Code-Style: `php-cs-fixer check` bzw. `shopware-cli extension format --dry-run` sauber; ESLint/Stylelint (Admin/Storefront) ohne Befunde.
- [ ] Alle ⚠-Lücken der verwendeten Referenzen im PR benannt oder gegen den Quellcode geklärt.
