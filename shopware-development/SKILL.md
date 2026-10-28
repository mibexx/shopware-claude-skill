---
name: shopware-development
description: Entwicklung mit Shopware 6.7 (Stand 6.7.15.1) – Plugins, Themes, Storefront (Twig, SCSS, JS-Plugins), `theme.json`, Snippets/Übersetzungen, Produkt-Listing und Suche, Administration (Admin-Module, Komponenten, ACL), CMS-Elemente und -Blöcke (Shopping Experiences), DAL/Entities, Migrationen, Plugin-Konfiguration (config.xml), Sales-Channel-Unterscheidung, Events/Subscriber, Service-Dekoration, Extension Points, Store-API und Admin-API, Rule Builder, Flow Builder, Scheduled Tasks, Message Queue, Mail-Templates, Warenkorb, Payment, Caching, Tests (PHPUnit, Jest), PHPStan/Code-Style, Build/Deployment. Verwenden bei jeder Aufgabe, die ein Shopware-Plugin, Theme, die Storefront, die Administration, einen CMS-Block, eine Entity, eine Migration, Rechte (ACL), Sales-Channel-Logik, einen Subscriber oder Decorator, eine Store-API-Route oder Shopware-Coding-Guidelines betrifft – auch wenn nur „Shopware“, `bin/console`, `custom/plugins` oder `services.php` genannt wird.
---

# Shopware-6-Entwicklung (Plugins und Themes)

**Versionsstand:** Shopware **6.7.15.1** (Core, Storefront, Administration, Elasticsearch; geprüft mit `bin/console --version`). Maßgeblich ist der installierte Quellcode unter `vendor/shopware/{core,storefront,administration,elasticsearch}`; weicht die Doku davon ab, gilt der Code. Quellen-Basis: https://developer.shopware.com (Doku kann auf „latest“ stehen).

Dieser Skill ist bewusst kompakt. Details stehen in `references/`, Gerüste in `templates/`, Prüflisten in `checklists/`. Lade nur die Referenz, die zur Aufgabe passt.

## Grundprinzipien

1. **Keine Core-Änderungen.** Nie `vendor/shopware/**` anpassen. Erweiterung ausschließlich über Plugin/Theme (oder App, siehe Entscheidungshilfen).
2. **Dekoration/Events vor Überschreiben.** Reihenfolge der Wahl: Event/Subscriber → Extension Point → Service-Dekoration (`decorates`) → (nur Twig/JS/Admin) Template-/Plugin-Override. Nie einen Core-Service durch eine eigene Klasse mit gleicher ID ersetzen.
3. **BC-Vertrag beachten.** `@internal` nicht nutzen, `@final` nicht erweitern (stattdessen Events/Dekoration). Für Dekoration vorgesehen sind Services mit `Abstract…`-Basis und `getDecorated()`. Deprecations (`@deprecated tag:v6.8.0`) nicht neu einführen, bestehende bei Neuentwicklung meiden. → [T-45](references/45-coding-guidelines.md)
4. **Symfony-Ordnerstruktur im Plugin.** Code unter `src/`, Konfiguration unter `src/Resources/config/` (`services.php`, `config.xml`, `routes.php`), Migrationen unter `src/Migration/`, Twig unter `src/Resources/views/`, Snippets unter `src/Resources/snippet/`, Storefront-Frontend unter `src/Resources/app/storefront/src/`, Administration unter `src/Resources/app/administration/src/`. → [T-01](references/01-projektstruktur-dev-umgebung.md), [T-02](references/02-plugin-aufbau.md)
5. **Coding-Guidelines.** `declare(strict_types=1)`, Konstruktor-Injection mit `private readonly`, **`services.php` ist in 6.7 das Standardformat (XML ist deprecated)**, eigener Namespace (z. B. `SwagExample\…`, nie `Shopware\…`), Domain-Exceptions statt `\RuntimeException`, Feature-Flags nur über `Feature::isActive()`. → [T-45](references/45-coding-guidelines.md), [T-05](references/05-services-registrieren.md)
6. **Lifecycle sauber halten.** Install/Update/Uninstall idempotent; bei Uninstall `keepUserData` beachten; Schema nur über Migrationen. → [T-03](references/03-plugin-lifecycle.md), [T-04](references/04-datenbank-migrationen.md)
7. **Nichts erfinden.** Unbelegtes mit `⚠ LÜCKE`/`⚠ UNGEPRÜFT` kennzeichnen (siehe „Bekannte Lücken“). Signaturen im Zweifel am installierten Quellcode prüfen.

