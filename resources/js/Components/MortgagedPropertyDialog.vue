<script setup>
import DebugDismissBadge from '@/Components/DebugDismissBadge.vue';

/**
 * MortgagedPropertyDialog
 *
 * Displays a dialog when a player lands on a mortgaged property.
 * Shown to all board participants, indicating that no rent is due and
 * showing the tokens of both the landing player and the property owner.
 *
 * Props:
 *   visible    – controls whether the dialog is shown
 *   payerName  – display name of the player who landed on the property
 *   payerIcon  – icon object { image_url, name } for the landing player
 *   ownerName  – display name of the property owner
 *   ownerIcon  – icon object { image_url, name } for the owner
 *   squareName – the property name
 *
 * Emits:
 *   close – the OK button was clicked; parent should hide the dialog
 *
 * Logic:
 *   A single OK button is the only dismiss path. There is no backdrop-click
 *   or Escape handler so players cannot accidentally skip the notification.
 *   The dialog clearly states that the property is mortgaged and no rent is due.
 */

defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    zIndex: {
        type: Number,
        default: 120,
    },
    payerName: {
        type: String,
        default: 'Player',
    },
    payerIcon: {
        type: Object,
        default: null,
    },
    ownerName: {
        type: String,
        default: 'Player',
    },
    ownerIcon: {
        type: Object,
        default: null,
    },
    squareName: {
        type: String,
        default: '',
    },
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);
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
            data-testid="mortgaged-property-dialog"
        >
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/40" />

            <!-- Dialog content -->
            <div class="relative bg-white rounded-lg shadow-2xl max-w-sm w-full p-6 mx-auto">
                <!-- Header -->
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="flex-1 text-center text-xl font-bold text-gray-900">Property Mortgaged</h2>
                    <DebugDismissBadge :debug-mode="debugMode" @dismiss="emit('close')" />
                </div>

                <!-- Square name -->
                <p class="text-center text-gray-600 font-semibold mb-6" data-testid="mortgaged-property-square-name">{{ squareName }}</p>

                <!-- Player tokens and names -->
                <div class="flex justify-around items-center mb-6">
                    <!-- Landing player -->
                    <div class="flex flex-col items-center gap-2">
                        <div v-if="payerIcon?.image_url" class="w-16 h-16 rounded-full border-2 border-blue-400 overflow-hidden flex-shrink-0">
                            <img :src="payerIcon.image_url" :alt="payerIcon.name" class="w-full h-full object-cover" />
                        </div>
                        <p class="text-sm text-center text-gray-700 font-medium" data-testid="mortgaged-property-payer-name">{{ payerName }}</p>
                    </div>

                    <!-- Owner -->
                    <div class="flex flex-col items-center gap-2">
                        <div v-if="ownerIcon?.image_url" class="w-16 h-16 rounded-full border-2 border-orange-400 overflow-hidden flex-shrink-0">
                            <img :src="ownerIcon.image_url" :alt="ownerIcon.name" class="w-full h-full object-cover" />
                        </div>
                        <p class="text-sm text-center text-gray-700 font-medium" data-testid="mortgaged-property-owner-name">{{ ownerName }}<br />(Owner)</p>
                    </div>
                </div>

                <!-- Status message -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4 mb-6">
                    <p class="text-sm text-yellow-800 text-center" data-testid="mortgaged-property-message">
                        <span class="font-semibold">No rent due.</span> This property is mortgaged and does not collect rent.
                    </p>
                </div>

                <!-- OK button -->
                <button
                    @click="emit('close')"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition-colors duration-200"
                    data-testid="mortgaged-property-ok"
                >
                    OK
                </button>
            </div>
        </div>
    </Transition>
</template>
