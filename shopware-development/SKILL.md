---
name: shopware-development
description: Development with Shopware 6.7 (as of 6.7.15.1) – plugins, themes, Storefront (Twig, SCSS, JS plugins), `theme.json`, snippets/translations, product listing and search, Administration (admin modules, components, ACL), CMS elements and blocks (Shopping Experiences), DAL/entities, migrations, plugin configuration (config.xml), sales channel distinction, events/subscribers, service decoration, extension points, Store API and Admin API, Rule Builder, Flow Builder, scheduled tasks, message queue, mail templates, cart, payment, caching, tests (PHPUnit, Jest), PHPStan/code style, build/deployment. Use for any task involving a Shopware plugin, theme, the Storefront, the Administration, a CMS block, an entity, a migration, permissions (ACL), sales channel logic, a subscriber or decorator, a Store API route, or the Shopware coding guidelines – even if only "Shopware", `bin/console`, `custom/plugins` or `services.php` is mentioned.
---

# Shopware 6 development (plugins and themes)

**Version:** Shopware **6.7.15.1** (Core, Storefront, Administration, Elasticsearch; verified with `bin/console --version`). The installed source code under `vendor/shopware/{core,storefront,administration,elasticsearch}` is authoritative; where the docs deviate from it, the code wins. Source basis: https://developer.shopware.com (docs may be on "latest").

This skill is deliberately compact. Details are in `references/`, scaffolds in `templates/`, checklists in `checklists/`. Load only the reference that matches the task.

## Core principles

1. **No core changes.** Never modify `vendor/shopware/**`. Extend exclusively via plugin/theme (or app, see Decision guide).
2. **Decoration/events before overriding.** Order of preference: event/subscriber → extension point → service decoration (`decorates`) → (Twig/JS/Admin only) template/plugin override. Never replace a core service with your own class under the same ID.
3. **Respect the BC contract.** Do not use `@internal`, do not extend `@final` (use events/decoration instead). Services intended for decoration have an `Abstract…` base and `getDecorated()`. Do not introduce new deprecations (`@deprecated tag:v6.8.0`); avoid existing ones in new development. → [T-45](references/45-coding-guidelines.md)
4. **Symfony folder structure in the plugin.** Code under `src/`, configuration under `src/Resources/config/` (`services.php`, `config.xml`, `routes.php`), migrations under `src/Migration/`, Twig under `src/Resources/views/`, snippets under `src/Resources/snippet/`, Storefront frontend under `src/Resources/app/storefront/src/`, Administration under `src/Resources/app/administration/src/`. → [T-01](references/01-project-structure-dev-environment.md), [T-02](references/02-plugin-structure.md)
5. **Coding guidelines.** `declare(strict_types=1)`, constructor injection with `private readonly`, **`services.php` is the default format in 6.7 (XML is deprecated)**, own namespace (e.g. `SwagExample\…`, never `Shopware\…`), domain exceptions instead of `\RuntimeException`, feature flags only via `Feature::isActive()`. → [T-45](references/45-coding-guidelines.md), [T-05](references/05-registering-services.md)
6. **Keep the lifecycle clean.** Install/update/uninstall idempotent; respect `keepUserData` on uninstall; schema only via migrations. → [T-03](references/03-plugin-lifecycle.md), [T-04](references/04-database-migrations.md)
7. **Invent nothing.** Mark unsupported claims with `⚠ GAP`/`⚠ UNVERIFIED` (see "Known gaps"). When in doubt, verify signatures against the installed source code.

## Standard development workflow

