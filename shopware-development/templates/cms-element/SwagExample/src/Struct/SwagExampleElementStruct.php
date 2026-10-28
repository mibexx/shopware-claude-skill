<?php declare(strict_types=1);

namespace SwagExample\Struct;

use Shopware\Core\Framework\Struct\Struct;

class SwagExampleElementStruct extends Struct
{
    protected ?string $headline = null;

    protected ?string $content = null;

    public function getHeadline(): ?string
    {
        return $this->headline;
    }

    public function setHeadline(?string $headline): void
    {
        $this->headline = $headline;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function getApiAlias(): string
    {
        return 'cms_swag_example_element';
    }
}
