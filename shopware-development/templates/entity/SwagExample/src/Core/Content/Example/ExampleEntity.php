<?php declare(strict_types=1);

namespace SwagExample\Core\Content\Example;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use SwagExample\Core\Content\Example\Aggregate\ExampleTranslation\ExampleTranslationCollection;

// Properties mindestens protected, NICHT readonly (Hydrator setzt sie).
class ExampleEntity extends Entity
{
    use EntityIdTrait;

    protected string $technicalName;

    protected bool $active = true;

    protected ?string $name = null;

    protected ?ExampleTranslationCollection $translations = null;

    public function getTechnicalName(): string
    {
        return $this->technicalName;
    }

    public function setTechnicalName(string $technicalName): void
    {
        $this->technicalName = $technicalName;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getTranslations(): ?ExampleTranslationCollection
    {
        return $this->translations;
    }

    public function setTranslations(ExampleTranslationCollection $translations): void
    {
        $this->translations = $translations;
    }
}
