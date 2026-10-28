# Template: quality (PHPStan, code style)

Shopware 6.7.15.1. Configuration for static analysis (PHPStan with the Shopware rules from `shopware/core`) and code style (php-cs-fixer, "Shopware Coding Standard"). References: ../../references/48-static-analysis.md, ../../references/45-coding-guidelines.md, build/release ../../references/49-build-deployment.md. Related templates: ../plugin/README.md, ../testing/README.md (PHPUnit), ../testing-js/README.md (Jest/Vitest).

Tested in practice in the project's Docker setup (PHP 8.4.27): PHPStan 2.3.3 ran green with `phpstan.neon.dist` and reported deliberately introduced violations of the Shopware and Symplify rules; php-cs-fixer 3.95.27 ran with `.php-cs-fixer.dist.php`. Details below.

## File tree

```
SwagExample/
├── phpstan.neon.dist             # level 8, Shopware rules, bootstrap of the StaticAnalyzeKernel
├── .php-cs-fixer.dist.php        # rules from the shopware-cli standard
├── .config/shopware-extension.yml  # only for shopware-cli validate (⚠ UNVERIFIED)
└── composer.json.snippet         # excerpt: php constraint (plugin), require-dev + scripts (project)
```

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `SwagExample` (in `phpstan.neon.dist`: `allowedUnitTestClassNamespaces`, `.config/...`) | Plugin name / test namespace | your own plugin name |
| `../../../vendor/...` | Path to the project `vendor` relative to the neon file (plugin lives in `custom/plugins/<Name>`) | adjust if the plugin lives elsewhere |
| `level: 8`, `phpVersion: 80200` | as in the core's `common.neon` | as needed |

## Setup

Dev tools belong in the project, not in the plugin: the project Composer does not install a plugin's `require-dev`.

Required packages (all on Packagist; resolvable via `composer require --dev --dry-run` in the project, not actually installed): `phpstan/phpstan` (2.3.3), `phpstan/phpstan-symfony` (2.1.1), `phpat/phpat` (0.12.5), `symplify/phpstan-rules` (14.19.3), `friendsofphp/php-cs-fixer` (3.95.27).

