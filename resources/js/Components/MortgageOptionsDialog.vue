<script setup>
/**
 * MortgageOptionsDialog
 *
 * Displays a payment-scoped mortgage planning session.
 *
 * Props:
 *   visible    – controls whether the dialog is shown
 *   properties            – list of owned properties with purchase and mortgage values
 *   selectedSquareIndexes – properties selected for this payment session
 *   currentCapital        – player's current capital before session mortgages
 *   requiredAmount        – amount required for the pending payment
 *   actionLabel           – primary action label (Pay/Buy)
 *   isLoading             – shows loading state while list is fetched
 *   isSubmitting          – disables actions during submit
 *   zIndex                – stacking context for the overlay
 *
 * Emits:
 *   toggle-property – toggle one property's mortgage selection in session
 *   submit-payment  – run the pending payment request with selected mortgages
 *   close           – dismiss the dialog
 *
 * Logic:
 *   Selection is local to the current payment session. No property state is
 *   persisted until the user submits the payment request.
 */

import { computed } from 'vue';

const props = defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    properties: {
        type: Array,
        default: () => [],
    },
    selectedSquareIndexes: {
        type: Array,
        default: () => [],
    },
    currentCapital: {
        type: Number,
        default: 0,
    },
    requiredAmount: {
        type: Number,
        default: 0,
    },
    actionLabel: {
        type: String,
        default: 'Pay now',
    },
    isLoading: {
        type: Boolean,
        default: false,
    },
    isSubmitting: {
        type: Boolean,
        default: false,
    },
    zIndex: {
        type: Number,
        default: 200,
    },
});

const emit = defineEmits(['toggle-property', 'submit-payment', 'close']);

const selectedSquareIndexSet = computed(
    () => new Set((props.selectedSquareIndexes ?? []).map(value => Number(value))),
);

const selectedMortgageValue = computed(() => (
    (props.properties ?? []).reduce((sum, property) => {
        const squareIndex = Number(property.square_index);
        const canBeSelected = !property.is_mortgaged;
        const isSelected = selectedSquareIndexSet.value.has(squareIndex);

        if (!canBeSelected || !isSelected) {
            return sum;
        }

        return sum + Number(property.mortgage_value ?? 0);
    }, 0)
));

const projectedCapital = computed(() => Number(props.currentCapital ?? 0) + selectedMortgageValue.value);
const shortfall = computed(() => Math.max(0, Number(props.requiredAmount ?? 0) - projectedCapital.value));
const canSubmitPayment = computed(
    () => !props.isLoading && !props.isSubmitting && shortfall.value === 0 && Number(props.requiredAmount ?? 0) > 0,
);

