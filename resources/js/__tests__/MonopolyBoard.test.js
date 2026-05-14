import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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
        // Reset Echo mock between tests.
        window.Echo = undefined;
    });

    afterEach(() => {
        window.Echo = undefined;
    });

    it('renders the game name in the header', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('Game #1');
    });

    it('renders the MONOPOLY wordmark', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.text()).toContain('MONOPOLY');
    });

    it('renders the dice roller area in the board centre panel', () => {
        const wrapper = mount(MonopolyBoard, { props: { game } });
        expect(wrapper.find('[data-testid="dice-roller-area"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="dice-roller"]').exists()).toBe(true);
    });

    it('renders the roll button inside the board', () => {
        const creator = { user_id: 1, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] };
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players: [creator], currentUserId: 1 } });
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
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

    // -- Real-time WebSocket subscription ------------------------------------

    it('subscribes to the game channel on mount when Echo is available', () => {
        const listenMock   = vi.fn().mockReturnThis();
        const channelMock  = vi.fn().mockReturnValue({ listen: listenMock });
        window.Echo = { channel: channelMock, leaveChannel: vi.fn() };

        mount(MonopolyBoard, { props: { game, players: [] } });

        expect(channelMock).toHaveBeenCalledWith('game.1');
        expect(listenMock).toHaveBeenCalledWith('PlayerJoined', expect.any(Function));
    });

    it('does not throw when Echo is not defined on mount', () => {
        window.Echo = undefined;
        expect(() => mount(MonopolyBoard, { props: { game, players: [] } })).not.toThrow();
    });

    it('leaves the game channel on unmount', () => {
        const leaveChannelMock = vi.fn();
        const listenMock       = vi.fn().mockReturnThis();
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: leaveChannelMock,
        };

        const wrapper = mount(MonopolyBoard, { props: { game, players: [] } });
        wrapper.unmount();

        expect(leaveChannelMock).toHaveBeenCalledWith('game.1');
    });

    it('does not throw on unmount when Echo is not defined', () => {
        window.Echo = undefined;
        const wrapper = mount(MonopolyBoard, { props: { game, players: [] } });
        expect(() => wrapper.unmount()).not.toThrow();
    });

    it('updates left panel reactively when PlayerJoined event arrives with a new player', async () => {
        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        const initialPlayers = [
            { user_id: 1, name: 'Alice', is_creator: true, join_order: 1,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, { props: { game, players: initialPlayers } });

        // Simulate a second player joining via the WS event.
        capturedListeners['PlayerJoined']({
            players: [
                ...initialPlayers,
                { user_id: 2, name: 'Bob', is_creator: false, join_order: 2,
                  icon: { id: 2, name: 'Car', image_url: '/car.svg' },
                  properties: [], chance_cards: [], community_chest_cards: [] },
            ],
        });

        await flushPromises();

        const rightPanel = wrapper.find('[aria-label="Right player panel"]');
        expect(rightPanel.text()).toContain('Bob');
    });

    it('updates board token reactively when PlayerJoined event arrives', async () => {
        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        const initialPlayers = [
            { user_id: 1, name: 'Alice', is_creator: true, join_order: 1,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, { props: { game, players: initialPlayers } });

        capturedListeners['PlayerJoined']({
            players: [
                ...initialPlayers,
                { user_id: 2, name: 'Bob', is_creator: false, join_order: 2,
                  icon: { id: 2, name: 'Car', image_url: '/car.svg' },
                  properties: [], chance_cards: [], community_chest_cards: [] },
            ],
        });

        await flushPromises();

        const tokenImg = wrapper.find('[data-testid="player-token-2"]');
        expect(tokenImg.exists()).toBe(true);
        expect(tokenImg.attributes('src')).toBe('/car.svg');
    });

    it('does not update localPlayers when PlayerJoined payload players is not an array', async () => {
        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        const initialPlayers = [
            { user_id: 1, name: 'Alice', is_creator: true, join_order: 1,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, { props: { game, players: initialPlayers } });

        // Send a malformed event (no players array).
        capturedListeners['PlayerJoined']({ players: null });

        await flushPromises();

        // Alice should still be there.
        expect(wrapper.find('[aria-label="Left player panel"]').text()).toContain('Alice');
    });

    // ── isCurrentPlayer / capital visibility ─────────────────────────────────

    it('passes isCurrentPlayer=true only to the card matching currentUserId', () => {
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true,  join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, {
            props: { game, players, currentUserId: 42 },
        });

        // Alice's card (left panel) must show capital; Bob's card (right panel) must not.
        const leftPanel  = wrapper.find('[aria-label="Left player panel"]');
        const rightPanel = wrapper.find('[aria-label="Right player panel"]');

        expect(leftPanel.find('[data-testid="capital-section"]').exists()).toBe(true);
        expect(rightPanel.find('[data-testid="capital-section"]').exists()).toBe(false);
    });

    it('passes isCurrentPlayer=true to the card matching currentInvitationId for guests', () => {
        const players = [
            { user_id: 1,    invitation_id: null, name: 'Alice', is_creator: true,  join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: null, invitation_id: 7,    name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, {
            props: { game, players, currentInvitationId: 7 },
        });

        const rightPanel = wrapper.find('[aria-label="Right player panel"]');
        expect(rightPanel.find('[data-testid="capital-section"]').exists()).toBe(true);

        const leftPanel = wrapper.find('[aria-label="Left player panel"]');
        expect(leftPanel.find('[data-testid="capital-section"]').exists()).toBe(false);
    });

    it('shows no capital section on any card when neither currentUserId nor currentInvitationId is set', () => {
        const players = [
            { user_id: 1, invitation_id: null, name: 'Alice', is_creator: true,  join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 2, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, { props: { game, players } });

        expect(wrapper.findAll('[data-testid="capital-section"]')).toHaveLength(0);
    });

    // ── PendingInvitationsList integration ────────────────────────────────────

    it('renders PendingInvitationsList when pendingInvitations is non-empty', () => {
        const pending = [{ email: 'waiting@example.com' }];

        const wrapper = mount(MonopolyBoard, {
            props: { game, pendingInvitations: pending },
        });

        expect(wrapper.find('[data-testid="pending-invitations-list"]').exists()).toBe(true);
    });

    it('does not render PendingInvitationsList when pendingInvitations is empty', () => {
        const wrapper = mount(MonopolyBoard, {
            props: { game, pendingInvitations: [] },
        });

        expect(wrapper.find('[data-testid="pending-invitations-list"]').exists()).toBe(false);
    });

    it('updates localPendingInvitations when PlayerJoined event includes pending_invitations', async () => {
        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        const initialPending = [{ email: 'still-waiting@example.com' }];

        const wrapper = mount(MonopolyBoard, {
            props: { game, pendingInvitations: initialPending },
        });

        // Pending list should be visible initially.
        expect(wrapper.find('[data-testid="pending-invitations-list"]').exists()).toBe(true);

        // Fire a PlayerJoined event that clears the pending list.
        capturedListeners['PlayerJoined']({ players: [], pending_invitations: [] });

        await flushPromises();

        // The pending list should now be hidden.
        expect(wrapper.find('[data-testid="pending-invitations-list"]').exists()).toBe(false);
    });

    // ── Turn tracking / DiceRoller visibility ─────────────────────────────────

    it('shows the roll button when currentUserId matches the active turn join_order', () => {
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 42 } });

        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
    });

    it('hides the roll button when currentUserId does not match the active turn', () => {
        const gameWithTurn = { ...game, current_turn_join_order: 2 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 42 } });

        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
    });

    it('shows the waiting label when it is not the current user turn', () => {
        const gameWithTurn = { ...game, current_turn_join_order: 2 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 42 } });

        expect(wrapper.find('[data-testid="waiting-label"]').exists()).toBe(true);
    });

    it('DiceRolled WebSocket event does not update currentTurnJoinOrder', async () => {
        let capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        // Alice (join_order 1) is the active player. Bob (join_order 2) views.
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        // Render as Bob (currentUserId=99), who is NOT the active player.
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 99 } });

        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);

        // Simulate Alice rolling — DiceRolled fires but does NOT advance the turn.
        capturedListeners['DiceRolled']({
            die1: 3,
            die2: 4,
            total: 7,
            current_turn_join_order: 1,
        });
        await flushPromises();

        // Turn has NOT advanced — Bob's roll button should still be hidden.
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
    });

    it('TurnAdvanced WebSocket event updates currentTurnJoinOrder and reveals roll button', async () => {
        let capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        // Alice (join_order 1) is the active player. Bob (join_order 2) views.
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        // Render as Bob (currentUserId=99), who is NOT the active player.
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 99 } });

        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);

        // Simulate Alice clicking Done — TurnAdvanced fires with Bob's join_order.
        capturedListeners['TurnAdvanced']({ current_turn_join_order: 2 });
        await flushPromises();

        // Now it is Bob's turn — roll button should appear.
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
    });

    it('DiceRolled WebSocket event updates displayed dice values', async () => {
        let capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 42 } });

        capturedListeners['DiceRolled']({
            die1: 5,
            die2: 6,
            total: 11,
            current_turn_join_order: 1,
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('5');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('6');
    });

    it('DiceRolled WebSocket event triggers the rolling animation on the dice', async () => {
        vi.useFakeTimers();

        let capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        // Alice (join_order 1) is active; we view as Bob (join_order 2) so no local roll.
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true,  join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 99 } });

        capturedListeners['DiceRolled']({
            die1: 3,
            die2: 4,
            total: 7,
            current_turn_join_order: 2,
        });
        await flushPromises();

        // The rolling class should be applied immediately after the trigger.
        expect(wrapper.find('[data-testid="die-1"]').classes()).toContain('rolling');
        expect(wrapper.find('[data-testid="die-2"]').classes()).toContain('rolling');

        vi.useRealTimers();
    });

    it('calls the roll API when roll-requested is emitted and updates dice', async () => {
        window.axios = { post: vi.fn().mockResolvedValue({ data: { die1: 2, die2: 3, total: 5, current_turn_join_order: 2 } }) };
        window.Echo  = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1, capital: 1500, icon: { id: 1, name: 'Hat', image_url: '/hat.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 42 }, attachTo: document.body });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/games/1/roll');
        wrapper.unmount();
    });

    it('calls the guest roll API when invitationToken is set', async () => {
        window.axios = { post: vi.fn().mockResolvedValue({ data: { die1: 1, die2: 1, total: 2, current_turn_join_order: 1 } }) };
        window.Echo  = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 2 };
        const players = [
            { user_id: null, invitation_id: 5, name: 'Guest', is_creator: false, join_order: 2, capital: 1500, icon: { id: 2, name: 'Car', image_url: '/car.svg' }, properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, invitationToken: 'abc-token', currentInvitationId: 5 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/join/abc-token/roll');
        wrapper.unmount();
    });

    // ── Token positions and animation ─────────────────────────────────────────

    it('renders player token at GO square when square_index is 0', () => {
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game, players } });
        // Token should be in GO corner (data-testid="go-player-tokens")
        const goTokens = wrapper.find('[data-testid="go-player-tokens"]');
        expect(goTokens.exists()).toBe(true);
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);
    });

    it('renders player token at a non-GO square when square_index is greater than 0', () => {
        // square_index 1 = Mediterranean Ave (bottom row, edge square)
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 1, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game, players } });
        // Token must NOT be in GO square
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(false);
        // Token must appear in edge-player-tokens on the non-GO square
        const tokenImg = wrapper.find('[data-testid="player-token-42"]');
        expect(tokenImg.exists()).toBe(true);
    });

    it('DiceRolled WebSocket event no longer triggers remote token animation (TokenMoved does)', async () => {
        vi.useFakeTimers();

        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        // Alice (join_order 1) rolls, we watch as Bob (join_order 2).
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true,  join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2,
              square_index: 0, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 99 } });

        // Before event: Alice is at GO (square_index 0).
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);

        // Fire DiceRolled — this no longer triggers animation.
        capturedListeners['DiceRolled']({
            die1: 1,
            die2: 2,
            total: 3,
            current_turn_join_order: 1,
            square_index: 3,
        });
        await flushPromises();

        // Even after plenty of time, no animation should have started from DiceRolled.
        vi.advanceTimersByTime(800);
        await flushPromises();
        // Alice should still be at GO — DiceRolled does NOT start animation.
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('TokenMoved WebSocket event triggers remote token animation step-by-step', async () => {
        vi.useFakeTimers();

        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        // Alice (join_order 1) rolls, we watch as Bob (join_order 2).
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true,  join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob',   is_creator: false, join_order: 2,
              square_index: 0, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 99 } });

        // Before event: Alice is at GO (square_index 0).
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);

        // Fire TokenMoved for Alice (join_order 1) landing on square 3.
        capturedListeners['TokenMoved']({
            join_order:   1,
            square_index: 3,
        });
        await flushPromises();

        // Token animation starts immediately — first step (200ms) has not fired yet.
        const edgeTokensBefore = wrapper.findAll('[data-testid="edge-player-tokens"]');
        expect(edgeTokensBefore.some(el => el.find('[data-testid="player-token-42"]').exists())).toBe(false);

        // After 1 step (200ms): Alice should be at square 1 — edge-player-tokens.
        vi.advanceTimersByTime(200);
        await flushPromises();
        const edgeTokens = wrapper.findAll('[data-testid="edge-player-tokens"]');
        const aliceInEdge = edgeTokens.some(el => el.find('[data-testid="player-token-42"]').exists());
        expect(aliceInEdge).toBe(true);

        // After all 3 steps: Alice at square 3 (still an edge-player-tokens square).
        vi.advanceTimersByTime(400);
        await flushPromises();
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('TokenMoved event does not animate own token (join_order matches self)', async () => {
        vi.useFakeTimers();

        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };

        // Alice (join_order 1) is the current viewer — her own TokenMoved should be ignored.
        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, { props: { game: gameWithTurn, players, currentUserId: 42 } });

        // Fire TokenMoved for Alice's own token (join_order 1 === myJoinOrder).
        capturedListeners['TokenMoved']({
            join_order:   1,
            square_index: 5,
        });
        await flushPromises();

        // After waiting well past a full animation, Alice should still be at GO.
        vi.advanceTimersByTime(1500);
        await flushPromises();
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('local roll API response animates own token step-by-step', async () => {
        vi.useFakeTimers();

        window.Echo = undefined;
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: { die1: 2, die2: 1, total: 3, current_turn_join_order: 1, square_index: 3 },
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises(); // axios resolves; move buffered (dice still shaking)

        // Token must NOT have moved — dice haven't settled yet.
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(true);

        // Advance past the 700ms dice animation (settles at ~720ms).
        vi.advanceTimersByTime(800);
        // Flush so notifyTokenMoved() resolves and animateTokenMovement's setInterval is created.
        await flushPromises();

        // Dice just settled; token animation started but first step (200ms) hasn't fired yet.
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(true);

        // After 1 step (200ms more): Alice leaves GO.
        vi.advanceTimersByTime(200);
        await flushPromises();
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(false);

        // After all 3 steps: Alice at square 3.
        vi.advanceTimersByTime(400);
        await flushPromises();
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('calls the token-moved endpoint as soon as the token starts moving', async () => {
        vi.useFakeTimers();

        window.Echo = undefined;
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: { die1: 2, die2: 1, total: 3, current_turn_join_order: 1, square_index: 3 },
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises(); // roll API responds; move buffered (dice still shaking)

        // Only the /roll call should have been made so far.
        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(window.axios.post).toHaveBeenCalledWith('/api/games/1/roll');

        // Advance past the 700ms dice animation (settles at ~720ms).
        // The token-moved POST fires immediately when dice settle — before animation steps.
        vi.advanceTimersByTime(800);
        await flushPromises();

        // token-moved must be called right when animation begins, not after it finishes.
        expect(window.axios.post).toHaveBeenCalledTimes(2);
        expect(window.axios.post).toHaveBeenNthCalledWith(2, '/api/games/1/token-moved');

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('calls the guest token-moved endpoint when invitationToken is set', async () => {
        vi.useFakeTimers();

        window.Echo = undefined;
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: { die1: 1, die2: 2, total: 3, current_turn_join_order: 2, square_index: 3 },
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 2 };
        const players = [
            { user_id: null, invitation_id: 5, name: 'Guest', is_creator: false, join_order: 2,
              square_index: 0, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, invitationToken: 'abc-token', currentInvitationId: 5 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();

        // Advance past dice animation — token-moved fires as animation begins.
        vi.advanceTimersByTime(800);
        await flushPromises();

        expect(window.axios.post).toHaveBeenCalledWith('/api/join/abc-token/token-moved');

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('DiceRolled event does not animate own token (local roll handles it)', async () => {
        vi.useFakeTimers();

        const capturedListeners = {};
        const listenMock = vi.fn().mockImplementation((event, cb) => {
            capturedListeners[event] = cb;
            return { listen: listenMock };
        });
        window.Echo = {
            channel:      vi.fn().mockReturnValue({ listen: listenMock }),
            leaveChannel: vi.fn(),
        };
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: { die1: 2, die2: 1, total: 3, current_turn_join_order: 1, square_index: 3 },
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        // Alice rolls via the button — this starts the local animation.
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();

        // Now fire the DiceRolled WS event for Alice's own roll.
        // This must NOT start a second setInterval for join_order 1.
        capturedListeners['DiceRolled']({
            die1: 2,
            die2: 1,
            total: 3,
            current_turn_join_order: 1,
            square_index: 3,
        });
        await flushPromises();

        // Advance past the 700ms dice animation (~720ms); flush so notifyTokenMoved()
        // resolves and animateTokenMovement's setInterval is registered.
        vi.advanceTimersByTime(800);
        await flushPromises();

        // Advance through all 3 token steps (3×200ms = 600ms).
        vi.advanceTimersByTime(600);
        await flushPromises();

        // Token should be at square 3 (edge square), not doubled back to GO.
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(false);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('token does not leave starting position while dice are still rolling (before roll-settled)', async () => {
        vi.useFakeTimers();

        window.Echo = undefined;
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: { die1: 2, die2: 1, total: 3, current_turn_join_order: 1, square_index: 3 },
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises(); // axios resolves; move buffered while dice are still shaking

        // Advance only 400ms — dice animation is still in progress (~720ms needed).
        vi.advanceTimersByTime(400);
        await flushPromises();

        // Token must still be at GO — roll-settled has not fired yet.
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="player-token-42"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    // ── GO bonus dialog ───────────────────────────────────────────────────────

    it('shows the GO bonus dialog after the token animation when passed_go is true', async () => {
        vi.useFakeTimers();

        // Stub axios: roll returns passed_go=true, square_index=2 (Baltic Ave).
        window.axios = {
            post: vi.fn().mockImplementation((url) => {
                if (url.includes('/roll')) {
                    return Promise.resolve({
                        data: {
                            die1: 4, die2: 4, total: 8,
                            current_turn_join_order: 1,
                            square_index: 2,
                            passed_go: true,
                            go_bonus: 200,
                            new_capital: 1700,
                            square_action: null,
                        },
                    });
                }
                // token-moved endpoint
                return Promise.resolve({ data: {} });
            }),
        };
        window.Echo = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();

        // Advance past the 700 ms dice shake so roll-settled fires.
        vi.advanceTimersByTime(750);
        await flushPromises();

        // Advance through the token step animation (2 steps × 200 ms each).
        vi.advanceTimersByTime(500);
        await flushPromises();

        // The GO bonus dialog must now be visible.
        expect(wrapper.find('[data-testid="go-bonus-dialog"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="go-dialog-ok"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Passed GO!');
        expect(wrapper.text()).toContain('$200');

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('hides the GO bonus dialog after clicking OK', async () => {
        vi.useFakeTimers();

        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: {
                    die1: 4, die2: 4, total: 8,
                    current_turn_join_order: 1,
                    square_index: 2,
                    passed_go: true,
                    go_bonus: 200,
                    new_capital: 1700,
                    square_action: null,
                },
            }),
        };
        window.Echo = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();
        vi.advanceTimersByTime(750);
        await flushPromises();
        vi.advanceTimersByTime(500);
        await flushPromises();

        // Dialog is open — click OK.
        await wrapper.find('[data-testid="go-dialog-ok"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="go-bonus-dialog"]').exists()).toBe(false);

        vi.useRealTimers();
        wrapper.unmount();
    });

    // ── Rent paid notification dialog ─────────────────────────────────────────

    it('shows rent notification dialog after handlePayRent succeeds', async () => {
        vi.useFakeTimers();

        window.Echo = undefined;
        window.axios = {
            post: vi.fn().mockImplementation((url) => {
                if (url.includes('/roll')) {
                    return Promise.resolve({
                        data: {
                            die1: 2, die2: 2, total: 4,
                            current_turn_join_order: 1,
                            square_index: 39, // Boardwalk, owned by Bob
                            passed_go: false,
                            square_action: {
                                type: 'rent',
                                square_name: 'Boardwalk',
                                price: null,
                                rent: 50,
                                owner_join_order: 2,
                                owner_name: 'Bob',
                            },
                        },
                    });
                }
                if (url.includes('/pay-rent')) {
                    return Promise.resolve({
                        data: {
                            payer: { join_order: 1, capital: 1450 },
                            owner: { join_order: 2, capital: 1550 },
                            rent_amount: 50,
                            square_name: 'Boardwalk',
                        },
                    });
                }
                // token-moved endpoint
                return Promise.resolve({ data: {} });
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 35, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob', is_creator: false, join_order: 2,
              square_index: 5, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        // Roll dice — the API returns a rent square_action for Boardwalk.
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await flushPromises();

        // Advance past the 700 ms dice shake.
        vi.advanceTimersByTime(750);
        await flushPromises();

        // Advance through the token animation (4 steps × 200 ms = 800 ms).
        vi.advanceTimersByTime(900);
        await flushPromises();

        // SquareActionModal is now open — emit 'pay'.
        const modal = wrapper.findComponent({ name: 'SquareActionModal' });
        await modal.vm.$emit('pay');
        await flushPromises();

        expect(wrapper.find('[data-testid="rent-notification-dialog"]').exists()).toBe(true);

        vi.useRealTimers();
        wrapper.unmount();
    });

    it('hides rent notification dialog after clicking OK', async () => {
        window.Echo = undefined;
        window.axios = {
            post: vi.fn().mockResolvedValue({
                data: {
                    payer: { join_order: 1, capital: 1450 },
                    owner: { join_order: 2, capital: 1550 },
                    rent_amount: 50,
                    square_name: 'Boardwalk',
                },
            }),
        };

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 39, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob', is_creator: false, join_order: 2,
              square_index: 5, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        // Trigger the pay action.
        const modal = wrapper.findComponent({ name: 'SquareActionModal' });
        await modal.vm.$emit('pay');
        await flushPromises();

        // Dialog should be open — click OK.
        const dialog = wrapper.findComponent({ name: 'RentNotificationDialog' });
        await dialog.vm.$emit('close');
        await flushPromises();

        expect(wrapper.find('[data-testid="rent-notification-dialog"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('RentPaid WebSocket event shows notification dialog for the owner (non-payer)', async () => {
        let capturedListeners = {};
        window.Echo = {
            channel: () => ({
                listen: (event, handler) => { capturedListeners[event] = handler; return { listen: (e, h) => { capturedListeners[e] = h; return { listen: (e2, h2) => { capturedListeners[e2] = h2; return { listen: (e3, h3) => { capturedListeners[e3] = h3; return { listen: (e4, h4) => { capturedListeners[e4] = h4; return {}; } }; } }; } }; } }; },
            }),
            leaveChannel: vi.fn(),
        };
        window.axios = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob', is_creator: false, join_order: 2,
              square_index: 5, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        // Mount as Bob (join_order=2, the OWNER). Payer is join_order=1 (Alice).
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 99 },
            attachTo: document.body,
        });

        // Simulate the RentPaid broadcast arriving.
        capturedListeners['RentPaid']({
            payer_join_order: 1,
            payer_name: 'Alice',
            payer_capital: 1450,
            owner_join_order: 2,
            owner_name: 'Bob',
            owner_capital: 1550,
            rent_amount: 50,
            square_name: 'Boardwalk',
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="rent-notification-dialog"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="rent-payer-name"]').text()).toBe('Alice');
        expect(wrapper.find('[data-testid="rent-owner-name"]').text()).toBe('Bob');
        expect(wrapper.find('[data-testid="rent-amount"]').text()).toBe('$50');

        wrapper.unmount();
    });

    it('RentPaid WebSocket event does NOT show dialog to the payer (they saw it from API)', async () => {
        let capturedListeners = {};
        window.Echo = {
            channel: () => ({
                listen: (event, handler) => { capturedListeners[event] = handler; return { listen: (e, h) => { capturedListeners[e] = h; return { listen: (e2, h2) => { capturedListeners[e2] = h2; return { listen: (e3, h3) => { capturedListeners[e3] = h3; return { listen: (e4, h4) => { capturedListeners[e4] = h4; return {}; } }; } }; } }; } }; },
            }),
            leaveChannel: vi.fn(),
        };
        window.axios = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 39, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        // Mount as Alice (join_order=1, the PAYER).
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 42 },
            attachTo: document.body,
        });

        // Simulate the RentPaid broadcast arriving — payer_join_order === myJoinOrder (1).
        capturedListeners['RentPaid']({
            payer_join_order: 1,
            payer_name: 'Alice',
            payer_capital: 1450,
            owner_join_order: 2,
            owner_name: 'Bob',
            owner_capital: 1550,
            rent_amount: 50,
            square_name: 'Boardwalk',
        });
        await flushPromises();

        // Dialog must NOT appear for the payer via WS — they get it from the API response.
        expect(wrapper.find('[data-testid="rent-notification-dialog"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('RentPaid WebSocket event updates both player capitals reactively', async () => {
        let capturedListeners = {};
        window.Echo = {
            channel: () => ({
                listen: (event, handler) => { capturedListeners[event] = handler; return { listen: (e, h) => { capturedListeners[e] = h; return { listen: (e2, h2) => { capturedListeners[e2] = h2; return { listen: (e3, h3) => { capturedListeners[e3] = h3; return { listen: (e4, h4) => { capturedListeners[e4] = h4; return {}; } }; } }; } }; } }; },
            }),
            leaveChannel: vi.fn(),
        };
        window.axios = undefined;

        const gameWithTurn = { ...game, current_turn_join_order: 1 };
        const players = [
            { user_id: 42, invitation_id: null, name: 'Alice', is_creator: true, join_order: 1,
              square_index: 0, capital: 1500,
              icon: { id: 1, name: 'Hat', image_url: '/hat.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 99, invitation_id: null, name: 'Bob', is_creator: false, join_order: 2,
              square_index: 5, capital: 1500,
              icon: { id: 2, name: 'Car', image_url: '/car.svg' },
              properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        // Mount as Bob (currentUserId=99, join_order=2, the OWNER receiving rent).
        // Bob's capital should update from 1500 to 1550 and be visible in his card.
        const wrapper = mount(MonopolyBoard, {
            props: { game: gameWithTurn, players, currentUserId: 99 },
            attachTo: document.body,
        });

        capturedListeners['RentPaid']({
            payer_join_order: 1,
            payer_name: 'Alice',
            payer_capital: 1450,
            owner_join_order: 2,
            owner_name: 'Bob',
            owner_capital: 1550,
            rent_amount: 50,
            square_name: 'Boardwalk',
        });
        await flushPromises();

        // Bob is the current player on this board so his updated capital is visible.
        // Capital is formatted with toLocaleString, e.g. 1,550.
        expect(wrapper.text()).toContain('1,550');

        wrapper.unmount();
    });
});
