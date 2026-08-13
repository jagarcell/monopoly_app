import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import PlayerHandCard from '@/Components/PlayerHandCard.vue';

const player = {
    user_id: 42,
    invitation_id: null,
    name: 'Alice',
    is_creator: true,
    capital: 1500,
    icon: { id: 1, name: 'Top Hat', image_url: '/images/icons/top-hat.svg' },
    properties: [],
    chance_cards: [],
    community_chest_cards: [],
};

describe('PlayerHandCard', () => {
    it('scrolls into view when expanded by mouse hover', async () => {
        const scrollIntoViewSpy = vi.fn();
        const originalScrollIntoView = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = scrollIntoViewSpy;

        const wrapper = mount(PlayerHandCard, { props: { player } });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        await card.trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();

        expect(scrollIntoViewSpy).toHaveBeenCalledWith({
            behavior: 'smooth',
            block: 'nearest',
            inline: 'nearest',
        });

        Element.prototype.scrollIntoView = originalScrollIntoView;
    });

    it('does not scroll into view for non-touch pointerdown without expansion', async () => {
        const scrollIntoViewSpy = vi.fn();
        const originalScrollIntoView = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = scrollIntoViewSpy;

        const wrapper = mount(PlayerHandCard, { props: { player } });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        await card.trigger('pointerdown', { pointerType: 'mouse' });
        await nextTick();

        expect(scrollIntoViewSpy).not.toHaveBeenCalled();

        Element.prototype.scrollIntoView = originalScrollIntoView;
    });

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

    it('renders a placeholder dash when the player has no properties', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.find('[data-testid="properties-empty"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="property-tag"]')).toHaveLength(0);
    });

    it('renders property tags when the player owns properties', () => {
        const withProperties = {
            ...player,
            properties: [
                { square_index: 1, name: 'Mediterranean Ave', color: '#955436' },
                { square_index: 39, name: 'Boardwalk', color: '#0072bb' },
            ],
        };
        const wrapper = mount(PlayerHandCard, { props: { player: withProperties } });

        const tags = wrapper.findAll('[data-testid="property-tag"]');
        expect(tags).toHaveLength(2);
        expect(wrapper.find('[data-testid="properties-empty"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Mediterranean Ave');
        expect(wrapper.text()).toContain('Boardwalk');
    });

    it('renders a placeholder dash when the player has no chance cards', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });

        expect(wrapper.find('[data-testid="chance-cards-empty"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="chance-card-tag"]')).toHaveLength(0);
    });

    it('renders held chance card tags when the player has chance cards', () => {
        const withChanceCards = {
            ...player,
            chance_cards: [
                { id: 8, action: 'get_out_of_jail_free', text: 'Get Out of Jail Free – This card may be kept until needed' },
            ],
        };

        const wrapper = mount(PlayerHandCard, { props: { player: withChanceCards } });

        expect(wrapper.find('[data-testid="chance-cards-empty"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="chance-card-tag"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('Get Out of Jail Free');
    });

    it('renders a placeholder dash when the player has no community chest cards', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });

        expect(wrapper.find('[data-testid="community-cards-empty"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-testid="community-card-tag"]')).toHaveLength(0);
    });

    it('renders held community card tags when the player has community chest cards', () => {
        const withCommunityCards = {
            ...player,
            community_chest_cards: [
                { id: 5, action: 'get_out_of_jail_free', text: 'Get Out of Jail Free – This card may be kept until needed' },
            ],
        };

        const wrapper = mount(PlayerHandCard, { props: { player: withCommunityCards } });

        expect(wrapper.find('[data-testid="community-cards-empty"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-testid="community-card-tag"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('Get Out of Jail Free');
    });

    it('renders a stacked and scrollable properties list sized for two visible tags', () => {
        const withManyProperties = {
            ...player,
            properties: [
                { square_index: 1, name: 'Mediterranean Ave', color: '#955436' },
                { square_index: 3, name: 'Baltic Ave', color: '#955436' },
                { square_index: 6, name: 'Oriental Ave', color: '#aae0fa' },
            ],
        };
        const wrapper = mount(PlayerHandCard, { props: { player: withManyProperties } });
        const propertiesList = wrapper.find('[data-testid="properties-list"]');

        expect(propertiesList.classes()).toContain('flex-col');
        expect(propertiesList.classes()).toContain('h-8');
        expect(propertiesList.classes()).toContain('overflow-y-auto');
    });

    it('expands on mouse hover and removes properties scroll clipping', async () => {
        const withManyProperties = {
            ...player,
            properties: [
                { square_index: 1, name: 'Mediterranean Ave', color: '#955436' },
                { square_index: 3, name: 'Baltic Ave', color: '#955436' },
                { square_index: 6, name: 'Oriental Ave', color: '#aae0fa' },
            ],
        };
        const wrapper = mount(PlayerHandCard, { props: { player: withManyProperties } });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        await card.trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();

        const propertiesList = wrapper.find('[data-testid="properties-list"]');
        expect(card.attributes('data-expanded')).toBe('true');
        expect(propertiesList.classes()).toContain('h-auto');
        expect(propertiesList.classes()).toContain('overflow-visible');
        expect(propertiesList.classes()).not.toContain('h-8');
        expect(propertiesList.classes()).not.toContain('overflow-y-auto');

        await card.trigger('pointerleave', { pointerType: 'mouse' });
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('false');
    });

    it('toggles expansion on single tap and collapses on outside tap', async () => {
        const wrapper = mount(PlayerHandCard, { props: { player }, attachTo: document.body });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        await card.trigger('pointerdown', { pointerType: 'touch' });
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('true');

        await card.trigger('pointerdown', { pointerType: 'touch' });
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('false');

        await card.trigger('pointerdown', { pointerType: 'touch' });
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('true');

        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('false');

        wrapper.unmount();
    });

    it('emits expanded-change with join_order on expand and collapse', async () => {
        const withJoinOrder = { ...player, join_order: 2 };
        const wrapper = mount(PlayerHandCard, { props: { player: withJoinOrder } });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        await card.trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();

        await card.trigger('pointerleave', { pointerType: 'mouse' });
        await nextTick();

        const emitted = wrapper.emitted('expanded-change') ?? [];
        expect(emitted.length).toBeGreaterThanOrEqual(2);
        expect(emitted[0]).toEqual([{ joinOrder: 2, expanded: true }]);
        expect(emitted[1]).toEqual([{ joinOrder: 2, expanded: false }]);
    });

    it('does not expand a bankrupt player card on hover or touch', async () => {
        const bankruptPlayer = { ...player, is_bankrupt: true, join_order: 7 };
        const wrapper = mount(PlayerHandCard, { props: { player: bankruptPlayer } });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        await card.trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('false');

        await card.trigger('pointerdown', { pointerType: 'touch' });
        await nextTick();
        expect(card.attributes('data-expanded')).toBe('false');
        expect(wrapper.emitted('expanded-change')).toBeUndefined();
    });

    it('prevents default touch selection behavior during single-tap interaction', async () => {
        const wrapper = mount(PlayerHandCard, { props: { player }, attachTo: document.body });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        const pointerDownEvent = new Event('pointerdown', { bubbles: true, cancelable: true });
        Object.defineProperty(pointerDownEvent, 'pointerType', { value: 'touch' });
        const pointerDownNotCancelled = card.element.dispatchEvent(pointerDownEvent);
        expect(pointerDownNotCancelled).toBe(false);

        await nextTick();
        expect(card.attributes('data-expanded')).toBe('true');

        const selectStartEvent = new Event('selectstart', { bubbles: true, cancelable: true });
        const selectStartNotCancelled = card.element.dispatchEvent(selectStartEvent);
        expect(selectStartNotCancelled).toBe(false);

        wrapper.unmount();
    });

    it('sets panel anchor metadata for inward expansion origin', () => {
        const wrapper = mount(PlayerHandCard, { props: { player, panelAnchor: 'end' } });
        const card = wrapper.find('[data-testid="player-hand-card"]');
        expect(card.attributes('data-panel-anchor')).toBe('end');
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

    it('uses fixed card dimensions', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        const card = wrapper.find('[data-testid="player-hand-card"]');

        expect(card.classes()).toContain('w-40');
        expect(card.classes()).toContain('h-32');
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

    // ── capital section ──────────────────────────────────────────────────────

    it('does not show the capital section by default (isCurrentPlayer = false)', () => {
        const wrapper = mount(PlayerHandCard, { props: { player } });
        expect(wrapper.find('[data-testid="capital-section"]').exists()).toBe(false);
    });

    it('shows the capital section when isCurrentPlayer is true', () => {
        const wrapper = mount(PlayerHandCard, {
            props: { player, isCurrentPlayer: true },
        });
        expect(wrapper.find('[data-testid="capital-section"]').exists()).toBe(true);
    });

    it('displays the correct capital amount when isCurrentPlayer is true', () => {
        const wrapper = mount(PlayerHandCard, {
            props: { player: { ...player, capital: 1500 }, isCurrentPlayer: true },
        });
        expect(wrapper.find('[data-testid="capital-amount"]').text()).toContain('1,500');
    });

    it('displays an updated capital amount when capital changes', () => {
        const wrapper = mount(PlayerHandCard, {
            props: { player: { ...player, capital: 800 }, isCurrentPlayer: true },
        });
        expect(wrapper.find('[data-testid="capital-amount"]').text()).toContain('800');
    });

    it('does not show capital for other players even when they have capital data', () => {
        const otherPlayer = { ...player, user_id: 99, name: 'Bob', is_creator: false };
        const wrapper = mount(PlayerHandCard, {
            props: { player: otherPlayer, isCurrentPlayer: false },
        });
        expect(wrapper.find('[data-testid="capital-section"]').exists()).toBe(false);
    });

    it('does not render the re-invite button while the card is collapsed', () => {
        const wrapper = mount(PlayerHandCard, {
            props: { player: { ...player, is_creator: false, invitation_id: 7 }, canReinvite: true },
        });

        expect(wrapper.find('[data-testid="reinvite-button"]').exists()).toBe(false);
    });

    it('renders the re-invite button when canReinvite is true and the card is expanded', async () => {
        const wrapper = mount(PlayerHandCard, {
            props: { player: { ...player, is_creator: false, invitation_id: 7 }, canReinvite: true },
        });

        await wrapper.find('[data-testid="player-hand-card"]').trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();

        expect(wrapper.find('[data-testid="reinvite-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="reinvite-button"]').text()).toContain('Re-Invite');
    });

    it('does not render the re-invite button when canReinvite is false', () => {
        const wrapper = mount(PlayerHandCard, {
            props: { player: { ...player, is_creator: false, invitation_id: 7 }, canReinvite: false },
        });

        expect(wrapper.find('[data-testid="reinvite-button"]').exists()).toBe(false);
    });

    it('emits reinvite when the re-invite button is clicked', async () => {
        const invitedPlayer = { ...player, is_creator: false, invitation_id: 7 };
        const wrapper = mount(PlayerHandCard, {
            props: { player: invitedPlayer, canReinvite: true },
        });

        await wrapper.find('[data-testid="player-hand-card"]').trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();
        await wrapper.find('[data-testid="reinvite-button"]').trigger('click');

        expect(wrapper.emitted('reinvite')).toEqual([[invitedPlayer]]);
    });

    it('does not render the re-invite button while collapsed even when a request is in flight', () => {
        const wrapper = mount(PlayerHandCard, {
            props: {
                player: { ...player, is_creator: false, invitation_id: 7 },
                canReinvite: true,
                isReinviting: true,
            },
        });

        expect(wrapper.find('[data-testid="reinvite-button"]').exists()).toBe(false);
    });

    it('disables the re-invite button while a request is in flight after expansion', async () => {
        const wrapper = mount(PlayerHandCard, {
            props: {
                player: { ...player, is_creator: false, invitation_id: 7 },
                canReinvite: true,
                isReinviting: true,
            },
        });

        await wrapper.find('[data-testid="player-hand-card"]').trigger('pointerenter', { pointerType: 'mouse' });
        await nextTick();

        const button = wrapper.find('[data-testid="reinvite-button"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.text()).toContain('Sending...');
    });
});
