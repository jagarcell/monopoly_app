import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CardDrawnNotification from '@/Components/CardDrawnNotification.vue';

const defaultCard = {
    id: 1,
    action: 'collect',
    text: 'Bank pays you dividend of $50',
    amount: 50,
};
const defaultIcon = { id: 2, name: 'Car', image_url: '/images/car.svg' };

describe('CardDrawnNotification', () => {
    it('is hidden when visible is false', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: false, playerName: 'Alice', type: 'chance', card: defaultCard, playerIcon: defaultIcon },
        });
        expect(wrapper.find('[data-testid="card-drawn-notification"]').exists()).toBe(false);
    });

    it('is shown when visible is true', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Alice', type: 'chance', card: defaultCard, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('displays the player name', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Bob', type: 'chance', card: defaultCard, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-player-name"]').text()).toBe('Bob');
        wrapper.unmount();
    });

    it('shows "Chance" label for chance type', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Alice', type: 'chance', card: defaultCard, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification-title"]').text()).toBe('Chance');
        wrapper.unmount();
    });

    it('shows "Community Chest" label for community type', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Carol', type: 'community', card: defaultCard, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification-title"]').text()).toBe('Community Chest');
        wrapper.unmount();
    });

    it('emits close when the OK button is clicked', async () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Alice', type: 'chance', card: defaultCard, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        await wrapper.find('[data-testid="card-drawn-notification-ok"]').trigger('click');
        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('uses the default player name when playerName is omitted', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, type: 'community', card: defaultCard },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-player-name"]').text()).toBe('Player');
        wrapper.unmount();
    });

    it('displays the card text when a card is provided', () => {
        const card = { id: 5, action: 'pay', text: 'Pay each player $50', amount: null };
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Dave', type: 'chance', card },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification-card-text"]').text()).toBe('Pay each player $50');
        wrapper.unmount();
    });

    it('displays the card detail amount when card has an amount', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Eve', type: 'chance', card: defaultCard },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification-card-detail"]').text()).toBe('$50');
        wrapper.unmount();
    });

    it('does not show card detail when card has no supplementary field', () => {
        const card = { id: 9, action: 'go_to', text: 'Advance to Boardwalk', amount: null, spaces: null, target: null, house_cost: null };
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Frank', type: 'chance', card },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification-card-detail"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('does not show card text section when card prop is null', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Grace', type: 'community', card: null },
            attachTo: document.body,
        });
        expect(wrapper.find('[data-testid="card-drawn-notification-card-text"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('shows the player token image when playerIcon is provided', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Hank', type: 'chance', card: defaultCard, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        const img = wrapper.find('[data-testid="card-drawn-notification-player-icon"]');
        expect(img.element.tagName).toBe('IMG');
        expect(img.attributes('src')).toBe('/images/car.svg');
        expect(img.attributes('alt')).toBe('Car');
        wrapper.unmount();
    });

    it('shows a placeholder when playerIcon is null', () => {
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Ivy', type: 'community', card: defaultCard, playerIcon: null },
            attachTo: document.body,
        });
        const placeholder = wrapper.find('[data-testid="card-drawn-notification-player-icon"]');
        expect(placeholder.element.tagName).toBe('DIV');
        wrapper.unmount();
    });

    it('shows total due when card.required_amount is present', () => {
        const card = { id: 12, action: 'property_repairs', text: 'Make general repairs', house_cost: 40, hotel_cost: 115, required_amount: 260 };
        const wrapper = mount(CardDrawnNotification, {
            props: { visible: true, playerName: 'Jake', type: 'community', card, playerIcon: defaultIcon },
            attachTo: document.body,
        });
        const total = wrapper.find('[data-testid="card-drawn-notification-total-due"]');
        expect(total.exists()).toBe(true);
        expect(total.text()).toContain('Total due: $260');
        wrapper.unmount();
    });
});
