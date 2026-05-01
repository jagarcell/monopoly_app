import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import PendingInvitationsList from '@/Components/PendingInvitationsList.vue';

describe('PendingInvitationsList', () => {
    it('renders nothing when pendingInvitations is empty', () => {
        const wrapper = mount(PendingInvitationsList, {
            props: { pendingInvitations: [] },
        });

        expect(wrapper.find('[data-testid="pending-invitations-list"]').exists()).toBe(false);
    });

    it('renders the list container when invitations are present', () => {
        const wrapper = mount(PendingInvitationsList, {
            props: { pendingInvitations: [{ email: 'alice@example.com' }] },
        });

        expect(wrapper.find('[data-testid="pending-invitations-list"]').exists()).toBe(true);
    });

    it('renders a row for each pending invitation', () => {
        const wrapper = mount(PendingInvitationsList, {
            props: {
                pendingInvitations: [
                    { email: 'alice@example.com' },
                    { email: 'bob@example.com' },
                ],
            },
        });

        const items = wrapper.findAll('[data-testid="pending-invitation-item"]');
        expect(items).toHaveLength(2);
    });

    it('masks the email address', () => {
        const wrapper = mount(PendingInvitationsList, {
            props: { pendingInvitations: [{ email: 'alice@example.com' }] },
        });

        // Should show masked form (first char + … + @domain), not the raw address.
        expect(wrapper.text()).toContain('a…@example.com');
        expect(wrapper.text()).not.toContain('alice@example.com');
    });

    it('shows "Waiting to join" heading', () => {
        const wrapper = mount(PendingInvitationsList, {
            props: { pendingInvitations: [{ email: 'x@y.com' }] },
        });

        expect(wrapper.text()).toContain('Waiting to join');
    });

    it('handles a single-character local-part email without error', () => {
        const wrapper = mount(PendingInvitationsList, {
            props: { pendingInvitations: [{ email: 'a@b.com' }] },
        });

        expect(wrapper.find('[data-testid="pending-invitation-item"]').exists()).toBe(true);
    });
});
