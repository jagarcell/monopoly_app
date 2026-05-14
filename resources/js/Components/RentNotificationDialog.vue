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
 *   ownerName  – display name of the property owner who received rent
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
    payerName: {
        type: String,
        default: 'Player',
    },
    ownerName: {
        type: String,
        default: 'Player',
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
            class="fixed inset-0 z-[120] flex items-center justify-center p-4"
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
