import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import GuestGame from '@/Pages/GuestGame.vue';

// ── Module mocks ──────────────────────────────────────────────────────────────

// Head from @inertiajs/vue3 requires an Inertia app context; stub it out so
// the component can mount in a bare test environment.
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />', props: ['title'] },
    usePage: () => ({
        props: {
            debugMode: false,
        },
    }),
}));

// ── Stubs ─────────────────────────────────────────────────────────────────────

const boardStub   = {
    name: 'MonopolyBoard',
    template: '<div data-testid="monopoly-board" />',
    props: ['game', 'invitationToken', 'players', 'currentInvitationId', 'pendingInvitations', 'debugMode'],
};

const globalConfig = {
    stubs: {
        MonopolyBoard: boardStub,
    },
};

// ── GuestGame ─────────────────────────────────────────────────────────────────

describe('GuestGame', () => {
    it('renders the MonopolyBoard when game and token are provided', () => {
        const game  = { id: 1, name: 'Game #1', user_id: 42, status: 'in_progress' };
        const token = 'test-token-uuid';

        const wrapper = mount(GuestGame, {
            props: { game, token },
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="monopoly-board"]').exists()).toBe(true);
    });

    it('passes the game prop to MonopolyBoard', () => {
        const game  = { id: 7, name: 'Game #7', user_id: 1, status: 'in_progress' };
        const token = 'abc-token';

        const wrapper = mount(GuestGame, {
            props: { game, token },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('game')).toEqual(game);
    });

    it('passes the invitationToken prop to MonopolyBoard', () => {
        const game  = { id: 2, name: 'Game #2', user_id: 1, status: 'in_progress' };
        const token = 'my-invitation-token';

        const wrapper = mount(GuestGame, {
            props: { game, token },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('invitationToken')).toBe(token);
    });

    it('passes the players prop to MonopolyBoard', () => {
        const game    = { id: 3, name: 'Game #3', user_id: 1, status: 'in_progress' };
        const token   = 'player-token';
        const players = [
            { user_id: 1, name: 'Alice', is_creator: true,  icon: { image_url: '/icons/hat.png' },  properties: [], chance_cards: [], community_chest_cards: [] },
            { user_id: 2, name: 'Bob',   is_creator: false, icon: { image_url: '/icons/car.png' },  properties: [], chance_cards: [], community_chest_cards: [] },
        ];

        const wrapper = mount(GuestGame, {
            props: { game, token, players },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('players')).toEqual(players);
    });

    it('passes an empty players array to MonopolyBoard when players prop is omitted', () => {
        const game  = { id: 4, name: 'Game #4', user_id: 1, status: 'in_progress' };
        const token = 'solo-token';

        const wrapper = mount(GuestGame, {
            props: { game, token },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('players')).toEqual([]);
    });

    it('shows the error panel when error prop is provided', () => {
        const wrapper = mount(GuestGame, {
            props: { error: 'This invitation has not been accepted yet.' },
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="monopoly-board"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Unable to Load Game');
        expect(wrapper.text()).toContain('This invitation has not been accepted yet.');
    });

    it('shows the return home link in the error panel', () => {
        const wrapper = mount(GuestGame, {
            props: { error: 'Invitation not found.' },
            global: globalConfig,
        });

        const link = wrapper.find('a[href="/"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Return to home');
    });

    it('does not render the board when error is provided', () => {
        const wrapper = mount(GuestGame, {
            props: { error: 'Some error', game: null, token: null },
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="monopoly-board"]').exists()).toBe(false);
    });

    it('passes currentInvitationId to MonopolyBoard', () => {
        const game = { id: 5, name: 'Game #5', user_id: 1, status: 'in_progress' };
        const token = 'guest-token';

        const wrapper = mount(GuestGame, {
            props: { game, token, currentInvitationId: 7 },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('currentInvitationId')).toBe(7);
    });

    it('passes null currentInvitationId to MonopolyBoard when prop is omitted', () => {
        const game  = { id: 6, name: 'Game #6', user_id: 1, status: 'in_progress' };
        const token = 'guest-token-2';

        const wrapper = mount(GuestGame, {
            props: { game, token },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('currentInvitationId')).toBeNull();
    });

    it('passes pendingInvitations to MonopolyBoard', () => {
        const game    = { id: 8, name: 'Game #8', user_id: 1, status: 'in_progress' };
        const token   = 'pending-token';
        const pending = [{ email: 'invited@example.com' }];

        const wrapper = mount(GuestGame, {
            props: { game, token, pendingInvitations: pending },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('pendingInvitations')).toEqual(pending);
    });

    it('passes an empty pendingInvitations array to MonopolyBoard when prop is omitted', () => {
        const game  = { id: 9, name: 'Game #9', user_id: 1, status: 'in_progress' };
        const token = 'no-pending-token';

        const wrapper = mount(GuestGame, {
            props: { game, token },
            global: globalConfig,
        });

        const board = wrapper.findComponent(boardStub);
        expect(board.props('pendingInvitations')).toEqual([]);
    });
});
