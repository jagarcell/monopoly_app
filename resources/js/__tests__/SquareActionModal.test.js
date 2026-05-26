import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SquareActionModal from '@/Components/SquareActionModal.vue';

const purchaseAction = {
    type: 'purchase',
    square_name: 'Boardwalk',
    price: 400,
    rent: 50,
    owner_join_order: null,
    owner_name: null,
};

const rentAction = {
    type: 'rent',
    square_name: 'Boardwalk',
    price: null,
    rent: 50,
    owner_join_order: 2,
    owner_name: 'Alice',
    payer_icon: { image_url: '/hat.svg', name: 'Hat' },
    owner_icon: { image_url: '/car.svg', name: 'Car' },
};

describe('SquareActionModal', () => {
    // ── Visibility ────────────────────────────────────────────────────────────

    it('renders nothing when visible is false', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: false, squareAction: purchaseAction },
        });
        expect(wrapper.find('[data-testid="square-action-modal"]').exists()).toBe(false);
    });

    it('renders nothing when squareAction is null', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: null },
        });
        expect(wrapper.find('[data-testid="square-action-modal"]').exists()).toBe(false);
    });

    it('renders the modal when visible and squareAction are set', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        expect(wrapper.find('[data-testid="square-action-modal"]').exists()).toBe(true);
    });

    it('hides the mortgage options button by default', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });

        expect(wrapper.find('[data-testid="btn-mortgage-options"]').exists()).toBe(false);
    });

    // ── Purchase dialog ───────────────────────────────────────────────────────

    it('shows the square name for a purchase action', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        expect(wrapper.find('[data-testid="square-name"]').text()).toBe('Boardwalk');
    });

    it('shows the purchase price', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        expect(wrapper.find('[data-testid="purchase-price"]').text()).toContain('400');
    });

    it('renders Mortgage options, Buy, and Skip buttons for a purchase action', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction, showMortgageOptionsButton: true },
        });
        expect(wrapper.find('[data-testid="btn-mortgage-options"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="btn-buy"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="btn-skip"]').exists()).toBe(true);
    });

    it('does not render the Pay button for a purchase action', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        expect(wrapper.find('[data-testid="btn-pay"]').exists()).toBe(false);
    });

    it('emits purchase when Buy button is clicked', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        await wrapper.find('[data-testid="btn-buy"]').trigger('click');
        expect(wrapper.emitted('purchase')).toBeTruthy();
    });

    it('emits skip when Skip button is clicked', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        await wrapper.find('[data-testid="btn-skip"]').trigger('click');
        expect(wrapper.emitted('skip')).toBeTruthy();
    });

    it('emits mortgage-options when Mortgage options button is clicked on purchase', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction, showMortgageOptionsButton: true },
        });
        await wrapper.find('[data-testid="btn-mortgage-options"]').trigger('click');
        expect(wrapper.emitted('mortgage-options')).toBeTruthy();
    });

    // ── Rent dialog ───────────────────────────────────────────────────────────

    it('shows the square name for a rent action', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction },
        });
        expect(wrapper.find('[data-testid="square-name"]').text()).toBe('Boardwalk');
    });

    it('shows the owner name in the rent dialog', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction },
        });
        expect(wrapper.find('[data-testid="owner-name"]').text()).toBe('Alice');
    });

    it('shows payer and owner token images in the rent dialog when provided', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction },
        });

        const payerIcon = wrapper.find('[data-testid="rent-due-payer-icon"]');
        const ownerIcon = wrapper.find('[data-testid="rent-due-owner-icon"]');

        expect(payerIcon.element.tagName).toBe('IMG');
        expect(payerIcon.attributes('src')).toBe('/hat.svg');
        expect(payerIcon.attributes('alt')).toBe('Hat');
        expect(ownerIcon.element.tagName).toBe('IMG');
        expect(ownerIcon.attributes('src')).toBe('/car.svg');
        expect(ownerIcon.attributes('alt')).toBe('Car');
    });

    it('shows neutral token placeholders in the rent dialog when icons are missing', () => {
        const wrapper = mount(SquareActionModal, {
            props: {
                visible: true,
                squareAction: {
                    ...rentAction,
                    payer_icon: null,
                    owner_icon: null,
                },
            },
        });

        const payerIcon = wrapper.find('[data-testid="rent-due-payer-icon"]');
        const ownerIcon = wrapper.find('[data-testid="rent-due-owner-icon"]');

        expect(payerIcon.element.tagName).toBe('DIV');
        expect(ownerIcon.element.tagName).toBe('DIV');
    });

    it('shows the rent amount', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction },
        });
        expect(wrapper.find('[data-testid="rent-amount"]').text()).toContain('50');
    });

    it('renders Mortgage options and Pay buttons for a rent action', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction, showMortgageOptionsButton: true },
        });
        expect(wrapper.find('[data-testid="btn-mortgage-options"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="btn-pay"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="btn-buy"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="btn-skip"]').exists()).toBe(false);
    });

    it('emits pay when Pay button is clicked', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction },
        });
        await wrapper.find('[data-testid="btn-pay"]').trigger('click');
        expect(wrapper.emitted('pay')).toBeTruthy();
    });

    it('emits mortgage-options when Mortgage options button is clicked on rent', async () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction, showMortgageOptionsButton: true },
        });
        await wrapper.find('[data-testid="btn-mortgage-options"]').trigger('click');
        expect(wrapper.emitted('mortgage-options')).toBeTruthy();
    });

    it('pay button label includes the rent amount', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: rentAction },
        });
        expect(wrapper.find('[data-testid="btn-pay"]').text()).toContain('50');
    });

    it('buy button label includes the price', () => {
        const wrapper = mount(SquareActionModal, {
            props: { visible: true, squareAction: purchaseAction },
        });
        expect(wrapper.find('[data-testid="btn-buy"]').text()).toContain('400');
    });
});
