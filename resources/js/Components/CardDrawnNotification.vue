<script setup>
/**
 * CardDrawnNotification
 *
 * Dismissible card-reveal dialog shown to observer boards when another player
 * draws a Chance or Community Chest card.  Displays the drawing player's token
 * icon and name alongside the full card text and detail, giving observers the
 * same information as the drawing player's CardRevealModal.
 *
 * Props:
 *   visible    – controls whether the dialog is shown
 *   playerName – display name of the player who drew the card
 *   playerIcon – icon object { image_url, name } for the drawing player; null
 *                shows a neutral placeholder circle
 *   card       – full card object { text, amount, ... } | null
 *   type       – 'chance' | 'community' — controls colour theme and label
 *
 * Emits:
 *   close – emitted when the observer dismisses the notification
 *
 * Logic:
 *   Renders a three-section modal: a coloured deck header, a player-identity
 *   row (token image + "[Name] drew this card"), and a card-content block
 *   (card text + optional supplementary detail). The OK button is the only
 *   dismiss path — no backdrop-click or Escape — so observers cannot
 *   accidentally skip the notification.
 */

import DebugDismissBadge from '@/Components/DebugDismissBadge.vue';

defineProps({
    visible: {
        type: Boolean,
        required: true,
    },
    zIndex: {
        type: Number,
        default: 130,
    },
    playerName: {
        type: String,
        default: 'Player',
    },
    playerIcon: {
        type: Object,
        default: null,
    },
    card: {
        type: Object,
        default: null,
    },
    type: {
        type: String,
        default: 'chance',
        validator: (v) => ['chance', 'community'].includes(v),
    },
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

/**
 * Derived theme values based on card type.
 *
 * Logic: Returns a plain object with Tailwind class strings and a human-readable
 * deck label so the template stays free of conditional logic.
 *
 * @returns {{ header: string, okBtn: string, label: string, icon: string }}
 */
const theme = {
    chance: {
        header: 'bg-orange-400',
        okBtn:  'bg-orange-500 hover:bg-orange-600 focus:ring-orange-500',
        label:  'Chance',
        icon:   '?',
    },
    community: {
        header: 'bg-amber-500',
        okBtn:  'bg-amber-600 hover:bg-amber-700 focus:ring-amber-600',
        label:  'Community Chest',
        icon:   '🏛',
    },
};

/**
 * Format the card's supplementary detail line.
 *
 * Logic: Checks each optional numeric/string field in priority order and
 * returns the first non-null value formatted for display.  Returns null when
 * no supplementary info is present so the detail paragraph is hidden.
 *
 * @param {Object|null} card
 * @returns {string|null}
 */
function cardDetail(card) {
    if (!card) return null;
    if (card.required_amount != null) return `Total due: $${card.required_amount}`;
    if (card.amount != null)     return `$${card.amount}`;
    if (card.house_cost != null) return `Houses: $${card.house_cost} / Hotels: $${card.hotel_cost}`;
    if (card.spaces != null)     return `${card.spaces} spaces`;
    if (card.target != null)     return card.target.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    return null;
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
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="card-drawn-notification-title"
            data-testid="card-drawn-notification"
        >
            <!-- Non-interactive backdrop — no click handler -->
            <div class="absolute inset-0 bg-black/60 cursor-default" aria-hidden="true" />

            <!-- Dialog panel -->
            <div class="relative z-10 w-full max-w-xs rounded-2xl shadow-2xl overflow-hidden">

                <!-- Deck header -->
                <div
                    class="flex items-center justify-between gap-3 px-6 pt-5 pb-4"
                    :class="theme[type].header"
                >
                    <div class="flex-1 text-center">
                        <div class="text-4xl mb-1" aria-hidden="true">{{ theme[type].icon }}</div>
                        <h2
                            id="card-drawn-notification-title"
                            class="text-white font-black text-xl tracking-wide uppercase"
                            data-testid="card-drawn-notification-title"
                        >{{ theme[type].label }}</h2>
                    </div>
                    <DebugDismissBadge :debug-mode="debugMode" @dismiss="emit('close')" />
                </div>

                <!-- Player identity row -->
                <div class="bg-white px-5 pt-4 pb-3 flex items-center gap-3 border-b border-gray-100">
                    <img
                        v-if="playerIcon"
                        :src="playerIcon.image_url"
                        :alt="playerIcon.name"
                        class="w-9 h-9 object-contain shrink-0"
                        data-testid="card-drawn-notification-player-icon"
                    />
                    <div
                        v-else
                        class="w-9 h-9 rounded-full bg-gray-200 shrink-0"
                        data-testid="card-drawn-notification-player-icon"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-gray-600 leading-tight">
                        <span
                            class="font-bold text-gray-900"
                            data-testid="card-drawn-player-name"
                        >{{ playerName }}</span>
                        drew this card
                    </p>
                </div>

                <!-- Card content -->
                <div class="bg-white px-6 py-4 text-center">
                    <p
                        v-if="card"
                        class="text-gray-800 font-semibold leading-snug mb-2"
                        data-testid="card-drawn-notification-card-text"
                    >{{ card.text }}</p>
                    <p
                        v-if="card && card.required_amount != null"
                        class="text-gray-700 font-semibold mb-2"
                        data-testid="card-drawn-notification-total-due"
                    >Total due: ${{ card.required_amount }}</p>
                    <p
                        v-if="card && cardDetail(card)"
                        class="text-gray-500 font-medium text-sm"
                        data-testid="card-drawn-notification-card-detail"
                    >{{ cardDetail(card) }}</p>
                </div>

                <!-- Footer — OK is the only dismiss action -->
                <div class="bg-white px-6 pb-6">
                    <button
                        type="button"
                        class="w-full py-2.5 rounded-xl text-white font-black text-base uppercase tracking-wide active:scale-95 transition focus:outline-none focus:ring-2 focus:ring-offset-2"
                        :class="theme[type].okBtn"
                        data-testid="card-drawn-notification-ok"
                        @click="emit('close')"
                    >
                        OK
                    </button>
                </div>

            </div>
        </div>
    </Transition>
</template>
