<script setup>
/**
 * PendingInvitationsList
 *
 * Renders a compact list of players who have been invited but have not yet
 * joined the game. Each row shows a partially masked email so the display is
 * informative without exposing the full address to other players.
 *
 * Hidden entirely when pendingInvitations is empty, so it takes no layout
 * space once all invited players have joined.
 *
 * Props:
 *   pendingInvitations – Array of { email: string } objects.
 */

const props = defineProps({
    /**
     * Invitations that are still pending (not yet accepted, not expired).
     * Each entry must have at least an `email` string field.
     */
    pendingInvitations: {
        type: Array,
        default: () => [],
    },
});

/**
 * Partially mask an email address for display.
 *
 * Logic: Splits the address into local-part and domain. Keeps the first
 * character of the local-part and replaces the rest with '…' so the row is
 * recognisable to the person who sent the invite without revealing the full
 * address to other players on screen.
 *
 * @param {string} email - The raw email address.
 * @returns {string}
 */
function maskEmail(email) {
    const [local, domain] = email.split('@');
    if (!domain) return email;
    return `${local.charAt(0)}…@${domain}`;
}
</script>

<template>
    <div
        v-if="pendingInvitations.length > 0"
        class="w-full rounded-xl border border-yellow-200 bg-yellow-50 px-3 py-2"
        data-testid="pending-invitations-list"
    >
        <p class="text-xs font-semibold text-yellow-700 uppercase tracking-wide mb-1">
            Waiting to join
        </p>
        <ul class="space-y-1" aria-label="Pending invitations">
            <li
                v-for="(invitation, index) in pendingInvitations"
                :key="index"
                class="flex items-center gap-2 text-xs text-yellow-800"
                data-testid="pending-invitation-item"
            >
                <!-- Clock icon -->
                <svg
                    class="w-3 h-3 flex-shrink-0 text-yellow-500"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10" />
                    <polyline points="12 6 12 12 16 14" />
                </svg>
                <span>{{ maskEmail(invitation.email) }}</span>
            </li>
        </ul>
    </div>
</template>
