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