function isSelected(squareIndex) {
    return selectedSquareIndexSet.value.has(Number(squareIndex));
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
            aria-labelledby="mortgage-options-title"
            data-testid="mortgage-options-dialog"
        >
            <div class="absolute inset-0 bg-black/60" aria-hidden="true" @click="emit('close')" />

            <div
                class="relative z-10 flex w-full max-w-md max-h-[calc(100vh-2rem)] flex-col overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-2xl"
                data-testid="mortgage-dialog-panel"
            >
                <div class="bg-amber-500 px-6 py-4 text-center">
                    <h2 id="mortgage-options-title" class="text-white text-lg font-black uppercase tracking-wide">
                        Mortgage Options
                    </h2>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4" data-testid="mortgage-dialog-scroll-body">
                    <div class="sticky top-0 z-20 -mx-6 border-b border-gray-200 bg-white px-6 pb-3">
                        <div
                            class="rounded-xl px-3 py-2"
                            :class="shortfall > 0 ? 'bg-red-50' : 'bg-emerald-50'"
                        >
                            <p
                                class="text-xs font-bold uppercase tracking-wide"
                                :class="shortfall > 0 ? 'text-red-700' : 'text-emerald-700'"
                            >
                                {{ shortfall > 0 ? 'Remaining shortfall' : 'Payment covered' }}
                            </p>
                            <p
                                class="text-lg font-black"
                                :class="shortfall > 0 ? 'text-red-700' : 'text-emerald-700'"
                                data-testid="mortgage-shortfall"
                            >
                                ${{ shortfall }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2" data-testid="mortgage-session-summary">
                        <div class="rounded-xl bg-gray-50 px-3 py-2">
                            <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Current capital</p>
                            <p class="text-lg font-black text-gray-900" data-testid="mortgage-current-capital">${{ currentCapital }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-3 py-2">
                            <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Required amount</p>
                            <p class="text-lg font-black text-gray-900" data-testid="mortgage-required-amount">${{ requiredAmount }}</p>
                        </div>
                        <div class="rounded-xl bg-amber-50 px-3 py-2 sm:col-span-2">
                            <p class="text-xs font-bold uppercase tracking-wide text-amber-700">Projected capital after selected mortgages</p>
                            <p class="text-lg font-black text-amber-900" data-testid="mortgage-projected-capital">${{ projectedCapital }}</p>
                        </div>
                    </div>

                    <div v-if="isLoading" class="rounded-xl bg-amber-50 px-4 py-3 text-center text-sm font-semibold text-amber-800" data-testid="mortgage-loading">
                        Loading your properties...
                    </div>

                    <div v-else-if="properties.length === 0" class="rounded-xl bg-gray-50 px-4 py-3 text-center text-sm text-gray-600" data-testid="mortgage-empty">
                        You do not own any properties yet.
                    </div>

                    <div v-else class="space-y-3" data-testid="mortgage-property-list">
                        <div
                            v-for="property in properties"
                            :key="property.square_index"
                            class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 px-4 py-3"
                            :data-testid="`mortgage-property-${property.square_index}`"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-bold text-gray-900" :data-testid="`property-name-${property.square_index}`">
                                    {{ property.name }}
                                </p>
                                <p
                                    class="text-xs text-gray-500"
                                    :data-testid="`mortgage-value-${property.square_index}`"
                                >
                                    Bought for ${{ property.purchase_price }} · Mortgage value ${{ property.mortgage_value }}
                                </p>
                            </div>

                            <button
                                v-if="!property.is_mortgaged"
                                type="button"
                                class="shrink-0 rounded-lg px-3 py-2 text-sm font-bold transition"
                                :class="isSelected(property.square_index)
                                    ? 'bg-amber-100 text-amber-900 hover:bg-amber-200'
                                    : 'bg-amber-500 text-white hover:bg-amber-600'"
                                :disabled="isSubmitting"
                                :data-testid="`btn-toggle-mortgage-${property.square_index}`"
                                @click="emit('toggle-property', property.square_index)"
                            >
                                {{ isSelected(property.square_index)
                                    ? `Remove ($${property.mortgage_value})`
                                    : `Select ($${property.mortgage_value})` }}
                            </button>

                            <span
                                v-else
                                class="shrink-0 rounded-lg bg-gray-200 px-3 py-2 text-sm font-bold text-gray-600"
                                :data-testid="`mortgaged-badge-${property.square_index}`"
                            >
                                Mortgaged
                            </span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 px-6 pb-6">
                    <button
                        type="button"
                        class="w-full rounded-xl bg-emerald-700 py-2.5 text-base font-black uppercase tracking-wide text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="!canSubmitPayment"
                        data-testid="btn-mortgage-submit-payment"
                        @click="emit('submit-payment')"
                    >
                        {{ actionLabel }}
                    </button>
                    <button
                        type="button"
                        class="w-full rounded-xl bg-gray-900 py-2.5 text-base font-black uppercase tracking-wide text-white hover:bg-black active:scale-95 transition"
                        :disabled="isSubmitting"
                        data-testid="btn-mortgage-close"
                        @click="emit('close')"
                    >
                        Back
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>