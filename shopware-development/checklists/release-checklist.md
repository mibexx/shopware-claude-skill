# Release checklist (Shopware 6.7.15.1)

Purpose: before delivering a plugin/theme. Prerequisite: the [review checklist](./review-checklist.md) is done.

## 1. Version and compatibility
- [ ] `composer.json`: `version` (if used)/tag, `require shopware/core` with a matching constraint (template default: `~6.7.0`), dependencies ([T-02](../references/02-plugin-structure.md)).
- [ ] Changelog/release notes updated.
- [ ] Lifecycle: update path (`update()`, `postUpdate()`) and new migrations with timestamp tested; uninstall with and without `keepUserData` ([T-03](../references/03-plugin-lifecycle.md), [T-04](../references/04-database-migrations.md)).
- [ ] New config defaults: `plugin:update` required and documented ([T-19](../references/19-plugin-configuration.md)).
- [ ] Deprecations checked (CI run with `V6_8_0_0=1` according to [T-45](../references/45-coding-guidelines.md)).

## 2. Quality: tests and static analysis ([T-46](../references/46-tests-phpunit.md), [T-47](../references/47-tests-jest.md), [T-48](../references/48-static-analysis.md))
- [ ] PHPUnit (suites `unit` and `integration`) green in CI, against the target Shopware version; run with `V6_8_0_0=1` without new deprecations ([T-45](../references/45-coding-guidelines.md)).
- [ ] Jest tests (Admin, Storefront JS) green; Node version according to `engines` of the core `package.json` (Node 22 recommended, [testing-js](../templates/testing-js/README.md)).
- [ ] PHPStan (level 8, Shopware rules) without findings; outdated ignores removed (`reportUnmatchedIgnoredErrors`).
- [ ] php-cs-fixer or `shopware-cli extension format --dry-run`, ESLint and Stylelint without findings.
- [ ] `shopware-cli extension validate` on source folder and ZIP (success does not guarantee Store approval).
- [ ] Dev dependencies, `tests/`, configuration files (`phpunit.xml.dist`, `phpstan.neon.dist`) not in the delivered package, if not wanted.
- [ ] Smoke test in a fresh installation: `plugin:refresh`, `plugin:install --activate`, `plugin:update`, `plugin:deactivate`, `plugin:uninstall`.

## 3. Build and artifacts ([T-49](../references/49-build-deployment.md))
- [ ] Administration built (`bin/build-administration.sh`; the Admin build runs via Vite in 6.7.15.1), Storefront built (`bin/build-storefront.sh`; plugin/theme JS via Webpack).
- [ ] Built assets (`Resources/public`, `dist`) included in the package if the plugin is to run at the customer without a build.
- [ ] Theme: `theme:compile --sync` without errors; preview image (`previewMedia`) present ([T-11](../references/11-theme-basics.md)).
- [ ] ZIP/package created (e.g. `shopware-cli extension zip`; command name partly differs according to the docs, see T-49 "Gaps"), content checked: no `node_modules`, no tests/dev files, if not wanted.

## 4. Operations and deployment
- [ ] Deployment helper: `composer require shopware/deployment-helper` (not preinstalled in the project, ⚠ package name according to the docs), then `vendor/bin/shopware-deployment-helper run` ([T-49](../references/49-build-deployment.md)); alternatively manually: after deployment: `plugin:refresh`, `plugin:update`, `theme:compile`, `assets:install`, `cache:clear`; if needed `scheduled-task:register` and a running worker ([T-33](../references/33-scheduled-tasks.md), [T-34](../references/34-message-queue.md)).
- [ ] Caching/invalidation verified for new routes ([T-41](../references/41-caching.md)).
- [ ] ACL: existing roles checked/re-saved after mapping change ([T-23](../references/23-acl-permissions-roles.md)).
- [ ] Snippets (German/English, language-neutral file names, `translation:lint-filenames` without errors) complete ([T-18](../references/18-snippets-translations.md)).
- [ ] Logging/monitoring: own logger channel, no secrets in the log ([T-43](../references/43-cli-logging-systemchecks.md)).

## 5. Documentation
- [ ] README with installation, configuration, permissions, cron job/worker requirements.
- [ ] Known limitations and unverified assumptions (⚠ gaps) documented.
