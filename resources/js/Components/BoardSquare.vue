<script setup>
import { computed } from 'vue';

/**
 * BoardSquare
 *
 * Renders a single Monopoly board square.
 *
 * Props:
 *   square        – { name, type, color?, price? }  (from BOARD_SQUARES data)
 *   orientation   – 'bottom' | 'top' | 'left' | 'right' | 'corner'
 *   playerTokens  – array of player objects currently standing on this square;
 *                   each entry: { user_id, name, icon: { image_url } }
 *
 * Logic: Displays a colour band in the appropriate direction, the property name,
 * and an optional price for purchasable squares.  Corner squares show only their
 * label.  Tax / utility / railroad squares show their type icon character.
 * When playerTokens is non-empty and the square is the GO corner, small player
 * icon images are rendered in the bottom-right area of the cell.
 */

const props = defineProps({
    square: {
        type: Object,
        required: true,
    },
    orientation: {
        type: String,
        default: 'bottom',
        validator: (v) => ['bottom', 'top', 'left', 'right', 'corner'].includes(v),
    },
    /**
     * Players currently standing on this square.
     * Each entry: { user_id: number, name: string, icon: { image_url: string } }
     */
    playerTokens: {
        type: Array,
        default: () => [],
    },
    /**
     * Whether this square should emit debug click events.
     */
    debugClickEnabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['debug-square-clicked']);

const BAND_POSITION = {
    bottom: 'top',
    top:    'bottom',
    left:   'right',
    right:  'left',
};

const bandSide = BAND_POSITION[props.orientation] ?? null;
const isCorner = props.orientation === 'corner';
const isVertical = props.orientation === 'left' || props.orientation === 'right';

const TYPE_ICONS = {
    railroad:  '🚂',
    utility:   '💡',
    tax:       '💰',
    go:        '→',
    jail:      '⛓',
    free:      '🅿',
    gotojail:  '👮',
    chance:    '?',
    community: '🏛',
    luxury:    '✦',
};

const icon = props.square.icon ?? TYPE_ICONS[props.square.type] ?? null;

const hasHighlightedToken = computed(
    () => props.playerTokens.some(player => Boolean(player.isHighlighted)),
);

const jailIncarceratedTokens = computed(() => {
    if (props.square.type !== 'jail') {
        return [];
    }

    return props.playerTokens.filter(player => Boolean(player.isInJail));
});

const jailIncarceratedTokensLeft = computed(() => jailIncarceratedTokens.value.filter((_, index) => index % 2 === 0));

const jailIncarceratedTokensRight = computed(() => jailIncarceratedTokens.value.filter((_, index) => index % 2 === 1));

const jailJustVisitingTokens = computed(() => {
    if (props.square.type !== 'jail') {
        return [];
    }

    return props.playerTokens.filter(player => !player.isInJail);
});

const highlightVisualStyle = computed(() => {
    if (!hasHighlightedToken.value) {
        return '';
    }

    const tint = 'inset 0 0 0 9999px rgba(251,146,60,0.15)';

    if (isCorner) {
        return `${tint}, inset 0 0 0 2px rgba(249,115,22,0.9)`;
    }

    return tint;
});

/**
 * Emit a debug square selection event when click-to-move is enabled.
 *
 * @returns {void}
 */
function emitDebugSquareClick() {
    if (!props.debugClickEnabled) {
        return;
    }

    emit('debug-square-clicked', props.square);
}
</script>

<template>
    <!-- Corner square -->
    <div
        v-if="isCorner"
        class="relative w-full h-full border border-gray-700 overflow-hidden"
        :class="[
            debugClickEnabled ? 'cursor-pointer' : '',
        ]"
        :style="{
            containerType: 'size',
            boxShadow: highlightVisualStyle,
        }"
        :aria-label="square.name"
        @click="emitDebugSquareClick"
    >
        <!-- ── Jail / Just Visiting ── -->
        <template v-if="square.type === 'jail'">
            <!-- Outer area – "Just Visiting" passage, warm cream background -->
            <div class="absolute inset-0 bg-[#fce9b8]" />

            <!-- Inner jail cell –– upper-right quadrant -->
            <div
                class="absolute top-0 right-0 w-[68%] h-[68%] bg-[#e8a822] border-l-2 border-b-2 border-gray-700 flex flex-col items-center justify-center overflow-hidden px-[3%] py-[3%]"
                data-testid="jail-cell"
            >
                <span class="font-black text-white drop-shadow leading-none uppercase tracking-wide mb-[4%]" style="font-size: clamp(0.18rem, 10cqmin, 0.5rem);">IN JAIL</span>

                <div class="relative w-[86%] h-[42%] shrink-0 flex items-center justify-center">
                    <div
                        v-if="jailIncarceratedTokensLeft.length"
                        class="absolute left-0 top-1/2 z-20 flex -translate-y-1/2 flex-col items-center gap-[6%]"
                        data-testid="jail-inmate-player-tokens-left"
                    >
                        <img
                            v-for="player in jailIncarceratedTokensLeft"
                            :key="player.user_id ?? player.invitation_id"
                            :src="player.icon.image_url"
                            :alt="player.name"
                            class="rounded-full border border-gray-600 bg-white object-contain transition-transform"
                            :class="[
                                player.isAnimating ? 'animate-bounce ring-2 ring-yellow-400 scale-125' : '',
                            ]"
                            style="width: clamp(0.4rem, 13cqmin, 0.95rem); height: clamp(0.4rem, 13cqmin, 0.95rem);"
                            :data-testid="`player-token-${player.user_id ?? player.invitation_id}`"
                        />
                    </div>

                    <img
                        src="/images/jail-bars.svg"
                        alt="Jail bars"
                        class="relative z-10 w-[54%] h-full"
                    />

                    <div
                        v-if="jailIncarceratedTokensRight.length"
                        class="absolute right-0 top-1/2 z-20 flex -translate-y-1/2 flex-col items-center gap-[6%]"
                        data-testid="jail-inmate-player-tokens-right"
                    >
                        <img
                            v-for="player in jailIncarceratedTokensRight"
                            :key="player.user_id ?? player.invitation_id"
                            :src="player.icon.image_url"
                            :alt="player.name"
                            class="rounded-full border border-gray-600 bg-white object-contain transition-transform"
                            :class="[
                                player.isAnimating ? 'animate-bounce ring-2 ring-yellow-400 scale-125' : '',
                            ]"
                            style="width: clamp(0.4rem, 13cqmin, 0.95rem); height: clamp(0.4rem, 13cqmin, 0.95rem);"
                            :data-testid="`player-token-${player.user_id ?? player.invitation_id}`"
                        />
                    </div>
                </div>
            </div>

            <!-- "JUST" – vertical, left passage -->
            <div class="absolute top-0 left-0 w-[32%] h-[68%] flex items-center justify-center">
                <span class="font-black text-[#7a4e00] leading-none uppercase tracking-widest [writing-mode:vertical-rl] rotate-180" style="font-size: clamp(0.16rem, 9cqmin, 0.48rem);">JUST</span>
            </div>

            <!-- "VISITING" – horizontal, bottom passage -->
            <div class="absolute bottom-0 left-0 right-0 h-[32%] flex items-center justify-center">
                <span class="font-black text-[#7a4e00] leading-none uppercase tracking-widest" style="font-size: clamp(0.16rem, 9cqmin, 0.48rem);">VISITING</span>
            </div>

            <!-- Just-visiting players (outside the jail bars) -->
            <div
                v-if="jailJustVisitingTokens.length"
                class="absolute bottom-[4%] left-[4%] right-[12%] flex flex-wrap items-end gap-[3%]"
                data-testid="jail-just-visiting-player-tokens"
            >
                <img
                    v-for="player in jailJustVisitingTokens"
                    :key="player.user_id ?? player.invitation_id"
                    :src="player.icon.image_url"
                    :alt="player.name"
                    class="rounded-full border border-gray-500 bg-white object-contain transition-transform"
                    :class="[
                        player.isAnimating ? 'animate-bounce ring-2 ring-yellow-400 scale-125' : '',
                    ]"
                    style="width: clamp(0.5rem, 18cqmin, 1.4rem); height: clamp(0.5rem, 18cqmin, 1.4rem);"
                    :data-testid="`player-token-${player.user_id ?? player.invitation_id}`"
                />
            </div>
        </template>

        <!-- ── Go To Jail ── -->
        <template v-else-if="square.type === 'gotojail'">
            <div class="absolute inset-0 bg-white flex flex-col items-center justify-center">
                <img
                    src="/images/police.svg"
                    alt="Police officer"
                    class="w-[40%] h-auto mb-0.5 shrink-0"
                />
                <span class="font-black text-gray-800 leading-tight text-center px-0.5" style="font-size: clamp(0.2rem, 11cqmin, 0.75rem);">
                    {{ square.name }}
                </span>
            </div>
        </template>

        <!-- ── Free Parking ── -->
        <template v-else-if="square.type === 'free'">
            <div class="absolute inset-0 bg-[#fef9c3] flex flex-col items-center justify-center gap-0.5">
                <img
                    src="/images/car.svg"
                    alt="Car"
                    class="w-[42%] h-auto shrink-0"
                />
                <span class="font-black text-gray-800 leading-tight text-center px-0.5" style="font-size: clamp(0.2rem, 11cqmin, 0.75rem);">
                    {{ square.name }}
                </span>
            </div>
        </template>

        <!-- ── GO ── -->
        <template v-else-if="square.type === 'go'">
            <div class="absolute inset-0 bg-[#fdf6e3] flex flex-col items-center justify-center gap-[2%] px-[4%] py-[3%]">
                <span class="font-black text-[#cc0000] leading-none text-center uppercase tracking-widest" style="font-size: clamp(0.22rem, 11cqmin, 0.875rem);">
                    COLLECT
                </span>
                <span class="font-black text-[#cc0000] leading-none text-center" style="font-size: clamp(0.2rem, 9cqmin, 0.75rem);">
                    $200 SALARY
                </span>
                <img
                    src="/images/go-arrow.svg"
                    alt="GO arrow"
                    class="w-[30%] h-auto shrink-0"
                />
                <span class="font-black text-[#cc0000] leading-none text-center tracking-wider" style="font-size: clamp(0.45rem, 22cqmin, 1.5rem);">
                    GO
                </span>
            </div>
            <!-- Player tokens currently on GO -->
            <div
                v-if="playerTokens.length"
                class="absolute bottom-[3%] right-[3%] flex flex-wrap gap-[2%] justify-end"
                data-testid="go-player-tokens"
            >
                <img
                    v-for="player in playerTokens"
                    :key="player.user_id ?? player.invitation_id"
                    :src="player.icon.image_url"
                    :alt="player.name"
                    class="rounded-full border border-gray-500 bg-white object-contain transition-transform"
                    :class="[
                        player.isAnimating ? 'animate-bounce ring-2 ring-yellow-400 scale-125' : '',
                    ]"
                    style="width: clamp(0.5rem, 18cqmin, 1.4rem); height: clamp(0.5rem, 18cqmin, 1.4rem);"
                    :data-testid="`player-token-${player.user_id ?? player.invitation_id}`"
                />
            </div>
        </template>

        <!-- ── All other corners (fallback) ── -->
        <template v-else>
            <div class="absolute inset-0 bg-white flex items-center justify-center">
                <span class="font-black text-gray-800 leading-tight text-center text-[0.45rem] sm:text-[0.6rem] lg:text-xs px-0.5">
                    {{ square.name }}
                </span>
            </div>
        </template>

        <!-- Player tokens on non-GO corner squares (jail, gotojail, free, fallback) -->
        <div
            v-if="playerTokens.length && square.type !== 'go' && square.type !== 'jail'"
            class="absolute bottom-[3%] left-[3%] flex flex-wrap gap-[2%]"
            data-testid="corner-player-tokens"
        >
            <img
                v-for="player in playerTokens"
                :key="player.user_id ?? player.invitation_id"
                :src="player.icon.image_url"
                :alt="player.name"
                class="rounded-full border border-gray-500 bg-white object-contain transition-transform"
                :class="[
                    player.isAnimating ? 'animate-bounce ring-2 ring-yellow-400 scale-125' : '',
                ]"
                style="width: clamp(0.5rem, 18cqmin, 1.4rem); height: clamp(0.5rem, 18cqmin, 1.4rem);"
                :data-testid="`player-token-${player.user_id ?? player.invitation_id}`"
            />
        </div>
    </div>

    <!-- Edge square -->
    <div
        v-else
        class="relative w-full h-full bg-white border border-gray-700 flex overflow-visible"
        :class="{
            'flex-col': !isVertical,
            'flex-row': isVertical,
            'cursor-pointer': debugClickEnabled,
        }"
        :style="{
            containerType: 'size',
            boxShadow: highlightVisualStyle,
        }"
        :aria-label="square.name"
        @click="emitDebugSquareClick"
    >
        <div
            class="absolute inset-0 flex"
            :class="{
                'flex-col': !isVertical,
                'flex-row': isVertical,
            }"
            style="overflow: hidden;"
        >
        <!-- Colour band -->
        <div
            v-if="square.color"
            class="shrink-0"
            :class="{
                'w-full h-[28%]':  !isVertical,
                'h-full w-[28%]':  isVertical,
                'order-first':     bandSide === 'top' || bandSide === 'left',
                'order-last':      bandSide === 'bottom' || bandSide === 'right',
            }"
            :style="{ backgroundColor: square.color }"
        />

        <!-- Icon for non-property squares (no colour band) -->
        <div
            v-else-if="icon"
            class="shrink-0 flex items-center justify-center"
            :class="{
                'w-full h-[28%]':  !isVertical,
                'h-full w-[28%]':  isVertical,
                'order-first':     bandSide === 'top' || bandSide === 'left',
                'order-last':      bandSide === 'bottom' || bandSide === 'right',
            }"
        >
            <span
                class="leading-none"
                style="font-size: clamp(0.25rem, 20cqmin, 0.75rem);"
                :class="{
                    'text-orange-500 font-black':   square.type === 'chance',
                    'rotate-180':                   orientation === 'top',
                    '[writing-mode:sideways-lr]':   orientation === 'right',
                    '[writing-mode:sideways-rl]':   orientation === 'left',
                }"
            >{{ icon }}</span>
        </div>

        <!-- Text body – vertical squares (left / right):
             flex-row with justify-between places name near the band and price
             near the opposite edge.  bandSide === 'right' (left orientation)
             requires order-last on name and order-first on price to swap them.
             When there is no price (e.g. Community Chest), justify-end is used
             for left-edge squares so the single name element stays near the band
             (right / inner) edge, matching the visual position of property names. -->
        <div
            v-if="isVertical"
            class="flex-1 flex flex-row items-center px-0.5 py-0.5 min-h-0 min-w-0 overflow-hidden"
            :class="{
                'justify-between': !!square.price,
                'justify-end':     !square.price && bandSide === 'right',
            }"
        >
            <span
                class="font-bold text-gray-800 leading-tight hyphens-auto text-center [writing-mode:vertical-rl]"
                style="font-size: clamp(0.18rem, 9cqw, 0.5rem);"
                :class="{ 'order-last': bandSide === 'right', 'rotate-180': orientation !== 'left' }"
            >{{ square.name }}</span>
            <span
                v-if="square.price"
                class="text-gray-500 leading-none text-center [writing-mode:vertical-rl]"
                style="font-size: clamp(0.15rem, 7cqw, 0.4rem);"
                :class="{ 'order-first': bandSide === 'right', 'rotate-180': orientation !== 'left' }"
            >${{ square.price }}</span>
        </div>

        <!-- Text body – horizontal squares (bottom / top):
             flex-col with justify-between pushes name to the band end and price
             to the far edge.  rotate-180 on 'top' squares means the DOM-first
             name appears visually near the (bottom) band after rotation. -->
        <div
            v-else
            class="flex-1 flex flex-col items-center px-0.5 py-0.5 min-h-0 min-w-0 overflow-hidden"
            :class="{
                'rotate-180':      orientation === 'top',
                'justify-between': !!square.price,
                'justify-start':   !square.price,
            }"
        >
            <span
                class="font-bold text-gray-800 leading-tight text-center break-words hyphens-auto"
                style="font-size: clamp(0.18rem, 9cqw, 0.5rem);"
            >{{ square.name }}</span>
            <span
                v-if="square.price"
                class="text-gray-500 leading-none"
                style="font-size: clamp(0.15rem, 7cqw, 0.4rem);"
            >${{ square.price }}</span>
        </div>

        <!-- Player tokens on this edge square -->
        <div
            v-if="playerTokens.length"
            class="absolute inset-0 flex flex-wrap content-end items-end justify-end gap-[2%] p-[2%] pointer-events-none z-10"
            data-testid="edge-player-tokens"
        >
            <img
                v-for="player in playerTokens"
                :key="player.user_id ?? player.invitation_id"
                :src="player.icon.image_url"
                :alt="player.name"
                class="rounded-full border border-gray-500 bg-white object-contain transition-transform"
                :class="[
                    player.isAnimating ? 'animate-bounce ring-2 ring-yellow-400 scale-125' : '',
                ]"
                style="width: clamp(0.4rem, 15cqmin, 1.2rem); height: clamp(0.4rem, 15cqmin, 1.2rem);"
                :data-testid="`player-token-${player.user_id ?? player.invitation_id}`"
            />
        </div>
        </div>
    </div>
</template>
