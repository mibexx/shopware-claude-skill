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
            // Merges defaultConfig into element.config. Name = "name" from registerCmsElement
            this.initElementConfig('swag-example-element');
        },
    },
};