1. **Classify:** Plugin, theme or app? Which extension mechanism (see Decision guide)? Read the matching reference.
2. **Create the scaffold:** `bin/console plugin:create SwagExample` or `theme:create SwagExampleTheme`, or copy a template from `templates/` and replace the placeholders.
3. **Schema/data:** Migration (`src/Migration/Migration<Timestamp><Name>.php`), entity definition, lifecycle hooks.
4. **Backend logic:** Register services in `services.php`; add subscriber/decorator/route/command/task.
5. **Frontend:** Storefront (Twig, SCSS, JS plugin) or Administration (module, components, snippets, ACL).
6. **Install and build:**
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install --activate SwagExample   # later: plugin:update SwagExample
   bin/console cache:clear
   bin/build-storefront.sh / bin/build-administration.sh   # → T-49; theme:compile on theme changes
   ```
7. **Verify:** Tests and static analysis as described in section "Quality assurance: Tests & static analysis". Then the [review checklist](checklists/review-checklist.md); before delivery the [release checklist](checklists/release-checklist.md).

## Quality assurance: Tests & static analysis

Mandatory for every extension. All commands run in this project's Docker setup in the `web` container (`docker compose exec web …`, working directory `/var/www/html`; Docker if needed via `export PATH=$PATH:/Applications/Docker.app/Contents/Resources/bin`). Scaffolds: [templates/testing](templates/testing/README.md) (PHPUnit), [templates/testing-js](templates/testing-js/README.md) (Jest), [templates/quality](templates/quality/README.md) (PHPStan, php-cs-fixer).

**Standard workflow**
1. **Set up (once):** Take over dev dependencies, `phpunit.xml.dist`, `tests/TestBootstrap.php`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php` from the templates. PHPUnit, PHPStan and php-cs-fixer are **not** preinstalled in the project (dry run: `shopware/dev-tools` 1.6.0, PHPUnit, `symfony/browser-kit`, PHPStan among others are resolvable via Composer).
2. **Write:** Unit tests without kernel (stubs, `#[CoversClass]`); integration tests with `IntegrationTestBehaviour` and `IdsCollection`/`ProductBuilder` (DB, transaction per test); admin components and Storefront JS plugins with Jest ([T-46](references/46-tests-phpunit.md), [T-47](references/47-tests-jest.md)).
3. **Run locally:**
   ```bash
   docker compose exec -e DATABASE_URL=mysql://root:root@database:3306/shopware web \
     vendor/bin/phpunit --configuration custom/plugins/SwagExample/phpunit.xml.dist      # integration tests create the separate DB shopware_test
   docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress
   docker compose exec web vendor/bin/php-cs-fixer check --diff --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
   ```
   **JS tests:** The `web` container has **no Node** (verified). Jest runs with Node on the host (Node 22 recommended) or in a Node container (`docker run --rm -v …:/app -w /app node:22 …`); details and verified variants in [templates/testing-js](templates/testing-js/README.md).
4. **Static analysis and code style:** PHPStan (level 8 with Shopware rules, [T-48](references/48-static-analysis.md)), php-cs-fixer, ESLint/Stylelint (Admin/Storefront), Rector only via `shopware-cli`. Result: no new findings.
5. **Before release:** the same runs without `docker compose exec` in CI, additionally `V6_8_0_0=1` against deprecations, `shopware-cli extension validate`; items in the [review](checklists/review-checklist.md) and [release checklist](checklists/release-checklist.md).

**Important findings (verified live, details in the references):** The DB user `shopware` may not create `shopware_test` (error 1044); the test run needs a DB user with CREATE/DROP for this (in the example, root only for the test run). `APP_ENV` must be set to `test` in the test via `<server … force="true"/>`, because the container sets `APP_ENV=dev`. Do not use `phpstan/extension-installer` (conflicts with `rules.neon`). PHPStan must run from the project root. The package `shopware/phpstan-extension` cannot be found on Packagist; the files are located under `vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/`.

**Gaps:** Tools were only verified in a temporary directory in the container, not installed permanently in the project (⚠ UNVERIFIED: `vendor/bin/phpunit` in the project, PHPUnit 12, coverage); Admin Jest needs a copy of the Admin sources with `node_modules` (`ADMIN_PATH`); ECS setup, `shopware/rector` and the recommended PHPUnit version for 6.7.15.1 are not documented; Playwright/acceptance tests are covered only for scope delimitation.

## Decision guide

**Plugin vs. app** → [T-10](references/10-app-system-scope.md)

| Criterion | Plugin | App |
|---|---|---|
| Runs as | PHP in the shop process | `manifest.xml` + declarative resources, if needed an external app server, app scripts (Twig, sandboxed) |
| Operation | Self-hosted (Composer, `custom/plugins`) | Cloud and self-hosted |
| Access | DI container, DAL, events, migrations, Twig, Admin/Storefront build | only via API/webhooks/scripts |
| Script hooks | not available (apps only) – plugin equivalent: subscriber, decoration, Twig blocks | yes |

This skill describes the **plugin approach**; apps are covered only for scope delimitation.

