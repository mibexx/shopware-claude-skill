# Static analysis and code style (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when PHPStan, code style (php-cs-fixer), Rector, ESLint/Stylelint or the store/extension validation are set up for a plugin. PHPStan and php-cs-fixer were actually run in the project's Docker setup (see section 7); `shopware-cli`, Rector, ESLint/Stylelint and store validation were not (marked ⚠).

Scope: conventions/rule contents in [T-45](./45-coding-guidelines.md); tests [T-46](./46-tests-phpunit.md), [T-47](./47-tests-jest.md); build/ZIP/`shopware-cli` in [T-49](./49-build-deployment.md); plugin scaffold [T-02](./02-plugin-structure.md).

Runnable template (phpstan.neon.dist, .php-cs-fixer.dist.php, Docker commands, pitfalls): [../templates/quality/README.md](../templates/quality/README.md); PHPUnit template: [../templates/testing/README.md](../templates/testing/README.md); JS tests: [../templates/testing-js/README.md](../templates/testing-js/README.md).

## 2. Concepts

- **PHPStan extension** in core (`core/DevOps/StaticAnalyze/PHPStan/`); the README names the split package `shopware/phpstan-extension`. Files: `extension.neon` (type extensions), `rules.neon` (rules for projects/plugins), `core-rules.neon` (according to the README "intended for shopware core (& plugin) development", not suitable for generic projects and therefore not included automatically, can be included deliberately via `includes:`; contains `DecorationPatternRule`, among others), `common.neon` (core reference: level 8, bleedingEdge, strictRules, type_perfect). Parameter `shopware:` in `Configuration.php`.
- **Bootstrap:** `core/DevOps/StaticAnalyze/phpstan-bootstrap.php` boots `StaticAnalyzeKernel` (env `phpstan_dev`, cache `var/cache/static_phpstan_dev`), reads `.env`, uses `ComposerPluginLoader`.
- **Plugin-relevant rules** (`rules.neon`): `PublicServiceDecoratorRule`, `NoRouteOverrideInDecoratorsRule` (`shopware.routeDecorator`), `RouteScopeRule` (`shopware.routeScope`), `NoDALAutoload`, `NoUnserializeUsageRule`, `NoFileExistsRule`, `NoNativeTimeFunctionRule`/`NoNativeTimeClassRule`, `UseHasherRule`, `PropertyNativeTypeRule`. `rules.neon` additionally references classes from `symplify/phpstan-rules` (`Symplify\PHPStanRules\Rules\…`, among others `RequireUniqueEnumConstantRule`, `UppercaseConstantRule`; a service `NoReturnSetterMethodRule` is decorated) and tags services with `phpat.test` (`NotExtendFlowEventAwareRule`, package `phpat/phpat`). Without `symplify/phpstan-rules`, PHPStan aborts while loading the rules; `phpat/phpat` is required for the `phpat.test` rules. Verified in practice (PHPStan 2.3.3): `rules.neon` additionally requires the service `…\PHPStan\Configuration` including the parameter `shopware:` (only comes from `common.neon`), `phpstan/phpstan-symfony` (`PHPStan\Symfony\ParameterMap`) and the Symplify helper services (`symplify/phpstan-rules/config/services/services.neon`). The split package `shopware/phpstan-extension` can NOT be found on Packagist (`composer require` in the container: "could not be found in any version"); the files live under `vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/`.
- **Only in `core-rules.neon`** (according to the README "intended for shopware core (& plugin) development", not for generic projects, not included automatically by the `extension-installer`): `DecorationPatternRule` (`shopware.decorationPattern`), `DomainExceptionRule`, `PackageAnnotationRule`, `InternalClassRule`/`InternalMethodRule`, deprecation/migration rules, among others. The file uses `%symfony.containerXmlPath%` (requires `phpstan/phpstan-symfony`) and `phpat.test` services. Anyone who wants `DecorationPatternRule` for their plugin includes `core-rules.neon` deliberately or lists the rule under `rules:`.
- **PHP code style:** the official way is `shopware-cli extension format` (php-cs-fixer, "Shopware Coding Standard"; a custom `.php-cs-fixer.dist.php` in the root takes precedence). The configuration shipped with shopware-cli (GitHub `shopware/shopware-cli`, `internal/verifier/php/configs/php-cs-fixer.dist.php`, main branch 2026-10-28): `@Symfony` plus adjustments; adopted as a template (`../templates/quality/SwagExample/.php-cs-fixer.dist.php`) and run with php-cs-fixer 3.95.27 in the container. There is no `.php-cs-fixer*`/`ecs.php` in `vendor/shopware/*` or in the project; an ECS setup is not documented. Prettier only uses the CLI configuration (a custom `.prettierrc` is ignored).
- **Rector:** via `shopware-cli extension fix` / `project fix` ("Shopware Rector", version-aware); a Composer package `shopware/rector` is not documented.
- **ESLint/Stylelint:** storefront (`eslint.config.js` flat config ESLint 9, `stylelint.config.js`) and Administration (`eslint.config.ts`, `.stylelintrc`, Prettier) each have their own configuration. For admin plugins there is extension tooling (`administration:setup-extension-tooling`, `administration:check-extensions`). ⚠ EXPERIMENTAL/`@internal`: the commands are `@internal` and `@experimental stableVersion:v6.8.0 feature:ADMIN_EXTENSION_TOOLING`; the description reads "[EXPERIMENTAL] … Command name, options and generated output can change in any release" (`administration/Command/{SetupExtensionToolingCommand,AbstractExtensionToolingCommand,CheckExtensionsCommand}.php`). Do not treat them as a stable API; use them in CI with version pinning.
- **Verifier/store:** `shopware-cli extension validate` runs built-in checkers, PHPStan, ESLint, Stylelint and the storefront Twig linter; `store_compliance: true` requires matching source/compiled assets. Success does not guarantee store approval (docs).

