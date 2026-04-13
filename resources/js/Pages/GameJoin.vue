<template>
    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center px-4 py-10">
        <!-- Error state (invalid / expired / already-used token) -->
        <div v-if="errorMessage" class="w-full max-w-md bg-white rounded-2xl shadow-md p-8 text-center">
            <h1 class="text-xl font-bold text-gray-900 mb-3">Unable to Join</h1>
            <p class="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-4 py-3 mb-6">
                {{ errorMessage }}
            </p>
            <a href="/" class="text-sm text-green-600 underline">Return to home</a>
        </div>

        <!-- Join flow -->
        <div v-else class="w-full max-w-lg bg-white rounded-2xl shadow-md p-8">
            <h1 class="text-2xl font-bold text-gray-900 mb-1">Join {{ gameName }}</h1>
            <p class="text-sm text-gray-500 mb-8">
                <strong>{{ creatorName }}</strong> invited you to play. Pick your token below.
            </p>

            <!-- Icon grid -->
            <div v-if="availableIcons.length > 0">
                <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
                    Available tokens
                </p>
                <div
                    class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6"
                    role="listbox"
                    aria-label="Available player tokens"
                >
                    <button
                        v-for="icon in availableIcons"
                        :key="icon.id"
                        type="button"
                        role="option"
                        :aria-selected="selectedIconId === icon.id"
                        :class="[
                            'flex flex-col items-center gap-2 rounded-xl border-2 p-3 transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2',
                            selectedIconId === icon.id
                                ? 'border-green-500 bg-green-50 shadow-md'
                                : 'border-gray-200 bg-white hover:border-green-300 hover:bg-green-50',
                        ]"
                        @click="selectedIconId = icon.id"
                    >
                        <img
                            :src="icon.image_url"
                            :alt="icon.name"
                            class="w-14 h-14 object-contain"
                            loading="lazy"
                        />
                        <span class="text-xs font-semibold text-gray-700 text-center leading-tight">
                            {{ icon.name }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- No icons left -->
            <div v-else class="text-sm text-gray-500 italic mb-6">
                All player tokens have been taken for this game.
            </div>

            <!-- Conflict / API error -->
            <p
                v-if="joinError"
                class="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-4 py-2 mb-4"
                role="alert"
            >
                {{ joinError }}
            </p>

            <!-- Actions -->
            <div class="flex justify-end">
                <button
                    type="button"
                    :disabled="selectedIconId === null || joining || availableIcons.length === 0"
                    class="inline-flex items-center gap-2 rounded-lg bg-green-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2 disabled:bg-gray-300 disabled:cursor-not-allowed"
                    @click="handleJoin"
                >
                    <svg
                        v-if="joining"
                        class="animate-spin h-4 w-4 text-white"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                    </svg>
                    Join Game
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    /** Join URL token passed as Inertia prop. */
    token: {
        type: String,
        default: null,
    },
    /** Name of the game being joined. */
    gameName: {
        type: String,
        default: '',
    },
    /** Name of the user who created the game. */
    creatorName: {
        type: String,
        default: '',
    },
    /**
     * Icons not yet taken by other players. Each item has:
     * { id, name, image_url, sort_order }
     */
    availableIcons: {
        type: Array,
        default: () => [],
    },
    /** Pre-set error passed from the controller when the token is invalid/expired. */
    error: {
        type: String,
        default: null,
    },
});

/** Error message to display in the error-state panel. */
const errorMessage = ref(props.error);

/** ID of the icon the guest has selected, or null. */
const selectedIconId = ref(null);

/** Reactive copy of available icons so the list can be refreshed on 409. */
const availableIcons = ref([...props.availableIcons]);

/** Inline error shown after a failed join attempt. */
const joinError = ref(null);

/** True while the join API call is in flight. */
const joining = ref(false);

/**
 * POST to accept the invitation with the selected icon.
 *
 * Logic: Sends POST /join/{token}/accept with the chosen player_icon_id. On
 * success the Monopoly board page would normally be rendered; for now the game
 * data is available in the response for future integration. On HTTP 409 (icon
 * conflict) the server returns the refreshed available-icon list and an inline
 * message so the guest can pick again — no full reload needed. On other errors
 * a generic message is shown.
 *
 * @return {Promise<void>}
 */
async function handleJoin() {
    if (selectedIconId.value === null || joining.value) return;

    joining.value  = true;
    joinError.value = null;

    try {
        await axios.post(`/join/${props.token}/accept`, {
            player_icon_id: selectedIconId.value,
        });

        // The invitation is accepted; navigate to the guest game board.
        window.location.href = `/join/${props.token}/game`;
    } catch (err) {
        if (err.response?.status === 409) {
            joinError.value      = err.response.data.message;
            availableIcons.value = err.response.data.availableIcons ?? [];
            selectedIconId.value = null;
        } else {
            joinError.value = err.response?.data?.message ?? 'Failed to join the game. Please try again.';
        }
    } finally {
        joining.value = false;
    }
}
</script>
