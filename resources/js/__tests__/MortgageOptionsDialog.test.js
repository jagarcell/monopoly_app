import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import MortgageOptionsDialog from '@/Components/MortgageOptionsDialog.vue';

const properties = [
    {
        square_index: 1,
        name: 'Mediterranean Ave',
        color: '#955436',
        purchase_price: 60,
        mortgage_value: 30,
        is_mortgaged: false,
    },
    {
        square_index: 39,
        name: 'Boardwalk',
        color: '#0072bb',
        purchase_price: 400,
        mortgage_value: 200,
        is_mortgaged: true,
    },
];

describe('MortgageOptionsDialog', () => {
    const baseProps = {
        visible: true,
        properties,
        selectedSquareIndexes: [],
        currentCapital: 100,
        requiredAmount: 250,
        actionLabel: 'Pay $250',
        isLoading: false,
        isSubmitting: false,
    };

    it('does not render when hidden', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: { ...baseProps, visible: false },
        });

        expect(wrapper.find('[data-testid="mortgage-options-dialog"]').exists()).toBe(false);
    });

    it('renders a loading state', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: { ...baseProps, properties: [], isLoading: true },
        });

        expect(wrapper.find('[data-testid="mortgage-options-dialog"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mortgage-loading"]').exists()).toBe(true);
    });

    it('renders an empty state', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: { ...baseProps, properties: [], isLoading: false },
        });

        expect(wrapper.find('[data-testid="mortgage-empty"]').exists()).toBe(true);
    });

    it('renders owned properties and mortgage actions', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: baseProps,
        });

        expect(wrapper.find('[data-testid="mortgage-property-list"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="property-name-1"]').text()).toBe('Mediterranean Ave');
        expect(wrapper.find('[data-testid="mortgage-value-1"]').text()).toContain('$30');
        expect(wrapper.find('[data-testid="btn-toggle-mortgage-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mortgaged-badge-39"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="btn-toggle-mortgage-39"]').exists()).toBe(false);
    });

    it('constrains the dialog within viewport height', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: baseProps,
        });

        const panel = wrapper.find('[data-testid="mortgage-dialog-panel"]');

        expect(panel.exists()).toBe(true);
        expect(panel.classes()).toContain('max-h-[calc(100vh-2rem)]');
        expect(panel.classes()).toContain('flex');
        expect(panel.classes()).toContain('flex-col');
    });

    it('uses a vertically scrollable body for long content', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: baseProps,
        });

        const scrollBody = wrapper.find('[data-testid="mortgage-dialog-scroll-body"]');

        expect(scrollBody.exists()).toBe(true);
        expect(scrollBody.classes()).toContain('overflow-y-auto');
        expect(scrollBody.classes()).toContain('flex-1');
    });

    it('emits property toggle when a property is selected', async () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: baseProps,
        });

        await wrapper.find('[data-testid="btn-toggle-mortgage-1"]').trigger('click');

        expect(wrapper.emitted('toggle-property')).toBeTruthy();
        expect(wrapper.emitted('toggle-property')[0]).toEqual([1]);
    });

    it('shows projected capital and shortfall from selected session mortgages', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: {
                ...baseProps,
                selectedSquareIndexes: [1],
            },
        });

        expect(wrapper.find('[data-testid="mortgage-projected-capital"]').text()).toBe('$130');
        expect(wrapper.find('[data-testid="mortgage-shortfall"]').text()).toBe('$120');
    });

    it('hides payment status and required amount in operation context', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: {
                ...baseProps,
                requiredAmount: 0,
                showStatusBlock: false,
                showRequiredAmount: false,
            },
        });

        expect(wrapper.find('[data-testid="mortgage-shortfall"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="mortgage-required-amount"]').exists()).toBe(false);
    });

    it('enables submit in operation context when at least one property is selected', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: {
                ...baseProps,
                requiredAmount: 0,
                actionLabel: 'Apply Mortgages',
                selectedSquareIndexes: [1],
                showStatusBlock: false,
                showRequiredAmount: false,
            },
        });

        const submitButton = wrapper.find('[data-testid="btn-mortgage-submit-payment"]');

        expect(submitButton.attributes('disabled')).toBeUndefined();
        expect(submitButton.text()).toContain('Apply Mortgages');
    });

    it('enables submit when selected mortgages cover required payment', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: {
                ...baseProps,
                currentCapital: 100,
                requiredAmount: 130,
                selectedSquareIndexes: [1],
            },
        });

        expect(wrapper.find('[data-testid="btn-mortgage-submit-payment"]').attributes('disabled')).toBeUndefined();
    });

    it('emits submit-payment when primary action is clicked', async () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: {
                ...baseProps,
                currentCapital: 100,
                requiredAmount: 130,
                selectedSquareIndexes: [1],
            },
        });

        await wrapper.find('[data-testid="btn-mortgage-submit-payment"]').trigger('click');

        expect(wrapper.emitted('submit-payment')).toBeTruthy();
    });

    it('shows a close button under the primary action for operation requests', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: {
                ...baseProps,
                actionType: 'operation',
                actionLabel: 'Apply Mortgages',
                requiredAmount: 0,
                showStatusBlock: false,
                showRequiredAmount: false,
            },
        });

        const closeButton = wrapper.find('[data-testid="btn-mortgage-close"]');

        expect(closeButton.exists()).toBe(true);
        expect(closeButton.text()).toContain('Close');
    });

    it('renders a color bar at the top of each property card using the property color', () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: baseProps,
        });

        const colorBar1 = wrapper.find('[data-testid="property-color-bar-1"]');
        const colorBar39 = wrapper.find('[data-testid="property-color-bar-39"]');

        expect(colorBar1.exists()).toBe(true);
        expect(colorBar1.attributes('style')).toContain('#955436');
        expect(colorBar39.exists()).toBe(true);
        expect(colorBar39.attributes('style')).toContain('#0072bb');
    });

    it('does not render a color bar when property has no color', () => {
        const propertiesWithoutColor = [
            {
                square_index: 5,
                name: 'Reading Railroad',
                color: null,
                purchase_price: 200,
                mortgage_value: 100,
                is_mortgaged: false,
            },
        ];
        const wrapper = mount(MortgageOptionsDialog, {
            props: { ...baseProps, properties: propertiesWithoutColor },
        });

        expect(wrapper.find('[data-testid="property-color-bar-5"]').exists()).toBe(false);
    });

    it('emits close when Back is clicked', async () => {
        const wrapper = mount(MortgageOptionsDialog, {
            props: baseProps,
        });

        await wrapper.find('[data-testid="btn-mortgage-close"]').trigger('click');

        expect(wrapper.emitted('close')).toBeTruthy();
    });
});