## Standard-Entwicklungsablauf

1. **Einordnen:** Plugin, Theme oder App? Welches Erweiterungsmittel (siehe Entscheidungshilfen)? Passende Referenz lesen.
2. **Gerüst anlegen:** `bin/console plugin:create SwagExample` bzw. `theme:create SwagExampleTheme`, oder Vorlage aus `templates/` kopieren und Platzhalter ersetzen.
3. **Schema/Daten:** Migration (`src/Migration/Migration<Timestamp><Name>.php`), Entity-Definition, Lifecycle-Hooks.
4. **Backend-Logik:** Services in `services.php` registrieren; Subscriber/Decorator/Route/Command/Task ergänzen.
5. **Frontend:** Storefront (Twig, SCSS, JS-Plugin) bzw. Administration (Modul, Komponenten, Snippets, ACL).
6. **Installieren und bauen:**
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample   # später: plugin:update SwagExample
   bin/console cache:clear
   bin/build-storefront.sh / bin/build-administration.sh   # → T-49; theme:compile bei Theme-Änderungen
   ```
7. **Prüfen:** Tests und statische Analyse laut Abschnitt „Qualitätssicherung: Tests & statische Analyse“. Dann [Review-Checkliste](checklists/review-checklist.md); vor Auslieferung [Release-Checkliste](checklists/release-checklist.md).

## Qualitätssicherung: Tests & statische Analyse

Pflicht für jede Erweiterung. Alle Befehle laufen im Docker-Setup dieses Projekts im Container `web` (`docker compose exec web …`, Arbeitsverzeichnis `/var/www/html`; Docker ggf. über `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`). Gerüste: [templates/testing](templates/testing/README.md) (PHPUnit), [templates/testing-js](templates/testing-js/README.md) (Jest), [templates/quality](templates/quality/README.md) (PHPStan, php-cs-fixer).

**Standardablauf**
1. **Einrichten (einmalig):** Dev-Abhängigkeiten, `phpunit.xml.dist`, `tests/TestBootstrap.php`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php` aus den Templates übernehmen. Im Projekt sind PHPUnit, PHPStan und php-cs-fixer **nicht** vorinstalliert (Dry-Run: `shopware/dev-tools` 1.6.0, PHPUnit, `symfony/browser-kit`, PHPStan u. a. sind per Composer auflösbar).
2. **Schreiben:** Unit-Tests ohne Kernel (Stubs, `#[CoversClass]`); Integrationstests mit `IntegrationTestBehaviour` und `IdsCollection`/`ProductBuilder` (DB, Transaktion je Test); Admin-Komponenten und Storefront-JS-Plugins mit Jest ([T-46](references/46-tests-phpunit.md), [T-47](references/47-tests-jest.md)).
3. **Lokal ausführen:**
   ```bash
   docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
     vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist      # Integrationstests legen die separate DB shopware_test an
   docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress
   docker compose exec web vendor/bin/php-cs-fixer check --diff --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
   ```
   **JS-Tests:** Der `web`-Container hat **kein Node** (geprüft). Jest läuft mit Node auf dem Host (Node 22 empfohlen) oder in einem Node-Container (`docker run --rm -v …:/app -w /app node:22 …`); Details und geprüfte Varianten in [templates/testing-js](templates/testing-js/README.md).
4. **Statische Analyse und Code-Style:** PHPStan (Level 8 mit Shopware-Regeln, [T-48](references/48-statische-analyse.md)), php-cs-fixer, ESLint/Stylelint (Admin/Storefront), Rector nur über `shopware-cli`. Ergebnis: keine neuen Befunde.
5. **Vor Release:** dieselben Läufe ohne `docker compose exec` in CI, zusätzlich `V6_8_0_0=1` gegen Deprecations, `shopware-cli extension validate`; Punkte in der [Review-](checklists/review-checklist.md) und [Release-Checkliste](checklists/release-checklist.md).

**Wichtige Befunde (live geprüft, Details in den Referenzen):** Der DB-User `shopware` darf `shopware_test` nicht anlegen (Fehler 1044), der Testlauf braucht dafür einen DB-User mit CREATE/DROP (im Beispiel root nur für den Testlauf). `APP_ENV` muss im Test per `<server … force="true"/>` auf `test` stehen, weil der Container `APP_ENV=dev` setzt. `phpstan/extension-installer` nicht verwenden (kollidiert mit `rules.neon`). PHPStan muss aus dem Projekt-Root laufen. Das Paket `shopware/phpstan-extension` ist auf Packagist nicht auffindbar; die Dateien liegen unter `vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/`.