Plugin structure (assumption):

```
custom/plugins/SwagExample/
├── composer.json                    # php constraint; the dev packages belong in the PROJECT's composer.json (the project Composer does not install a plugin's require-dev)
├── phpstan.neon.dist
├── .php-cs-fixer.dist.php           # optional
├── .config/shopware-extension.yml   # validation: php_version, store_compliance, ignore
└── src/Resources/app/administration/{eslint.config.mjs, tsconfig.json, .shopware/}
```

## 3. Instructions

**PHPStan**
1. Packages into the project: `docker compose exec web composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpat/phpat symplify/phpstan-rules friendsofphp/php-cs-fixer`. Resolved in the project via `--dry-run`: PHPStan 2.3.3, phpstan-symfony 2.1.1, phpat 0.12.5, symplify/phpstan-rules 14.19.3, php-cs-fixer 3.95.27 (Composer 2.10.3, PHP 8.4.27); not actually installed into the project (⚠ UNVERIFIED). What actually ran is the variant with a separate directory `/tmp/qa` in the container (no change to the project, details in [../templates/quality/README.md](../templates/quality/README.md)). Do NOT use `phpstan/extension-installer`: conflict `Multiple services of type Symplify\PHPStanRules\Rules\NoReturnSetterMethodRule`.
2. Create `phpstan.neon.dist` from the template (section 4): includes `extension.neon`, `rules.neon` from `vendor/shopware/core/…`, plus phpstan-symfony, phpat and the Symplify `services.neon`, as well as the parameter `shopware:`, service `Configuration` and `parametersSchema` (from `common.neon`), bootstrap `phpstan-bootstrap.php`.
3. Run in the Docker setup, ALWAYS from the project root (`/var/www/html`; from the plugin folder the run fails with `Class …McpToolResponseRule not found`, because the project autoloader is missing):

