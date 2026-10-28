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
     * Muss dem "name" in registerCmsElement() und dem Suffix des Storefront-Templates entsprechen.
     */
    public function getType(): string
    {
        return 'swag-example-element';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        // Keine Entity-Daten noetig. Fuer Daten: new CriteriaCollection(), $collection->add($key, Definition::class, $criteria),
        // der Key muss ueber alle Slots eindeutig sein (z. B. mit $slot->getUniqueIdentifier() suffixen).
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        // Immer ein Struct setzen, auch wenn keine Daten vorliegen
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

        // Gemappte Konfiguration (source: mapped) wird ueber die Entity des Kontexts aufgeloest
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
