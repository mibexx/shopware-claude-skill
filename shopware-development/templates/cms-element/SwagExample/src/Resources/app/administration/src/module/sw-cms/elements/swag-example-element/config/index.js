import template from './sw-cms-el-config-swag-example-element.html.twig';

const { Mixin } = Shopware;

export default {
    template,

    emits: ['element-update'],

    mixins: [Mixin.getByName('cms-element')],

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig('swag-example-element');
        },

        onChange() {
            this.$emit('element-update', this.element);
        },
    },
};
