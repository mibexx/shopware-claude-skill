<?php declare(strict_types=1);

namespace SwagExample\DataResolver;

use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\EntityResolverContext;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Util\HtmlSanitizer;
use SwagExample\Struct\SwagExampleElementStruct;

class SwagExampleElementCmsElementResolver extends AbstractCmsElementResolver
{
    public function __construct(private readonly HtmlSanitizer $sanitizer)
    {
    }

    /**
     * Must match the "name" in registerCmsElement() and the suffix of the storefront template.
     */
    public function getType(): string
    {
        return 'swag-example-element';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        // No entity data needed. For data: new CriteriaCollection(), $collection->add($key, Definition::class, $criteria),
        // the key must be unique across all slots (e.g. suffix it with $slot->getUniqueIdentifier()).
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        // Always set a struct, even if there is no data
        $struct = new SwagExampleElementStruct();
        $slot->setData($struct);

        $headlineConfig = $slot->getFieldConfig()->get('headline');
        if ($headlineConfig !== null && $headlineConfig->isStatic()) {
            $struct->setHeadline($headlineConfig->getStringValue());
        }

        $contentConfig = $slot->getFieldConfig()->get('content');
        if ($contentConfig === null) {
            return;
        }

        $content = null;

        // Mapped configuration (source: mapped) is resolved via the entity of the context
        if ($contentConfig->isMapped() && $resolverContext instanceof EntityResolverContext) {
            $content = $this->resolveEntityValueToString(
                $resolverContext->getEntity(),
                $contentConfig->getStringValue(),
                $resolverContext
            );
        }

        if ($contentConfig->isStatic()) {
            $content = $contentConfig->getStringValue();
        }

        if ($content !== null) {
            $struct->setContent($this->sanitizer->sanitize($content));
        }
    }
}
