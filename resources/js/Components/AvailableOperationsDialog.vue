<script setup>
/**
 * AvailableOperationsDialog
 *
 * Presents manual operation actions that a player can request during their turn.
 */

import { computed } from 'vue';

const props = defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    enabledOperationKeys: {
        type: Array,
        default: () => [],
    },
    zIndex: {
        type: Number,
        default: 220,
    },
});

const emit = defineEmits(['close', 'select-operation']);

const operationButtons = [
    { key: 'build', label: 'Build' },
    { key: 'mortgage-property', label: 'Mortgage Property' },
    { key: 'unmortgage-property', label: 'Unmortgage Property' },
    { key: 'use-get-out-of-jail-card', label: 'Use Get Out Of The Jail Card' },
    { key: 'pay-jail-release', label: 'Pay $50 To Leave Jail' },
];

const enabledOperationSet = computed(
    () => new Set((props.enabledOperationKeys ?? []).map(key => String(key))),
);

function isOperationEnabled(operationKey) {
    return enabledOperationSet.value.has(String(operationKey));
}

function handleOperationClick(operationKey) {
    if (!isOperationEnabled(operationKey)) {
        return;
    }

    emit('select-operation', operationKey);
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
    >
        <div
            v-if="visible"
            class="fixed inset-0 flex items-center justify-center p-4"
            :style="{ zIndex }"
            role="dialog"
            aria-modal="true"
            aria-labelledby="available-operations-title"
            data-testid="available-operations-dialog"
        >
            <div class="absolute inset-0 bg-black/60" aria-hidden="true" @click="emit('close')" />

            <div class="relative z-10 w-full max-w-md rounded-2xl border border-emerald-200 bg-white shadow-2xl overflow-hidden">
                <div class="bg-[#1a7a2e] px-6 py-4 text-center">
                    <h2
                        id="available-operations-title"
                        class="text-white text-lg font-black uppercase tracking-wide"
                        data-testid="available-operations-title"
                    >
                        Available Operations
                    </h2>
                </div>

                <div class="px-6 py-5 space-y-3" data-testid="available-operations-list">
                    <button
                        v-for="operation in operationButtons"
                        :key="operation.key"
                        type="button"
                        class="w-full rounded-xl py-2.5 text-base font-black uppercase tracking-wide transition"
                        :class="isOperationEnabled(operation.key)
                            ? 'bg-emerald-700 text-white hover:bg-emerald-800 active:scale-95'
                            : 'bg-gray-200 text-gray-600 cursor-not-allowed'"
                        :disabled="!isOperationEnabled(operation.key)"
                        :data-testid="`available-operation-${operation.key}`"
                        @click="handleOperationClick(operation.key)"
                    >
                        {{ operation.label }}
                    </button>
                </div>

                <div class="px-6 pb-6">
                    <button
                        type="button"
                        class="w-full rounded-xl bg-gray-900 py-2.5 text-base font-black uppercase tracking-wide text-white transition hover:bg-black active:scale-95"
                        data-testid="available-operations-close"
                        @click="emit('close')"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