- The package `shopware/phpstan-extension` (named in the extension's README) cannot be found on Packagist (`composer require` in the container: "could not be found in any version"). The files `extension.neon`/`rules.neon` live in `vendor/shopware/core/DevOps/StaticAnalyze/PHPStan/`; the template includes them from there.
- `rules.neon` additionally requires: the service `Shopware\...\PHPStan\Configuration` together with the parameter `shopware:` (only comes from `common.neon`, hence adopted in the template), `phpstan/phpstan-symfony` (`ParameterMap`), Symplify's helper services (`config/services/services.neon`) and `phpat/phpat`.
- Do NOT use `phpstan/extension-installer`: it includes the complete Symplify configuration; together with `rules.neon` this results in `Multiple services of type Symplify\PHPStanRules\Rules\NoReturnSetterMethodRule found`.

Variant A (standard, in the project): `docker compose exec web composer require --dev phpstan/phpstan phpstan/phpstan-symfony phpat/phpat symplify/phpstan-rules friendsofphp/php-cs-fixer` (changes `composer.json`/`composer.lock`; ⚠ UNVERIFIED for real, dry run only).
Variant B (tried, without touching the project): `docker compose exec web sh -c 'mkdir -p /tmp/qa && cd /tmp/qa && echo "{}" > composer.json && composer require --no-interaction phpstan/phpstan phpstan/phpstan-symfony phpat/phpat symplify/phpstan-rules friendsofphp/php-cs-fixer'`. Then in `phpstan.neon.dist` switch the three includes `../../../vendor/phpstan/...`, `.../phpat/...`, `.../symplify/...` to `/tmp/qa/vendor/...` (this is how it was tested). `/tmp` is not in the volume.

## Running in the Docker setup

Always from the project directory (where `compose.yaml` lives); in the container the working directory is `/var/www/html`.

```bash
# PHPStan (variant A: vendor/bin/phpstan; variant B: /tmp/qa/vendor/bin/phpstan)
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress
docker compose exec web vendor/bin/phpstan analyse -c custom/plugins/SwagExample/phpstan.neon.dist --no-progress custom/plugins/SwagExample/src/Service
docker compose exec web vendor/bin/phpstan clear-result-cache -c custom/plugins/SwagExample/phpstan.neon.dist

# Code style: check (exit code != 0 on deviations) or apply
docker compose exec web vendor/bin/php-cs-fixer check --diff --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
docker compose exec web vendor/bin/php-cs-fixer fix --config=custom/plugins/SwagExample/.php-cs-fixer.dist.php custom/plugins/SwagExample
```

PHPStan does not need a database. The bootstrap (`phpstan-bootstrap.php`) boots the `StaticAnalyzeKernel` and creates `var/cache/static_phpstan_dev/` in the project; PHPStan itself caches to `/tmp/phpstan` in the container. After changes to services/container, delete `var/cache/static_phpstan_dev` and run `clear-result-cache`.

Example result (variant B): `[OK] No errors` (approx. 2.6 s). A deliberately inserted `unserialize()` violation in a class was reported as `shopware.unserializeUsage`; two classes in one file as `symplify.multipleClassLikeInFile`.

The Composer script wrappers from `composer.json.snippet` are ⚠ UNVERIFIED (the individual commands were executed).

CI and release: the same commands without `docker compose exec`; additionally store/extension validation with `shopware-cli extension validate` (see ../../references/48-static-analysis.md and ../../references/49-build-deployment.md). `shopware-cli` is not installed in the Docker setup; none of this was executed.

## Code style: what is established

- Official way according to the docs: `shopware-cli extension format <path>` (php-cs-fixer, "Shopware Coding Standard"). `shopware-cli` prefers a `.php-cs-fixer.dist.php` in the plugin root over its bundled configuration.
- The template's rules are taken 1:1 from shopware-cli's bundled configuration (`internal/verifier/php/configs/php-cs-fixer.dist.php` in the GitHub repo `shopware/shopware-cli`, main branch on 2026-10-28): `@Symfony` plus adjustments (among others `ordered_class_elements`, `yoda_style` off, `single_quote`, `concat_space` one). The finder exclusions (`vendor`, `node_modules`) are an addition of the template.
- Neither `vendor/shopware/*` nor the project contains a `.php-cs-fixer*` or `ecs.php` file; an ECS setup is not established. Local check: a plugin with `\Namespace\Class::class` notation in `services.php` was flagged by the configuration (leading backslash removed); the plugin templates of this skill are NOT formatted accordingly (⚠ UNVERIFIED).
- Without a `php` constraint in `composer.json`, php-cs-fixer reports: "No PHP version requirement found" (the snippet sets `php: >=8.2`).

## Pitfalls (encountered in this environment)

- PHPStan started from the plugin folder: `Class ...McpToolResponseRule not found`, because the project autoloader is not found. Always start from the project root.
- `Service of type ...\PHPStan\Configuration ... not found`: the `shopware:` parameter and service are missing (including `rules.neon` alone is not enough).
- `Service of type PHPStan\Symfony\ParameterMap ... not found`: `phpstan-symfony` not included.
- `Service of type Symplify\...\EnumAnalyzer ... not found`: `symplify/phpstan-rules/config/services/services.neon` not included.
- `Multiple services of type ...NoReturnSetterMethodRule`: `phpstan/extension-installer` active, see above.
- Real finding in the plugin template: `$this->container->get()` in `uninstall()` reported `method.nonObject` (container is nullable); template ../plugin/SwagExample/src/SwagExample.php has been corrected accordingly (runtime check instead of `@var`).
- `core-rules.neon` (among others `DecorationPatternRule`) is NOT included and not tested (⚠ UNVERIFIED).

## ESLint / Stylelint (admin, storefront)

Reference only: see ../../references/48-static-analysis.md (section Administration/Storefront) and ../testing-js/README.md. The admin extension tooling is marked as `@internal`/experimental; none of it was executed here (⚠ UNVERIFIED).

## Gaps

- ⚠ GAP: Version of the shopware-cli standard at release 6.7.15.1 not established (configuration from the main branch).
- ⚠ UNVERIFIED: Installing the tools directly in the project; `shopware-cli`; `.config/shopware-extension.yml`; Rector (`shopware-cli extension fix`, a Composer package is not established).
