<script setup>
import { computed, watchEffect } from 'vue';

/**
 * TotalAssetsBreakdownDialog
 *
 * Shows a breakdown of the current player's total assets (cash + properties)
 * and displays the computed percent amount. When opened from a tax percent
 * choice, shows a primary action to pay the percent.
 */

const props = defineProps({
    visible: { type: Boolean, required: true },
    percent: { type: Number, default: 10 },
    currentCapital: { type: Number, default: 0 },
    properties: { type: Array, default: () => [] },
    // Optional authoritative properties array supplied by the server. When
    // present, prefer this over the local `properties` payload so the dialog
    // renders exactly what the server used to compute totals.
    server_properties: { type: Array, default: null },
    showPayButton: { type: Boolean, default: false },
    // Optional authoritative totals supplied by the server to ensure the
    // percent-based tax calculation matches server-side logic exactly.
    server_total_assets: { type: Number, default: null },
    server_percent_amount: { type: Number, default: null },
});

const emit = defineEmits(['close', 'confirm-pay-percent']);

const showDebug = typeof window !== 'undefined' && String(window.location.search || '').includes('debug_assets=1');

const propertyContributions = computed(() => {
    const list = (props.server_properties !== null && props.server_properties !== undefined)
        ? (props.server_properties || [])
        : (props.properties || []);

    return list.map((p) => {
        const name = p.name || p.square_name || 'Property';
        const purchase = Number(p.purchase_price ?? p.price ?? 0);
        // If the server did not provide a mortgage_value, derive it the same
        // way the backend does (half the purchase price, integer div).
        const mortgage = Number((p.mortgage_value ?? Math.floor(purchase / 2)) || 0);
        const houses = Number(p.houses_count ?? 0);
        const hasHotel = Boolean(p.has_hotel);

        // Building cost: default charged price per house/hotel is half the purchase price
        const houseCost = Math.floor(purchase / 2);

        // Represent building value as the invested cost in buildings.
        // Hotel represents the 5-house equivalent (4 houses + upgrade).
        const buildingValue = hasHotel ? (5 * houseCost) : (houses * houseCost);

        // Include building value in the contribution for unmortgaged properties
        // so percent-based tax calculations reflect invested building costs.
        const contribution = p.is_mortgaged ? purchase : (purchase + buildingValue);

        return {
            name,
            purchase,
            mortgage,
            is_mortgaged: Boolean(p.is_mortgaged),
            contribution,
            houses,
            hasHotel,
            buildingValue,
        };
    });
});

const totalPropertiesValue = computed(() => propertyContributions.value.reduce((s, x) => s + Number(x.contribution || 0), 0));

// Allow server-provided authoritative totals to override the local
// computation so both the assets breakdown and the tax dialog share a
// single source of truth.
const totalAssets = computed(() => {
    if (props.server_total_assets !== null && props.server_total_assets !== undefined) {
        return Number(props.server_total_assets || 0);
    }

    return Number(props.currentCapital || 0) + totalPropertiesValue.value;
});

const percentAmount = computed(() => {
    if (props.server_percent_amount !== null && props.server_percent_amount !== undefined) {
        return Number(props.server_percent_amount || 0);
    }

    return Math.floor(totalAssets.value * (Number(props.percent || 0) / 100));
});
</script>

<template>
    <Transition>
        <div v-if="visible" class="fixed inset-0 flex items-center justify-center p-4 z-[300]" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/60" @click="emit('close')" aria-hidden="true" />

            <div class="relative z-10 w-full max-w-md rounded-2xl bg-white shadow-2xl overflow-hidden">
                <div class="px-6 py-4 text-center bg-blue-600">
                    <h2 class="text-white font-black uppercase">Total Assets</h2>
                </div>

                <div class="px-6 py-4 space-y-3">
                    <div class="flex justify-between text-sm text-gray-600">
                        <div>Cash</div>
                        <div class="font-black text-gray-900">${{ currentCapital }}</div>
                    </div>

                    <div class="text-sm text-gray-600">Properties</div>
                    <div class="space-y-2 max-h-40 overflow-y-auto">
                        <div v-for="prop in propertyContributions" :key="prop.name" class="flex justify-between text-sm">
                            <div :class="prop.is_mortgaged ? 'text-gray-400 italic' : ''">{{ prop.name }}</div>
                            <div class="text-right">
                                <div class="font-medium">${{ prop.contribution }}</div>
                                <div v-if="prop.buildingValue && prop.buildingValue > 0" class="text-xs text-gray-500">Buildings: ${{ prop.buildingValue }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-between pt-2 border-t border-gray-100">
                        <div class="text-sm font-bold">Total assets</div>
                        <div class="text-lg font-black">${{ totalAssets }}</div>
                    </div>

                    <div class="mt-2 flex justify-between items-center">
                        <div class="text-sm">{{ percent }}% of total assets</div>
                        <div class="text-xl font-black text-blue-600">${{ percentAmount }}</div>
                    </div>

                    <!-- Debug UI removed. -->
                </div>

                <div class="px-6 pb-6 space-y-2">
                    <!--
                        NOTE: This dialog does NOT call the server directly.
                        Clicking "Pay" emits the `confirm-pay-percent` event which
                        the parent (`MonopolyBoard.vue`) handles. The parent
                        performs the authoritative API call to the tax endpoint:
                        - POST /api/games/{gameId}/tax  (authenticated games)
                        - POST /api/join/{token}/tax   (guest/invitation)
                        Ensuring the parent performs the request guarantees a
                        single source of truth for the percent payment flow.
                        See: routes/api.php and resources/js/Components/MonopolyBoard.vue
                    -->
                    <button v-if="showPayButton" @click="emit('confirm-pay-percent')" type="button" class="w-full py-3 rounded-xl font-bold text-white bg-blue-600 hover:bg-blue-700">Pay {{ percent }}%</button>
                    <button @click="emit('close')" type="button" class="w-full py-2 rounded-xl font-semibold text-gray-700 bg-gray-100">Close</button>
                </div>
            </div>
        </div>
    </Transition>
</template>
