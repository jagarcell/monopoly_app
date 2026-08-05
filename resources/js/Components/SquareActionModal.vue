<script setup>
/**
 * SquareActionModal
 *
 * Displays a contextual dialog when a player lands on a purchasable square.
 *
 * Props:
 *   visible      – controls whether the modal is shown
 *   squareAction – the action object from the roll API response:
 *                  {
 *                    type: 'purchase' | 'rent',
 *                    square_name: string,
 *                    price: number | null,   // present for type='purchase'
 *                    rent: number,
 *                    owner_join_order: number | null,
 *                    owner_name: string | null,
 *                    payer_icon?: { image_url: string, name: string } | null,
 *                    owner_icon?: { image_url: string, name: string } | null,
 *                  }
 *   showMortgageOptionsButton – controls whether the mortgage trigger button
 *                               is shown for the current payment request.
 *
 * Emits:
 *   purchase – the player chose to buy the property
 *   skip     – the player chose not to buy (type='purchase' only)
 *   pay      – the player paid the rent (type='rent' only)
 *   mortgage-options – the player wants to open the mortgage options dialog
 *
 * Logic:
 *   For type='purchase': shows property name, price, and base rent info with
 *   two buttons — "Buy for $X" and "Skip".
 *   For type='rent': shows rent owed and to whom, with only a "Pay $X" button.
 *   The rent dialog intentionally has no dismiss option — the player must pay.
 */

defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    squareAction: {
        type: Object,
        default: null,
    },
    showMortgageOptionsButton: {
        type: Boolean,
        default: false,
    },
    serverPercentAmount: {
        type: Number,
        default: null,
    },
});

