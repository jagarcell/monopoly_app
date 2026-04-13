import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import GuestGame from '@/Pages/GuestGame.vue';

// ── Module mocks ──────────────────────────────────────────────────────────────

// Head from @inertiajs/vue3 requires an Inertia app context; stub it out so
// the component can mount in a bare test environment.
vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', template: '<div />', props: ['title'] },
}));

// ── Stubs ─────────────────────────────────────────────────────────────────────

const boardStub   = {
    name: 'MonopolyBoard',
    template: '<div data-testid="monopoly-board" />',
    props: ['game', 'invitationToken'],
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
});
