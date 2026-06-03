<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * PlayerHandCard
 *
 * Renders a single player's hand panel displayed in the free side space
 * outside the Monopoly board. Shows the player's icon image, display name,
 * a creator badge when applicable, and three labelled empty sections for the
 * properties, chance cards, and community chest cards the player holds during
 * the game. When isCurrentPlayer is true a capital section is also shown,
 * displaying the viewer's own remaining cash balance.
 *
 * Props:
 *   player – {
 *     user_id:               number|null,
 *     invitation_id:         number|null,
 *     name:                  string,
 *     is_creator:            boolean,
 *     capital:               number,
 *     icon:                  { id: number, name: string, image_url: string },
 *     properties:            Array,
 *     chance_cards:          Array,
 *     community_chest_cards: Array,
 *   }
 *   isCurrentPlayer – boolean — true only for the card belonging to the current
 *                     viewer; controls whether the capital balance is rendered.
 *   canReinvite     – boolean — whether to show the creator-only re-invite
 *                     button on this player's card.
 *   isReinviting    – boolean — whether a re-invite request is currently in
 *                     flight for this card.
 *   panelAnchor     – string  — indicates which panel the card belongs to:
 *                     'start' for left/top panel and 'end' for right/bottom panel.
 *                     Used to make expanded cards grow toward the board center.
 */
const props = defineProps({
    player: {
        type: Object,
        required: true,
    },
    isCurrentPlayer: {
        type: Boolean,
        default: false,
    },
    canReinvite: {
        type: Boolean,
        default: false,
    },
    isReinviting: {
        type: Boolean,
        default: false,
    },
    panelAnchor: {
        type: String,
        default: 'start',
        validator: (value) => ['start', 'end'].includes(value),
    },
    // Whether debug/QA mode is enabled on the parent board. When true,
    // show both previous and current capital amounts for inspection.
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['expanded-change', 'reinvite']);

const cardRef = ref(null);
const isHovered = ref(false);
const isTouchTapExpanded = ref(false);
const isTouchPressing = ref(false);

const isExpanded = computed(() => isHovered.value || isTouchTapExpanded.value);

/**
 * Ensure expanded cards remain fully visible by scrolling the nearest
 * scroll container only as much as needed.
 *
 * @return {void}
 * Logic: Uses nearest block/inline alignment after expansion so the viewport
 * adjusts minimally while keeping the card in view.
 */
function scrollExpandedCardIntoView() {
    if (!cardRef.value || typeof cardRef.value.scrollIntoView !== 'function') {
        return;
    }

    cardRef.value.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'nearest',
    });
}

/**
 * Handle desktop hover entry to expand the card.
 *
 * @param {PointerEvent} event
 * @return {void}
 * Logic: Ignores touch pointers so mobile interaction is controlled by single tap.
 */
function handlePointerEnter(event) {
    if (event.pointerType === 'touch') {
        return;
    }
    isHovered.value = true;
}

/**
 * Handle desktop hover exit to collapse the card.
 *
 * @param {PointerEvent} event
 * @return {void}
 * Logic: Mirrors hover-enter behavior while ignoring touch pointers.
 */
function handlePointerLeave(event) {
    if (event.pointerType === 'touch') {
        return;
    }
    isHovered.value = false;
}

/**
 * Toggle touch expansion on a single tap.
 *
 * @param {PointerEvent} event
 * @return {void}
 * Logic: Touch pointerdown toggles the expanded card state immediately.
 */
function handlePointerDown(event) {
    if (event.pointerType !== 'touch') {
        return;
    }

    // Prevent native selection/callout behavior during touch-tap expansion.
    event.preventDefault();
    isTouchPressing.value = true;
    isTouchTapExpanded.value = !isTouchTapExpanded.value;
}

/**
 * End touch press interaction on release/cancel.
 *
 * @return {void}
 * Logic: Clears press state after the touch interaction is complete.
 */
function handlePointerEnd() {
    isTouchPressing.value = false;
}

/**
 * Block native text/element selection while touch long-press interaction is active.
 *
 * @param {Event} event
 * @return {void}
 * Logic: Prevents browser select behavior from competing with touch-tap expansion.
 */
function handleSelectStart(event) {
    if (isTouchPressing.value || isTouchTapExpanded.value) {
        event.preventDefault();
    }
}

/**
 * Collapse touch-expanded cards when tapping outside the card.
 *
 * @param {Event} event
 * @return {void}
 * Logic: Keeps single-tap expansion sticky until the user taps elsewhere.
 */
function handleDocumentPointerDown(event) {
    if (!isTouchTapExpanded.value) {
        return;
    }

    const targetNode = event.target;
    if (cardRef.value && targetNode instanceof Node && !cardRef.value.contains(targetNode)) {
        isTouchTapExpanded.value = false;
    }
}

watch(isExpanded, async (expanded) => {
    emit('expanded-change', {
        joinOrder: props.player.join_order ?? null,
        expanded,
    });

    if (!expanded) {
        return;
    }

    await nextTick();
    scrollExpandedCardIntoView();
});

