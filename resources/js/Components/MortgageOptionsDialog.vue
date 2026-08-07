<script setup>
/**
 * MortgageOptionsDialog
 *
 * Displays a reusable mortgage planning session.
 *
 * Props:
 *   visible    – controls whether the dialog is shown
 *   properties            – list of owned properties with purchase and mortgage values
 *   selectedSquareIndexes – properties selected for this payment session
 *   currentCapital        – player's current capital before session mortgages
 *   requiredAmount        – amount required for payment contexts
 *   actionLabel           – primary action label (Pay/Buy/Apply Mortgages)
 *   showStatusBlock       – controls visibility of shortfall/payment status
 *   showRequiredAmount    – controls visibility of required amount summary card
 *   selectionMode         – toggles between mortgage and unmortgage selections
 *   allowMultipleSelection – controls whether multiple properties can be selected
 *   isLoading             – shows loading state while list is fetched
 *   isSubmitting          – disables actions during submit
 *   zIndex                – stacking context for the overlay
 *
 * Emits:
 *   toggle-property – toggle one property's mortgage selection in session
 *   submit-payment  – run the current mortgage action with selected mortgages
 *   close           – dismiss the dialog
 *
 * Logic:
 *   Selection is local to the current session. No property state is persisted
 *   until the user submits the action.
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
        default: 'Apply Mortgages',
    },
    showStatusBlock: {
        type: Boolean,
        default: true,
    },
    showRequiredAmount: {
        type: Boolean,
        default: true,
    },
    selectionMode: {
        type: String,
        default: 'mortgage',
    },
    actionType: {
        type: String,
        default: '',
    },
    allowMultipleSelection: {
        type: Boolean,
        default: true,
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

const emit = defineEmits(['toggle-property', 'submit-payment', 'close', 'sell-house', 'sell-hotel']);

const selectedSquareIndexSet = computed(
    () => new Set((props.selectedSquareIndexes ?? []).map(value => Number(value))),
);

const selectedOperationValue = computed(() => (
    (props.properties ?? []).reduce((sum, property) => {
        const squareIndex = Number(property.square_index);
        const canBeSelected = props.selectionMode === 'unmortgage'
            ? property.is_mortgaged
            : !property.is_mortgaged;
        const isSelected = selectedSquareIndexSet.value.has(squareIndex);

        if (!canBeSelected || !isSelected) {
            return sum;
        }

        if (props.selectionMode === 'unmortgage') {
            return sum + Number(property.unmortgage_cost ?? 0);
        }

        // Use provided mortgage_value when available; otherwise derive it
        // the same way as the server (half the purchase price, integer div).
        const purchase = Number(property.purchase_price ?? 0);
        const computedMortgage = Number((property.mortgage_value ?? Math.floor(purchase / 2)) || 0);
        return sum + computedMortgage;
    }, 0)
));

const projectedCapital = computed(() => Number(props.currentCapital ?? 0) + selectedOperationValue.value);
const shortfall = computed(() => Math.max(0, Number(props.requiredAmount ?? 0) - projectedCapital.value));
const canSubmitPayment = computed(() => {
    if (props.isLoading || props.isSubmitting) {
        return false;
    }

    const requiredAmount = Number(props.requiredAmount ?? 0);

    if (requiredAmount > 0) {
        return shortfall.value === 0;
    }

    return selectedOperationValue.value > 0;
});

function isSelected(squareIndex) {
    return selectedSquareIndexSet.value.has(Number(squareIndex));
}

function salePrice(prop) {
    return Math.floor((prop.purchase_price || 0) / 4);
}

const colorGroupHasBuildings = computed(() => {
    const map = {};
    (props.properties ?? []).forEach((p) => {
        const c = String(p?.color ?? '').toLowerCase();
        if (!c) return;
        if (!map[c]) map[c] = false;
        if ((Number(p.houses_count || 0) > 0) || Boolean(p.has_hotel)) {
            map[c] = true;
        }
    });
    return map;
});

function groupHasBuildingsForProperty(property) {
    const c = String(property?.color ?? '').toLowerCase();
    return Boolean(c && colorGroupHasBuildings.value[c]);
}

function selectButtonDisabledFor(property) {
    // Disable selection when submitting or when attempting to mortgage
    // a property that belongs to a color group which currently has any buildings.
    if (props.isSubmitting) return true;
    if (props.selectionMode !== 'mortgage') return false;
    return groupHasBuildingsForProperty(property);
}

function selectButtonText(property) {
    const purchase = Number(property.purchase_price ?? 0);
    const computedMortgage = (property.mortgage_value ?? Math.floor(purchase / 2)) || 0;

    if (isSelected(property.square_index)) {
        return props.selectionMode === 'unmortgage'
            ? `Remove ($${property.unmortgage_cost})`
            : `Remove ($${computedMortgage})`;
    }
    return props.selectionMode === 'unmortgage'
        ? `Select ($${property.unmortgage_cost})`
        : `Select ($${computedMortgage})`;
}

function selectButtonHiddenFor(property) {
    // Hide the select button when attempting to mortgage and any property in
    // the same colour group has buildings (houses or hotel).
    return props.selectionMode === 'mortgage' && groupHasBuildingsForProperty(property);
}

const allPropertiesMortgaged = computed(() => {
    const list = props.properties ?? [];
    if (list.length === 0) return false;

    return list.every((p) => {
        const v = p?.is_mortgaged;
        const currentlyMortgaged = v === true || v === 1 || v === '1';

        // If the dialog is in mortgage selection mode, consider a property
        // mortgaged if the user has selected it for mortgage in this session.
        const willBeMortgagedBySelection = props.selectionMode === 'mortgage'
            && selectedSquareIndexSet.value.has(Number(p.square_index));

        return currentlyMortgaged || willBeMortgagedBySelection;
    });
});

// Determine whether there remain any mortgageable properties (selectable)
// or buildings that can be sold. We only show the bankruptcy declaration
// when there are no further actions to raise funds and a shortfall remains.
const hasMortgageableProperties = computed(() => {
    if (props.selectionMode !== 'mortgage') return false;
    return (props.properties ?? []).some((p) => {
        if (p?.is_mortgaged) return false;
        if (selectButtonHiddenFor(p)) return false; // blocked by buildings in the group
        return true;
    });
});

const hasSellableBuildings = computed(() => {
    return (props.properties ?? []).some((p) => Number(p.houses_count || 0) > 0 || Boolean(p.has_hotel));
});

// Compute totals for all remaining capital-raise options so the dialog can
// determine whether declaring bankruptcy is the only remaining action.
const totalMortgageableRaise = computed(() => {
    if (props.selectionMode !== 'mortgage') return 0;
    return (props.properties ?? []).reduce((sum, p) => {
        if (!p) return sum;
        if (p?.is_mortgaged) return sum;
        if (selectButtonHiddenFor(p)) return sum; // blocked by buildings in the group
        const purchase = Number(p.purchase_price ?? 0);
        const mv = Number(p.mortgage_value ?? Math.floor(purchase / 2)) || 0;
        return sum + mv;
    }, 0);
});

const totalSellableBuildingsRaise = computed(() => {
    return (props.properties ?? []).reduce((sum, p) => {
        if (!p) return sum;
        const price = salePrice(p) || 0;
        const houses = Number(p.houses_count || 0);
        const hotel = p.has_hotel ? 1 : 0;
        return sum + (price * houses) + (hotel ? price : 0);
    }, 0);
});

const totalPossibleRaise = computed(() => totalMortgageableRaise.value + totalSellableBuildingsRaise.value);

// Remaining possible raise after accounting for already-selected mortgages
const remainingPossibleRaise = computed(() => Math.max(0, totalPossibleRaise.value - selectedOperationValue.value));

// If the player sold all buildings first, previously-blocked properties
// would become mortgageable. Compute the total mortgage value available
// after selling all buildings so the dialog can reason about the full
// sequential raise path (sell buildings -> mortgage properties).
const totalMortgageableIfSellAll = computed(() => {
    if (props.selectionMode !== 'mortgage') return 0;
    return (props.properties ?? []).reduce((sum, p) => {
        if (!p) return sum;
        if (p?.is_mortgaged) return sum;
        const purchase = Number(p.purchase_price ?? 0);
        const mv = Number(p.mortgage_value ?? Math.floor(purchase / 2)) || 0;
        return sum + mv;
    }, 0);
});

// Remaining possible raise if the player first sells all buildings, then
// mortgages every eligible property (including those previously blocked).
const remainingPossibleRaiseIfSellAll = computed(() => Math.max(0, (totalSellableBuildingsRaise.value + totalMortgageableIfSellAll.value) - selectedOperationValue.value));

// Show the bankruptcy declaration when opened for a payment action, a shortfall remains,
// and even after applying all remaining raises the shortfall would persist.
const showDeclareBankruptcyButton = computed(() => {
    const required = Number(props.requiredAmount ?? 0);
    // Include 'tax' so the bankruptcy option appears for income-tax payment flows
    const paymentActions = ['purchase', 'rent', 'card', 'operation', 'tax'];
    // Do not offer bankruptcy while the property list is still loading.
    if (props.isLoading) return false;
    if (!paymentActions.includes(String(props.actionType || ''))) return false;

    // Consider both the immediate remaining raise (without selling buildings)
    // and the sequential raise path where the player first sells buildings.
    const maxRemaining = Math.max(remainingPossibleRaise.value, remainingPossibleRaiseIfSellAll.value);

    return required > 0 && shortfall.value > 0 && maxRemaining < shortfall.value;
});
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
                    <div
                        v-if="showStatusBlock"
                        class="sticky top-0 z-20 -mx-6 border-b border-gray-200 bg-white px-6 pb-3"
                    >
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
                        <div v-if="showRequiredAmount" class="rounded-xl bg-gray-50 px-3 py-2">
                            <p class="text-xs font-bold uppercase tracking-wide text-gray-500">Required amount</p>
                            <p class="text-lg font-black text-gray-900" data-testid="mortgage-required-amount">${{ requiredAmount }}</p>
                        </div>
                        <div class="rounded-xl bg-amber-50 px-3 py-2" :class="showRequiredAmount ? 'sm:col-span-2' : ''">
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
                            class="overflow-hidden rounded-xl border border-gray-200"
                            :data-testid="`mortgage-property-${property.square_index}`"
                        >
                            <div
                                v-if="property.color"
                                class="h-2 w-full"
                                :style="{ backgroundColor: property.color }"
                                :data-testid="`property-color-bar-${property.square_index}`"
                            />
                            <div class="flex items-center justify-between gap-3 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate font-bold text-gray-900" :data-testid="`property-name-${property.square_index}`">
                                    {{ property.name }}
                                </p>

                                <div class="mt-2 flex items-center gap-2">
                                    <div class="flex items-center gap-1">
                                        <span v-for="n in (property.houses_count ?? 0)" :key="`mh-${property.square_index}-${n}`" class="text-sm">🏠</span>
                                        <span v-if="property.has_hotel" class="text-sm">🏨</span>
                                    </div>

                                    <div class="ml-auto flex gap-2">
                                        <button
                                            v-if="(property.houses_count ?? 0) > 0"
                                            type="button"
                                            class="shrink-0 rounded-lg px-3 py-1 text-xs font-bold bg-gray-100 text-gray-800 hover:bg-gray-200"
                                            :disabled="isSubmitting"
                                            @click="emit('sell-house', property.square_index)"
                                            :data-testid="`btn-sell-house-${property.square_index}`"
                                        >
                                            Sell house ${{ salePrice(property) }}
                                        </button>

                                        <button
                                            v-if="property.has_hotel"
                                            type="button"
                                            class="shrink-0 rounded-lg px-3 py-1 text-xs font-bold bg-gray-100 text-gray-800 hover:bg-gray-200"
                                            :disabled="isSubmitting"
                                            @click="emit('sell-hotel', property.square_index)"
                                            :data-testid="`btn-sell-hotel-${property.square_index}`"
                                        >
                                            Sell hotel ${{ salePrice(property) }}
                                        </button>
                                    </div>
                                </div>
                                <p
                                    class="text-xs text-gray-500"
                                    :data-testid="`mortgage-value-${property.square_index}`"
                                >
                                    Bought for ${{ property.purchase_price }} · Mortgage value ${{ property.mortgage_value ?? Math.floor(property.purchase_price/2) }}
                                </p>
                            </div>

                            <button
                                v-if="((selectionMode === 'mortgage' && !property.is_mortgaged)
                                    || (selectionMode === 'unmortgage' && property.is_mortgaged)) && !selectButtonHiddenFor(property)"
                                type="button"
                                class="shrink-0 rounded-lg px-3 py-2 text-sm font-bold transition"
                                :class="isSelected(property.square_index)
                                    ? 'bg-amber-100 text-amber-900 hover:bg-amber-200'
                                    : 'bg-amber-500 text-white hover:bg-amber-600'"
                                :disabled="isSubmitting"
                                :data-testid="`btn-toggle-mortgage-${property.square_index}`"
                                @click="emit('toggle-property', property.square_index)"
                            >
                                {{ selectButtonText(property) }}
                            </button>

                            <span
                                v-else-if="!(selectionMode === 'mortgage' && !property.is_mortgaged && selectButtonHiddenFor(property))"
                                class="shrink-0 rounded-lg bg-gray-200 px-3 py-2 text-sm font-bold text-gray-600"
                                :data-testid="`mortgaged-badge-${property.square_index}`"
                            >
                                {{ property.is_mortgaged ? 'Mortgaged' : 'Available' }}
                            </span>
                            </div>
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
                        v-if="showDeclareBankruptcyButton"
                        type="button"
                        class="w-full rounded-xl bg-red-600 py-2.5 text-base font-black uppercase tracking-wide text-white hover:bg-red-700 active:scale-95 transition"
                        data-testid="btn-declare-bankruptcy"
                        @click="emit('declare-bankruptcy')"
                    >
                        Declare Bankruptcy
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