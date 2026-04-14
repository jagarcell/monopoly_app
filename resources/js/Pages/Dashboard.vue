<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreateGameCard from '@/Components/CreateGameCard.vue';
import MonopolyBoard from '@/Components/MonopolyBoard.vue';
import SetPlayersDialog from '@/Components/SetPlayersDialog.vue';
import IconPickerDialog from '@/Components/IconPickerDialog.vue';
import InvitePlayersDialog from '@/Components/InvitePlayersDialog.vue';
import { Head } from '@inertiajs/vue3';
import { ref, nextTick } from 'vue';
import axios from 'axios';

/** Holds the created game returned by the API; null means no game yet. */
const game                  = ref(null);
const players               = ref([]);
const loading               = ref(false);
const error                 = ref(null);
const dialogVisible         = ref(false);
const iconDialogVisible     = ref(false);
const inviteDialogVisible   = ref(false);

/**
 * True only after the invite flow has been fully resolved (invited,
 * skipped, or cancelled). Controls when the Monopoly board is revealed.
 */
const gameReady = ref(false);

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
 * Call POST /api/games with the chosen player count and icon, then open the invite dialog.
 *
 * Logic: Closes the icon dialog immediately, sends the API request with
 * max_players and player_icon_id, stores the returned game, then opens the
 * InvitePlayersDialog. Rolls back loading on failure.
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
        game.value    = response.data.game;
        players.value = response.data.players ?? [];
        // Wait one tick so InvitePlayersDialog mounts with show=false first,
        // then the watch inside Modal.vue fires correctly when show flips to true.
        await nextTick();
        inviteDialogVisible.value = true;
    } catch (e) {
        error.value             = e.response?.data?.message ?? 'Unable to create game. Please try again.';
        loading.value           = false;
        pendingMaxPlayers.value = null;
    } finally {
        // Keep loading=true until invite dialog is dismissed so the card stays disabled.
        if (!game.value) loading.value = false;
    }
}

/**
 * Invitations were sent; close the invite dialog and proceed to the board.
 *
 * Logic: Closes the invite dialog. The board is revealed because game.value
 * is already set; the loading flag is cleared.
 *
 * @param {number} _sentCount - The number of invitations the API confirmed.
 */
function handleInviteConfirm(_sentCount) {
    inviteDialogVisible.value = false;
    loading.value             = false;
    gameReady.value           = true;
}

/**
 * Creator skipped the invite step; proceed directly to the board.
 *
 * Logic: Closes the invite dialog and clears loading so the board renders.
 */
function handleInviteSkip() {
    inviteDialogVisible.value = false;
    loading.value             = false;
    gameReady.value           = true;
}

/**
 * Return from the invite step back to the icon-picker step.
 *
 * Logic: Closes the invite dialog and re-opens icon picker. The already-created
 * game is discarded — the user will create a new one after re-confirming.
 */
function handleInviteBack() {
    inviteDialogVisible.value = false;
    game.value                = null;
    gameReady.value           = false;
    iconDialogVisible.value   = true;
}

/**
 * Cancel from the invite step.
 *
 * Logic: Closes the invite dialog. The game was already created so we keep it
 * and go straight to the board (equivalent to skip).
 */
function handleInviteCancel() {
    inviteDialogVisible.value = false;
    loading.value             = false;
    gameReady.value           = true;
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout v-if="!gameReady">
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
            :max-players="pendingMaxPlayers"
            @confirm="handleIconConfirm"
            @cancel="handleIconCancel"
            @back="handleIconBack"
        />
    </AuthenticatedLayout>

    <!--
        InvitePlayersDialog lives outside the layout so it remains mounted
        while the game is being created and the layout condition changes.
        It is only rendered after the game exists and before the board is shown.
    -->
    <InvitePlayersDialog
        v-if="game && !gameReady"
        :show="inviteDialogVisible"
        :game-id="game.id"
        :max-players="game.max_players"
        @invited="handleInviteConfirm"
        @skip="handleInviteSkip"
        @back="handleInviteBack"
        @cancel="handleInviteCancel"
    />

    <!-- Full-screen board replaces everything once the invite flow is done -->
    <MonopolyBoard v-if="gameReady" :game="game" :players="players" />
</template>