onMounted(() => {
    document.addEventListener('pointerdown', handleDocumentPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handleDocumentPointerDown);
});

/**
 * Generate inline styles for a property tag based on its color.
 *
 * @param {Object} property - The property object { square_index, name, color }
 * @return {Object} Inline style object with backgroundColor and color
 * Logic: Uses the property's hex color as the background, and determines
 * whether to use black or white text based on the color's perceived brightness.
 * Light colors (yellow: #fef200) get dark text; dark colors get white text.
 */
function getPropertyTagStyles(property) {
    if (!property.color) {
        // Fallback to original amber styling
        return { backgroundColor: '#fde047', color: '#78350f' };
    }

    const hexColor = property.color.replace('#', '');
    const r = parseInt(hexColor.substring(0, 2), 16);
    const g = parseInt(hexColor.substring(2, 4), 16);
    const b = parseInt(hexColor.substring(4, 6), 16);

    // Use relative luminance formula to determine if text should be dark or light
    // https://www.w3.org/TR/WCAG20/#relativeluminancedef
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    return {
        backgroundColor: property.color,
        color: luminance > 0.5 ? '#1f2937' : '#ffffff', // dark gray for light backgrounds, white for dark
    };
}

/**
 * Emit a re-invite request for this player card.
 *
 * @return {void}
 * Logic: Emits only when the card is eligible and not currently sending so the
 * parent board can perform the API call.
 */
function handleReinviteClick() {
    if (!props.canReinvite || props.isReinviting) {
        return;
    }

    emit('reinvite', props.player);
}
</script>