**Event vs. decorator vs. extension point** → [T-07](references/07-events-subscriber.md), [T-08](references/08-extension-points.md), [T-06](references/06-service-decoration.md)

| Goal | Mechanism |
|---|---|
| React to something / side effect (mail, log, external system, enrich data) | **Event + subscriber** |
| Replace, post-process or catch errors in core logic in a typed way (pre/post/error, `stopPropagation`) | **Extension point** (`Extension`, if one exists for that spot) |
| Change/replace the behavior of a service method | **Service decoration** (only services with `Abstract…` + `getDecorated()`, not `@internal`/`@final`) |
| Change a Store API route | Decoration of the `Abstract…Route` → [T-30](references/30-store-api-routes.md) |

Docs rule: prefer events when reacting to something that happened; decoration when changing the behavior of a service.

**EntityExtension vs. custom field** → [T-29](references/29-entity-extensions-custom-fields.md)

| Criterion | Custom field | EntityExtension |
|---|---|---|
| Maintenance | configurable by the merchant in the Admin | in code |
| Storage | JSON field `customFields` | association to own table (no scalar column in the core table), runtime field |
| Suitable for | scalar values, IDs, no join/filter need | associations, struct types, index/FK, performance-relevant filters |
| Lifecycle | `custom-fields.xml` (since 6.7.13.0 according to the code) or repository | migration + `EntityExtension`/`BulkEntityExtension` |

**Theme vs. plugin with Storefront part** → [T-11](references/11-theme-basics.md)

| Goal | Choice |
|---|---|
| Look/layout/SCSS variables, assignable to a sales channel, configuration fields (`theme.json`) | **Theme** (`ThemeInterface`, `theme.json`) |
| Functionality with a Storefront part (controller, JS plugin, Twig extension), independent of design, effective in every theme | **Plugin** (Storefront files under `src/Resources/app/storefront`, `views`) |
| Both | Plugin for logic + theme for design; theme can include plugin styles via `@Plugins` |

**Further entry points:** Per-sales-channel configuration → [T-19](references/19-plugin-configuration.md); behavior per sales channel/domain → [T-20](references/20-sales-channel-distinction.md); asynchronous work → [T-33](references/33-scheduled-tasks.md), [T-34](references/34-message-queue.md).

## Mandatory topics → reference

| Topic | Reference |
|---|---|
| Plugin and theme creation | [T-02](references/02-plugin-structure.md), [T-03](references/03-plugin-lifecycle.md), [T-04](references/04-database-migrations.md), [T-11](references/11-theme-basics.md) |
| Hooks/events/subscribers | [T-07](references/07-events-subscriber.md), [T-08](references/08-extension-points.md), [T-09](references/09-flow-builder.md), [T-10](references/10-app-system-scope.md), [T-14](references/14-storefront-twig-templates.md) |
| Service registration and decoration | [T-05](references/05-registering-services.md), [T-06](references/06-service-decoration.md) |
| Storefront template and JS plugin overriding | [T-14](references/14-storefront-twig-templates.md), [T-15](references/15-storefront-js-plugins.md) |
| Theme styles | [T-12](references/12-theme-config-scss-variables.md), [T-13](references/13-storefront-styling-assets.md) |
| Plugin configuration | [T-19](references/19-plugin-configuration.md) |
| Sales channel distinction | [T-20](references/20-sales-channel-distinction.md) |
| CMS elements and blocks including visibility | [T-21](references/21-cms-elements.md), [T-22](references/22-cms-blocks-visibility.md) |
| ACL/permissions and roles | [T-23](references/23-acl-permissions-roles.md) |
| New admin modules | [T-24](references/24-admin-modules.md), [T-25](references/25-admin-components.md), [T-26](references/26-admin-data-handling.md) |

## Table of contents

### Fundamentals
- [T-01 Project structure & development environment](references/01-project-structure-dev-environment.md)
- [T-02 Plugin structure, composer.json, dependencies](references/02-plugin-structure.md)
- [T-03 Plugin lifecycle](references/03-plugin-lifecycle.md)
- [T-04 Database migrations](references/04-database-migrations.md)
- [T-05 Registering services](references/05-registering-services.md)
- [T-06 Decorating services](references/06-service-decoration.md)
- [T-07 Events & subscribers](references/07-events-subscriber.md)
- [T-08 Extension points](references/08-extension-points.md)
- [T-09 Flow Builder](references/09-flow-builder.md)
- [T-10 App system scope & script hooks](references/10-app-system-scope.md)

