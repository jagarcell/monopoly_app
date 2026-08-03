import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SquareActionModal from '@/Components/SquareActionModal.vue';

const taxAction = {
    type: 'tax',
    square_name: 'Income Tax',
    tax_kind: 'income',
    options: { flat: 200, percent: 10 },
};

describe('SquareActionModal — Income Tax', () => {
    it('renders tax buttons when action type is tax', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: taxAction },
        });

        expect(wrapper.find('[data-testid="btn-pay-flat"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="btn-pay-percent"]').exists()).toBe(true);
    });

    it('emits tax-choice with flat when flat button clicked', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: taxAction },
        });

        await wrapper.find('[data-testid="btn-pay-flat"]').trigger('click');
        expect(wrapper.emitted('tax-choice')).toBeTruthy();
        const emitted = wrapper.emitted('tax-choice')[0][0];
        expect(emitted.choice).toBe('flat');
        expect(emitted.amount).toBe(200);
    });

    it('emits tax-choice with percent when percent button clicked', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: taxAction },
        });

        await wrapper.find('[data-testid="btn-pay-percent"]').trigger('click');
        expect(wrapper.emitted('tax-choice')).toBeTruthy();
        const emitted = wrapper.emitted('tax-choice')[0][0];
        expect(emitted.choice).toBe('percent');
        expect(emitted.percent).toBe(10);
    });
});
