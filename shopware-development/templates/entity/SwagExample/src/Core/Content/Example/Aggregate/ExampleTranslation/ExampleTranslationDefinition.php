<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Aggregate\ExampleTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityTranslationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use SwagExample\Core\Content\Example\ExampleDefinition;

class ExampleTranslationDefinition extends EntityTranslationDefinition
{
    final public const ENTITY_NAME = 'swag_example_translation';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return ExampleTranslationEntity::class;
    }

    public function getCollectionClass(): string
    {
        return ExampleTranslationCollection::class;
    }

    protected function getParentDefinitionClass(): string
    {
        return ExampleDefinition::class;
    }

    protected function defineFields(): FieldCollection
    {
        // FK (swag_example_id), language_id and reverse associations are provided by the base class's getBaseFields().
        return new FieldCollection([
            (new StringField('name', 'name'))->addFlags(new ApiAware()),
        ]);
    }
}
