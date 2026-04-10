<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreateGameCard from '@/Components/CreateGameCard.vue';
import MonopolyBoard from '@/Components/MonopolyBoard.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

/** Holds the created game returned by the API; null means no game yet. */
const game    = ref(null);
const loading = ref(false);
const error   = ref(null);

/**
 * Call POST /api/games to create a game for the authenticated user.
 *
 * Logic: Sets loading state optimistically to disable the button, sends the
 * API request, merges the returned game into reactive state, and rolls back
 * loading on failure so the user can retry.
 */
async function handleCreateGame() {
    if (loading.value) return;

    loading.value = true;
    error.value   = null;

    try {
        const response = await axios.post('/api/games');
        game.value = response.data.game;
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Unable to create game. Please try again.';
        loading.value = false;
    }
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout v-if="!game">
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h2>
        </template>

        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <p
                    v-if="error"
                    class="mb-4 text-sm text-red-600 bg-red-50 border border-red-200 rounded px-4 py-2"
                >
                    {{ error }}
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <CreateGameCard :loading="loading" @create="handleCreateGame" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

    <!-- Full-screen board replaces everything once the game is created -->
    <MonopolyBoard v-else :game="game" />
</template>
