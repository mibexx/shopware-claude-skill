Shopware.Component.register('sw-cms-el-preview-swag-example-element', () => import('./preview'));
Shopware.Component.register('sw-cms-el-config-swag-example-element', () => import('./config'));
Shopware.Component.register('sw-cms-el-swag-example-element', () => import('./component'));

Shopware.Service('cmsService').registerCmsElement({
    name: 'swag-example-element',
    label: 'swag-example.elements.swagExampleElement.label',
    component: 'sw-cms-el-swag-example-element',
    configComponent: 'sw-cms-el-config-swag-example-element',
    previewComponent: 'sw-cms-el-preview-swag-example-element',
    defaultConfig: {
        headline: {
            source: 'static',
            value: '',
        },
        content: {
            source: 'static',
            value: '<p>Lorem ipsum dolor sit amet</p>',
        },
    },
});
