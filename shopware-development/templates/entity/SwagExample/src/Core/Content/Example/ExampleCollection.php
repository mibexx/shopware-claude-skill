<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ExampleEntity>
 */
class ExampleCollection extends EntityCollection
{
    public function getApiAlias(): string
    {
        return 'swag_example_collection';
    }

    protected function getExpectedClass(): string
    {
        return ExampleEntity::class;
    }
}
