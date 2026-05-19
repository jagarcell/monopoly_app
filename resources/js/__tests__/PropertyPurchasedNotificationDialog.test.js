import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PropertyPurchasedNotificationDialog from '@/Components/PropertyPurchasedNotificationDialog.vue';

describe('PropertyPurchasedNotificationDialog', () => {
    it('renders nothing when visible is false', () => {
        const wrapper = mount(PropertyPurchasedNotificationDialog, {
            props: {
                visible: false,
                buyerName: 'Alice',
                buyerIcon: { image_url: '/hat.svg', name: 'Hat' },
                squareName: 'Boardwalk',
                purchasePrice: 400,
            },
        });

        expect(wrapper.find('[data-testid="property-purchased-notification"]').exists()).toBe(false);
    });

    it('renders the dialog when visible is true', () => {
        const wrapper = mount(PropertyPurchasedNotificationDialog, {
            props: {
                visible: true,
                buyerName: 'Alice',
                buyerIcon: { image_url: '/hat.svg', name: 'Hat' },
                squareName: 'Boardwalk',
                purchasePrice: 400,
            },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="property-purchased-notification"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('shows the buyer token image and purchase details', () => {
        const wrapper = mount(PropertyPurchasedNotificationDialog, {
            props: {
                visible: true,
                buyerName: 'Alice',
                buyerIcon: { image_url: '/hat.svg', name: 'Hat' },
                squareName: 'Boardwalk',
                purchasePrice: 400,
            },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-testid="property-purchased-player-name"]').text()).toBe('Alice');
        expect(wrapper.find('[data-testid="property-purchased-message"]').text()).toContain('Boardwalk');
        expect(wrapper.find('[data-testid="property-purchased-message"]').text()).toContain('$400');

        const tokenImg = wrapper.find('[data-testid="property-purchased-player-icon"]');
        expect(tokenImg.element.tagName).toBe('IMG');
        expect(tokenImg.attributes('src')).toBe('/hat.svg');
        expect(tokenImg.attributes('alt')).toBe('Hat');

        wrapper.unmount();
    });

    it('emits close when the OK button is clicked', async () => {
        const wrapper = mount(PropertyPurchasedNotificationDialog, {
            props: {
                visible: true,
                buyerName: 'Alice',
                buyerIcon: { image_url: '/hat.svg', name: 'Hat' },
                squareName: 'Boardwalk',
                purchasePrice: 400,
            },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="property-purchased-ok"]').trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
        wrapper.unmount();
    });
});