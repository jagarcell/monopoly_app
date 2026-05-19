import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import RentNotificationDialog from '@/Components/RentNotificationDialog.vue';

describe('RentNotificationDialog', () => {
    // ── Visibility ────────────────────────────────────────────────────────────

    it('renders nothing when visible is false', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: false, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
        });

        expect(wrapper.find('[data-testid="rent-notification-dialog"]').exists()).toBe(false);
    });

    it('renders the dialog when visible is true', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-notification-dialog"]').exists()).toBe(true);
        wrapper.unmount();
    });

    // ── Content ───────────────────────────────────────────────────────────────

    it('shows the payer name', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-payer-name"]').text()).toBe('Alice');
        wrapper.unmount();
    });

    it('shows the owner name', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-owner-name"]').text()).toBe('Bob');
        wrapper.unmount();
    });

    it('shows the rent amount', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-amount"]').text()).toBe('$50');
        wrapper.unmount();
    });

    it('shows the square name', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-square-name"]').text()).toContain('Boardwalk');
        wrapper.unmount();
    });

    it('hides the square name section when squareName is empty', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: '' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-square-name"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('shows payer and owner token images when icons are provided', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                payerIcon: { image_url: '/hat.svg', name: 'Hat' },
                ownerName: 'Bob',
                ownerIcon: { image_url: '/car.svg', name: 'Car' },
                rentAmount: 50,
                squareName: 'Boardwalk',
            },
            attachTo: document.body,
        });

        const payerIcon = wrapper.find('[data-testid="rent-payer-icon"]');
        const ownerIcon = wrapper.find('[data-testid="rent-owner-icon"]');

        expect(payerIcon.element.tagName).toBe('IMG');
        expect(payerIcon.attributes('src')).toBe('/hat.svg');
        expect(payerIcon.attributes('alt')).toBe('Hat');
        expect(ownerIcon.element.tagName).toBe('IMG');
        expect(ownerIcon.attributes('src')).toBe('/car.svg');
        expect(ownerIcon.attributes('alt')).toBe('Car');

        wrapper.unmount();
    });

    it('shows neutral icon placeholders when token icons are missing', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                payerIcon: null,
                ownerName: 'Bob',
                ownerIcon: null,
                rentAmount: 50,
                squareName: 'Boardwalk',
            },
            attachTo: document.body,
        });

        const payerIcon = wrapper.find('[data-testid="rent-payer-icon"]');
        const ownerIcon = wrapper.find('[data-testid="rent-owner-icon"]');

        expect(payerIcon.element.tagName).toBe('DIV');
        expect(ownerIcon.element.tagName).toBe('DIV');

        wrapper.unmount();
    });

    // ── OK button ─────────────────────────────────────────────────────────────

    it('emits close when the OK button is clicked', async () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="rent-notification-ok"]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });

    it('renders the OK button', () => {
        const wrapper = mount(RentNotificationDialog, {
            props: { visible: true, payerName: 'Alice', ownerName: 'Bob', rentAmount: 50, squareName: 'Boardwalk' },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="rent-notification-ok"]').exists()).toBe(true);
        wrapper.unmount();
    });
});
