<script setup>
import DebugDismissBadge from '@/Components/DebugDismissBadge.vue';

/**
 * CardRevealModal
 *
 * Animated card-reveal overlay that shows a drawn Chance or Community Chest card.
 *
 * Props:
 *   card    – the drawn card object { id, action, text, amount, ... }
 *   type    – 'chance' | 'community'  — controls colour theme and label
 *   visible – boolean, controls whether the overlay is shown
 *
 * Emits:
 *   close – emitted when the user dismisses the card
 *
 * Logic:
 *   The card enters with a CSS flip animation (rotateY 90° → 0°) driven by a
 *   CSS class toggled when `visible` transitions from false to true via a
 *   watch + nextTick so the browser renders the "face-down" state first.
 *   Clicking anywhere on the overlay or the "Got it" button emits `close`.
 */

import { ref, watch } from 'vue';

const props = defineProps({
    card: {
        type: Object,
        default: null,
    },
    type: {
        type: String,
        default: 'chance',
        validator: (v) => ['chance', 'community'].includes(v),
    },
    visible: {
        type: Boolean,
        default: false,
    },
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);

/**
 * `flipped` is set to false the moment a new card appears so the flip animation
 * runs fresh on every draw. After one animation frame it is set to true.
 */
const flipped = ref(false);

watch(
    () => props.visible,
    (show) => {
        if (show) {
            flipped.value = false;
            // eslint-disable-next-line no-undef -- safe: requestAnimationFrame is always available in a browser context
            requestAnimationFrame(() => {
                flipped.value = true;
            });
        }
    },
);

/**
 * Derived theme values based on card type.
 *
 * Logic: Returns a plain object with Tailwind class strings and labels so the
 * template stays free of conditional logic.
 *
 * @returns {{ bg: string, border: string, header: string, icon: string, label: string }}
 */
const theme = {
    chance: {
        bg:     'bg-orange-50',
        border: 'border-orange-400',
        header: 'bg-orange-400 text-white',
        icon:   '?',
        label:  'CHANCE',
    },
    community: {
        bg:     'bg-amber-50',
        border: 'border-amber-500',
        header: 'bg-amber-500 text-white',
        icon:   '🏛',
        label:  'COMMUNITY CHEST',
    },
};

/**
 * Format the card's supplementary detail line (amount, costs, target).
 *
 * Logic: Returns a human-readable string for non-null numeric or string
 * fields.  Returns null when no supplementary info is present.
 *
 * @param {Object} card
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
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="visible"
                class="fixed inset-0 z-[200] flex items-center justify-center bg-black/60"
                aria-modal="true"
                role="dialog"
                :aria-label="`${theme[type].label} card`"
                @click.self="emit('close')"
            >
                <!-- Card itself -->
                <div
                    class="card-flip-container"
                    :class="{ flipped }"
                >
                    <!-- Front (shown after flip) -->
                    <div
                        class="card-face card-front rounded-2xl border-4 shadow-2xl flex flex-col overflow-hidden"
                        :class="[theme[type].bg, theme[type].border]"
                        style="width: min(80vw, 320px); min-height: min(60vw, 260px);"
                    >
                        <!-- Header -->
                        <div
                            class="flex items-center justify-between gap-2 py-3 px-4"
                            :class="theme[type].header"
                        >
                            <div class="flex flex-1 items-center justify-center gap-2">
                                <span class="text-2xl leading-none">{{ theme[type].icon }}</span>
                                <span class="font-black tracking-widest text-sm uppercase">{{ theme[type].label }}</span>
                            </div>
                            <DebugDismissBadge :debug-mode="props.debugMode" @dismiss="emit('close')" />
                        </div>

                        <!-- Card text -->
                        <div class="flex-1 flex flex-col items-center justify-center gap-3 px-6 py-5">
                            <p
                                v-if="card"
                                class="text-gray-800 font-semibold text-center leading-snug"
                                style="font-size: clamp(0.75rem, 3vw, 1rem);"
                                data-testid="card-text"
                            >{{ card.text }}</p>

                            <p
                                v-if="card && cardDetail(card)"
                                class="text-gray-600 text-center font-medium"
                                style="font-size: clamp(0.7rem, 2.5vw, 0.9rem);"
                                data-testid="card-detail"
                            >{{ cardDetail(card) }}</p>
                        </div>

                        <!-- Dismiss button -->
                        <div class="flex justify-center pb-5">
                            <button
                                type="button"
                                class="px-6 py-2 rounded-full font-bold text-sm text-white shadow active:scale-95 transition-transform"
                                :class="type === 'chance' ? 'bg-orange-500 hover:bg-orange-600' : 'bg-amber-600 hover:bg-amber-700'"
                                aria-label="Dismiss card"
                                data-testid="dismiss-button"
                                @click="emit('close')"
                            >Got it</button>
                        </div>
                    </div>

                    <!-- Back (shown before flip completes) -->
                    <div
                        class="card-face card-back rounded-2xl border-4 shadow-2xl flex items-center justify-center"
                        :class="[theme[type].border, type === 'chance' ? 'bg-orange-400' : 'bg-amber-500']"
                        style="width: min(80vw, 320px); min-height: min(60vw, 260px);"
                    >
                        <span class="text-white font-black tracking-widest text-2xl">{{ theme[type].label }}</span>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.card-flip-container {
    perspective: 800px;
    position: relative;
}

.card-face {
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}

.card-back {
    position: absolute;
    inset: 0;
    transform: rotateY(0deg);
    transition: transform 0.45s ease;
}

.card-front {
    transform: rotateY(-90deg);
    transition: transform 0.45s ease;
}

.flipped .card-back {
    transform: rotateY(90deg);
}

.flipped .card-front {
    transform: rotateY(0deg);
}
</style>
