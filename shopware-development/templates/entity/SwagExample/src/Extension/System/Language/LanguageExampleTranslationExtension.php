<?php declare(strict_types=1);

namespace SwagExample\Extension\System\Language;

use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\CascadeDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\System\Language\LanguageDefinition;
use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationDefinition;

// Rueckseite der ManyToOne-Assoziation `language` der Translation-Definition.
// Ohne diese Extension meldet `bin/console dal:validate`: "Missing reverse one-to-many association ... LanguageDefinition".
class LanguageExampleTranslationExtension extends EntityExtension
{
    public function getEntityName(): string
    {
        return LanguageDefinition::ENTITY_NAME;
    }

    public function extendFields(FieldCollection $collection): void
    {
        // Das Flag `Extension` ergaenzt die Basisklasse (EntityDefinition::getFields()) selbst.
        $collection->add(
            (new OneToManyAssociationField('swagExampleTranslations', ExampleTranslationDefinition::class, 'language_id'))
                ->addFlags(new CascadeDelete())
        );
    }
}
