<script setup>
/**
 * BoardSquare
 *
 * Renders a single Monopoly board square.
 *
 * Props:
 *   square      – { name, type, color?, price? }  (from BOARD_SQUARES data)
 *   orientation – 'bottom' | 'top' | 'left' | 'right' | 'corner'
 *
 * Logic: Displays a colour band in the appropriate direction, the property name,
 * and an optional price for purchasable squares.  Corner squares show only their
 * label.  Tax / utility / railroad squares show their type icon character.
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
});

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

const icon = TYPE_ICONS[props.square.type] ?? null;
</script>

<template>
    <!-- Corner square -->
    <div
        v-if="isCorner"
        class="relative w-full h-full border border-gray-700 overflow-hidden"
        :aria-label="square.name"
    >
        <!-- ── Jail / Just Visiting ── -->
        <template v-if="square.type === 'jail'">
            <!-- Outer area – "Just Visiting" passage, warm cream background -->
            <div class="absolute inset-0 bg-[#fce9b8]" />

            <!-- Inner jail cell –– upper-right quadrant -->
            <div class="absolute top-0 right-0 w-[68%] h-[68%] bg-[#e8a822] border-l-2 border-b-2 border-gray-700 flex flex-col items-center justify-center overflow-hidden px-[3%] py-[3%]">
                <span class="font-black text-white drop-shadow leading-none text-[0.28rem] sm:text-[0.38rem] lg:text-[0.5rem] uppercase tracking-wide mb-[4%]">IN JAIL</span>
                <img src="/images/jail-bars.svg" alt="Jail bars" class="w-[85%] h-auto shrink-0" />
            </div>

            <!-- Diagonal cut line from inner-cell corner to outer corner -->
            <svg
                class="absolute inset-0 w-full h-full pointer-events-none"
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
            >
                <line x1="32" y1="68" x2="0" y2="100" stroke="#777" stroke-width="1.2"/>
            </svg>

            <!-- "JUST" – vertical, left passage -->
            <div class="absolute top-0 left-0 w-[32%] h-[68%] flex items-center justify-center">
                <span class="font-black text-[#7a4e00] leading-none text-[0.26rem] sm:text-[0.36rem] lg:text-[0.48rem] uppercase tracking-widest [writing-mode:vertical-rl] rotate-180">JUST</span>
            </div>

            <!-- "VISITING" – horizontal, bottom passage -->
            <div class="absolute bottom-0 left-0 right-0 h-[32%] flex items-center justify-center">
                <span class="font-black text-[#7a4e00] leading-none text-[0.26rem] sm:text-[0.36rem] lg:text-[0.48rem] uppercase tracking-widest">VISITING</span>
            </div>
        </template>

        <!-- ── Go To Jail ── -->
        <template v-else-if="square.type === 'gotojail'">
            <div class="absolute inset-0 bg-white flex flex-col items-center justify-center">
                <img
                    src="/images/police.svg"
                    alt="Police officer"
                    class="w-[55%] h-auto mb-0.5 shrink-0"
                />
                <span class="font-black text-gray-800 leading-tight text-center text-[0.45rem] sm:text-[0.6rem] lg:text-xs px-0.5">
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
                    class="w-[55%] h-auto shrink-0"
                />
                <span class="font-black text-gray-800 leading-tight text-center text-[0.45rem] sm:text-[0.6rem] lg:text-xs px-0.5">
                    {{ square.name }}
                </span>
            </div>
        </template>

        <!-- ── GO ── -->
        <template v-else-if="square.type === 'go'">
            <div class="absolute inset-0 bg-[#fdf6e3] flex flex-col items-center justify-center gap-[2%] px-[4%] py-[3%]">
                <span class="font-black text-[#cc0000] leading-none text-center text-[0.5rem] sm:text-[0.65rem] lg:text-sm uppercase tracking-widest">
                    COLLECT
                </span>
                <span class="font-black text-[#cc0000] leading-none text-center text-[0.45rem] sm:text-[0.6rem] lg:text-xs">
                    $200 SALARY
                </span>
                <img
                    src="/images/go-arrow.svg"
                    alt="GO arrow"
                    class="w-[40%] h-auto shrink-0"
                />
                <span class="font-black text-[#cc0000] leading-none text-center text-[0.9rem] sm:text-[1.1rem] lg:text-2xl tracking-wider">
                    GO
                </span>
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
    </div>

    <!-- Edge square -->
    <div
        v-else
        class="relative w-full h-full bg-white border border-gray-700 flex overflow-hidden"
        :class="{
            'flex-col': !isVertical,
            'flex-row': isVertical,
        }"
        :aria-label="square.name"
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
                class="text-[0.5rem] sm:text-xs leading-none"
                :class="{
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
                class="font-bold text-gray-800 leading-tight hyphens-auto text-center text-[0.35rem] sm:text-[0.45rem] lg:text-[0.55rem] [writing-mode:vertical-rl]"
                :class="{ 'order-last': bandSide === 'right', 'rotate-180': orientation !== 'left' }"
            >{{ square.name }}</span>
            <span
                v-if="square.price"
                class="text-gray-500 leading-none text-center text-[0.3rem] sm:text-[0.4rem] [writing-mode:vertical-rl] rotate-180"
                :class="{ 'order-first': bandSide === 'right' }"
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
                class="font-bold text-gray-800 leading-tight text-center break-words hyphens-auto text-[0.35rem] sm:text-[0.45rem] lg:text-[0.55rem]"
            >{{ square.name }}</span>
            <span
                v-if="square.price"
                class="text-gray-500 leading-none text-[0.3rem] sm:text-[0.4rem]"
            >${{ square.price }}</span>
        </div>
    </div>
</template>
