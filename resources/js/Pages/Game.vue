<script setup>
import MonopolyBoard from '@/Components/MonopolyBoard.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /**
     * The game object returned by the server.
     * Contains id, name, user_id, status, max_players.
     */
    game: {
        type: Object,
        required: true,
    },

    /**
     * Array of player objects for all players who have joined the game,
     * ordered by join_order.
     * Each entry: { user_id, invitation_id, name, is_creator, join_order,
     * capital, icon, properties, chance_cards, community_chest_cards }.
     */
    players: {
        type: Array,
        default: () => [],
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

/** The authenticated user's ID, used to identify the current player's card. */
const currentUserId = computed(() => usePage().props.auth?.user?.id ?? null);

/** Shared debug mode flag from the server. */
const debugMode = computed(() => Boolean(usePage().props.debugMode));
</script>

<template>
    <Head :title="game.name" />

    <MonopolyBoard
        :game="game"
        :players="players"
        :pending-invitations="pendingInvitations"
        :current-user-id="currentUserId"
        :debug-mode="debugMode"
    />
</template>
