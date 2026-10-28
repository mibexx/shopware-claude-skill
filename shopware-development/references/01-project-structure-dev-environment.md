# Project structure and development environment (reference, Shopware 6.7.15.1)

## 1. Purpose

This reference applies when you need to find your way around a Shopware 6.7 project: where custom code lives, which configuration files and environment variables apply, and how to operate the Docker environment. It is the entry point before any plugin work.

Scope: structure of a single plugin and `composer.json` in [T-02 Plugin structure](./02-plugin-structure.md); lifecycle commands in [T-03 Plugin lifecycle](./03-plugin-lifecycle.md); build scripts (`bin/build-*.sh`, `bin/watch-*.sh`) in [T-49 Build and deployment](./49-build-deployment.md).

## 2. Concepts

- Shopware 6.7 is a **Symfony Flex project** (`shopware/production`, `composer.json` type `project`). Core, Storefront, Administration and Elasticsearch live as Composer packages in `vendor/shopware/*`. **Custom code** belongs in `custom/plugins`, `custom/static-plugins`, `custom/apps` or (project-specific) in `src/` (namespace `App\`). Never change anything in `vendor/shopware` (it is overwritten on `composer install/update`).
- Kernel: `Shopware\Core\Kernel` (not `App\Kernel`), built via `KernelFactory::create()` (entry points `public/index.php`, `bin/console`). Bundles in `config/bundles.php`; plugins are additionally loaded via a `KernelPluginLoader` (default `DbalKernelPluginLoader`, reads the `plugin` table).
- Configuration: `config/packages/*.yaml` (+ `config/packages/<env>/**`), `config/services.yaml`, `config/routes.yaml`. Shopware defaults (`shopware.yaml`, `feature.yaml` ...) live in the bundle under `core/Framework/Resources/config/packages/`, not in the project.
- Environment: precedence according to `debug:dotenv` (descending) `.env.local.php`, `.env.dev.local`, `.env.dev`, `.env.local`, `.env`. Real process variables (e.g. from `compose.override.yaml`) beat all files.
- Docker services: `web` (nginx + PHP 8.4, image `ghcr.io/shopware/docker-base:8.4-nginx`), `database` (MariaDB 11.8), `mailer` (Mailpit), optionally `opensearch` (profile `search`).

### Directory tree (project root, `kernel.project_dir`)

```text
shopware/
├── .env  .env.local  .env.dev        # defaults (APP_ENV=prod) / local overrides (gitignored) / env-specific
├── composer.json  composer.lock  symfony.lock
├── compose.yaml                      # base: database, opensearch (profile search)
├── compose.override.yaml             # PROJECT-SPECIFIC: web, mailer, ports, env variables
├── docker/Dockerfile                 # PROJECT-SPECIFIC
├── bin/{console,ci,build-*.sh,watch-*.sh}
├── config/
│   ├── bundles.php  services.yaml  routes.yaml  routes/mcp.yaml
│   ├── packages/{http_discovery,lock,mcp}.yaml  and <env>/...
│   └── jwt/                          # via system:generate-jwt-secret, gitignored
├── custom/{plugins,static-plugins,apps}/
├── src/Controller/                   # project code, namespace App\
├── files/                            # private filesystem (among others theme-config/)
├── public/                           # webroot; bundles/ media/ thumbnail/ theme/ sitemap/ are generated
├── var/                              # generated: cache/<env>_h<hash>/, log/<env>.log, plugins.json
├── install.lock                      # prevents the web installer
└── vendor/shopware/{core,storefront,administration,elasticsearch}/
```

### Decision guide: Where does my code go?

| Location | When | Loading/lifecycle |
|---|---|---|
| `custom/plugins/<Name>` | classic plugin, Admin/CLI lifecycle | `DbalKernelPluginLoader` via the `plugin` table; `plugin:refresh/install/activate` |
| `custom/static-plugins/<Name>` (`plugin:create --static`) | project-internal plugin, versioned in the project repo | Composer path repository (`repositories[type=path,url=custom/static-plugins/*]`, `symlink: true`), usually `composer require vendor/name` |
| `custom/apps/<Name>` with `manifest.xml` | app system | `app:refresh`, see [T-10 App system](./10-app-system-scope.md) |
| `src/` (`App\`) | pure project code | `config/services.yaml` autowires automatically; bundles in `config/bundles.php` |

### Important environment variables

| Variable | Purpose |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `dev`/`prod`/`test`; `.env` default `prod`; controls debug and `config/packages/<env>/` |
| `APP_URL` | public shop URL (port must match the port mapping) |
| `APP_SECRET`, `INSTANCE_ID` | Symfony secret / shop instance ID |
| `DATABASE_URL` | DB DSN (`Adapter\Database\MySQLFactory`; optional `DATABASE_SSL_*`, `DATABASE_REPLICA_*`) |
| `MAILER_DSN`, `LOCK_DSN` | mailer / Symfony Lock |
| `MESSENGER_TRANSPORT_DSN` (+ `_FAILURE_DSN`, `_LOW_PRIORITY_DSN`) | queue transports |
| `BLUE_GREEN_DEPLOYMENT`, `SHOPWARE_CDN_STRATEGY_DEFAULT` | `shopware.deployment.blue_green`, `shopware.cdn.strategy` |
| `COMPOSER_PLUGIN_LOADER` | `ComposerPluginLoader` instead of the DB loader (Composer plugins only) |
| `SHOPWARE_SKIP_WEBINSTALLER` | skip the web installer (`/installer`) |
| `OPENSEARCH_URL`, `SHOPWARE_ES_*` | Elasticsearch/OpenSearch |
| `PROXY_URL`, `SHOPWARE_HTTP_CACHE_ENABLED`, `SHOPWARE_HTTP_DEFAULT_TTL` | HTTP cache |
| `APP_CACHE_DIR`, `APP_BUILD_DIR`, `APP_LOG_DIR`, `SHOPWARE_CACHE_ID`, `PROJECT_ROOT` | kernel paths (`Kernel::getCacheDir()/getBuildDir()/getLogDir()`, `KernelFactory`) |

## 3. Instructions

1. **Start/stop** (from the project root):
   ```bash
   docker compose up -d
   docker compose ps
   docker compose logs -f web
   docker compose stop
   docker compose down        # volume db-data is kept
   docker compose down -v     # deletes the DB!
   docker compose --profile search up -d   # with OpenSearch
   ```
2. **URLs** (project setup according to `compose.override.yaml`, project-specific): Storefront `http://localhost:8100`, Admin `/admin` (admin/shopware), Mailpit `http://localhost:8025`, MariaDB host port `3307`. The docs default would be port 8000.
3. **Always run the console inside the container**: `docker compose exec web php bin/console <cmd>`. Important groups: `plugin:*`, `app:*`, `cache:clear` / `cache:clear:all` / `cache:clear:http`, `assets:install`, `theme:*`, `database:migrate`, `dal:refresh:index`, `bundle:dump`, `feature:list|enable|disable|dump`, `debug:config|container|router|dotenv|event-dispatcher|autowiring|twig`, `administration:*`.
4. **Composer inside the container**: `docker compose exec web composer require ...`. The post-install script `assets:install` runs automatically (`scripts.auto-scripts`).
5. **Check the configuration** instead of guessing: `bin/console debug:config shopware <section>` (e.g. `filesystem`), `bin/console debug:dotenv`. Your own overrides of the Shopware defaults go into `config/packages/shopware.yaml` in the project (does not exist in the standard installation).
6. **After changes**: config/plugin -> `cache:clear`; theme/Twig -> `theme:compile`; Admin/Storefront JS needs a build ([T-49](./49-build-deployment.md)); `assets:install` copies to `public/bundles`.

## 4. Examples

Create and activate a plugin via CLI (details and required fields in [T-02](./02-plugin-structure.md); scaffold: [../templates/plugin/README.md](../templates/plugin/README.md)):

```bash
cd /path/to/project-root
docker compose up -d
docker compose exec web php bin/console plugin:create SwagExample Swag --no-scaffold
docker compose exec web php bin/console plugin:refresh
docker compose exec web php bin/console plugin:install --activate SwagExample
docker compose exec web php bin/console plugin:list
docker compose exec web php bin/console cache:clear
```

`custom/plugins/SwagExample/composer.json` (minimal, `extra.label` is mandatory, otherwise invalid according to `PluginFinder::isPluginComposerValid`):

```json
{
    "name": "swag/example",
    "description": "Example plugin",
    "type": "shopware-platform-plugin",
    "version": "1.0.0",
    "license": "MIT",
    "require": { "shopware/core": "~6.7.0" },
    "extra": {
        "shopware-plugin-class": "SwagExample\\SwagExample",
        "label": { "de-DE": "Beispiel-Plugin", "en-GB": "Example plugin" }
    },
    "autoload": { "psr-4": { "SwagExample\\": "src/" } }
}
```

`custom/plugins/SwagExample/src/SwagExample.php`:

```php
<?php declare(strict_types=1);

namespace SwagExample;

use Shopware\Core\Framework\Plugin;

class SwagExample extends Plugin
{
}
```

## 5. Pitfalls

- **Plugin not visible**: forgot `plugin:refresh` (the `plugin` table is only updated there) or `type`/`extra.shopware-plugin-class`/`extra.label` is missing (`PluginFinder`; with the `ComposerPluginLoader` the message is "Skipped package ... invalid shopware-plugin-class").
- **Commands on the host instead of in the container**: the DB host `database` and PHP 8.4 only exist in the container; on the host, `DATABASE_URL` from `.env` (`localhost`) applies and fails. The same goes for Composer.
- **Assuming `APP_ENV` from `.env`**: the process env beats `.env` (which says `prod`; in the container it is `dev`). `debug:dotenv` shows both.
- **`APP_URL` wrong (port)**: leads to wrong asset/Admin URLs.
- **Committing generated directories**: `public/bundles|media|theme|thumbnail|sitemap`, `files/*`, `var/*`, `.env.local` are excluded according to `.gitignore`.
- **Changing code in `vendor/shopware`**: lost on the next update. Use a plugin, [decoration](./06-service-decoration.md) or an [event subscriber](./07-events-subscriber.md) instead.
- **XML project configuration**: deprecated, use YAML or PHP.

### Deprecations and version notes (6.5/6.6 → 6.7)

- XML configuration (`config/packages/**/*.xml`, `config/services.xml`, `config/services_<env>.xml`, `config/routes/**/*.xml`, `config/routes.xml`) triggers a deprecation in 6.7 ("will not be loaded in v6.8.0.0", `Kernel::configureContainer/configureRoutes`); the same applies to XML in bundle `Resources/config/packages` (`Bundle.php`).
- `Symfony\UX\TwigComponent\TwigComponentBundle` must be listed in `config/bundles.php` (otherwise deprecation; auto-registration only until 6.8).
- New bundles in 6.7.15.1: `Symfony\AI\McpBundle\McpBundle`, `Pentatrion\ViteBundle\PentatrionViteBundle`, `Shopware\Core\Service\Service`, `Shopware\Core\Profiling\Profiling`; plus `config/packages/mcp.yaml`, `config/routes/mcp.yaml` (see [T-44 MCP server](./44-mcp-server.md)).
- Feature flags `v6.5.0.0`, `v6.6.0.0`, `v6.7.0.0` are `default: true, toggleable: false` (old behavior can no longer be switched off); `v6.8.0.0` is `default: false` (opt-in preview).
- `COMPOSER_PLUGIN_LOADER` is supported by `public/index.php` and `bin/console`; `system:install` always uses the `StaticKernelPluginLoader`.
- Docker default according to the docs: Shopware CLI (`shopware-cli project dev`) or the Compose setup.

## 6. Checklist

- [ ] All `bin/console` and Composer commands run inside the container (`docker compose exec web ...`).
- [ ] Custom code lives in `custom/plugins`, `custom/static-plugins` or `src/`, never in `vendor/shopware`.
- [ ] `composer.json` has `type`, `extra.shopware-plugin-class`, `extra.label`, `autoload.psr-4`.
- [ ] After a new/changed plugin: `plugin:refresh`, if needed `plugin:install --activate`, `cache:clear`.
- [ ] `APP_URL` matches the port mapping.
- [ ] Config keys checked with `debug:config shopware <section>` before overriding.
- [ ] New configuration/services in YAML or PHP, not XML.
- [ ] Generated directories (`var`, `public/bundles`, `files`) not in the repository.

## 7. Sources

Docs:
- https://developer.shopware.com/docs/guides/plugins/plugins/bundle.html (plugin -> `Shopware\Bundle` -> Symfony bundle; bundles in `src/` and `config/bundles.php`; migrations not automatic)
- https://developer.shopware.com/docs/guides/installation/ (Shopware CLI + Docker, `shopware-cli project dev`, login admin/shopware; contains no directory tree)

Source code (relative to `vendor/shopware/`):
- `core/Kernel.php` (`Shopware\Core\Kernel`: `registerBundles`, `configureContainer`, `configureRoutes`, `getKernelParameters`, `getCacheDir`, `getLogDir`)
- `core/Framework/Adapter/Kernel/KernelFactory.php`, `core/Framework/Framework.php`, `core/Framework/Bundle.php`
- `core/Framework/Resources/config/packages/{shopware,feature,framework}.yaml`
- `core/Framework/Plugin/KernelPluginLoader/*`, `core/Framework/Plugin/Util/PluginFinder.php` (`COMPOSER_TYPE`), `core/Framework/Plugin/PluginService.php`, `core/Framework/Plugin/Command/PluginCreateCommand.php` (`--static`, `--no-scaffold`)
- `core/DevOps/Environment/EnvironmentHelper.php`

### Gaps and uncertainties

- ⚠ UNVERIFIED: `config/packages/shopware.yaml` does not exist in the installation; the override option follows from `Kernel::configureContainer` (glob `config/packages/*`), not from a test run.
- ⚠ UNVERIFIED: Including static plugins via `composer require` is derived from the path repository and `PluginFinder::enrichWithVendorPlugins`, not executed. `plugin:create`/`plugin:install` were not executed (examples from stub and code).
- ⚠ GAP: `compose.override.yaml`, `docker/Dockerfile`, port 8100 and the `web` service are project-specific, not checked against the official `shopware/production` recipe.
- ⚠ GAP: Effect of `APP_URL_CHECK_DISABLED` not verified in the code.
- ⚠ GAP: Whether plugin `services.xml` will still be loaded in 6.8 is not established (see [T-05](./05-registering-services.md)); differences from 6.5/6.6 recipes not checked against old recipes.
- ⚠ GAP: Webpack vs. Vite (the Vite bundle is registered) belongs to [T-49](./49-build-deployment.md); docs page `/docs/concepts/` not fetched.
