const { PluginBaseClass } = window;

/**
 * Toggles a CSS class on the target element as soon as the bound element is clicked.
 * Markup: <button data-swag-example data-swag-example-options="{&quot;activeClass&quot;: &quot;is-open&quot;}"
 *         aria-controls="swag-example-target" aria-expanded="false">
 */
export default class SwagExamplePlugin extends PluginBaseClass {
    static options = {
        targetSelector: '[data-swag-example-target]',
        activeClass: 'is-active',
    };

    init() {
        this._onClick = this._onClick.bind(this);
        this.el.addEventListener('click', this._onClick);

        this.$emitter.publish('swagExampleInit');
    }

    update() {
        // called when initializePlugins() runs again on the same element
    }

    destroy() {
        // remove listeners in case the plugin is overridden later
        this.el.removeEventListener('click', this._onClick);
    }

    _onClick() {
        const target = document.querySelector(this.options.targetSelector);

        if (!target) {
            return;
        }

        const isActive = target.classList.toggle(this.options.activeClass);

        // keep the ARIA state of the button in sync (the button has aria-controls pointing to the target element)
        this.el.setAttribute('aria-expanded', String(isActive));

        this.$emitter.publish('swagExampleToggle', { isActive });
    }
}
