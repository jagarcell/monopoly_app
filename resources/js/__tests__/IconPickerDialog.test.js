import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import IconPickerDialog from '@/Components/IconPickerDialog.vue';

// ── Stubs ─────────────────────────────────────────────────────────────────────

const modalStub = {
    name: 'Modal',
    template: '<div v-if="show"><slot /></div>',
    props: ['show', 'maxWidth', 'closeable'],
    emits: ['close'],
};

const primaryBtnStub = {
    name: 'PrimaryButton',
    template: '<button type="button" :disabled="disabled"><slot /></button>',
    props: ['disabled'],
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

// ── Fixture data ──────────────────────────────────────────────────────────────

const mockIcons = [
    { id: 1, name: 'Top Hat',     image_url: '/images/icons/top-hat.svg',     sort_order: 1 },
    { id: 2, name: 'Scottie Dog', image_url: '/images/icons/scottie-dog.svg', sort_order: 2 },
    { id: 3, name: 'Racing Car',  image_url: '/images/icons/racing-car.svg',  sort_order: 3 },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAxios(icons = mockIcons) {
    return {
        get: vi.fn().mockResolvedValue({ data: { player_icons: icons } }),
    };
}

function makeAxiosError(message = 'Server error') {
    return {
        get: vi.fn().mockRejectedValue({ response: { data: { message } } }),
    };
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('IconPickerDialog', () => {
    afterEach(() => {
        window.axios = undefined;
    });

    it('is hidden when show prop is false', () => {
        window.axios = makeAxios();
        const wrapper = mount(IconPickerDialog, {
            props: { show: false, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });
        expect(wrapper.find('h2').exists()).toBe(false);
    });

    it('shows loading spinner while fetching icons', async () => {
        // Never resolves immediately so loading state is visible
        window.axios = { get: vi.fn().mockReturnValue(new Promise(() => {})) };

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        // Immediate watch fires synchronously; loading is set before the first
        // await so it is true on the very next tick — no flushPromises needed.
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
    });

    it('renders an icon button for each fetched icon', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const buttons = wrapper.findAll('[role="option"]');
        expect(buttons).toHaveLength(mockIcons.length);
    });

    it('renders the icon name and image for each icon', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const buttons = wrapper.findAll('[role="option"]');
        expect(buttons[0].text()).toContain('Top Hat');
        expect(buttons[0].find('img').attributes('alt')).toBe('Top Hat');
        expect(buttons[0].find('img').attributes('src')).toBe('/images/icons/top-hat.svg');
    });

    it('has no icon selected initially (aria-selected="false" on all)', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const selected = wrapper
            .findAll('[role="option"]')
            .filter((b) => b.attributes('aria-selected') === 'true');

        expect(selected).toHaveLength(0);
    });

    it('marks the clicked icon as selected', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const buttons = wrapper.findAll('[role="option"]');
        await buttons[1].trigger('click'); // Scottie Dog

        const selected = wrapper
            .findAll('[role="option"]')
            .filter((b) => b.attributes('aria-selected') === 'true');

        expect(selected).toHaveLength(1);
        expect(selected[0].text()).toContain('Scottie Dog');
    });

    it('Invite Players button is disabled until an icon is selected', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const startBtn = wrapper.findAll('button').find((b) => b.text() === 'Invite Players');
        expect(startBtn.attributes('disabled')).toBeDefined();
    });

    it('Invite Players button is enabled after selecting an icon', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const iconButtons = wrapper.findAll('[role="option"]');
        await iconButtons[0].trigger('click');

        const startBtn = wrapper.findAll('button').find((b) => b.text() === 'Invite Players');
        expect(startBtn.attributes('disabled')).toBeUndefined();
    });

    it('emits "confirm" with the selected icon ID when Invite Players is clicked', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const iconButtons = wrapper.findAll('[role="option"]');
        await iconButtons[2].trigger('click'); // Racing Car (id=3)

        const startBtn = wrapper.findAll('button').find((b) => b.text() === 'Invite Players');
        await startBtn.trigger('click');

        expect(wrapper.emitted('confirm')).toBeTruthy();
        expect(wrapper.emitted('confirm')[0]).toEqual([3]);
    });

    it('resets selected icon after confirming', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const iconButtons = wrapper.findAll('[role="option"]');
        await iconButtons[0].trigger('click');

        const startBtn = wrapper.findAll('button').find((b) => b.text() === 'Invite Players');
        await startBtn.trigger('click');

        const selected = wrapper
            .findAll('[role="option"]')
            .filter((b) => b.attributes('aria-selected') === 'true');

        expect(selected).toHaveLength(0);
    });

    it('emits "back" when the Back button is clicked', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const backBtn = wrapper.findAll('button').find((b) => b.text() === 'Back');
        await backBtn.trigger('click');

        expect(wrapper.emitted('back')).toBeTruthy();
    });

    it('emits "cancel" when the Cancel button is clicked', async () => {
        window.axios = makeAxios();

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'Cancel');
        await cancelBtn.trigger('click');

        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('shows error message when icon fetch fails', async () => {
        window.axios = makeAxiosError('Unable to load icons. Please try again.');

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
        expect(wrapper.find('[role="alert"]').text()).toContain('Unable to load icons');
    });

    it('does not re-fetch icons on a second open when already loaded', async () => {
        const axiosMock = makeAxios();
        window.axios = axiosMock;

        const wrapper = mount(IconPickerDialog, {
            props: { show: true, maxPlayers: 4 },
            global: { stubs: globalStubs },
        });

        // First open fetches icons
        await flushPromises();
        expect(axiosMock.get).toHaveBeenCalledTimes(1);

        // Close then re-open — icons already loaded, no second fetch
        await wrapper.setProps({ show: false });
        await wrapper.setProps({ show: true });
        await flushPromises();

        expect(axiosMock.get).toHaveBeenCalledTimes(1);
    });
});