<template>
    <div
        ref="cardRef"
        class="hand-card w-40 rounded-xl border border-amber-300 bg-amber-50 shadow-md flex flex-col shrink-0"
        :class="isExpanded ? 'is-expanded min-h-32 overflow-visible' : 'h-32 overflow-hidden'"
        style="container-type: inline-size;"
        :aria-label="`${player.name}'s hand`"
        :aria-expanded="isExpanded"
        :data-panel-anchor="props.panelAnchor"
        :data-expanded="isExpanded ? 'true' : 'false'"
        data-testid="player-hand-card"
        @pointerenter="handlePointerEnter"
        @pointerleave="handlePointerLeave"
        @pointerdown="handlePointerDown"
        @pointerup="handlePointerEnd"
        @pointercancel="handlePointerEnd"
        @selectstart="handleSelectStart"
    >
        <!-- Header: icon + name + creator badge -->
        <div class="flex items-center gap-2 px-3 py-2 border-b border-amber-200 bg-amber-100/60 shrink-0">
            <img
                v-if="player.icon?.image_url"
                :src="player.icon.image_url"
                :alt="player.icon.name"
                class="object-contain shrink-0 rounded-full"
                style="width: clamp(1.2rem, 8cqw, 2rem); height: clamp(1.2rem, 8cqw, 2rem);"
            />
            <div
                v-else
                class="rounded-full bg-amber-200 shrink-0"
                style="width: clamp(1.2rem, 8cqw, 2rem); height: clamp(1.2rem, 8cqw, 2rem);"
                aria-hidden="true"
            />
            <span class="font-bold text-amber-900 truncate flex-1 leading-tight uppercase tracking-wide" style="font-size: clamp(0.6rem, 3cqw, 0.95rem);">
                {{ player.name }}
            </span>
            <span
                v-if="player.is_creator"
                class="font-semibold text-amber-700 uppercase tracking-wide shrink-0 bg-amber-200 rounded px-1.5 py-0.5 leading-none"
                style="font-size: clamp(0.45rem, 2.3cqw, 0.7rem);"
            >
                ★ Creator
            </span>
        </div>

        <div
            v-if="canReinvite && isExpanded"
            class="px-3 py-2 border-b border-amber-200 bg-amber-100/40"
        >
            <button
                type="button"
                class="w-full rounded-md bg-[#1a7a2e] px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wide text-white transition-opacity"
                :class="isReinviting ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:opacity-90'"
                :disabled="isReinviting"
                data-testid="reinvite-button"
                @click.stop="handleReinviteClick"
            >
                {{ isReinviting ? 'Sending...' : 'Re-Invite' }}
            </button>
        </div>

        <!-- Card sections: Properties / Chance / Community -->
        <div
            class="flex flex-col divide-y divide-amber-100"
            :class="isExpanded ? 'overflow-visible' : 'flex-1 min-h-0 overflow-hidden'"
        >
            <div class="flex items-start px-3 gap-2" :class="isExpanded ? 'py-2 overflow-visible' : 'flex-1 py-1 min-h-0 overflow-hidden'">
                <span class="font-bold text-amber-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Properties
                </span>
                <div
                    v-if="Array.isArray(player.properties) && player.properties.length > 0"
                    class="flex flex-col gap-1 min-w-0 pr-1"
                    :class="isExpanded ? 'h-auto overflow-visible' : 'min-h-0 h-8 overflow-y-auto'"
                    data-testid="properties-list"
                >
                    <span
                        v-for="property in player.properties"
                        :key="property.square_index"
                        class="inline-flex items-center rounded px-1.5 py-0.5 font-semibold truncate"
                        :style="getPropertyTagStyles(property)"
                        style="font-size: clamp(0.45rem, 2.2cqw, 0.65rem); max-width: 100%;"
                        :title="property.name"
                        data-testid="property-tag"
                    >
                        {{ property.name }}
                    </span>
                </div>
                <span
                    v-else
                    class="text-amber-300 leading-none"
                    style="font-size: clamp(0.4rem, 2cqw, 0.65rem);"
                    data-testid="properties-empty"
                >—</span>
            </div>
            <div class="flex items-center px-3 gap-2" :class="isExpanded ? 'py-1.5 overflow-visible' : 'flex-1 min-h-0 overflow-hidden'">
                <span class="font-bold text-amber-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Chance
                </span>
                <div
                    v-if="Array.isArray(player.chance_cards) && player.chance_cards.length > 0"
                    class="flex flex-col gap-1 min-w-0 pr-1"
                    :class="isExpanded ? 'h-auto overflow-visible' : 'min-h-0 h-8 overflow-y-auto'"
                    data-testid="chance-cards-list"
                >
                    <span
                        v-for="card in player.chance_cards"
                        :key="card.id"
                        class="inline-flex items-center rounded bg-amber-200 text-amber-800 px-1.5 py-0.5 font-semibold truncate"
                        style="font-size: clamp(0.45rem, 2.2cqw, 0.65rem); max-width: 100%;"
                        :title="card.text"
                        data-testid="chance-card-tag"
                    >
                        {{ card.text }}
                    </span>
                </div>
                <span
                    v-else
                    class="text-amber-300 leading-none"
                    style="font-size: clamp(0.4rem, 2cqw, 0.65rem);"
                    data-testid="chance-cards-empty"
                >—</span>
            </div>
            <div class="flex items-center px-3 gap-2" :class="isExpanded ? 'py-1.5 overflow-visible' : 'flex-1 min-h-0 overflow-hidden'">
                <span class="font-bold text-amber-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Community
                </span>
                <div
                    v-if="Array.isArray(player.community_chest_cards) && player.community_chest_cards.length > 0"
                    class="flex flex-col gap-1 min-w-0 pr-1"
                    :class="isExpanded ? 'h-auto overflow-visible' : 'min-h-0 h-8 overflow-y-auto'"
                    data-testid="community-cards-list"
                >
                    <span
                        v-for="card in player.community_chest_cards"
                        :key="card.id"
                        class="inline-flex items-center rounded bg-amber-200 text-amber-800 px-1.5 py-0.5 font-semibold truncate"
                        style="font-size: clamp(0.45rem, 2.2cqw, 0.65rem); max-width: 100%;"
                        :title="card.text"
                        data-testid="community-card-tag"
                    >
                        {{ card.text }}
                    </span>
                </div>
                <span
                    v-else
                    class="text-amber-300 leading-none"
                    style="font-size: clamp(0.4rem, 2cqw, 0.65rem);"
                    data-testid="community-cards-empty"
                >—</span>
            </div>
            <!-- Capital balance — only shown to the card's own player -->
            <div
                v-if="isCurrentPlayer"
                class="flex items-center justify-between px-3 py-1 bg-green-50 border-t border-green-200 shrink-0"
                data-testid="capital-section"
            >
                <span class="font-bold text-green-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Capital
                </span>
                <div class="text-right">
                    <template v-if="debugMode && player.previous_capital !== null && Number(player.previous_capital) !== Number(player.capital)">
                        <div class="text-xs text-gray-500" style="line-height: 1;">Prev: ${{ (Number(player.previous_capital ?? 0)).toLocaleString() }}</div>
                        <div class="font-semibold text-green-800 tabular-nums" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);" data-testid="capital-amount">
                            ${{ (player.capital ?? 0).toLocaleString() }}
                        </div>
                    </template>
                    <template v-else>
                        <span
                            class="font-semibold text-green-800 tabular-nums"
                            style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);"
                            data-testid="capital-amount"
                        >
                            ${{ (player.capital ?? 0).toLocaleString() }}
                        </span>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.hand-card {
    transform: scale(1);
    transition: transform 180ms ease, box-shadow 180ms ease;
}

.hand-card.is-expanded {
    transform: scale(1.34);
    z-index: 40;
    box-shadow: 0 18px 45px rgba(0, 0, 0, 0.32);
}

/* Portrait: top panel grows down, bottom panel grows up toward the board. */
.hand-card[data-panel-anchor='start'] {
    transform-origin: top center;
}

.hand-card[data-panel-anchor='end'] {
    transform-origin: bottom center;
}

/* Landscape: left panel grows right, right panel grows left toward the board. */
@media (orientation: landscape) {
    .hand-card[data-panel-anchor='start'] {
        transform-origin: left center;
    }

    .hand-card[data-panel-anchor='end'] {
        transform-origin: right center;
    }
}
</style>
