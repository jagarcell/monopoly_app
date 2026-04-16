import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import MonopolyBoard from '@/Components/MonopolyBoard.vue';

const game = {
    id: 1,
    name: 'Game #1',
    user_id: 42,
    status: 'in_progress',
};

describe('MonopolyBoard', () => {
    beforeEach(() => {
        // Reset any global axios mock between tests.
        window.axios = undefined;
    });

    it('renders the game name in the header', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Game #1');
    });

    it('renders the MONOPOLY wordmark', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('MONOPOLY');
    });

    it('renders the GO corner square', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('GO');
    });

    it('renders the Jail corner square', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Jail');
    });

    it('renders the Free Parking corner square', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Free Parking');
    });

    it('renders the Go To Jail corner square', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Go To Jail');
    });

    it('renders Boardwalk', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Boardwalk');
    });

    it('renders Park Place', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Park Place');
    });

    it('renders Baltic Ave', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Baltic Ave');
    });

    it('renders the Community Chest deck label', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('COMMUNITY');
    });

    it('renders the Chance deck label', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('CHANCE');
    });

    it('renders all four railroads', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        const text = wrapper.text();
        expect(text).toContain('Reading Railroad');
        expect(text).toContain('Pennsylvania Railroad');
        expect(text).toContain('B&O Railroad');
        expect(text).toContain('Short Line Railroad');
    });

    it('renders as a fixed full-screen overlay', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        const root = wrapper.find('[aria-label="Monopoly board"]');
        expect(root.exists()).toBe(true);
        expect(root.classes()).toContain('fixed');
    });

    it('renders a clickable Community Chest deck button', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        const btn = wrapper.find('[data-testid="community-deck"]');
        expect(btn.exists()).toBe(true);
        expect(btn.element.tagName).toBe('BUTTON');
    });

    it('renders a clickable Chance deck button', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        const btn = wrapper.find('[data-testid="chance-deck"]');
        expect(btn.exists()).toBe(true);
        expect(btn.element.tagName).toBe('BUTTON');
    });

    it('calls the chance draw API when the Chance deck is clicked', async () => {
        const card = { id: 3, action: 'collect', text: 'Bank pays you $50', amount: 50, house_cost: null, hotel_cost: null, target: null, spaces: null };
        window.axios = { post: vi.fn().mockResolvedValue({ data: { card } }) };

        const wrapper = mount(MonopolyBoard, { props: { game }, attachTo: document.body });
        await wrapper.find('[data-testid="chance-deck"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/games/1/chance/draw');
        wrapper.unmount();
    });

    it('calls the community draw API when the Community Chest deck is clicked', async () => {
        const card = { id: 7, action: 'collect', text: 'Bank error $200', amount: 200, house_cost: null, hotel_cost: null, target: null };
        window.axios = { post: vi.fn().mockResolvedValue({ data: { card } }) };

        const wrapper = mount(MonopolyBoard, { props: { game }, attachTo: document.body });
        await wrapper.find('[data-testid="community-deck"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/games/1/community/draw');
        wrapper.unmount();
    });

    it('disables both deck buttons while a draw is in flight', async () => {
        // Never resolves -- keeps isDrawing=true
        window.axios = { post: vi.fn().mockReturnValue(new Promise(() => {})) };

        const wrapper = mount(MonopolyBoard, { props: { game }, attachTo: document.body });
        await wrapper.find('[data-testid="chance-deck"]').trigger('click');

        expect(wrapper.find('[data-testid="chance-deck"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="community-deck"]').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('does not render a player hand card when players prop is empty', () => {
        const wrapper = mount(MonopolyBoard, { props: { game, players: [] } });
        expect(wrapper.find('[data-testid="player-hand-card"]').exists()).toBe(false);
    });

    it('renders the creator PlayerHandCard when players prop includes a creator', () => {
        const players = [
            {
                user_id: 42,
                name: 'Alice',
                is_creator: true,
                join_order: 1,
                icon: { id: 1, name: 'Top Hat', image_url: '/images/icons/top-hat.svg' },
                properties: [],
                chance_cards: [],
                community_chest_cards: [],
            },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game, players } });
        expect(wrapper.find('[data-testid="player-hand-card"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Alice');
    });

    it('renders the left player panel container on the board', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.find('[aria-label="Left player panel"]').exists()).toBe(true);
    });

    it('renders the right player panel container on the board', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.find('[aria-label="Right player panel"]').exists()).toBe(true);
    });

    it('renders the creator player icon in the GO square at game creation', () => {
        const players = [
            {
                user_id: 42,
                name: 'Alice',
                is_creator: true,
                join_order: 1,
                icon: { id: 1, name: 'Top Hat', image_url: '/images/icons/top-hat.svg' },
                properties: [],
                chance_cards: [],
                community_chest_cards: [],
            },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game, players } });
        const tokenImg = wrapper.find('[data-testid="player-token-42"]');
        expect(tokenImg.exists()).toBe(true);
        expect(tokenImg.attributes('src')).toBe('/images/icons/top-hat.svg');
        expect(tokenImg.attributes('alt')).toBe('Alice');
    });

    it('does not render any player tokens in the GO square when players prop is empty', () => {
        const wrapper = mount(MonopolyBoard, { props: { game, players: [] } });
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(false);
    });

    // -- Panel distribution by join_order ------------------------------------

    it('renders odd join_order players (1, 3, 5, 7) in the left panel', () => {
        const players = [
            { user_id: 1, name: 'Alice', is_creator: true,  join_order: 1, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },  properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 2, name: 'Bob',   is_creator: false, join_order: 2, icon: { id: 2, name: 'Car', image_url: '/car.svg' },  properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 3, name: 'Carol', is_creator: false, join_order: 3, icon: { id: 3, name: 'Dog', image_url: '/dog.svg' },  properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 4, name: 'Dave',  is_creator: false, join_order: 4, icon: { id: 4, name: 'Iron', image_url: '/iron.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game, players } });

        const leftPanel  = wrapper.find('[aria-label="Left player panel"]');
        const rightPanel = wrapper.find('[aria-label="Right player panel"]');

        expect(leftPanel.text()).toContain('Alice');
        expect(leftPanel.text()).toContain('Carol');
        expect(leftPanel.text()).not.toContain('Bob');
        expect(leftPanel.text()).not.toContain('Dave');

        expect(rightPanel.text()).toContain('Bob');
        expect(rightPanel.text()).toContain('Dave');
        expect(rightPanel.text()).not.toContain('Alice');
        expect(rightPanel.text()).not.toContain('Carol');
    });

    it('renders all four odd-slot players (join_order 1,3,5,7) in the left panel', () => {
        const players = [
            { user_id: 1, name: 'P1', is_creator: true,  join_order: 1, icon: { id: 1, name: 'Hat',   image_url: '/1.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 3, name: 'P3', is_creator: false, join_order: 3, icon: { id: 3, name: 'Dog',   image_url: '/3.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 5, name: 'P5', is_creator: false, join_order: 5, icon: { id: 5, name: 'Boat',  image_url: '/5.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 7, name: 'P7', is_creator: false, join_order: 7, icon: { id: 7, name: 'Thimble', image_url: '/7.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper    = mount(MonopolyBoard, { props: { game, players } });
        const leftPanel  = wrapper.find('[aria-label="Left player panel"]');
        const rightPanel = wrapper.find('[aria-label="Right player panel"]');

        ['P1', 'P3', 'P5', 'P7'].forEach(name => expect(leftPanel.text()).toContain(name));
        expect(rightPanel.findAll('[data-testid="player-hand-card"]')).toHaveLength(0);
    });

    it('renders all four even-slot players (join_order 2,4,6,8) in the right panel', () => {
        const players = [
            { user_id: 1, name: 'P1', is_creator: true,  join_order: 1, icon: { id: 1, name: 'Hat',    image_url: '/1.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 2, name: 'P2', is_creator: false, join_order: 2, icon: { id: 2, name: 'Car',    image_url: '/2.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 4, name: 'P4', is_creator: false, join_order: 4, icon: { id: 4, name: 'Iron',   image_url: '/4.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 6, name: 'P6', is_creator: false, join_order: 6, icon: { id: 6, name: 'Cannon', image_url: '/6.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 8, name: 'P8', is_creator: false, join_order: 8, icon: { id: 8, name: 'Shoe',   image_url: '/8.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper    = mount(MonopolyBoard, { props: { game, players } });
        const rightPanel = wrapper.find('[aria-label="Right player panel"]');

        ['P2', 'P4', 'P6', 'P8'].forEach(name => expect(rightPanel.text()).toContain(name));
    });

    it('renders no hand cards in either panel when players prop is empty', () => {
        const wrapper    = mount(MonopolyBoard, { props: { game, players: [] } });
        const leftPanel  = wrapper.find('[aria-label="Left player panel"]');
        const rightPanel = wrapper.find('[aria-label="Right player panel"]');

        expect(leftPanel.findAll('[data-testid="player-hand-card"]')).toHaveLength(0);
        expect(rightPanel.findAll('[data-testid="player-hand-card"]')).toHaveLength(0);
    });
});
