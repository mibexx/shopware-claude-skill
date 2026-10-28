import { mount } from '@vue/test-utils';
import './index';

async function createWrapper(propsData = {}) {
    // Template-Vererbung/Twig-Bloecke werden von Shopware.Component.build aufgeloest
    return mount(await Shopware.Component.build('swag-example-counter'), {
        props: propsData,
        global: {
            stubs: {},
            mocks: {},
        },
    });
}

describe('src/component/swag-example-counter', () => {
    let wrapper;

    afterEach(() => {
        wrapper?.unmount();
    });

    it('zeigt den Startwert', async () => {
        wrapper = await createWrapper({ start: 3 });

        expect(wrapper.find('.swag-example-counter__value').text()).toBe('3');
    });

    it('erhoeht den Zaehler und emittiert change', async () => {
        wrapper = await createWrapper();

        await wrapper.find('.swag-example-counter__button').trigger('click');

        expect(wrapper.find('.swag-example-counter__value').text()).toBe('1');
        expect(wrapper.emitted('change')[0]).toEqual([1]);
    });
});
