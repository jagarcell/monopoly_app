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
        // Never resolves — keeps isDrawing=true
        window.axios = { post: vi.fn().mockReturnValue(new Promise(() => {})) };

        const wrapper = mount(MonopolyBoard, { props: { game }, attachTo: document.body });
        await wrapper.find('[data-testid="chance-deck"]').trigger('click');

        expect(wrapper.find('[data-testid="chance-deck"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="community-deck"]').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });
});
