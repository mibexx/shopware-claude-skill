# Review checklist (Shopware 6.7.15.1)

Purpose: code review of a plugin/theme before merge. Tick items only when verified against the code. Details are in the linked reference.

## 1. Core principles
- [ ] No changes under `vendor/shopware/**` or to the core ([T-45](../references/45-coding-guidelines.md)).
- [ ] Appropriate extension mechanism: event/subscriber → extension point → decoration; no replacement of core service IDs ([T-06](../references/06-service-decoration.md), [T-07](../references/07-events-subscriber.md), [T-08](../references/08-extension-points.md)).
- [ ] Only non-`@internal` API used, `@final` classes not extended; decorated services have `Abstract…` + `getDecorated()`.
- [ ] Own namespace (no `Shopware\…`), tables with vendor prefix (`swag_…`).
- [ ] `declare(strict_types=1)`, native types, constructor injection (`private readonly`).
- [ ] Errors as domain exceptions, no `\RuntimeException` in domain code.
- [ ] No new deprecations; no use of deprecated APIs (e.g. `--no-cleanup`, XML services, Vuex, `CookieProviderInterface`, `HttpClient`/`DomAccess` in Storefront JS) – see the respective reference, section "Deprecations".

## 2. Plugin structure and lifecycle ([T-02](../references/02-plugin-structure.md), [T-03](../references/03-plugin-lifecycle.md))
- [ ] `composer.json`: `type`, `extra.shopware-plugin-class`, `extra.label`, `autoload.psr-4`, `require shopware/core` constraint.
- [ ] Lifecycle methods idempotent; `uninstall()` checks `keepUserData()`; no own services assumed in `install()`.
- [ ] Sales channel and context logic via `SalesChannelContext`/channel ID, not via global assumptions ([T-20](../references/20-sales-channel-distinction.md)).

## 3. Database and DAL ([T-04](../references/04-database-migrations.md), [T-27](../references/27-dal-entities.md), [T-28](../references/28-dal-reading-writing.md), [T-29](../references/29-entity-extensions-custom-fields.md))
- [ ] Schema only via migration (`src/Migration/`), idempotent, backward compatible (expand/contract); destructive changes only in `updateDestructive()`.
- [ ] Entity registered (`shopware.entity.definition`), flags/fields correct, translation/associations with matching migrations.
- [ ] No `EntityRepository` access in Storefront controllers/PageLoaders (according to [T-16](../references/16-storefront-controller-pages.md)); `SalesChannelRepository` for sales-channel-filtered data.
- [ ] Choice of custom field vs. EntityExtension justified.

## 4. Services, events, routes ([T-05](../references/05-registering-services.md), [T-30](../references/30-store-api-routes.md), [T-31](../references/31-admin-api-routes.md))
- [ ] `services.php` (no new XML); tags complete (`kernel.event_subscriber`, `console.command`, `messenger.message_handler`, …); controllers `public` + `setContainer`.
- [ ] Routes: correct `_routeScope` (`storefront`/`store-api`/`api`), admin routes with `_acl`.
- [ ] Store API route with Abstract/Route/Response; changes to existing routes via decoration.
- [ ] Cacheable routes/tags/invalidation set deliberately ([T-41](../references/41-caching.md)).

## 5. Configuration and permissions ([T-19](../references/19-plugin-configuration.md), [T-23](../references/23-acl-permissions-roles.md))
- [ ] `config.xml` XSD-compliant; config keys `<Plugin>.config.<field>`; defaults set; read access with `salesChannelId`.
- [ ] Secrets not in Twig/frontend/logs.
- [ ] ACL: backend protection (`_acl`) in addition to UI gating; privileges with `viewer`/`editor`/`creator`/`deleter` and dependencies; snippets for privileges.

