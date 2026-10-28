const { PluginBaseClass } = window;

/**
 * Toggelt eine CSS-Klasse am Zielelement, sobald das gebundene Element angeklickt wird.
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
        // wird aufgerufen, wenn initializePlugins() erneut auf demselben Element laeuft
    }

    destroy() {
        // Listener entfernen, falls das Plugin spaeter ueberschrieben wird
        this.el.removeEventListener('click', this._onClick);
    }

    _onClick() {
        const target = document.querySelector(this.options.targetSelector);

        if (!target) {
            return;
        }

        const isActive = target.classList.toggle(this.options.activeClass);

        // ARIA-Zustand des Buttons mitfuehren (Button hat aria-controls auf das Zielelement)
        this.el.setAttribute('aria-expanded', String(isActive));

        this.$emitter.publish('swagExampleToggle', { isActive });
    }
}