### Theme and Storefront
- [T-11 Theme basics](references/11-theme-basics.md)
- [T-12 Theme configuration & SCSS variables](references/12-theme-config-scss-variables.md)
- [T-13 Storefront styling, assets & icons](references/13-storefront-styling-assets.md)
- [T-14 Storefront Twig templates](references/14-storefront-twig-templates.md)
- [T-15 Storefront JS plugins](references/15-storefront-js-plugins.md)
- [T-16 Storefront controllers, pages & pagelets](references/16-storefront-controller-pages.md)
- [T-17 Listing, search & Elasticsearch](references/17-listing-search-elasticsearch.md)
- [T-18 Snippets & translations](references/18-snippets-translations.md)

### Configuration, sales channel, CMS, permissions
- [T-19 Plugin configuration](references/19-plugin-configuration.md)
- [T-20 Sales channel distinction](references/20-sales-channel-distinction.md)
- [T-21 CMS elements](references/21-cms-elements.md)
- [T-22 CMS blocks, sections & visibility](references/22-cms-blocks-visibility.md)
- [T-23 ACL: permissions & roles](references/23-acl-permissions-roles.md)

### Administration
- [T-24 Admin modules, routes & menu](references/24-admin-modules.md)
- [T-25 Admin components & styles](references/25-admin-components.md)
- [T-26 Admin data handling, services & state](references/26-admin-data-handling.md)

### Data and APIs
- [T-27 DAL: entities, definitions, associations](references/27-dal-entities.md)
- [T-28 DAL: reading, writing, indexers](references/28-dal-reading-writing.md)
- [T-29 EntityExtensions & custom fields](references/29-entity-extensions-custom-fields.md)
- [T-30 Store API routes](references/30-store-api-routes.md)
- [T-31 Admin API routes, integrations, Sync API](references/31-admin-api-routes.md)

### Business extensions
- [T-32 Rule Builder](references/32-rule-builder.md)
- [T-33 Scheduled tasks](references/33-scheduled-tasks.md)
- [T-34 Message queue](references/34-message-queue.md)
- [T-35 Mail templates](references/35-mail-templates.md)
- [T-36 Cart](references/36-cart.md)
- [T-37 Payment integration](references/37-payment-integration.md)
- [T-38 Orders, state machine, documents](references/38-order-statemachine-documents.md)
- [T-39 Media & filesystem](references/39-media-filesystem.md)
- [T-40 SEO URLs & sitemap](references/40-seo-sitemap.md)
- [T-41 Caching](references/41-caching.md)
- [T-42 Cookies & consent](references/42-cookies-consent.md)

