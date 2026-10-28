<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Aggregate\ExampleTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<ExampleTranslationEntity>
 */
class ExampleTranslationCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return ExampleTranslationEntity::class;
    }
}
