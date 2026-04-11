<template>
    <Modal :show="show" max-width="lg" :closeable="true" @close="$emit('cancel')">
        <div class="p-6">
            <h2 class="text-lg font-bold text-gray-900 mb-1">Choose Your Token</h2>
            <p class="text-sm text-gray-500 mb-6">Select the icon you'll use for this game.</p>

            <!-- Loading state -->
            <div v-if="loading" class="flex items-center justify-center py-12" aria-live="polite">
                <svg class="animate-spin h-8 w-8 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                </svg>
                <span class="sr-only">Loading icons…</span>
            </div>

            <!-- Error state -->
            <p
                v-else-if="fetchError"
                class="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-4 py-3 mb-6"
                role="alert"
            >
                {{ fetchError }}
            </p>

            <!-- Icon grid -->
            <div
                v-else
                class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8"
                role="listbox"
                aria-label="Player token icons"
            >
                <button
                    v-for="icon in icons"
                    :key="icon.id"
                    type="button"
                    role="option"
                    :aria-selected="selected === icon.id"
                    :class="[
                        'flex flex-col items-center gap-2 rounded-xl border-2 p-3 transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2',
                        selected === icon.id
                            ? 'border-green-500 bg-green-50 shadow-md'
                            : 'border-gray-200 bg-white hover:border-green-300 hover:bg-green-50',
                    ]"
                    @click="selected = icon.id"
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

            <!-- Actions -->
            <div class="flex flex-col-reverse sm:flex-row sm:justify-between gap-3">
                <SecondaryButton @click="$emit('back')">Back</SecondaryButton>
                <div class="flex flex-col-reverse sm:flex-row gap-3">
                    <SecondaryButton @click="$emit('cancel')">Cancel</SecondaryButton>
                    <PrimaryButton :disabled="selected === null || loading" @click="handleConfirm">
                        Start Game
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    /** Controls modal visibility. */
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'cancel', 'back']);

/** List of PlayerIcon records fetched from the API. */
const icons = ref([]);

/** ID of the currently selected icon, or null if none chosen. */
const selected = ref(null);

/** True while the icon list is being fetched. */
const loading = ref(false);

/** Error message when the fetch fails, or null. */
const fetchError = ref(null);

/**
 * Fetch the icon list whenever the dialog becomes visible.
 *
 * Logic: Only fires if icons have not yet been loaded (icons.value is empty)
 * so repeat open/close cycles do not trigger redundant API calls. The watch
 * runs immediately on mount (immediate: true) so icons are fetched when the
 * component is first shown. On failure the error is surfaced in-dialog
 * without blocking the rest of the UI.
 */
watch(
    () => props.show,
    async (isVisible) => {
        if (!isVisible || icons.value.length > 0) return;

        loading.value    = true;
        fetchError.value = null;

        try {
            const response   = await window.axios.get('/api/player-icons');
            icons.value      = response.data.player_icons;
        } catch (err) {
            fetchError.value = err.response?.data?.message ?? 'Unable to load icons. Please try again.';
        } finally {
            loading.value = false;
        }
    },
    { immediate: true },
);

/**
 * Emit the confirm event with the selected icon ID.
 *
 * Logic: Only callable when an icon has been selected (button is disabled
 * otherwise). Resets selection so the dialog is clean if re-opened.
 */
function handleConfirm() {
    if (selected.value === null) return;
    emit('confirm', selected.value);
    selected.value = null;
}
</script>
