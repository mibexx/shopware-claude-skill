# Template: migration (MigrationStep)

Shopware 6.7.15.1. A migration creates the database schema. The template creates the tables `swag_example` and `swag_example_translation` matching the template ../entity/README.md (the DAL never creates tables itself).

## File tree

```
SwagExample/
└── src/
    └── Migration/
        └── Migration1730000000CreateSwagExampleTable.php
```

The directory `src/Migration` and the namespace `<PluginNamespace>\Migration` are fixed (`Bundle::getMigrationNamespace()/getMigrationPath()`); the path is registered automatically as a migration source, a `services.php` registration is NOT needed. Root scaffold: ../plugin/README.md

## Placeholders

| Placeholder | Meaning | Replace with |
|---|---|---|
| `1730000000` (file name, class name, `getCreationTimestamp()`) | Unix timestamp, determines the order | current timestamp (`date +%s`); identical in all three places |
| `CreateSwagExampleTable` | Description in the class name | descriptive name |
| `SwagExample\Migration` | Namespace | `<PluginNamespace>\Migration` |
| `swag_example`, `swag_example_translation` | Table names (= entity names) | `<prefix>_<name>` |
| Columns | `technical_name`, `active`, `name` | matching the entity definition |

## Rules

- File name = class name = `Migration<Timestamp><Description>`; the order is determined solely by `getCreationTimestamp()` (values `< 1` or `>= 2147483647` are implausible and throw). File, class and namespace must match: every `.php` file in `src/Migration/` must be loadable.
- Write idempotently (`IF NOT EXISTS`, helper methods `addColumn`, `dropColumnIfExists`, `dropTableIfExists`, `dropIndexIfExists`, `dropForeignKeyIfExists`; checks `columnExists($connection, $table, $column)` and `indexExists($connection, $table, $index)`; all `protected` in `MigrationStep`, called via `$this->`. `TableHelper` is `@internal`/`@final` and not intended for plugins).
- Never change published migrations, create a new one instead. No rollback, no enclosing transaction.
- Backward compatible (expand/contract): new things in `update()`, destructive things (DROP, rename) only later in `updateDestructive()`. `plugin:install` and `plugin:update` automatically run ONLY `update()` (on `plugin:activate` only with `setAutoMigrate(true)` in `activate()`), never `updateDestructive()`.
- Keep it short (guideline: under 10 seconds).
- Set foreign key `ON DELETE` in addition to the DAL flags (`CascadeDelete` only takes effect when deleting via the DAL).
- Mapping tables (n:m) have no DAL timestamps; see ../entity/README.md.

## Integration / CLI

```bash
# Generate a skeleton (the template contains update(), but no updateDestructive())
bin/console database:create-migration -p SwagExample --name CreateSwagExampleTable

# or generate from the entity schema (plugin must be active; without --bundle it would write into the CORE!)
bin/console dal:migration:create swag_example,swag_example_translation --bundle=SwagExample

# execute
bin/console plugin:refresh && bin/console plugin:install --activate SwagExample   # runs automatically on install/update
bin/console database:migrate SwagExample --all
bin/console database:migrate-destructive SwagExample --all                         # manual only
bin/console dal:validate                                                           # check schema against definitions
```

Cleaning up on uninstall: `Plugin::uninstall()` (template in ../plugin/SwagExample/src/SwagExample.php) deletes the tables only when `!keepUserData()`. Without `keepUserData` the core removes the rows from the `migration` table; a reinstall runs the migrations again. The automation can be switched off per lifecycle step with `InstallContext::setAutoMigrate(false)` and `getMigrationCollection()->migrateInPlace()`.

## References

- ../../references/04-database-migrations.md
- ../../references/03-plugin-lifecycle.md
- ../../references/27-dal-entities.md
- Templates: ../plugin/README.md, ../entity/README.md

## Notes and gaps

- Deprecation: `MigrationStep::indexExists()` (`#[ExceptionChange(v6.8.0)]`); with `v6.8.0.0` it returns `false` instead of `TableNotFoundException`. Keep using `$this->indexExists()`; do not call `TableHelper` (`@internal`, `@final`) directly.
- Checked: method signatures against `core/Framework/Migration/MigrationStep.php`; SQL patterns from the scaffold stub and the research.
- ⚠ UNVERIFIED: The SQL was not executed against a database (no PHP/DB locally).
- ⚠ UNVERIFIED: Whether `dal:migration:create` creates no file when the table already exists (assumption, delta to the DB); always check the result of the `@internal` command.
- ⚠ GAP: Versioned entities (`version_id` primary key, `ReferenceVersionField`) and inheritance (`parent_id`) are only included as a note in ../entity/README.md, not as a migration.
