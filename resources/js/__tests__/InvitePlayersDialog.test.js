import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import InvitePlayersDialog from '@/Components/InvitePlayersDialog.vue';

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
    template: '<button type="button" :disabled="disabled"><slot /></button>',
    props: ['disabled'],
};

const globalStubs = {
    Modal: modalStub,
    PrimaryButton: primaryBtnStub,
    SecondaryButton: secondaryBtnStub,
};

// ── Default props ─────────────────────────────────────────────────────────────

const defaultProps = {
    show: true,
    gameId: 1,
    maxPlayers: 4,
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeAxios(status = 201) {
    return {
        post: vi.fn().mockResolvedValue({ data: { invitations_sent: 2 } }),
    };
}

function makeAxiosError(message = 'Server error') {
    return {
        post: vi.fn().mockRejectedValue({ response: { data: { message } } }),
    };
}

function mountDialog(props = {}) {
    return mount(InvitePlayersDialog, {
        props: { ...defaultProps, ...props },
        global: { stubs: globalStubs },
    });
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('InvitePlayersDialog', () => {
    afterEach(() => {
        window.axios = undefined;
    });

    it('is hidden when show prop is false', () => {
        const wrapper = mountDialog({ show: false });
        expect(wrapper.find('h2').exists()).toBe(false);
    });

    it('renders the email input and add button when visible', () => {
        const wrapper = mountDialog();
        expect(wrapper.find('input[type="email"]').exists()).toBe(true);
        expect(wrapper.find('button[aria-label="Add email"]').exists()).toBe(true);
    });

    it('shows empty state message before any emails are added', () => {
        const wrapper = mountDialog();
        expect(wrapper.text()).toContain('No players added yet');
    });

    it('adds a valid email to the list on + button click', async () => {
        const wrapper = mountDialog();
        const input = wrapper.find('input[type="email"]');
        await input.setValue('alice@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        expect(wrapper.find('li').text()).toContain('alice@example.com');
    });

    it('adds a valid email on Enter key in the input', async () => {
        const wrapper = mountDialog();
        const input = wrapper.find('input[type="email"]');
        await input.setValue('bob@example.com');
        await input.trigger('keyup.enter');

        expect(wrapper.findAll('li')).toHaveLength(1);
        expect(wrapper.find('li').text()).toContain('bob@example.com');
    });

    it('shows an inline error for an invalid email format', async () => {
        const wrapper = mountDialog();
        await wrapper.find('input[type="email"]').setValue('not-an-email');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toContain('valid email address');
    });

    it('shows an inline error for a duplicate email', async () => {
        const wrapper = mountDialog();
        const input = wrapper.find('input[type="email"]');

        await input.setValue('dup@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');
        await input.setValue('dup@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        expect(wrapper.find('[role="alert"]').text()).toContain('already been added');
    });

    it('disables the input and add button at the max-guest cap', async () => {
        // maxPlayers=2 → maxGuests=1
        const wrapper = mountDialog({ maxPlayers: 2 });
        const input = wrapper.find('input[type="email"]');

        await input.setValue('a@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        // Cap reached — input and + button must be disabled
        expect(wrapper.findAll('li')).toHaveLength(1);
        expect(input.attributes('disabled')).toBeDefined();
        expect(wrapper.find('button[aria-label="Add email"]').attributes('disabled')).toBeDefined();
    });

    it('removes an email when the × button is clicked', async () => {
        const wrapper = mountDialog();
        const input = wrapper.find('input[type="email"]');

        await input.setValue('remove@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');
        expect(wrapper.findAll('li')).toHaveLength(1);

        await wrapper.find('button[aria-label="Remove remove@example.com"]').trigger('click');
        expect(wrapper.findAll('li')).toHaveLength(0);
    });

    it('Invite button is disabled when no emails are added', () => {
        const wrapper = mountDialog();
        const inviteBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Invite');
        expect(inviteBtn.attributes('disabled')).toBeDefined();
    });

    it('Invite button is enabled after adding at least one email', async () => {
        const wrapper = mountDialog();
        await wrapper.find('input[type="email"]').setValue('p@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        const inviteBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Invite');
        expect(inviteBtn.attributes('disabled')).toBeUndefined();
    });

    it('emits "invited" with the confirmed count on successful API call', async () => {
        window.axios = makeAxios();

        const wrapper = mountDialog();
        await wrapper.find('input[type="email"]').setValue('p@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        const inviteBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Invite');
        await inviteBtn.trigger('click');
        await flushPromises();

        expect(wrapper.emitted('invited')).toBeTruthy();
        expect(wrapper.emitted('invited')[0]).toEqual([2]);
    });

    it('shows an API error message when the POST fails', async () => {
        window.axios = makeAxiosError('Failed to send invitations.');

        const wrapper = mountDialog();
        await wrapper.find('input[type="email"]').setValue('p@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        const inviteBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Invite');
        await inviteBtn.trigger('click');
        await flushPromises();

        expect(wrapper.emitted('invited')).toBeFalsy();
        expect(wrapper.text()).toContain('Failed to send invitations');
    });

    it('emits "skip" when the Skip button is clicked', async () => {
        const wrapper = mountDialog();
        const skipBtn = wrapper.findAll('button').find((b) => b.text() === 'Skip');
        await skipBtn.trigger('click');

        expect(wrapper.emitted('skip')).toBeTruthy();
    });

    it('emits "cancel" when the Cancel button is clicked', async () => {
        const wrapper = mountDialog();
        const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'Cancel');
        await cancelBtn.trigger('click');

        expect(wrapper.emitted('cancel')).toBeTruthy();
    });

    it('emits "back" when the Back button is clicked', async () => {
        const wrapper = mountDialog();
        const backBtn = wrapper.findAll('button').find((b) => b.text() === 'Back');
        await backBtn.trigger('click');

        expect(wrapper.emitted('back')).toBeTruthy();
    });

    it('resets state when dialog is reopened', async () => {
        const wrapper = mountDialog();
        await wrapper.find('input[type="email"]').setValue('p@example.com');
        await wrapper.find('button[aria-label="Add email"]').trigger('click');

        // Close then re-open
        await wrapper.setProps({ show: false });
        await wrapper.setProps({ show: true });

        expect(wrapper.findAll('li')).toHaveLength(0);
    });
});
