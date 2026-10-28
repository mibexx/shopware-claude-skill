# Release-Checkliste (Shopware 6.7.15.1)

Zweck: Vor Auslieferung eines Plugins/Themes. Voraussetzung: [Review-Checkliste](./review-checklist.md) ist erledigt.

## 1. Version und Kompatibilität
- [ ] `composer.json`: `version` (falls genutzt)/Tag, `require shopware/core` mit passendem Constraint (Template-Standard: `~6.7.0`), Abhängigkeiten ([T-02](../references/02-plugin-aufbau.md)).
- [ ] Changelog/Release Notes aktualisiert.
- [ ] Lifecycle: Update-Pfad (`update()`, `postUpdate()`) und neue Migrationen mit Zeitstempel getestet; Uninstall mit und ohne `keepUserData` ([T-03](../references/03-plugin-lifecycle.md), [T-04](../references/04-datenbank-migrationen.md)).
- [ ] Neue Config-Defaults: `plugin:update` nötig und dokumentiert ([T-19](../references/19-plugin-konfiguration.md)).
- [ ] Deprecations geprüft (CI-Lauf mit `V6_8_0_0=1` laut [T-45](../references/45-coding-guidelines.md)).

## 2. Qualität: Tests und statische Analyse ([T-46](../references/46-tests-phpunit.md), [T-47](../references/47-tests-jest.md), [T-48](../references/48-statische-analyse.md))
- [ ] PHPUnit (Suites `unit` und `integration`) in CI grün, gegen die Ziel-Shopware-Version; Lauf mit `V6_8_0_0=1` ohne neue Deprecations ([T-45](../references/45-coding-guidelines.md)).
- [ ] Jest-Tests (Admin, Storefront-JS) grün; Node-Version laut `engines` der Core-`package.json` (Node 22 empfohlen, [testing-js](../templates/testing-js/README.md)).
- [ ] PHPStan (Level 8, Shopware-Regeln) ohne Befunde; veraltete Ignores entfernt (`reportUnmatchedIgnoredErrors`).
- [ ] php-cs-fixer bzw. `shopware-cli extension format --dry-run`, ESLint und Stylelint ohne Befunde.
- [ ] `shopware-cli extension validate` auf Quellordner und ZIP (Erfolg garantiert keine Store-Freigabe).
- [ ] Dev-Abhängigkeiten, `tests/`, Konfigurationsdateien (`phpunit.xml.dist`, `phpstan.neon.dist`) nicht im ausgelieferten Paket, falls nicht gewünscht.
- [ ] Smoke-Test in frischer Installation: `plugin:refresh`, `plugin:install --activate`, `plugin:update`, `plugin:deactivate`, `plugin:uninstall`.

## 3. Build und Artefakte ([T-49](../references/49-build-deployment.md))
- [ ] Administration gebaut (`bin/build-administration.sh`; Admin-Build läuft in 6.7.15.1 über Vite), Storefront gebaut (`bin/build-storefront.sh`; Plugin-/Theme-JS über Webpack).
- [ ] Gebaute Assets (`Resources/public`, `dist`) im Paket enthalten, wenn das Plugin ohne Build beim Kunden laufen soll.
- [ ] Theme: `theme:compile --sync` fehlerfrei; Vorschaubild (`previewMedia`) vorhanden ([T-11](../references/11-theme-grundlagen.md)).
- [ ] ZIP/Paket erstellt (z. B. `shopware-cli extension zip`; Befehlsname laut Doku teils abweichend, siehe T-49 „Lücken“), Inhalt geprüft: kein `node_modules`, keine Tests/Dev-Dateien, falls nicht gewünscht.

## 4. Betrieb und Deployment
- [ ] Deployment-Helper: `composer require shopware/deployment-helper` (im Projekt nicht vorinstalliert, ⚠ Paketname laut Doku), dann `vendor/bin/shopware-deployment-helper run` ([T-49](../references/49-build-deployment.md)); alternativ manuell: Nach Deployment: `plugin:refresh`, `plugin:update`, `theme:compile`, `assets:install`, `cache:clear`; ggf. `scheduled-task:register` und laufender Worker ([T-33](../references/33-scheduled-tasks.md), [T-34](../references/34-message-queue.md)).
- [ ] Caching/Invalidierung bei neuen Routen verifiziert ([T-41](../references/41-caching.md)).
- [ ] ACL: bestehende Rollen nach Mapping-Änderung geprüft/neu gespeichert ([T-23](../references/23-acl-rechte-rollen.md)).
- [ ] Snippets (Deutsch/Englisch, sprachneutrale Dateinamen, `translation:lint-filenames` fehlerfrei) vollständig ([T-18](../references/18-snippets-uebersetzungen.md)).
- [ ] Logging/Monitoring: eigener Logger-Channel, keine Secrets im Log ([T-43](../references/43-cli-logging-systemchecks.md)).

## 5. Dokumentation
- [ ] README mit Installation, Konfiguration, Rechten, Cronjobs/Worker-Bedarf.
- [ ] Bekannte Einschränkungen und nicht verifizierte Annahmen (⚠-Lücken) dokumentiert.
