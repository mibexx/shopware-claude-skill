import template from './sw-cms-el-swag-example-element.html.twig';

const { Mixin } = Shopware;

export default {
    template,

    mixins: [Mixin.getByName('cms-element')],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            // Merged defaultConfig in element.config. Name = "name" aus registerCmsElement
            this.initElementConfig('swag-example-element');
        },
    },
};
