import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import CardRevealModal from '@/Components/CardRevealModal.vue';

const chanceCard = {
    id: 3,
    action: 'collect',
    text: 'Bank pays you a dividend of $50',
    amount: 50,
    house_cost: null,
    hotel_cost: null,
    target: null,
    spaces: null,
};

const communityCard = {
    id: 7,
    action: 'go_to_jail',
    text: 'Go to Jail – Go directly to Jail, do not pass GO, do not collect $200',
    amount: null,
    house_cost: null,
    hotel_cost: null,
    target: null,
};

/**
 * CardRevealModal teleports its content to document.body.
 * Vue Test Utils' wrapper.find() only searches within the component root, so
 * we query document.body directly for any element that lives inside the teleport.
 */
function bodyFind(selector) {
    return document.body.querySelector(selector);
}

describe('CardRevealModal', () => {
    it('is not rendered when visible is false', () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: chanceCard, type: 'chance', visible: false },
            attachTo: document.body,
        });
        expect(bodyFind('[data-testid="card-text"]')).toBeNull();
        wrapper.unmount();
    });

    it('renders card text when visible is true', () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: chanceCard, type: 'chance', visible: true },
            attachTo: document.body,
        });
        expect(bodyFind('[data-testid="card-text"]').textContent).toContain('Bank pays you a dividend of $50');
        wrapper.unmount();
    });

    it('renders the CHANCE label for chance type', () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: chanceCard, type: 'chance', visible: true },
            attachTo: document.body,
        });
        expect(document.body.textContent).toContain('CHANCE');
        wrapper.unmount();
    });

    it('renders the COMMUNITY CHEST label for community type', () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: communityCard, type: 'community', visible: true },
            attachTo: document.body,
        });
        expect(document.body.textContent).toContain('COMMUNITY CHEST');
        wrapper.unmount();
    });

    it('renders amount detail when card has an amount', () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: chanceCard, type: 'chance', visible: true },
            attachTo: document.body,
        });
        expect(bodyFind('[data-testid="card-detail"]').textContent).toContain('$50');
        wrapper.unmount();
    });

    it('does not render detail when card has no supplementary info', () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: communityCard, type: 'community', visible: true },
            attachTo: document.body,
        });
        expect(bodyFind('[data-testid="card-detail"]')).toBeNull();
        wrapper.unmount();
    });

    it('emits close when the dismiss button is clicked', async () => {
        const wrapper = mount(CardRevealModal, {
            props: { card: chanceCard, type: 'chance', visible: true },
            attachTo: document.body,
        });
        bodyFind('[data-testid="dismiss-button"]').click();
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('close')).toBeTruthy();
        wrapper.unmount();
    });

    it('renders house and hotel costs for property repairs card', () => {
        const repairCard = {
            id: 11,
            action: 'property_repairs',
            text: 'Make general repairs',
            amount: null,
            house_cost: 25,
            hotel_cost: 100,
            target: null,
            spaces: null,
        };
        const wrapper = mount(CardRevealModal, {
            props: { card: repairCard, type: 'chance', visible: true },
            attachTo: document.body,
        });
        const detail = bodyFind('[data-testid="card-detail"]').textContent;
        expect(detail).toContain('$25');
        expect(detail).toContain('$100');
        wrapper.unmount();
    });
});