```bash
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress custom/plugins/SwagExample/src/Service
docker compose exec web vendor/bin/phpstan clear-result-cache -c custom/plugins/SwagExample/phpstan.neon.dist
docker compose exec web rm -rf var/cache/static_phpstan_dev   # after changes to services/container
```

   Ran (variant `/tmp/qa`, same arguments): `[OK] No errors` in approx. 2.6 s; deliberate violations were reported (`shopware.unserializeUsage`, `symplify.multipleClassLikeInFile`). No database needed. The PHPStan result cache lives in `/tmp/phpstan` in the container, the kernel cache in `var/cache/static_phpstan_dev`. CI/release: the same commands without `docker compose exec` (see [T-49](./49-build-deployment.md)).
4. Individual rules can also be listed under `rules:` (README, e.g. `NoEnvironmentHelperInsideCompilerPassRule`).

**php-cs-fixer directly in the Docker setup** (actually run, 3.95.27; configuration from the template; `check` returns exit code ≠ 0 on deviations):

```bash
docker compose exec web vendor/bin/php-cs-fixer check --diff --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
docker compose exec web vendor/bin/php-cs-fixer fix --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
```

**Code style and Rector via `shopware-cli`** (⚠ UNVERIFIED: `shopware-cli` is not installed in the Docker setup; docs `products/tools/cli/formatter.html`, `automatic-refactoring.html`)

```bash
shopware-cli extension format custom/plugins/SwagExample --dry-run   # check
shopware-cli extension format custom/plugins/SwagExample             # apply
shopware-cli extension format . --only php-cs-fixer
shopware-cli extension fix custom/plugins/SwagExample --only rector  # clean Git state beforehand, git diff afterwards
shopware-cli project fix --only rector
shopware-cli project validate --only phpstan /path                   # breaking-change check without modification
```

**Administration (plugin)** (⚠ EXPERIMENTAL and `@internal`, `@experimental stableVersion:v6.8.0`; commands/options can change in any release)

```bash
( cd vendor/shopware/administration/Resources/app/administration && npm ci )
bin/console bundle:dump
bin/console administration:setup-extension-tooling
bin/console administration:check-extensions -- --only=SwagExample
bin/console administration:check-extensions -- --only=SwagExample --fix
bin/console administration:check-extensions -- --all --fail-on-skipped   # CI
```

Do not forget the `--` separator with Composer scripts (otherwise options are swallowed). Scaffolded `eslint.config.mjs`: `import shopware from './.shopware/eslint.mjs'; export default [ ...shopware ];`. The factory `shopwareAdminExtension(options)` (`extension-tooling/eslint.mjs`) brings Vue, TS-typed, `sw-core-rules`, `sw-deprecation-rules`, `plugin-rules` and forbids imports of admin internals ("Use the global Shopware object …").

**Platform scripts** (admin `package.json`): `lint`, `lint:types` (tsc), `lint:scss`, `format`/`format:fix` (Prettier), `lint:twig`, `lint:snippets`, `lint:deprecations`, `lint:all`; `npm run extension-tooling:setup|check`.

**Storefront** (`package.json`): `npm run lint` (ESLint `./src` + Stylelint `./src/scss`), `lint:js`, `lint:scss`, `lint-fix`. ESLint: `@eslint/js`, `typescript-eslint` recommended+typeChecked, 4-space indentation, `quotes: single`, `semi`, `no-console` except warn/error, `consistent-type-imports`, JSON snippets `sw-core-rules/require-global-default-use`. Stylelint: `stylelint-config-recommended-scss` + `stylelint-prettier`, class pattern `^[a-z0-9\-]+$`, `max-nesting-depth` 3 (warning).

**Verifier/store**

```bash
shopware-cli extension validate custom/plugins/SwagExample
shopware-cli extension validate /path --only phpstan,eslint
shopware-cli extension validate /path --exclude stylelint --format github
shopware-cli extension package /path --release --output-directory dist --filename extension.zip
shopware-cli extension validate dist/extension.zip
```

