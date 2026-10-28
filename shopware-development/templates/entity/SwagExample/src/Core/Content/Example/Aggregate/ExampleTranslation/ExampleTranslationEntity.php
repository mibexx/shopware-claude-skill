<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example\Aggregate\ExampleTranslation;

use Shopware\Core\Framework\DataAbstractionLayer\TranslationEntity;
use SwagExample\Core\Content\Example\ExampleEntity;

class ExampleTranslationEntity extends TranslationEntity
{
    // Names are derived from the entity name: swag_example => swagExampleId / swagExample
    protected string $swagExampleId;

    protected ?string $name = null;

    protected ?ExampleEntity $swagExample = null;

    public function getSwagExampleId(): string
    {
        return $this->swagExampleId;
    }

    public function setSwagExampleId(string $swagExampleId): void
    {
        $this->swagExampleId = $swagExampleId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getSwagExample(): ?ExampleEntity
    {
        return $this->swagExample;
    }

    public function setSwagExample(ExampleEntity $swagExample): void
    {
        $this->swagExample = $swagExample;
    }
}
