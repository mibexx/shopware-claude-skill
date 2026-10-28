<?php declare(strict_types=1);

namespace SwagExample\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1730000000CreateSwagExampleTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1730000000;
    }

    public function update(Connection $connection): void
    {
        // Idempotent: IF NOT EXISTS, so that a retry after partial failures works.
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_example` (
                `id` BINARY(16) NOT NULL,
                `technical_name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `uniq.swag_example.technical_name` UNIQUE (`technical_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        // Translation table (only needed if the entity has translated fields)
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `swag_example_translation` (
                `swag_example_id` BINARY(16) NOT NULL,
                `language_id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`swag_example_id`, `language_id`),
                CONSTRAINT `fk.swag_example_translation.swag_example_id` FOREIGN KEY (`swag_example_id`)
                    REFERENCES `swag_example` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.swag_example_translation.language_id` FOREIGN KEY (`language_id`)
                    REFERENCES `language` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        // Adding a column in a LATER migration, idempotent (returns false if it exists):
        // $this->addColumn($connection, 'swag_example', 'sort_order', 'INT(11)');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Destructive changes only (expand/contract), does NOT run on plugin:install/update:
        // $this->dropColumnIfExists($connection, 'swag_example', 'old_column');
    }
}