## 4. Examples

`phpstan.neon.dist` (modeled on `common.neon`; run from the project root, plugin in `custom/plugins/SwagExample`; complete, tested version: [../templates/quality/SwagExample/phpstan.neon.dist](../templates/quality/README.md)). Shortened excerpt:

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
    shopware:                       # mandatory for rules.neon, see template (plus services: Configuration, parametersSchema)
        allowedNonDomainExceptions: []
        allowedStorefrontRouteNamespaces: [Shopware\Storefront]
        allowedUnitTestClassNamespaces: [SwagExample\Tests\Unit\]
        createMockWithoutExpectationsEnabledNamespaces: []
```

Paths are relative to the neon file. The version tested used `/tmp/qa/vendor/...` instead of `../../../vendor/...` for the three third-party packages (variant `/tmp/qa`); the `../../../vendor/…` version for installation in the project is therefore ⚠ UNVERIFIED. A real finding of the run: `$this->container->get()` in `uninstall()` of the plugin template reported `method.nonObject` (nullable container); the template has been corrected.

`src/Service/GreetingService.php` (runtime check instead of `@var`, as required by the docs page "Writing code for static analysis")

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

(Note: domain exceptions instead of `\InvalidArgumentException` in core domain code, see [T-45](./45-coding-guidelines.md); in the plugin here for illustration only.)

`.config/shopware-extension.yml`

```yaml
validation:
  php_version: '8.4'
  store_compliance: true
  ignore:
    - identifier: 'Shopware.XXXXXX'
      path: 'src/Foo.php'
```

## 5. Pitfalls

- `phpstan-bootstrap.php` missing/broken: container classes are not resolved, follow-up errors; clear the cache `var/cache/static_phpstan_dev`.
- Running from the plugin folder instead of the project root: `Class … not found` (verified).
- Load errors at startup (all verified): `Service of type …\PHPStan\Configuration not found` (parameter `shopware:`/service missing), `Service of type PHPStan\Symfony\ParameterMap not found` (phpstan-symfony missing), `Service of type Symplify\…\EnumAnalyzer not found` (Symplify `services.neon` missing), `Multiple services of type …NoReturnSetterMethodRule` (`phpstan/extension-installer` active).
- Including `core-rules.neon` without thought: according to the README intended for core and plugin development, not suitable for generic projects (hence not included automatically via the `extension-installer`); deliberately possible for plugins via `includes:`; needs `phpstan-symfony` (`containerXmlPath`) and `phpat`; contains `DecorationPatternRule`, which is missing from `rules.neon`.
- Decorator with `#[Route]`: `shopware.routeDecorator`.
- Forcing types via `@var` instead of a runtime check/`assert`; casts hide root causes; `list<T>` instead of `array<T>` for lists.
- `reportUnmatchedIgnoredErrors: true` makes outdated ignores fail themselves.
- A custom `.prettierrc` in the plugin is ignored by `extension format`.
- An extension without admin config is "skipped" instead of green; in CI use `--fail-on-skipped`.
- "validate green = store approval" is wrong according to the docs.

### Deprecations and version notes (6.5/6.6 → 6.7)

- ⚠ UNVERIFIED: the comparison with 6.5/6.6 is not documented from source code.
- 6.7.15.1: ESLint 9 flat config in admin and storefront; Stylelint 14 (admin) vs. 16 (storefront).
- `NoRouteOverrideInDecoratorsRule` also checks the deprecated annotation `Route`; annotation routes are deprecated.
- Admin extension tooling is present in this version, but ⚠ `@internal` and `@experimental stableVersion:v6.8.0 feature:ADMIN_EXTENSION_TOOLING`; introducing version unknown. The docs name `shopware-cli` as the current standard for validate/format/fix.

## 6. Checklist

