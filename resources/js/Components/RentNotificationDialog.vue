<script setup>
/**
 * RentNotificationDialog
 *
 * Displays a dismissible notification dialog when rent has been paid.
 * Shown to every board participant — the payer sees a payment confirmation,
 * the owner and observers see a payment received notification.
 *
 * Props:
 *   visible    – controls whether the dialog is shown
 *   payerName  – display name of the player who paid rent
 *   payerIcon  – icon object { image_url, name } for the payer
 *   ownerName  – display name of the property owner who received rent
 *   ownerIcon  – icon object { image_url, name } for the owner
 *   rentAmount – the rent amount that was transferred
 *   squareName – the property name where rent was owed
 *
 * Emits:
 *   close – the OK button was clicked; parent should hide the dialog
 *
 * Logic:
 *   A single OK button is the only dismiss path. There is no backdrop-click
 *   or Escape handler so players cannot accidentally skip the notification.
 *   The dialog is purely informational — capitals are updated reactively in
 *   the player panels before this dialog appears.
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
    rentAmount: {
        type: Number,
        default: 0,
    },
    squareName: {
        type: String,
        default: '',
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
            aria-labelledby="rent-notification-title"
            data-testid="rent-notification-dialog"
        >
            <!-- Non-interactive backdrop — no click handler -->
            <div class="absolute inset-0 bg-black/60 cursor-default" aria-hidden="true" />

            <!-- Dialog panel -->
            <div class="relative z-10 w-full max-w-xs rounded-2xl shadow-2xl overflow-hidden">
                <!-- Header -->
                <div class="bg-red-500 px-6 pt-6 pb-4 text-center">
                    <div class="text-4xl mb-2" aria-hidden="true">🏠</div>
                    <h2
                        id="rent-notification-title"
                        class="text-white font-black text-xl tracking-wide uppercase"
                    >Rent Paid</h2>
                </div>

                <!-- Body -->
                <div class="bg-white px-6 py-5 text-center space-y-2">
                    <div class="flex items-center justify-center gap-5">
                        <div class="flex flex-col items-center gap-1">
                            <img
                                v-if="payerIcon"
                                :src="payerIcon.image_url"
                                :alt="payerIcon.name"
                                class="w-9 h-9 object-contain"
                                data-testid="rent-payer-icon"
                            />
                            <div
                                v-else
                                class="w-9 h-9 rounded-full bg-gray-200"
                                data-testid="rent-payer-icon"
                                aria-hidden="true"
                            />
                            <span class="text-[10px] uppercase font-bold text-gray-500">Payer</span>
                        </div>
                        <div class="text-red-500 font-black text-sm">→</div>
                        <div class="flex flex-col items-center gap-1">
                            <img
                                v-if="ownerIcon"
                                :src="ownerIcon.image_url"
                                :alt="ownerIcon.name"
                                class="w-9 h-9 object-contain"
                                data-testid="rent-owner-icon"
                            />
                            <div
                                v-else
                                class="w-9 h-9 rounded-full bg-gray-200"
                                data-testid="rent-owner-icon"
                                aria-hidden="true"
                            />
                            <span class="text-[10px] uppercase font-bold text-gray-500">Owner</span>
                        </div>
                    </div>
                    <p
                        class="text-gray-700 text-sm leading-relaxed"
                        data-testid="rent-notification-message"
                    >
                        <span class="font-bold text-gray-900" data-testid="rent-payer-name">{{ payerName }}</span>
                        paid
                        <span class="font-black text-red-600" data-testid="rent-amount">${{ rentAmount }}</span>
                        rent to
                        <span class="font-bold text-gray-900" data-testid="rent-owner-name">{{ ownerName }}</span>
                    </p>
                    <p
                        v-if="squareName"
                        class="text-xs text-gray-500"
                        data-testid="rent-square-name"
                    >
                        Property: {{ squareName }}
                    </p>
                </div>

                <!-- Footer — OK is the only dismiss action -->
                <div class="bg-white px-6 pb-6">
                    <button
                        type="button"
                        class="w-full py-2.5 rounded-xl bg-red-500 text-white font-black text-base uppercase tracking-wide hover:bg-red-600 active:scale-95 transition focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                        data-testid="rent-notification-ok"
                        @click="emit('close')"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