## 6. Storefront and theme ([T-11](../references/11-theme-basics.md)–[T-15](../references/15-storefront-js-plugins.md))
- [ ] Twig: `sw_extends`/`sw_include`, block names checked against core, `parent()` deliberate, `|sw_sanitize` instead of `|raw`, texts as snippets ([T-18](../references/18-snippets-translations.md)).
- [ ] `theme.json`: `name`/`author`, `views`/`style`/`script`/`asset` order, `overrides.scss` before `@Storefront`, no `@ParentTheme`.
- [ ] JS plugin: registered in `main.js`, `destroy()` cleans up listeners, `window`/`document` access via helpers.
- [ ] Cookies/consent for tracking scripts taken into account ([T-42](../references/42-cookies-consent.md)).

## 7. Administration ([T-24](../references/24-admin-modules.md)–[T-26](../references/26-admin-data-handling.md), [T-21](../references/21-cms-elements.md), [T-22](../references/22-cms-blocks-visibility.md))
- [ ] Module ID with hyphen; navigation with `parent`; routes with `meta.privilege`.
- [ ] Components via Twig block override (stable approach in 6.7.15.1); SFC/Composition extensions only deliberately (experimental according to [T-25](../references/25-admin-components.md)).
- [ ] Pinia instead of Vuex; error handling and notifications with `message`.
- [ ] CMS element: `name`, `getType()` and Twig suffix match; visibility only as viewport flags (no rule-based visibility in the installed code).
- [ ] Admin snippets for German and English, files language-neutral (`de.json`/`en.json`, Storefront `<domain>.de.json`/`.en.json`); `bin/console translation:lint-filenames` passes without errors ([T-18](../references/18-snippets-translations.md)).

## 8. Asynchronous processing and business logic ([T-32](../references/32-rule-builder.md)–[T-38](../references/38-order-statemachine-documents.md))
- [ ] Scheduled task: task + handler (`#[AsMessageHandler]`), registered; message marker for routing; no blocking in the request.
- [ ] Rule: no DB access in `match()`.
- [ ] Payment: `AbstractPaymentHandler`, status only via `OrderTransactionStateHandler`.
- [ ] State transitions only via `StateMachineRegistry::transition()`.

## 9. Tests and static analysis ([T-46](../references/46-tests-phpunit.md), [T-47](../references/47-tests-jest.md), [T-48](../references/48-static-analysis.md); scaffolds: [testing](../templates/testing/README.md), [testing-js](../templates/testing-js/README.md), [quality](../templates/quality/README.md))
- [ ] New/changed logic has tests: unit tests without kernel (`#[CoversClass]`, stubs) and integration tests with `IntegrationTestBehaviour` (+ `SalesChannelApiTestBehaviour` for Store API); tests are independent of each other (no unclosed transactions, no DDL in the test).
- [ ] `phpunit.xml.dist` with suites `unit` and `integration`, `APP_ENV=test` (`force="true"`), `tests/TestBootstrap.php` with `addCallingPlugin()`, `setForceInstallPlugins(true)` and `addPsr4` for the test namespace; dev dependencies only under `require-dev`.
- [ ] Tests run locally in the Docker setup: `docker compose exec … vendor/bin/phpunit --configuration custom/plugins/<Plugin>/phpunit.xml.dist` (test DB `shopware_test`, DB user with CREATE/DROP) and green; additionally `V6_8_0_0=1` without new deprecations.
- [ ] Admin components and Storefront JS plugins tested with Jest (host Node or Node container; the `web` container has no Node); Playwright/acceptance only for scope delimitation.
- [ ] PHPStan (`phpstan.neon.dist`, level 8, Shopware rules) from the project root without new findings; no new entries in `ignoreErrors`, no `phpstan/extension-installer`.
- [ ] Code style: `php-cs-fixer check` or `shopware-cli extension format --dry-run` clean; ESLint/Stylelint (Admin/Storefront) without findings.
- [ ] All ⚠ gaps of the references used are named in the PR or clarified against the source code.