- [ ] `phpstan.neon.dist` with level, bootstrap and Shopware extension/rules (`core-rules.neon` only deliberately, see section 2); `phpstan-symfony`/`symplify/phpstan-rules`/`phpat/phpat` installed
- [ ] PHPStan runs from the project root; cache cleared on changes
- [ ] `shopware-cli extension format --dry-run` clean
- [ ] Rector only with a clean Git state, diff reviewed
- [ ] Admin plugin (⚠ experimental): `administration:setup-extension-tooling` and `check-extensions` green (`--fail-on-skipped` in CI)
- [ ] Storefront: `npm run lint` (ESLint + Stylelint) green
- [ ] `shopware-cli extension validate` on plugin and ZIP; `.config/shopware-extension.yml` maintained
- [ ] No outdated PHPStan ignores

## 7. Sources

Docs: https://developer.shopware.com/docs/resources/guidelines/code/core/writing-code-for-static-analysis.html (200), https://developer.shopware.com/docs/resources/guidelines/code/, https://developer.shopware.com/docs/products/tools/cli/validation.html, `…/formatter.html`, `…/automatic-refactoring.html`, https://developer.shopware.com/docs/products/cli/, https://github.com/shopware/shopware-cli. Planned URLs `/docs/guides/development/tooling/` and `/docs/guides/plugins/plugins/testing/`: 404/not documented.

Source code (under `vendor/shopware/`): `core/DevOps/StaticAnalyze/PHPStan/{README.md,common.neon,extension.neon,rules.neon,core-rules.neon,Configuration.php}`, `core/DevOps/StaticAnalyze/{phpstan-bootstrap.php,StaticAnalyzeKernel.php}`, `…/PHPStan/Rules/{DecorationPatternRule,PublicServiceDecoratorRule,NoRouteOverrideInDecoratorsRule,RouteScopeRule,NoDALAutoload,DomainExceptionRule}.php`, `…/PHPStan/Type/*`, `storefront/Resources/app/storefront/{eslint.config.js,stylelint.config.js,package.json,eslint-rules/core-rules/}`, `administration/Resources/app/administration/{eslint.config.ts,.stylelintrc,.prettierrc.js,package.json,extension-tooling/README.md,extension-tooling/eslint.mjs}`.

Hands-on verification (2026-10-28, project Docker setup, container `web`: PHP 8.4.27, Composer 2.10.3, Packagist reachable): PHPStan 2.3.3, php-cs-fixer 3.95.27 from `/tmp/qa` against a temporary plugin; project `composer.json`/`composer.lock` unchanged (only `--dry-run`). shopware-cli default configuration: https://github.com/shopware/shopware-cli (`internal/verifier/php/configs/php-cs-fixer.dist.php`, `internal/verifier/phpcsfixer.go`).

### Gaps and uncertainties

- Verified in practice: PHPStan run with Shopware/Symplify rules (level 8) and php-cs-fixer run in the Docker setup; load errors and solutions (section 5).
- ⚠ UNVERIFIED: actual installation of the tools into the project (dry run only); `../../../vendor/…` path version of the neon file; Composer script wrappers; `core-rules.neon`; ECS, Rector, `shopware-cli` (not installed).
- ⚠ GAP: `shopware/phpstan-extension` cannot be found on Packagist (README statement not confirmed); the configuration uses the files from `shopware/core`. Which version of the shopware-cli coding standard matches 6.7.15.1 is not documented (configuration from the main branch).
- ⚠ EXPERIMENTAL: admin extension tooling (`administration:setup-extension-tooling`, `administration:check-extensions`, `extension-tooling:*`) is `@internal`/`@experimental`, announced as stable from 6.8 at the earliest (`stableVersion:v6.8.0`).
- ⚠ GAP: `shopware/rector`, ECS/coding standard package not documented (GitHub URLs `shopware/rector`, `shopware/extension-verifier`, `shopwareLabs/store-plugin-codestyle` returned 404); validity of older ECS setups unknown.
- ⚠ GAP: the docs do not conclusively state which verifier rules the store actually enforces.