**Lücken:** Tools wurden nur in einem temporären Verzeichnis im Container geprüft, nicht dauerhaft ins Projekt installiert (⚠ UNGEPRÜFT: `vendor/bin/phpunit` im Projekt, PHPUnit 12, Coverage); Admin-Jest braucht eine Kopie der Admin-Quellen mit `node_modules` (`ADMIN_PATH`); ECS-Setup, `shopware/rector` und die empfohlene PHPUnit-Version für 6.7.15.1 sind nicht belegt; Playwright/Acceptance-Tests sind nur Abgrenzung.

## Entscheidungshilfen

**Plugin vs. App** → [T-10](references/10-app-system-abgrenzung.md)

| Kriterium | Plugin | App |
|---|---|---|
| Läuft als | PHP im Shop-Prozess | `manifest.xml` + deklarative Ressourcen, ggf. externer App-Server, App-Scripts (Twig, sandboxed) |
| Betrieb | Self-Hosted (Composer, `custom/plugins`) | Cloud und Self-Hosted |
| Zugriff | DI-Container, DAL, Events, Migrationen, Twig, Admin-/Storefront-Build | nur über API/Webhooks/Scripts |
| Script-Hooks | nicht verfügbar (nur Apps) – Plugin-Äquivalent: Subscriber, Dekoration, Twig-Blöcke | ja |

Dieser Skill beschreibt den **Plugin-Weg**; die App ist nur zur Abgrenzung behandelt.

**Event vs. Decorator vs. Extension Point** → [T-07](references/07-events-subscriber.md), [T-08](references/08-extension-points.md), [T-06](references/06-service-dekoration.md)

| Ziel | Mittel |
|---|---|
| Auf etwas reagieren / Seiteneffekt (Mail, Log, externes System, Daten anreichern) | **Event + Subscriber** |
| Kernlogik typisiert ersetzen, nachbearbeiten oder Fehler abfangen (pre/post/error, `stopPropagation`) | **Extension Point** (`Extension`, falls für die Stelle vorhanden) |
| Verhalten einer Service-Methode ändern/ersetzen | **Service-Dekoration** (nur Services mit `Abstract…` + `getDecorated()`, nicht `@internal`/`@final`) |
| Store-API-Route ändern | Dekoration der `Abstract…Route` → [T-30](references/30-store-api-routen.md) |

Doku-Regel: Events bevorzugen, wenn man auf Geschehenes reagiert; Dekoration, wenn man das Verhalten eines Services ändert.

**EntityExtension vs. Custom Field** → [T-29](references/29-entity-extensions-custom-fields.md)

| Kriterium | Custom Field | EntityExtension |
|---|---|---|
| Pflege | durch Händler im Admin konfigurierbar | im Code |
| Speicherung | JSON-Feld `customFields` | Assoziation auf eigene Tabelle (keine Skalarspalte in der Core-Tabelle), Runtime-Feld |
| Geeignet für | skalare Werte, IDs, kein Join/Filter-Bedarf | Assoziationen, Struct-Typen, Index/FK, Performance-relevante Filter |
| Lifecycle | `custom-fields.xml` (seit 6.7.13.0 laut Code) oder Repository | Migration + `EntityExtension`/`BulkEntityExtension` |

**Theme vs. Plugin mit Storefront-Anteil** → [T-11](references/11-theme-grundlagen.md)

| Ziel | Wahl |
|---|---|
| Aussehen/Layout/SCSS-Variablen, einem Sales Channel zuweisbar, Konfigurationsfelder (`theme.json`) | **Theme** (`ThemeInterface`, `theme.json`) |
| Funktion mit Storefront-Teil (Controller, JS-Plugin, Twig-Erweiterung), unabhängig vom Design, in jedem Theme wirksam | **Plugin** (Storefront-Dateien unter `src/Resources/app/storefront`, `views`) |
| Beides | Plugin für Logik + Theme für Design; Theme kann Plugin-Styles über `@Plugins` einbinden |

