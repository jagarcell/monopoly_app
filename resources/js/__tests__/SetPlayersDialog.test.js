import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import SetPlayersDialog from '@/Components/SetPlayersDialog.vue';

// Stub child components so the test focuses on dialog logic only.
const modalStub = {
    name: 'Modal',
    template: '<div v-if="show"><slot /></div>',
    props: ['show', 'maxWidth', 'closeable'],
    emits: ['close'],
};

const primaryBtnStub = {
    name: 'PrimaryButton',
    template: '<button type="button"><slot /></button>',
};

const secondaryBtnStub = {
    name: 'SecondaryButton',
    template: '<button type="button"><slot /></button>',
};

const globalStubs = {
    Modal: modalStub,
    PrimaryButton: primaryBtnStub,
    SecondaryButton: secondaryBtnStub,
};

describe('SetPlayersDialog', () => {
    it('is hidden when show prop is false', () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: false },
            global: { stubs: globalStubs },
        });
        expect(wrapper.find('h2').exists()).toBe(false);
    });

    it('is visible when show prop is true', () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        expect(wrapper.find('h2').exists()).toBe(true);
        expect(wrapper.find('h2').text()).toContain('Number of Players');
    });

    it('renders player-count buttons for 2 through 8', () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        const buttons = wrapper.findAll('button[aria-pressed]');
        expect(buttons).toHaveLength(7);
        const labels = buttons.map((b) => b.text());
        expect(labels).toEqual(['2', '3', '4', '5', '6', '7', '8']);
    });

    it('defaults to 2 players selected (aria-pressed="true")', () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        const pressedButtons = wrapper
            .findAll('button[aria-pressed]')
            .filter((b) => b.attributes('aria-pressed') === 'true');
        expect(pressedButtons).toHaveLength(1);
        expect(pressedButtons[0].text()).toBe('2');
    });

    it('updates selected state when a different player-count button is clicked', async () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        const buttons = wrapper.findAll('button[aria-pressed]');
        // Click the "5" button (index 3)
        await buttons[3].trigger('click');
        const pressedButtons = wrapper
            .findAll('button[aria-pressed]')
            .filter((b) => b.attributes('aria-pressed') === 'true');
        expect(pressedButtons).toHaveLength(1);
        expect(pressedButtons[0].text()).toBe('5');
    });

    it('emits "confirm" with the selected player count when Next is clicked', async () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        // Select 4 players
        const buttons = wrapper.findAll('button[aria-pressed]');
        await buttons[2].trigger('click'); // "4"
        // Click Next
        const nextBtn = wrapper.findAll('button').find((b) => b.text() === 'Next');
        await nextBtn.trigger('click');
        expect(wrapper.emitted('confirm')).toBeTruthy();
        expect(wrapper.emitted('confirm')[0]).toEqual([4]);
    });

    it('resets selection to 2 after confirming', async () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        // Pick 6, confirm, then re-check
        const buttons = wrapper.findAll('button[aria-pressed]');
        await buttons[4].trigger('click'); // "6"
        const nextBtn = wrapper.findAll('button').find((b) => b.text() === 'Next');
        await nextBtn.trigger('click');
        const pressedAfter = wrapper
            .findAll('button[aria-pressed]')
            .filter((b) => b.attributes('aria-pressed') === 'true');
        expect(pressedAfter[0].text()).toBe('2');
    });

    it('emits "cancel" when the Cancel button is clicked', async () => {
        const wrapper = mount(SetPlayersDialog, {
            props: { show: true },
            global: { stubs: globalStubs },
        });
        const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'Cancel');
        await cancelBtn.trigger('click');
        expect(wrapper.emitted('cancel')).toBeTruthy();
    });
});
