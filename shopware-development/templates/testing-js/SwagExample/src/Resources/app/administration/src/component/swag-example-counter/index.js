import template from './swag-example-counter.html.twig';

/**
 * Minimal example component only for the test scaffold (not part of the admin-module template).
 */
Shopware.Component.register('swag-example-counter', {
    template,

    props: {
        start: {
            type: Number,
            required: false,
            default: 0,
        },
    },

    data() {
        return {
            count: this.start,
        };
    },

    methods: {
        onIncrement() {
            this.count += 1;
            this.$emit('change', this.count);
        },
    },
});