**Weitere Einstiege:** Pro-Sales-Channel-Konfiguration → [T-19](references/19-plugin-konfiguration.md); Verhalten pro Sales Channel/Domain → [T-20](references/20-sales-channel-unterscheidung.md); Asynchrone Arbeit → [T-33](references/33-scheduled-tasks.md), [T-34](references/34-message-queue.md).

## Pflichtthemen → Referenz

| Thema | Referenz |
|---|---|
| Plugin- und Theme-Erstellung | [T-02](references/02-plugin-aufbau.md), [T-03](references/03-plugin-lifecycle.md), [T-04](references/04-datenbank-migrationen.md), [T-11](references/11-theme-grundlagen.md) |
| Hooks/Events/Subscriber | [T-07](references/07-events-subscriber.md), [T-08](references/08-extension-points.md), [T-09](references/09-flow-builder.md), [T-10](references/10-app-system-abgrenzung.md), [T-14](references/14-storefront-twig-templates.md) |
| Service-Registrierung und -Dekoration | [T-05](references/05-services-registrieren.md), [T-06](references/06-service-dekoration.md) |
| Storefront-Template- und JS-Plugin-Überschreibung | [T-14](references/14-storefront-twig-templates.md), [T-15](references/15-storefront-js-plugins.md) |
| Theme-Styles | [T-12](references/12-theme-config-scss-variablen.md), [T-13](references/13-storefront-styling-assets.md) |
| Plugin-Konfiguration | [T-19](references/19-plugin-konfiguration.md) |
| Sales-Channel-Unterscheidung | [T-20](references/20-sales-channel-unterscheidung.md) |
| CMS-Elemente und -Blöcke samt Sichtbarkeit | [T-21](references/21-cms-elemente.md), [T-22](references/22-cms-bloecke-sichtbarkeit.md) |
| ACL/Rechte und Rollen | [T-23](references/23-acl-rechte-rollen.md) |
| Neue Admin-Module | [T-24](references/24-admin-module.md), [T-25](references/25-admin-komponenten.md), [T-26](references/26-admin-datenhandling.md) |

## Inhaltsverzeichnis

### Grundlagen
- [T-01 Projektstruktur & Entwicklungsumgebung](references/01-projektstruktur-dev-umgebung.md)
- [T-02 Plugin-Aufbau, composer.json, Abhängigkeiten](references/02-plugin-aufbau.md)
- [T-03 Plugin-Lifecycle](references/03-plugin-lifecycle.md)
- [T-04 Datenbank-Migrationen](references/04-datenbank-migrationen.md)
- [T-05 Services registrieren](references/05-services-registrieren.md)
- [T-06 Services dekorieren](references/06-service-dekoration.md)
- [T-07 Events & Subscriber](references/07-events-subscriber.md)
- [T-08 Extension Points](references/08-extension-points.md)
- [T-09 Flow Builder](references/09-flow-builder.md)
- [T-10 App-System-Abgrenzung & Script-Hooks](references/10-app-system-abgrenzung.md)

### Theme und Storefront
- [T-11 Theme-Grundlagen](references/11-theme-grundlagen.md)
- [T-12 Theme-Konfiguration & SCSS-Variablen](references/12-theme-config-scss-variablen.md)
- [T-13 Storefront-Styling, Assets & Icons](references/13-storefront-styling-assets.md)
- [T-14 Storefront-Twig-Templates](references/14-storefront-twig-templates.md)
- [T-15 Storefront-JS-Plugins](references/15-storefront-js-plugins.md)
- [T-16 Storefront-Controller, Pages & Pagelets](references/16-storefront-controller-pages.md)
- [T-17 Listing, Suche & Elasticsearch](references/17-listing-suche-elasticsearch.md)
- [T-18 Snippets & Übersetzungen](references/18-snippets-uebersetzungen.md)

### Konfiguration, Sales Channel, CMS, Rechte
- [T-19 Plugin-Konfiguration](references/19-plugin-konfiguration.md)
- [T-20 Sales-Channel-Unterscheidung](references/20-sales-channel-unterscheidung.md)
- [T-21 CMS-Elemente](references/21-cms-elemente.md)
- [T-22 CMS-Blöcke, Sektionen & Sichtbarkeit](references/22-cms-bloecke-sichtbarkeit.md)
- [T-23 ACL: Rechte & Rollen](references/23-acl-rechte-rollen.md)