### Tools, quality, operations
- [T-43 CLI, logging, system checks](references/43-cli-logging-systemchecks.md)
- [T-44 MCP server extension](references/44-mcp-server.md)
- [T-45 Coding guidelines](references/45-coding-guidelines.md)
- [Quality assurance: Tests & static analysis](#quality-assurance-tests--static-analysis) (section in this file)
- [T-46 PHP tests (PHPUnit)](references/46-tests-phpunit.md)
- [T-47 JS tests (Jest/Vitest)](references/47-tests-jest.md)
- [T-48 Static analysis & code style](references/48-static-analysis.md)
- [T-49 Build & deployment](references/49-build-deployment.md)

### Templates (scaffolds, placeholder `SwagExample`)
- [plugin](templates/plugin/README.md) – composer.json, plugin class, `services.php`/`services.xml`
- [theme](templates/theme/README.md) – `theme.json`, theme class, SCSS/JS entry
- [cms-element](templates/cms-element/README.md) – Admin + Storefront + resolver
- [cms-block](templates/cms-block/README.md)
- [admin-module](templates/admin-module/README.md) – incl. ACL privileges
- [config](templates/config/README.md) – `config.xml`
- [migration](templates/migration/README.md)
- [entity](templates/entity/README.md) – definition, entity, collection
- [event-subscriber](templates/event-subscriber/README.md)
- [decorator](templates/decorator/README.md)
- [storefront-js-plugin](templates/storefront-js-plugin/README.md)
- [testing](templates/testing/README.md) – `phpunit.xml.dist`, `tests/TestBootstrap.php`, unit and integration tests
- [testing-js](templates/testing-js/README.md) – Jest for Administration and Storefront
- [quality](templates/quality/README.md) – `phpstan.neon.dist`, `.php-cs-fixer.dist.php`

### Checklists
- [Review checklist](checklists/review-checklist.md)
- [Release checklist](checklists/release-checklist.md)

## Known gaps

None of the examples and CLI commands were **executed in a running installation** (no Docker, `custom/plugins` empty, PHP not available for `php -l`); they are checked against signatures in the 6.7.15.1 source code (JSON/XML/JS of the templates syntactically validated). Docs URLs marked `[unverified]` do not come from the retrieved table of contents; some docs were only available as a summary. Details per topic are at the end of each reference under "Sources → Gaps and uncertainties". The most important:

| Topic | Gap / docs-vs-code contradiction (code wins) |
|---|---|
| T-11 Theme | Docs pages `theme-base-guide` and `add-theme-inheritance-without-resources` not read (a theme without its own resources is confirmed by live test and source code: it works, but logs an ERROR because of the missing `dist/storefront/js/<name>` folder); `@ParentTheme` in the docs, not implemented in the code (verified against the source code); introduction versions of the `theme:create` options unknown. Core statements (ThemeInterface, theme.json required fields, commands, Twig path) verified against the source code. |
| T-16 Controller/Pages | Docs on Page, Pagelet, AJAX, PageLoader never retrieved; no complete path for a custom captcha entry in `activeCaptchasV2`; plugin controller registration (`public` + `setContainer`) only via docs/core pattern. Route/scope/page/captcha core confirmed against the source code. |
| T-01/T-05 | `config/packages/shopware.yaml` does not exist in the installation; `Resources/config/packages/*.yaml` are not loaded in plugins (confirmed: `Bundle::build()`/`Plugin` do not call the protected `buildDefaultConfig()`; only if the plugin calls it itself in `build()`, otherwise project config); autoconfigure with `->defaults()->autowire()->autoconfigure()` verified live; without `defaults()` it does not stay active. |
| T-02/T-03/T-04 | `executeComposerCommands`: docs (activation) vs. code (install/update); transaction around `update()` not confirmed. |
| T-08/T-09/T-10 | Introduction version of extension points unknown; flow delay not present in core (presumably commercial); cloud restriction for plugins not confirmed. |
| T-21/T-22 | No rule-based CMS visibility in the installed code (only `visibility` mobile/tablet/desktop; commercial extensions not checked); `useCmsElement` present in the code, docs only list it from 6.7.16.0. |
| T-23/T-24 | Snippet path and `parent` of the navigation: docs deviate; `bundle:dump-js` does not exist, only `bundle:dump`. |
| T-25/T-26 | SFC/`swDefineOverride`, composables experimental; order of multiple overrides unclear; docs on Vuex contradict Pinia code. |
| T-27–T-30 | Attribute entities (`#[ManyToOne]` schema) only partially traced; rate limiter approach from the docs not verified against the code. |
| T-33–T-38 | No implementation of `DeduplicatableMessageInterface` in the code; transition matrix of the payment state machine incomplete; DocumentV2 only behind flag `DOCUMENT_GENERATION_REWORK`; Twig block/template resolution for new documents partly unverified. |
| T-41 | Status of the flag `CACHE_REWORK` not checked; `StoreApiRouteCacheTagsEvent` has no effect. |
| T-44–T-49 | MCP server experimental, introduction version unknown; recommended PHPUnit version for 6.7.15.1, ECS setup and `shopware/rector` not documented (PHPUnit, PHPStan and php-cs-fixer only ran live in a temporary directory in the container, not permanently in the project); `web` container has no Node (JS tests via host Node or Node container, Admin Jest needs a copy of the Admin sources with `node_modules`); docs "Extending Webpack" (Admin) outdated (Admin = Vite); ZIP command `extension zip` vs. `extension package`; deployment helper file name contradictory in the docs; `shopware/deployment-helper` not installed in the project (package name only according to the docs); Admin extension tooling experimental/`@internal`. |

Places marked `⚠ GAP`/`⚠ UNVERIFIED` in references and template READMEs must be clarified against the source code or in an instance before production use.