const emit = defineEmits(['purchase', 'skip', 'pay', 'mortgage-options', 'tax-choice']);
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
            v-if="visible && squareAction"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="'square-action-title'"
        >
            <!-- Backdrop — clickable only for 'purchase' (player can skip) -->
            <div
                class="absolute inset-0 bg-black/60"
                :class="squareAction.type === 'purchase' ? 'cursor-pointer' : 'cursor-default'"
                aria-hidden="true"
                @click="squareAction.type === 'purchase' ? emit('skip') : undefined"
            />

            <!-- Dialog panel -->
            <div
                class="relative z-10 w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden"
                :class="squareAction.type === 'purchase' ? 'bg-white' : 'bg-red-50 border-2 border-red-400'"
                data-testid="square-action-modal"
            >
                <!-- Header -->
                <div
                    class="px-6 py-4 text-center"
                    :class="squareAction.type === 'purchase' ? 'bg-[#1a7a2e]' : 'bg-red-500'"
                >
                    <span
                        id="square-action-title"
                        class="text-white font-black text-lg tracking-wide uppercase"
                    >
                        {{ squareAction.type === 'purchase' ? 'Property Available' : (squareAction.type === 'tax' ? 'Income Tax' : 'Rent Due') }}
                    </span>
                </div>

                <!-- Body -->
                <div class="px-6 py-5 flex flex-col items-center gap-3 text-center">
                    <!-- Property name (hide for tax modal) -->
                    <p
                        v-if="squareAction.type !== 'tax'"
                        class="font-bold text-xl text-gray-800"
                        data-testid="square-name"
                    >
                        {{ squareAction.square_name }}
                    </p>

                    <!-- Purchase details -->
                    <template v-if="squareAction.type === 'purchase'">
                        <p class="text-gray-600 text-sm">
                            This property is <span class="font-semibold text-[#1a7a2e]">unowned</span>.
                            Do you want to buy it?
                        </p>
                        <div class="flex gap-6 mt-1">
                            <div class="flex flex-col items-center">
                                <span class="text-xs text-gray-500 uppercase tracking-wider">Price</span>
                                <span
                                    class="font-black text-2xl text-gray-900"
                                    data-testid="purchase-price"
                                >${{ squareAction.price }}</span>
                            </div>
                            <div class="flex flex-col items-center">
                                <span class="text-xs text-gray-500 uppercase tracking-wider">Base Rent</span>
                                <span class="font-semibold text-lg text-gray-700">${{ squareAction.rent }}</span>
                            </div>
                        </div>
                    </template>

                    <!-- Rent details -->
                    <template v-if="squareAction.type === 'rent'">
                        <div class="flex items-center justify-center gap-5">
                            <div class="flex flex-col items-center gap-1">
                                <img
                                    v-if="squareAction.payer_icon"
                                    :src="squareAction.payer_icon.image_url"
                                    :alt="squareAction.payer_icon.name"
                                    class="w-9 h-9 object-contain"
                                    data-testid="rent-due-payer-icon"
                                />
                                <div
                                    v-else
                                    class="w-9 h-9 rounded-full bg-gray-200"
                                    data-testid="rent-due-payer-icon"
                                    aria-hidden="true"
                                />
                                <span class="text-[10px] uppercase font-bold text-gray-500">Payer</span>
                            </div>
                            <div class="text-red-500 font-black text-sm">→</div>
                            <div class="flex flex-col items-center gap-1">
                                <img
                                    v-if="squareAction.owner_icon"
                                    :src="squareAction.owner_icon.image_url"
                                    :alt="squareAction.owner_icon.name"
                                    class="w-9 h-9 object-contain"
                                    data-testid="rent-due-owner-icon"
                                />
                                <div
                                    v-else
                                    class="w-9 h-9 rounded-full bg-gray-200"
                                    data-testid="rent-due-owner-icon"
                                    aria-hidden="true"
                                />
                                <span class="text-[10px] uppercase font-bold text-gray-500">Owner</span>
                            </div>
                        </div>
                        <p class="text-gray-700 text-sm">
                            This property is owned by
                            <span
                                class="font-bold text-red-600"
                                data-testid="owner-name"
                            >{{ squareAction.owner_name }}</span>.
                            You must pay rent.
                        </p>
                        <div class="mt-1">
                            <span class="text-xs text-gray-500 uppercase tracking-wider">Rent</span>
                            <p
                                class="font-black text-3xl text-red-600"
                                data-testid="rent-amount"
                            >${{ squareAction.rent }}</p>
                        </div>
                    </template>
                </div>

                    <!-- Actions -->
                <div class="px-6 pb-6 flex flex-col gap-2">
                    <!-- Purchase actions -->
                    <template v-if="squareAction.type === 'purchase'">
                        <button
                            v-if="showMortgageOptionsButton"
                            type="button"
                            class="w-full py-2 rounded-xl font-semibold text-amber-800 bg-amber-100 hover:bg-amber-200 active:scale-95 transition-all"
                            data-testid="btn-mortgage-options"
                            @click="emit('mortgage-options')"
                        >
                            Mortgage options
                        </button>
                        <button
                            type="button"
                            class="w-full py-3 rounded-xl font-bold text-white bg-[#1a7a2e] hover:bg-[#155f24] active:scale-95 transition-all shadow"
                            data-testid="btn-buy"
                            @click="emit('purchase')"
                        >
                            Buy for ${{ squareAction.price }}
                        </button>
                        <button
                            type="button"
                            class="w-full py-2 rounded-xl font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 active:scale-95 transition-all"
                            data-testid="btn-skip"
                            @click="emit('skip')"
                        >
                            Skip
                        </button>
                    </template>

                    <!-- Rent action — no dismiss option -->
                    <template v-if="squareAction.type === 'rent'">
                        <button
                            v-if="showMortgageOptionsButton"
                            type="button"
                            class="w-full py-2 rounded-xl font-semibold text-amber-800 bg-amber-100 hover:bg-amber-200 active:scale-95 transition-all"
                            data-testid="btn-mortgage-options"
                            @click="emit('mortgage-options')"
                        >
                            Mortgage options
                        </button>
                        <button
                            type="button"
                            class="w-full py-3 rounded-xl font-bold text-white bg-red-500 hover:bg-red-600 active:scale-95 transition-all shadow"
                            data-testid="btn-pay"
                            @click="emit('pay')"
                        >
                            Pay ${{ squareAction.rent }}
                        </button>
                    </template>

                    <!-- Tax action (Income Tax) -->
                    <template v-if="squareAction.type === 'tax'">
                        <div class="mt-3 grid grid-cols-1 gap-2">
                            <button
                                type="button"
                                class="w-full py-3 rounded-xl font-bold text-white bg-[#1a7a2e] hover:bg-[#155f24] active:scale-95 transition-all shadow"
                                data-testid="btn-pay-flat"
                                @click="$emit('tax-choice', { choice: 'flat', amount: squareAction.options?.flat ?? 200 })"
                            >
                                Pay ${{ squareAction.options?.flat ?? 200 }}
                            </button>
                            <!-- The percent value and percent_amount displayed here
                                 are supplied by the server in the `squareAction.options`.
                                 The server computes the authoritative 10% using
                                 `computePlayerTotalAssets(...)` and `floor(totalAssets * (percent/100))`
                                 in `app/Services/GameService.php` so the dialog shows the
                                 exact dollar amount calculated server-side. If `options.percent`
                                 is absent, the UI falls back to 10% by convention.
                            -->
                            <button
                                type="button"
                                class="w-full py-3 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700 active:scale-95 transition-all shadow"
                                data-testid="btn-pay-percent"
                                @click="$emit('tax-choice', { choice: 'percent', percent: squareAction.options?.percent ?? 10 })"
                            >
                                Pay {{ squareAction.options?.percent ?? 10 }}% of total assets
                                <span v-if="(serverPercentAmount !== null) || squareAction.options?.percent_amount">({{ '$' + (serverPercentAmount !== null ? serverPercentAmount : squareAction.options.percent_amount) }})</span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </Transition>
</template>
