import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import UnmortgageCapitalShortfallDialog from '@/Components/UnmortgageCapitalShortfallDialog.vue';

describe('UnmortgageCapitalShortfallDialog', () => {
    it('renders when visible', () => {
        const wrapper = mount(UnmortgageCapitalShortfallDialog, {
            props: {
                visible: true,
                requiredAmount: 220,
            },
        });

        expect(wrapper.find('[data-testid="unmortgage-shortfall-dialog"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Not Enough Capital');
    });

    it('shows required amount', () => {
        const wrapper = mount(UnmortgageCapitalShortfallDialog, {
            props: {
                visible: true,
                requiredAmount: 220,
            },
        });

        expect(wrapper.find('[data-testid="unmortgage-shortfall-required-amount"]').text()).toBe('$220');
    });

    it('emits back when BACK is clicked', async () => {
        const wrapper = mount(UnmortgageCapitalShortfallDialog, {
            props: {
                visible: true,
            },
        });

        await wrapper.find('[data-testid="unmortgage-shortfall-back"]').trigger('click');

        expect(wrapper.emitted('back')).toHaveLength(1);
    });

    it('emits mortgage-others when Mortgage Others is clicked', async () => {
        const wrapper = mount(UnmortgageCapitalShortfallDialog, {
            props: {
                visible: true,
            },
        });

        await wrapper.find('[data-testid="unmortgage-shortfall-mortgage-others"]').trigger('click');

        expect(wrapper.emitted('mortgage-others')).toHaveLength(1);
    });
});
