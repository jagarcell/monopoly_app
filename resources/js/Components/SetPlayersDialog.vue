<template>
    <Modal :show="show" max-width="sm" :closeable="true" @close="$emit('cancel')">
        <div class="p-6">
            <h2 class="text-lg font-bold text-gray-900 mb-1">Number of Players</h2>
            <p class="text-sm text-gray-500 mb-6">Choose how many players will join this game (2–8).</p>

            <!-- Player-count selector -->
            <div class="flex flex-wrap justify-center gap-3 mb-8">
                <button
                    v-for="n in playerOptions"
                    :key="n"
                    type="button"
                    :aria-pressed="selected === n"
                    :class="[
                        'w-12 h-12 rounded-full text-base font-bold border-2 transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2',
                        selected === n
                            ? 'bg-green-500 border-green-500 text-white shadow-md'
                            : 'bg-white border-gray-300 text-gray-700 hover:border-green-400 hover:text-green-600',
                    ]"
                    @click="selected = n"
                >
                    {{ n }}
                </button>
            </div>

            <!-- Actions -->
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
                <SecondaryButton @click="$emit('cancel')">Cancel</SecondaryButton>
                <PrimaryButton @click="handleConfirm">Next</PrimaryButton>
            </div>
        </div>
    </Modal>
</template>

<script setup>
import { ref } from 'vue';
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

const emit = defineEmits(['confirm', 'cancel']);

/** The range of valid player counts as per game rules. */
const playerOptions = [2, 3, 4, 5, 6, 7, 8];

/** Currently selected player count; defaults to the minimum (2). */
const selected = ref(2);

/**
 * Emit the confirm event with the chosen player count.
 *
 * Resets selection back to the default so the dialog is clean if re-opened.
 */
function handleConfirm() {
    emit('confirm', selected.value);
    selected.value = 2;
}
</script>
