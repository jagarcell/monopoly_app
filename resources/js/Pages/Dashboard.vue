<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreateGameCard from '@/Components/CreateGameCard.vue';
import MonopolyBoard from '@/Components/MonopolyBoard.vue';
import SetPlayersDialog from '@/Components/SetPlayersDialog.vue';
import IconPickerDialog from '@/Components/IconPickerDialog.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

/** Holds the created game returned by the API; null means no game yet. */
const game              = ref(null);
const loading           = ref(false);
const error             = ref(null);
const dialogVisible     = ref(false);
const iconDialogVisible = ref(false);

/** Temporarily holds the chosen max_players between dialog steps. */
const pendingMaxPlayers = ref(null);

/**
 * Open the player-count dialog when the user clicks Create Game.
 *
 * Logic: Sets dialogVisible to true so SetPlayersDialog becomes visible.
 * The actual API call is deferred until the user completes both steps.
 */
function handleCreateClick() {
    if (loading.value) return;
    dialogVisible.value = true;
}

/**
 * Close the player-count dialog without creating a game.
 *
 * Logic: Sets dialogVisible back to false; no network request is fired.
 */
function handleDialogCancel() {
    dialogVisible.value = false;
}

/**
 * Advance from the player-count step to the icon-picker step.
 *
 * Logic: Stores the chosen max_players in pendingMaxPlayers, closes the
 * player-count dialog, and opens the icon-picker dialog.
 *
 * @param {number} maxPlayers - The player count chosen in the dialog (2–8).
 */
function handleDialogConfirm(maxPlayers) {
    pendingMaxPlayers.value = maxPlayers;
    dialogVisible.value     = false;
    iconDialogVisible.value  = true;
}

/**
 * Return from the icon-picker step to the player-count step.
 *
 * Logic: Closes the icon dialog and re-opens the player-count dialog so the
 * user can adjust their selection without starting over.
 */
function handleIconBack() {
    iconDialogVisible.value = false;
    dialogVisible.value = true;
}

/**
 * Cancel from the icon-picker step.
 *
 * Logic: Closes icon dialog completely and clears pending state.
 */
function handleIconCancel() {
    iconDialogVisible.value = false;
    pendingMaxPlayers.value = null;
}

/**
 * Call POST /api/games with the chosen player count and icon.
 *
 * Logic: Closes the icon dialog immediately, sets loading state optimistically
 * to disable the card button, sends the API request with max_players and
 * player_icon_id, merges the returned game into reactive state, and rolls back
 * loading on failure so the user can retry.
 *
 * @param {number} playerIconId - The ID of the chosen PlayerIcon.
 */
async function handleIconConfirm(playerIconId) {
    iconDialogVisible.value = false;
    loading.value           = true;
    error.value             = null;

    try {
        const response = await axios.post('/api/games', {
            max_players:    pendingMaxPlayers.value,
            player_icon_id: playerIconId,
        });
        game.value = response.data.game;
    } catch (e) {
        error.value             = e.response?.data?.message ?? 'Unable to create game. Please try again.';
        loading.value           = false;
        pendingMaxPlayers.value = null;
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
                    <CreateGameCard :loading="loading" @create="handleCreateClick" />
                </div>
            </div>
        </div>

        <SetPlayersDialog
            :show="dialogVisible"
            @confirm="handleDialogConfirm"
            @cancel="handleDialogCancel"
        />

        <IconPickerDialog
            :show="iconDialogVisible"
            @confirm="handleIconConfirm"
            @cancel="handleIconCancel"
            @back="handleIconBack"
        />
    </AuthenticatedLayout>

    <!-- Full-screen board replaces everything once the game is created -->
    <MonopolyBoard v-else :game="game" />
</template>
