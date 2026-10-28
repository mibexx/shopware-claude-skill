import SwagExamplePlugin from 'plugin/swag-example/swag-example.plugin';

describe('SwagExamplePlugin', () => {
    let button;
    let target;
    let plugin;

    beforeEach(() => {
        document.body.innerHTML = `
            <button data-swag-example aria-controls="t" aria-expanded="false"></button>
            <div id="t" data-swag-example-target></div>`;
        button = document.querySelector('[data-swag-example]');
        target = document.querySelector('[data-swag-example-target]');
        plugin = new SwagExamplePlugin(button);
    });

    afterEach(() => {
        plugin = undefined;
    });

    test('toggelt Klasse und aria-expanded beim Klick', () => {
        const spy = jest.fn();
        plugin.$emitter.subscribe('swagExampleToggle', spy);

        button.click();

        expect(target.classList.contains('is-active')).toBe(true);
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(spy).toHaveBeenCalledTimes(1);

        button.click();
        expect(target.classList.contains('is-active')).toBe(false);
        expect(button.getAttribute('aria-expanded')).toBe('false');
    });
});
