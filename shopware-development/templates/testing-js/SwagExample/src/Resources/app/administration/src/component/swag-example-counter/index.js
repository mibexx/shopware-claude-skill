import template from './swag-example-counter.html.twig';

/**
 * Minimale Beispiel-Komponente nur fuer das Test-Geruest (kein Teil des admin-module-Templates).
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