### Administration
- [T-24 Admin-Module, Routen & Menü](references/24-admin-module.md)
- [T-25 Admin-Komponenten & Styles](references/25-admin-komponenten.md)
- [T-26 Admin-Datenhandling, Services & State](references/26-admin-datenhandling.md)

### Daten und APIs
- [T-27 DAL: Entities, Definitionen, Associations](references/27-dal-entities.md)
- [T-28 DAL: Lesen, Schreiben, Indexer](references/28-dal-lesen-schreiben.md)
- [T-29 EntityExtensions & Custom Fields](references/29-entity-extensions-custom-fields.md)
- [T-30 Store-API-Routen](references/30-store-api-routen.md)
- [T-31 Admin-API-Routen, Integrationen, Sync-API](references/31-admin-api-routen.md)

### Fachliche Erweiterungen
- [T-32 Rule Builder](references/32-rule-builder.md)
- [T-33 Scheduled Tasks](references/33-scheduled-tasks.md)
- [T-34 Message Queue](references/34-message-queue.md)
- [T-35 Mail-Templates](references/35-mail-templates.md)
- [T-36 Warenkorb](references/36-warenkorb.md)
- [T-37 Zahlungsintegration](references/37-payment-integration.md)
- [T-38 Bestellungen, State Machine, Dokumente](references/38-order-statemachine-dokumente.md)
- [T-39 Media & Filesystem](references/39-media-filesystem.md)
- [T-40 SEO-URLs & Sitemap](references/40-seo-sitemap.md)
- [T-41 Caching](references/41-caching.md)
- [T-42 Cookies & Consent](references/42-cookies-consent.md)

