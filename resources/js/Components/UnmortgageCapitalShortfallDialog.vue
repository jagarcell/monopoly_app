<script setup>
import DebugDismissBadge from '@/Components/DebugDismissBadge.vue';

/**
 * UnmortgageCapitalShortfallDialog
 *
 * Displays a conspicuous warning when a player cannot afford to unmortgage
 * the selected property/properties.
 *
 * Props:
 *   visible        – controls whether the dialog is shown
 *   requiredAmount – total capital required to proceed with unmortgage
 *
 * Emits:
 *   back            – return to unmortgage selection dialog
 *   mortgage-others – continue by opening mortgage options
 */

defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    requiredAmount: {
        type: Number,
        default: 0,
    },
    zIndex: {
        type: Number,
        default: 230,
    },
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['back', 'mortgage-others']);
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
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="unmortgage-shortfall-title"
            data-testid="unmortgage-shortfall-dialog"
        >
            <div class="absolute inset-0 bg-black/65" aria-hidden="true" />

            <div class="relative z-10 w-full max-w-md overflow-hidden rounded-2xl border border-red-300 bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 bg-red-700 px-6 py-4">
                    <h2 id="unmortgage-shortfall-title" class="flex-1 text-center text-lg font-black uppercase tracking-wide text-white">
                        Not Enough Capital
                    </h2>
                    <DebugDismissBadge :debug-mode="debugMode" @dismiss="emit('back')" />
                </div>

                <div class="space-y-4 px-6 py-5 text-center">
                    <p class="text-sm font-bold uppercase tracking-wide text-red-700" data-testid="unmortgage-shortfall-message">
                        You must raise capital to unmortgage the selected properties.
                    </p>
                    <p class="text-sm text-gray-700">
                        Required amount:
                        <span class="font-black text-gray-900" data-testid="unmortgage-shortfall-required-amount">${{ requiredAmount }}</span>
                    </p>
                </div>

                <div class="space-y-2 px-6 pb-6">
                    <button
                        type="button"
                        class="w-full rounded-xl bg-gray-900 py-2.5 text-base font-black uppercase tracking-wide text-white transition hover:bg-black"
                        data-testid="unmortgage-shortfall-back"
                        @click="emit('back')"
                    >
                        Back
                    </button>
                    <button
                        type="button"
                        class="w-full rounded-xl bg-amber-500 py-2.5 text-base font-black uppercase tracking-wide text-white transition hover:bg-amber-600"
                        data-testid="unmortgage-shortfall-mortgage-others"
                        @click="emit('mortgage-others')"
                    >
                        Mortgage Others
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
