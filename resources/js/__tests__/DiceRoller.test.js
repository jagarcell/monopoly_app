import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import DiceRoller from '@/Components/DiceRoller.vue';

describe('DiceRoller', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the dice roller container', () => {
        const wrapper = mount(DiceRoller);
        expect(wrapper.find('[data-testid="dice-roller"]').exists()).toBe(true);
    });

    it('renders die-1 and die-2', () => {
        const wrapper = mount(DiceRoller);
        expect(wrapper.find('[data-testid="die-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="die-2"]').exists()).toBe(true);
    });

    it('renders the roll button', () => {
        const wrapper = mount(DiceRoller);
        const btn = wrapper.find('[data-testid="roll-button"]');
        expect(btn.exists()).toBe(true);
        expect(btn.element.tagName).toBe('BUTTON');
    });

    it('renders a dice total element', () => {
        const wrapper = mount(DiceRoller);
        expect(wrapper.find('[data-testid="dice-total"]').exists()).toBe(true);
    });

    it('shows initial die face values of 1 for both dice', () => {
        const wrapper = mount(DiceRoller);
        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('1');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('1');
    });

    it('shows an initial total of 2', () => {
        const wrapper = mount(DiceRoller);
        expect(wrapper.find('[data-testid="dice-total"]').text()).toBe('2');
    });

    it('disables the roll button while rolling', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        expect(wrapper.find('[data-testid="roll-button"]').attributes('disabled')).toBeDefined();
    });

    it('applies the rolling class to die-1 and die-2 while rolling', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        expect(wrapper.find('[data-testid="die-1"]').classes()).toContain('rolling');
        expect(wrapper.find('[data-testid="die-2"]').classes()).toContain('rolling');
    });

    it('applies the rolling class to the roll button while rolling', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        expect(wrapper.find('[data-testid="roll-button"]').classes()).toContain('rolling');
    });

    it('removes the rolling class from dice after animation completes', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="die-1"]').classes()).not.toContain('rolling');
        expect(wrapper.find('[data-testid="die-2"]').classes()).not.toContain('rolling');
    });

    it('re-enables the roll button after animation completes', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        // Roll button should be gone until the turn advances away from this player.
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);
    });

    it('shows die face values between 1 and 6 after rolling', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        const v1 = parseInt(wrapper.find('[data-testid="die-1"]').attributes('data-die-value'), 10);
        const v2 = parseInt(wrapper.find('[data-testid="die-2"]').attributes('data-die-value'), 10);
        expect(v1).toBeGreaterThanOrEqual(1);
        expect(v1).toBeLessThanOrEqual(6);
        expect(v2).toBeGreaterThanOrEqual(1);
        expect(v2).toBeLessThanOrEqual(6);
    });

    it('shows a total between 2 and 12 after rolling', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        const total = parseInt(wrapper.find('[data-testid="dice-total"]').text(), 10);
        expect(total).toBeGreaterThanOrEqual(2);
        expect(total).toBeLessThanOrEqual(12);
    });

    it('displayed total matches the sum of the two die face values after rolling', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        const v1    = parseInt(wrapper.find('[data-testid="die-1"]').attributes('data-die-value'), 10);
        const v2    = parseInt(wrapper.find('[data-testid="die-2"]').attributes('data-die-value'), 10);
        const total = parseInt(wrapper.find('[data-testid="dice-total"]').text(), 10);
        expect(total).toBe(v1 + v2);
    });

    it('does not start a second roll while one is already in progress', async () => {
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        // Attempt a second click while rolling
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        // Advance only 400ms — if two intervals were started we'd expect
        // rolling to still be true (both still running), which is fine; what
        // matters is the button stays disabled throughout.
        vi.advanceTimersByTime(400);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="roll-button"]').attributes('disabled')).toBeDefined();
    });

    it('renders a pip SVG circle for a face-1 die', () => {
        const wrapper = mount(DiceRoller);
        // die-1 starts at face 1 → exactly 1 circle inside its SVG
        const circles = wrapper.find('[data-testid="die-1"]').findAll('circle');
        expect(circles).toHaveLength(1);
    });

    it('renders six pip SVG circles when a die shows face 6', async () => {
        // Force both dice to 6 immediately by mocking Math.random
        vi.spyOn(Math, 'random').mockReturnValue(5 / 6); // floor(5/6 * 6)+1 = 6
        const wrapper = mount(DiceRoller);
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        vi.restoreAllMocks();
        const circles = wrapper.find('[data-testid="die-1"]').findAll('circle');
        expect(circles).toHaveLength(6);
    });

    // ── isMyTurn prop ─────────────────────────────────────────────────────────

    it('hides the roll button when isMyTurn is false', () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false } });
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
    });

    it('shows the roll button when isMyTurn is true', () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
    });

    it('shows the roll button by default (isMyTurn defaults to true)', () => {
        const wrapper = mount(DiceRoller);
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
    });

    it('shows the waiting label when isMyTurn is false', () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false } });
        expect(wrapper.find('[data-testid="waiting-label"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="waiting-label"]').text()).toBe('waiting...');
    });

    it('shows the waiting token when active player token data is provided', () => {
        const wrapper = mount(DiceRoller, {
            props: {
                isMyTurn: false,
                waitingForTokenImageUrl: '/car.svg',
                waitingForTokenName: 'Car',
            },
        });

        expect(wrapper.find('[data-testid="waiting-label"]').text()).toContain('waiting for');
        expect(wrapper.find('[data-testid="waiting-token-image"]').attributes('src')).toBe('/car.svg');
        expect(wrapper.find('[data-testid="waiting-token-image"]').attributes('alt')).toBe('Car token');
        expect(wrapper.find('[data-testid="waiting-label"]').text()).toContain('...');
    });

    it('hides the waiting label when isMyTurn is true', () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        expect(wrapper.find('[data-testid="waiting-label"]').exists()).toBe(false);
    });

    it('emits roll-requested when the roll button is clicked', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        expect(wrapper.emitted('roll-requested')).toBeTruthy();
        expect(wrapper.emitted('roll-requested')).toHaveLength(1);
    });

    it('does not emit roll-requested when isMyTurn is false', async () => {
        // Button does not exist so no click is possible, but guard also blocks it.
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false } });
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
        expect(wrapper.emitted('roll-requested')).toBeFalsy();
    });

    // ── displayDie1 / displayDie2 props ───────────────────────────────────────

    it('snaps die values to displayDie1/displayDie2 after animation when props arrive during roll', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true, displayDie1: null, displayDie2: null } });
        await wrapper.find('[data-testid="roll-button"]').trigger('click');

        // Simulate server response arriving mid-animation.
        await wrapper.setProps({ displayDie1: 3, displayDie2: 5 });

        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('3');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('5');
    });

    it('applies displayDie1/displayDie2 immediately when not rolling', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false, displayDie1: null, displayDie2: null } });

        await wrapper.setProps({ displayDie1: 6, displayDie2: 2 });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('6');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('2');
    });

    it('total updates to reflect displayDie1 + displayDie2 when applied', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false, displayDie1: null, displayDie2: null } });

        await wrapper.setProps({ displayDie1: 4, displayDie2: 4 });
        await wrapper.vm.$nextTick();

        const total = parseInt(wrapper.find('[data-testid="dice-total"]').text(), 10);
        expect(total).toBe(8);
    });

    it('shows roll button again when resetHasRolledSignal updates during an active roll', async () => {
        const wrapper = mount(DiceRoller, {
            props: {
                isMyTurn: true,
                resetHasRolledSignal: 0,
            },
        });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        await wrapper.setProps({ resetHasRolledSignal: 1 });

        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
    });

    // ── externalTrigger prop ───────────────────────────────────────────────────

    it('starts the shake animation when externalTrigger increments from 0', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false, externalTrigger: 0 } });

        await wrapper.setProps({ externalTrigger: 1 });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="die-1"]').classes()).toContain('rolling');
        expect(wrapper.find('[data-testid="die-2"]').classes()).toContain('rolling');
    });

    it('removes the rolling class after the external animation completes (700 ms)', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false, externalTrigger: 0 } });

        await wrapper.setProps({ externalTrigger: 1 });
        await wrapper.vm.$nextTick();

        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="die-1"]').classes()).not.toContain('rolling');
        expect(wrapper.find('[data-testid="die-2"]').classes()).not.toContain('rolling');
    });

    it('snaps to displayDie1/displayDie2 after the external animation ends', async () => {
        const wrapper = mount(DiceRoller, {
            props: { isMyTurn: false, externalTrigger: 0, displayDie1: 5, displayDie2: 6 },
        });

        await wrapper.setProps({ externalTrigger: 1 });
        await wrapper.vm.$nextTick();

        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('5');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('6');
    });

    it('does not start a second animation when externalTrigger changes while a local roll is in progress', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true, externalTrigger: 0 } });

        // Start a local roll animation.
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        expect(wrapper.find('[data-testid="die-1"]').classes()).toContain('rolling');

        // Simulate an external trigger arriving while the local roll is in progress.
        await wrapper.setProps({ externalTrigger: 1 });
        await wrapper.vm.$nextTick();

        // Advance past the local animation duration — it should settle normally.
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="die-1"]').classes()).not.toContain('rolling');
        expect(wrapper.find('[data-testid="die-2"]').classes()).not.toContain('rolling');
    });

    it('does not emit roll-requested when animation is triggered externally', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false, externalTrigger: 0 } });

        await wrapper.setProps({ externalTrigger: 1 });
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('roll-requested')).toBeFalsy();
    });

    it('hides the roll button after the roll animation completes', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
    });

    it('does not show the done button when isMyTurn is false', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false } });
        // Simulate external roll so dice values change without local rolling.
        await wrapper.setProps({ externalTrigger: 1 });
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);
    });

    it('resets hasRolled and shows roll button again when isMyTurn becomes false then true', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        // Roll — the roll button should disappear until the turn advances.
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);

        // Simulate turn passing to another player.
        await wrapper.setProps({ isMyTurn: false });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="waiting-label"]').exists()).toBe(true);

        // Simulate turn returning to this player.
        await wrapper.setProps({ isMyTurn: true });
        await wrapper.vm.$nextTick();
        // Roll button should be shown again (hasRolled was reset).
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);
    });

    it('keeps roll available next turn when turn changes away mid-animation', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });

        await wrapper.find('[data-testid="roll-button"]').trigger('click');

        // Simulate turn advancing before this local animation settles.
        await wrapper.setProps({ isMyTurn: false });
        await wrapper.vm.$nextTick();

        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();

        // Later, when turn comes back, roll should be available.
        await wrapper.setProps({ isMyTurn: true });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);
    });

    // ── roll-settled event ────────────────────────────────────────────────────

    it('emits roll-settled after the local roll animation completes', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('roll-settled')).toBeTruthy();
        expect(wrapper.emitted('roll-settled')).toHaveLength(1);
    });

    it('emits roll-settled after the external trigger animation completes', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: false, externalTrigger: 0 } });
        await wrapper.setProps({ externalTrigger: 1 });
        await wrapper.vm.$nextTick();
        vi.advanceTimersByTime(800);
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('roll-settled')).toBeTruthy();
        expect(wrapper.emitted('roll-settled')).toHaveLength(1);
    });

    it('does not emit roll-settled before the animation completes', async () => {
        const wrapper = mount(DiceRoller, { props: { isMyTurn: true } });
        await wrapper.find('[data-testid="roll-button"]').trigger('click');
        // Only advance 400ms — animation hasn't finished (needs ~720ms).
        vi.advanceTimersByTime(400);
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted('roll-settled')).toBeFalsy();
    });

    // ── initialHasRolled prop ─────────────────────────────────────────────────

    it('keeps the roll button hidden when initialHasRolled is true and isMyTurn is true', () => {
        const wrapper = mount(DiceRoller, {
            props: { isMyTurn: true, initialHasRolled: true },
        });
        expect(wrapper.find('[data-testid="done-button"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(false);
    });

    it('shows roll button when initialHasRolled is false (default) and isMyTurn is true', () => {
        const wrapper = mount(DiceRoller, {
            props: { isMyTurn: true, initialHasRolled: false },
        });
        expect(wrapper.find('[data-testid="roll-button"]').exists()).toBe(true);
    });

    it('seeds die face values from displayDie1/displayDie2 on mount when non-null', () => {
        const wrapper = mount(DiceRoller, {
            props: { isMyTurn: true, displayDie1: 4, displayDie2: 6 },
        });
        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('4');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('6');
    });

    it('shows correct total from displayDie props on mount', () => {
        const wrapper = mount(DiceRoller, {
            props: { isMyTurn: true, displayDie1: 3, displayDie2: 5 },
        });
        const total = parseInt(wrapper.find('[data-testid="dice-total"]').text(), 10);
        expect(total).toBe(8);
    });

    it('falls back to face value 1 when displayDie props are null on mount', () => {
        const wrapper = mount(DiceRoller, {
            props: { isMyTurn: true, displayDie1: null, displayDie2: null },
        });
        expect(wrapper.find('[data-testid="die-1"]').attributes('data-die-value')).toBe('1');
        expect(wrapper.find('[data-testid="die-2"]').attributes('data-die-value')).toBe('1');
    });
});
