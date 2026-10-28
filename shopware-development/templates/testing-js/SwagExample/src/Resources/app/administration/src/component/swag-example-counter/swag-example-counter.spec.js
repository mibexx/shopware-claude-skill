import { mount } from '@vue/test-utils';
import './index';

async function createWrapper(propsData = {}) {
    // template inheritance/Twig blocks are resolved by Shopware.Component.build
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

    it('shows the start value', async () => {
        wrapper = await createWrapper({ start: 3 });

        expect(wrapper.find('.swag-example-counter__value').text()).toBe('3');
    });

    it('increments the counter and emits change', async () => {
        wrapper = await createWrapper();

        await wrapper.find('.swag-example-counter__button').trigger('click');

        expect(wrapper.find('.swag-example-counter__value').text()).toBe('1');
        expect(wrapper.emitted('change')[0]).toEqual([1]);
    });
});
