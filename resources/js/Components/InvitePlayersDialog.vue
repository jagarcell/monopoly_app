<template>
    <Modal :show="show" max-width="lg" :closeable="true" @close="$emit('cancel')">
        <div class="p-6">
            <h2 class="text-lg font-bold text-gray-900 mb-1">Invite Players</h2>
            <p class="text-sm text-gray-500 mb-6">
                Add up to {{ maxGuests }} player email{{ maxGuests === 1 ? '' : 's' }} to invite.
            </p>

            <!-- Email input row -->
            <div class="flex gap-2 mb-4">
                <div class="flex-1">
                    <input
                        v-model="emailInput"
                        type="email"
                        placeholder="player@example.com"
                        maxlength="254"
                        :disabled="emails.length >= maxGuests"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-400 focus:border-transparent disabled:bg-gray-100 disabled:cursor-not-allowed"
                        aria-label="Email address to invite"
                        @keyup.enter="addEmail"
                    />
                    <p v-if="inputError" class="mt-1 text-xs text-red-600" role="alert">
                        {{ inputError }}
                    </p>
                </div>
                <button
                    type="button"
                    :disabled="emails.length >= maxGuests"
                    class="flex-shrink-0 w-10 h-10 rounded-full bg-green-500 text-white font-bold text-xl leading-none flex items-center justify-center transition-colors duration-150 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2 disabled:bg-gray-300 disabled:cursor-not-allowed"
                    aria-label="Add email"
                    @click="addEmail"
                >
                    +
                </button>
            </div>

            <!-- Email chip list -->
            <ul v-if="emails.length > 0" class="flex flex-wrap gap-2 mb-6" aria-label="Invited email addresses">
                <li
                    v-for="email in emails"
                    :key="email"
                    class="flex items-center gap-1 bg-green-50 border border-green-200 text-green-800 text-sm rounded-full px-3 py-1"
                >
                    <span>{{ email }}</span>
                    <button
                        type="button"
                        class="ml-1 text-green-600 hover:text-red-500 focus:outline-none focus:ring-2 focus:ring-red-300 rounded-full leading-none"
                        :aria-label="`Remove ${email}`"
                        @click="removeEmail(email)"
                    >
                        &times;
                    </button>
                </li>
            </ul>

            <p v-else class="text-sm text-gray-400 italic mb-6">No players added yet.</p>

            <!-- API error -->
            <p
                v-if="apiError"
                class="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-4 py-2 mb-4"
                role="alert"
            >
                {{ apiError }}
            </p>

            <!-- Actions -->
            <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-3">
                <SecondaryButton @click="$emit('back')">Back</SecondaryButton>
                <div class="flex flex-col-reverse sm:flex-row gap-3">
                    <SecondaryButton :disabled="loading" @click="$emit('cancel')">Cancel</SecondaryButton>
                    <SecondaryButton :disabled="loading" @click="handleSkip">Skip</SecondaryButton>
                    <PrimaryButton
                        :disabled="emails.length === 0 || loading"
                        @click="handleInvite"
                    >
                        <svg
                            v-if="loading"
                            class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                        </svg>
                        Invite
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    /** Controls modal visibility. */
    show: {
        type: Boolean,
        default: false,
    },
    /** The ID of the newly created game to send invitations for. */
    gameId: {
        type: Number,
        required: true,
    },
    /** Maximum players configured for the game; caps invite count at max − 1. */
    maxPlayers: {
        type: Number,
        required: true,
    },
});

const emit = defineEmits(['invited', 'skip', 'cancel', 'back']);

/** Maximum guests that can be invited (one slot is held by the creator). */
const maxGuests = computed(() => props.maxPlayers - 1);

/** Current value of the email text input. */
const emailInput = ref('');

/** List of email addresses added to the invite list. */
const emails = ref([]);

/** Validation error for the email input field. */
const inputError = ref(null);

/** API-level error message. */
const apiError = ref(null);

/** True while the API call is in flight. */
const loading = ref(false);

// Reset state whenever the dialog is opened fresh.
watch(
    () => props.show,
    (visible) => {
        if (!visible) return;
        emailInput.value = '';
        emails.value     = [];
        inputError.value = null;
        apiError.value   = null;
        loading.value    = false;
    },
);

/**
 * Validate and add the current emailInput to the invite list.
 *
 * Logic: Trims the input, validates format with a simple RFC-5321-safe regex,
 * guards against duplicates and the max-guest cap, then pushes to the list.
 * Inline errors are cleared on the next successful add.
 *
 * @return void
 */
function addEmail() {
    const val = emailInput.value.trim().toLowerCase();
    inputError.value = null;

    if (!val) return;

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(val)) {
        inputError.value = 'Please enter a valid email address.';
        return;
    }

    if (emails.value.includes(val)) {
        inputError.value = 'This email has already been added.';
        return;
    }

    if (emails.value.length >= maxGuests.value) {
        inputError.value = `You can invite at most ${maxGuests.value} player(s).`;
        return;
    }

    emails.value.push(val);
    emailInput.value = '';
}

/**
 * Remove an email address from the invite list.
 *
 * @param  {string}  email  The address to remove.
 * @return void
 */
function removeEmail(email) {
    emails.value = emails.value.filter((e) => e !== email);
}

/**
 * POST the invite list to the API and emit 'invited' on success.
 *
 * Logic: Sends a POST /api/games/{gameId}/invitations with the email list.
 * Sets loading optimistically to prevent double-submit. On success emits
 * 'invited' with the server-confirmed count. On failure surfaces apiError.
 *
 * @return {Promise<void>}
 */
async function handleInvite() {
    if (emails.value.length === 0 || loading.value) return;

    loading.value  = true;
    apiError.value = null;

    try {
        const response = await window.axios.post(`/api/games/${props.gameId}/invitations`, {
            emails: emails.value,
        });
        emit('invited', response.data.invitations_sent);
    } catch (err) {
        apiError.value = err.response?.data?.message ?? 'Failed to send invitations. Please try again.';
    } finally {
        loading.value = false;
    }
}

/**
 * Skip the invite step and emit 'skip' so the caller can proceed directly.
 *
 * @return void
 */
function handleSkip() {
    emit('skip');
}
</script>
