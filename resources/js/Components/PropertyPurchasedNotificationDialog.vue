<script setup>
/**
 * PropertyPurchasedNotificationDialog
 *
 * Displays a dismissible notification dialog when another player purchases a
 * property. Shows the purchaser's token, name, the purchased property, and
 * the price paid so observer boards can follow the game without a refresh.
 *
 * Props:
 *   visible      – controls whether the dialog is shown
 *   buyerName    – display name of the player who bought the property
 *   buyerIcon    – icon object { image_url, name } for the buying player; null
 *                  shows a neutral placeholder circle
 *   squareName   – purchased property name
 *   purchasePrice – purchase price shown in the confirmation line
 *
 * Emits:
 *   close – emitted when the observer dismisses the notification
 *
 * Logic:
 *   Renders a three-part modal with a purchase header, a buyer-identity row,
 *   and a confirmation message naming the property and price. The OK button
 *   is the only dismiss action so observers see the notification exactly once.
 */

defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    zIndex: {
        type: Number,
        default: 125,
    },
    buyerName: {
        type: String,
        default: 'Player',
    },
    buyerIcon: {
        type: Object,
        default: null,
    },
    squareName: {
        type: String,
        default: '',
    },
    purchasePrice: {
        type: Number,
        default: 0,
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
            aria-labelledby="property-purchased-notification-title"
            data-testid="property-purchased-notification"
        >
            <div class="absolute inset-0 bg-black/60 cursor-default" aria-hidden="true" />

            <div class="relative z-10 w-full max-w-xs rounded-2xl shadow-2xl overflow-hidden">
                <div class="bg-[#1a7a2e] px-6 pt-6 pb-4 text-center">
                    <div class="text-4xl mb-2" aria-hidden="true">🏠</div>
                    <h2
                        id="property-purchased-notification-title"
                        class="text-white font-black text-xl tracking-wide uppercase"
                        data-testid="property-purchased-notification-title"
                    >Property Purchased</h2>
                </div>

                <div class="bg-white px-5 pt-4 pb-3 flex items-center gap-3 border-b border-gray-100">
                    <img
                        v-if="buyerIcon"
                        :src="buyerIcon.image_url"
                        :alt="buyerIcon.name"
                        class="w-9 h-9 object-contain shrink-0"
                        data-testid="property-purchased-player-icon"
                    />
                    <div
                        v-else
                        class="w-9 h-9 rounded-full bg-gray-200 shrink-0"
                        data-testid="property-purchased-player-icon"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-gray-600 leading-tight">
                        <span class="font-bold text-gray-900" data-testid="property-purchased-player-name">{{ buyerName }}</span>
                        bought this property
                    </p>
                </div>

                <div class="bg-white px-6 py-4 text-center">
                    <p class="text-gray-800 font-semibold leading-snug mb-2" data-testid="property-purchased-message">
                        <span class="font-bold text-gray-900">{{ squareName }}</span>
                        was purchased for
                        <span class="font-black text-[#1a7a2e]">${{ purchasePrice }}</span>
                    </p>
                </div>

                <div class="bg-white px-6 pb-6">
                    <button
                        type="button"
                        class="w-full py-2.5 rounded-xl bg-[#1a7a2e] text-white font-black text-base uppercase tracking-wide hover:bg-[#145f23] active:scale-95 transition focus:outline-none focus:ring-2 focus:ring-[#1a7a2e] focus:ring-offset-2"
                        data-testid="property-purchased-ok"
                        @click="emit('close')"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>