### Werkzeuge, Qualität, Betrieb
- [T-43 CLI, Logging, System-Checks](references/43-cli-logging-systemchecks.md)
- [T-44 MCP-Server-Erweiterung](references/44-mcp-server.md)
- [T-45 Coding-Guidelines](references/45-coding-guidelines.md)
- [Qualitätssicherung: Tests & statische Analyse](#qualitätssicherung-tests--statische-analyse) (Abschnitt in dieser Datei)
- [T-46 PHP-Tests (PHPUnit)](references/46-tests-phpunit.md)
- [T-47 JS-Tests (Jest/Vitest)](references/47-tests-jest.md)
- [T-48 Statische Analyse & Code-Style](references/48-statische-analyse.md)
- [T-49 Build & Deployment](references/49-build-deployment.md)

### Templates (Gerüste, Platzhalter `SwagExample`)
- [plugin](templates/plugin/README.md) – composer.json, Plugin-Klasse, `services.php`/`services.xml`
- [theme](templates/theme/README.md) – `theme.json`, Theme-Klasse, SCSS-/JS-Einstieg
- [cms-element](templates/cms-element/README.md) – Admin + Storefront + Resolver
- [cms-block](templates/cms-block/README.md)
- [admin-module](templates/admin-module/README.md) – inkl. ACL-Privilegien
- [config](templates/config/README.md) – `config.xml`
- [migration](templates/migration/README.md)
- [entity](templates/entity/README.md) – Definition, Entity, Collection
- [event-subscriber](templates/event-subscriber/README.md)
- [decorator](templates/decorator/README.md)
- [storefront-js-plugin](templates/storefront-js-plugin/README.md)
- [testing](templates/testing/README.md) – `phpunit.xml.dist`, `tests/TestBootstrap.php`, Unit- und Integrationstests
- [testing-js](templates/testing-js/README.md) – Jest für Administration und Storefront
- [quality](templates/quality/README.md) – `phpstan.neon.dist`, `.php-cs-fixer.dist.php`

### Checklisten
- [Review-Checkliste](checklists/review-checklist.md)
- [Release-Checkliste](checklists/release-checklist.md)

## Bekannte Lücken

Alle Beispiele und CLI-Befehle wurden **nicht in einer laufenden Installation ausgeführt** (kein Docker, `custom/plugins` leer, PHP für `php -l` nicht verfügbar); sie sind gegen Signaturen im Quellcode 6.7.15.1 geprüft (JSON/XML/JS der Templates syntaktisch validiert). Doku-URLs mit `[ungeprüft]` stammen nicht aus dem abgerufenen Inhaltsverzeichnis; die Doku lag teils nur als Zusammenfassung vor. Details je Thema stehen am Ende jeder Referenz unter „Quellen → Lücken und Unsicherheiten“. Die wichtigsten:

| Thema | Lücke / Doku-Code-Widerspruch (Code gilt) |
|---|---|
| T-11 Theme | Doku-Seiten `theme-base-guide` und `add-theme-inheritance-without-resources` nicht gelesen (Theme ohne eigene Ressourcen ist per Live-Test und Quellcode belegt: funktioniert, protokolliert aber einen ERROR wegen des fehlenden `dist/storefront/js/<name>`-Ordners); `@ParentTheme` in der Doku, im Code nicht implementiert (am Quellcode geprüft); Einführungsversionen der `theme:create`-Optionen unbekannt. Zentrale Aussagen (ThemeInterface, theme.json-Pflichtfelder, Commands, Twig-Pfad) am Quellcode verifiziert. |
| T-16 Controller/Pages | Doku zu Page, Pagelet, AJAX, PageLoader nie abgerufen; kein vollständiger Weg für eigenen Captcha-Eintrag in `activeCaptchasV2`; Plugin-Controller-Registrierung (`public` + `setContainer`) nur per Doku/Core-Muster. Route-/Scope-/Page-/Captcha-Kern am Quellcode bestätigt. |
| T-01/T-05 | `config/packages/shopware.yaml` existiert in der Installation nicht; `Resources/config/packages/*.yaml` werden in Plugins nicht geladen (belegt: `Bundle::build()`/`Plugin` rufen das protected `buildDefaultConfig()` nicht auf; nur wenn das Plugin es selbst in `build()` aufruft, sonst Projekt-Config); Autoconfigure mit `->defaults()->autowire()->autoconfigure()` live bestätigt, ohne `defaults()` bleibt es nicht aktiv. |
| T-02/T-03/T-04 | `executeComposerCommands`: Doku (Aktivierung) vs. Code (Install/Update); Transaktion um `update()` nicht belegt. |
| T-08/T-09/T-10 | Einführungsversion der Extension Points unbekannt; Flow-Delay im Core nicht vorhanden (vermutlich kommerziell); Cloud-Einschränkung für Plugins nicht belegt. |
| T-21/T-22 | Keine regelbasierte CMS-Sichtbarkeit im installierten Code (nur `visibility` mobile/tablet/desktop; kommerzielle Erweiterungen nicht geprüft); `useCmsElement` im Code vorhanden, Doku nennt es erst ab 6.7.16.0. |
| T-23/T-24 | Snippet-Pfad und `parent` der Navigation: Doku weicht ab; `bundle:dump-js` existiert nicht, nur `bundle:dump`. |
| T-25/T-26 | SFC/`swDefineOverride`, Composables experimentell; Reihenfolge mehrerer Overrides ungeklärt; Doku zu Vuex widerspricht Pinia-Code. |
| T-27–T-30 | Attribut-Entities (`#[ManyToOne]`-Schema) nur teilweise nachvollzogen; Rate-Limiter-Weg der Doku nicht am Code geprüft. |
| T-33–T-38 | Keine Implementierung von `DeduplicatableMessageInterface` im Code; Transitionsmatrix der Zahlungs-State-Machine unvollständig; DocumentV2 nur hinter Flag `DOCUMENT_GENERATION_REWORK`; Twig-Block/Template-Auflösung für neue Dokumente teils ungeprüft. |
| T-41 | Status des Flags `CACHE_REWORK` nicht geprüft; `StoreApiRouteCacheTagsEvent` wirkungslos. |
| T-44–T-49 | MCP-Server experimentell, Einführungsversion unbekannt; empfohlene PHPUnit-Version für 6.7.15.1, ECS-Setup und `shopware/rector` nicht belegt (PHPUnit, PHPStan und php-cs-fixer liefen live nur in einem temporären Verzeichnis im Container, nicht dauerhaft im Projekt); `web`-Container hat kein Node (JS-Tests über Host-Node oder Node-Container, Admin-Jest braucht Kopie der Admin-Quellen mit `node_modules`); Doku „Extending Webpack“ (Admin) veraltet (Admin = Vite); ZIP-Befehl `extension zip` vs. `extension package`; Deployment-Helper-Dateiname in der Doku widersprüchlich; `shopware/deployment-helper` im Projekt nicht installiert (Paketname nur laut Doku); Admin-Extension-Tooling experimentell/`@internal`. |

Mit `⚠ LÜCKE`/`⚠ UNGEPRÜFT` markierte Stellen in Referenzen und Template-READMEs sind vor Produktiveinsatz am Quellcode oder in einer Instanz zu klären.
