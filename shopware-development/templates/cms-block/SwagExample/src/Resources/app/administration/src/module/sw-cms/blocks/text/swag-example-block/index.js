Shopware.Component.register('sw-cms-preview-swag-example-block', () => import('./preview'));
Shopware.Component.register('sw-cms-block-swag-example-block', () => import('./component'));

Shopware.Service('cmsService').registerCmsBlock({
    name: 'swag-example-block',
    label: 'swag-example.blocks.swagExampleBlock.label',
    category: 'text',
    component: 'sw-cms-block-swag-example-block',
    previewComponent: 'sw-cms-preview-swag-example-block',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: null,
        marginRight: null,
        sizingMode: 'boxed',
    },
    // Slot names must be identical in <slot name>, here and in block.slots.getSlot('<name>') (storefront)
    slots: {
        left: 'text',
        right: {
            type: 'image',
            default: {
                config: {
                    displayMode: { source: 'static', value: 'cover' },
                },
            },
        },
    },
});
