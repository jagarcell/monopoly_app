import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PlayerHandCard from '@/Components/PlayerHandCard.vue';

const player = {
    user_id: 42,
    name: 'Alice',
    is_creator: true,
    icon: { id: 1, name: 'Top Hat', image_url: '/images/icons/top-hat.svg' },
    properties: [],
    chance_cards: [],
    community_chest_cards: [],
};

describe('PlayerHandCard', () => {
    it('renders the player name', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.text()).toContain('Alice');
    });

    it('renders the creator badge when is_creator is true', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.text()).toContain('Creator');
    });

    it('does not render the creator badge when is_creator is false', () => {
        const nonCreator = { ...player, is_creator: false };
        const wrapper = mount(PlayerHandCard, { props: { player: nonCreator } });
        expect(wrapper.text()).not.toContain('Creator');
    });

    it('renders the player icon image with correct src and alt', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('/images/icons/top-hat.svg');
        expect(img.attributes('alt')).toBe('Top Hat');
    });

    it('renders a fallback placeholder div when image_url is empty', () => {
        const noIcon = { ...player, icon: { id: 1, name: 'Hat', image_url: '' } };
        const wrapper = mount(PlayerHandCard, { props: { player: noIcon } });
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
    });

    it('renders the Properties section label', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.text()).toContain('Properties');
    });

    it('renders the Chance section label', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.text()).toContain('Chance');
    });

    it('renders the Community section label', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.text()).toContain('Community');
    });

    it('has data-testid player-hand-card on the root element', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.find('[data-testid="player-hand-card"]').exists()).toBe(true);
    });

    it('applies the subtle golden background classes', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        const card = wrapper.find('[data-testid="player-hand-card"]');
        expect(card.classes()).toContain('bg-amber-50');
        expect(card.classes()).toContain('border-amber-300');
    });

    it('uses aria-label containing the player name', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        const card = wrapper.find('[data-testid="player-hand-card"]');
        expect(card.attributes('aria-label')).toContain('Alice');
    });
});
