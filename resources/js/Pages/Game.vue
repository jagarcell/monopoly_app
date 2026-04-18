<script setup>
import MonopolyBoard from '@/Components/MonopolyBoard.vue';
import { Head } from '@inertiajs/vue3';

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
     * Each entry: { user_id, name, is_creator, join_order, icon,
     * properties, chance_cards, community_chest_cards }.
     */
    players: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head :title="game.name" />

    <MonopolyBoard :game="game" :players="players" />
</template>
