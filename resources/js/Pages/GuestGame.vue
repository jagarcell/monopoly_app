<script setup>
import MonopolyBoard from '@/Components/MonopolyBoard.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** UUID token used to authenticate guest draw API calls. */
    token: {
        type: String,
        default: null,
    },
    /** The game object: { id, name, user_id, status } */
    game: {
        type: Object,
        default: null,
    },
    /** Pre-set error message when the token is invalid or not yet accepted. */
    error: {
        type: String,
        default: null,
    },
    /**
     * Array of player objects for all players who have joined the game.
     * Each entry: { user_id, invitation_id, name, is_creator, capital, icon,
     * properties, chance_cards, community_chest_cards }.
     */
    players: {
        type: Array,
        default: () => [],
    },
    /**
     * The ID of the game_invitations row that belongs to the current guest.
     * Passed by the server so the guest's own card can show their capital
     * without revealing it on any other player's card.
     */
    currentInvitationId: {
        type: Number,
        default: null,
    },
    /**
     * Invitations that have been sent but not yet accepted (and not expired).
     * Each entry: { email: string }.
     */
    pendingInvitations: {
        type: Array,
        default: () => [],
    },
});

/** Shared debug mode flag from the server. */
const debugMode = computed(() => Boolean(usePage().props.debugMode));
</script>

<template>
    <Head title="Game Board" />

    <!-- Error state: invalid / pending / unknown token -->
    <div
        v-if="error"
        class="min-h-screen bg-gray-50 flex flex-col items-center justify-center px-4 py-10"
    >
        <div class="w-full max-w-md bg-white rounded-2xl shadow-md p-8 text-center">
            <h1 class="text-xl font-bold text-gray-900 mb-3">Unable to Load Game</h1>
            <p class="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-4 py-3 mb-6">
                {{ error }}
            </p>
            <a href="/" class="text-sm text-green-600 underline">Return to home</a>
        </div>
    </div>

    <!-- Full-screen board for accepted guests -->
    <MonopolyBoard
        v-else-if="game"
        :game="game"
        :invitation-token="token"
        :players="players"
        :pending-invitations="pendingInvitations"
        :current-invitation-id="currentInvitationId"
        :debug-mode="debugMode"
    />
</template>
