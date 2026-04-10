<script setup>
/**
 * MonopolyBoard
 *
 * Full-screen board overlay rendered as an 11×11 CSS grid.
 *
 * Props:
 *   game – the game object returned by the API { id, name, user_id, status }
 *
 * Logic:
 *   BOARD_SQUARES defines all 40 squares in clockwise order starting at square 0
 *   (GO, bottom-right corner).  The 11×11 grid has:
 *     - 4 corner squares occupying 1 cell each
 *     - 9 edge squares per side filling the remaining cells
 *     - A 9×9 interior area (grid columns/rows 2-10) for the centre panel
 *   The component uses BoardSquare for every individual cell.
 */

import { computed } from 'vue';
import BoardSquare from '@/Components/BoardSquare.vue';

defineProps({
    game: {
        type: Object,
        required: true,
    },
});

/**
 * All 40 Monopoly squares in clockwise order starting from GO (index 0).
 * Positions on the 11×11 grid are assigned by the layout helpers below.
 * 'color' uses CSS colour strings faithful to the standard board groups.
 */
const BOARD_SQUARES = [
    // Bottom row – right to left (GO corner is index 0)
    { name: 'GO',                type: 'go',        col: 11, row: 11 },
    { name: 'Mediterranean Ave', type: 'property',  color: '#955436', price: 60,  col: 10, row: 11 },
    { name: 'Community Chest',   type: 'community',                               col: 9,  row: 11 },
    { name: 'Baltic Ave',        type: 'property',  color: '#955436', price: 60,  col: 8,  row: 11 },
    { name: 'Income Tax',        type: 'tax',                                      col: 7,  row: 11 },
    { name: 'Reading Railroad',  type: 'railroad',  icon: '🚂', price: 200,        col: 6,  row: 11 },
    { name: 'Oriental Ave',      type: 'property',  color: '#aae0fa', price: 100, col: 5,  row: 11 },
    { name: 'Chance',            type: 'chance',                                   col: 4,  row: 11 },
    { name: 'Vermont Ave',       type: 'property',  color: '#aae0fa', price: 100, col: 3,  row: 11 },
    { name: 'Connecticut Ave',   type: 'property',  color: '#aae0fa', price: 120, col: 2,  row: 11 },
    // Bottom-left corner — Jail
    { name: 'Jail / Just Visiting', type: 'jail',                                 col: 1,  row: 11 },

    // Left column – bottom to top
    { name: 'St. Charles Place', type: 'property',  color: '#d93a96', price: 140, col: 1, row: 10 },
    { name: 'Electric Company',  type: 'utility',   icon: '💡', price: 150,        col: 1, row: 9  },
    { name: 'States Ave',        type: 'property',  color: '#d93a96', price: 140, col: 1, row: 8  },
    { name: 'Virginia Ave',      type: 'property',  color: '#d93a96', price: 160, col: 1, row: 7  },
    { name: 'Pennsylvania Railroad', type: 'railroad', icon: '🚂', price: 200,   col: 1, row: 6  },
    { name: 'St. James Place',   type: 'property',  color: '#f7941d', price: 180, col: 1, row: 5  },
    { name: 'Community Chest',   type: 'community',                                col: 1, row: 4  },
    { name: 'Tennessee Ave',     type: 'property',  color: '#f7941d', price: 180, col: 1, row: 3  },
    { name: 'New York Ave',      type: 'property',  color: '#f7941d', price: 200, col: 1, row: 2  },
    // Top-left corner — Free Parking
    { name: 'Free Parking',      type: 'free',                                     col: 1, row: 1  },

    // Top row – left to right
    { name: 'Kentucky Ave',      type: 'property',  color: '#ed1b24', price: 220, col: 2,  row: 1 },
    { name: 'Chance',            type: 'chance',                                   col: 3,  row: 1 },
    { name: 'Indiana Ave',       type: 'property',  color: '#ed1b24', price: 220, col: 4,  row: 1 },
    { name: 'Illinois Ave',      type: 'property',  color: '#ed1b24', price: 240, col: 5,  row: 1 },
    { name: 'B&O Railroad',      type: 'railroad',  icon: '🚂', price: 200,        col: 6,  row: 1 },
    { name: 'Atlantic Ave',      type: 'property',  color: '#fef200', price: 260, col: 7,  row: 1 },
    { name: 'Ventnor Ave',       type: 'property',  color: '#fef200', price: 260, col: 8,  row: 1 },
    { name: 'Water Works',       type: 'utility',   icon: '🚰',  price: 150,       col: 9,  row: 1 },
    { name: 'Marvin Gardens',    type: 'property',  color: '#fef200', price: 280, col: 10, row: 1 },
    // Top-right corner — Go To Jail
    { name: 'Go To Jail',        type: 'gotojail',                                col: 11, row: 1  },

    // Right column – top to bottom
    { name: 'Pacific Ave',       type: 'property',  color: '#1fb25a', price: 300, col: 11, row: 2  },
    { name: 'North Carolina Ave',type: 'property',  color: '#1fb25a', price: 300, col: 11, row: 3  },
    { name: 'Community Chest',   type: 'community',                                col: 11, row: 4  },
    { name: 'Pennsylvania Ave',  type: 'property',  color: '#1fb25a', price: 320, col: 11, row: 5  },
    { name: 'Short Line Railroad',type: 'railroad', icon: '🚂', price: 200,        col: 11, row: 6  },
    { name: 'Chance',            type: 'chance',                                   col: 11, row: 7  },
    { name: 'Park Place',        type: 'property',  color: '#0072bb', price: 350, col: 11, row: 8  },
    { name: 'Luxury Tax',        type: 'luxury',                                   col: 11, row: 9  },
    { name: 'Boardwalk',         type: 'property',  color: '#0072bb', price: 400, col: 11, row: 10 },
];

/**
 * Build a lookup map { 'col-row': square } for fast template binding.
 *
 * Logic: Iterates BOARD_SQUARES and keys each entry by its CSS grid position
 * string so the grid template can reference any cell in O(1).
 */
const squareMap = computed(() => {
    const map = {};
    for (const sq of BOARD_SQUARES) {
        map[`${sq.col}-${sq.row}`] = sq;
    }
    return map;
});

/**
 * Derive the square orientation from its grid position.
 *
 * Logic:
 *   - Corner cells (1,1), (11,1), (1,11), (11,11) → 'corner'
 *   - Top row (row 1)    → 'top'
 *   - Bottom row (row 11)→ 'bottom'
 *   - Left col (col 1)   → 'left'
 *   - Right col (col 11) → 'right'
 *
 * @param {number} col
 * @param {number} row
 * @returns {string}
 */
function orientation(col, row) {
    const isEdgeCol = col === 1 || col === 11;
    const isEdgeRow = row === 1 || row === 11;
    if (isEdgeCol && isEdgeRow) return 'corner';
    if (row === 1)  return 'top';
    if (row === 11) return 'bottom';
    if (col === 1)  return 'left';
    return 'right';
}

/** Rows 1-11, columns 1-11 used in the grid template. */
const GRID_INDICES = Array.from({ length: 11 }, (_, i) => i + 1);

/**
 * The 8 standard Monopoly property colour groups in board order.
 * 'darkText' forces black text on light-coloured headers.
 */
const PROPERTY_GROUPS = [
    { name: 'Brown',      color: '#955436', count: 2, darkText: false },
    { name: 'Light Blue', color: '#aae0fa', count: 3, darkText: true  },
    { name: 'Pink',       color: '#d93a96', count: 3, darkText: false },
    { name: 'Orange',     color: '#f7941d', count: 3, darkText: false },
    { name: 'Red',        color: '#ed1b24', count: 3, darkText: false },
    { name: 'Yellow',     color: '#fef200', count: 3, darkText: true  },
    { name: 'Green',      color: '#1fb25a', count: 3, darkText: false },
    { name: 'Dark Blue',  color: '#0072bb', count: 2, darkText: false },
];

/**
 * Enriched group list joining colour-group metadata with its board property squares.
 *
 * Logic: Maps each PROPERTY_GROUPS entry and attaches a `properties` array built
 * by filtering BOARD_SQUARES for squares whose type is 'property' and whose color
 * matches the group color, preserving board order so the cheapest property is first.
 *
 * @returns {Array<{name: string, color: string, count: number, darkText: boolean, properties: Array}>}
 */
const PROPERTY_GROUPS_WITH_PROPS = computed(() =>
    PROPERTY_GROUPS.map(group => ({
        ...group,
        properties: BOARD_SQUARES.filter(
            sq => sq.type === 'property' && sq.color === group.color,
        ),
    }))
);

/**
 * All 4 railroad squares from the board, in board order.
 *
 * Logic: Filters BOARD_SQUARES for entries whose type is 'railroad',
 * preserving clockwise board order so the deck reads naturally.
 *
 * @returns {Array<{name: string, icon: string, type: string}>}
 */
const RAILROADS = computed(() =>
    BOARD_SQUARES.filter(sq => sq.type === 'railroad')
);

/**
 * Both utility squares from the board (Electric Company, Water Works).
 *
 * Logic: Filters BOARD_SQUARES for entries whose type is 'utility',
 * preserving board order so they are displayed consistently.
 *
 * @returns {Array<{name: string, icon: string, type: string}>}
 */
const UTILITIES = computed(() =>
    BOARD_SQUARES.filter(sq => sq.type === 'utility')
);
</script>

<template>
    <!-- Full-screen overlay -->
    <div
        class="fixed inset-0 z-50 bg-[#c8e6c9] flex flex-col items-center justify-center overflow-auto"
        aria-label="Monopoly board"
    >
        <!-- Game name header -->
        <div class="w-full text-center py-1 sm:py-2 bg-[#1a7a2e] shadow z-10 shrink-0">
            <span class="text-white font-black tracking-widest text-xs sm:text-sm lg:text-base uppercase">
                {{ game.name }}
            </span>
        </div>

        <!-- Board wrapper – keeps the 11×11 grid square & responsive -->
        <div class="flex-1 flex items-center justify-center w-full min-h-0 p-1 sm:p-2 lg:p-4">
            <div
                class="board-grid w-full h-full"
                style="
                    display: grid;
                    grid-template-columns: 1.1fr repeat(9, 1fr) 1.1fr;
                    grid-template-rows:    1.1fr repeat(9, 1fr) 1.1fr;
                    max-width: min(98vw, 98vh);
                    max-height: min(98vw, 98vh);
                    aspect-ratio: 1 / 1;
                "
            >
                <!-- Render all 121 cells -->
                <template v-for="row in GRID_INDICES" :key="row">
                    <template v-for="col in GRID_INDICES" :key="`${col}-${row}`">
                        <!-- Edge / corner square -->
                        <template v-if="squareMap[`${col}-${row}`]">
                            <BoardSquare
                                :square="squareMap[`${col}-${row}`]"
                                :orientation="orientation(col, row)"
                                :style="{
                                    gridColumn: col,
                                    gridRow: row,
                                }"
                            />
                        </template>

                        <!-- Interior cell -->
                        <template v-else>
                            <div
                                v-if="col === 6 && row === 6"
                                :style="{ gridColumn: '2 / 11', gridRow: '2 / 11' }"
                                class="relative z-10 bg-[#c8e6c9] flex flex-col items-center justify-center select-none overflow-hidden min-w-0 min-h-0"
                                style="container-type: size; gap: 1.4cqw;"
                            >
                                <!-- MONOPOLY wordmark -->
                                <div class="flex items-center justify-center">
                                    <span
                                        class="font-black tracking-widest text-[#1a7a2e] uppercase leading-none"
                                        style="font-size: clamp(0.5rem, 7cqw, 2.2rem);"
                                    >MONOPOLY</span>
                                </div>

                                <!-- Deck cards row – all sizing relative to the container width via cqw -->
                                <div
                                    class="flex flex-row items-center justify-center min-w-0 overflow-hidden"
                                    style="gap: 2cqw;"
                                >
                                    <!-- Community Chest deck -->
                                    <div
                                        class="flex flex-col items-center"
                                        style="gap: 0.7cqw;"
                                        aria-label="Community Chest deck"
                                    >
                                        <div
                                            class="rounded border-2 border-gray-700 bg-amber-100 flex items-center justify-center shadow"
                                            style="width: clamp(0.8rem, 11cqw, 4rem); height: clamp(1.2rem, 15cqw, 5.5rem);"
                                        >
                                            <div class="flex flex-col items-center px-1" style="gap: 0.7cqw;">
                                                <span class="text-amber-700 font-black leading-none" style="font-size: clamp(0.35rem, 3.5cqw, 1.1rem);">🏛</span>
                                                <span class="text-amber-800 font-bold text-center leading-tight" style="font-size: clamp(0.18rem, 2cqw, 0.6rem);">COMMUNITY<br>CHEST</span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Left property groups: Brown, Light Blue, Pink, Orange — 2×2 stacked deck grid -->
                                    <div
                                        class="grid"
                                        style="grid-template-columns: clamp(0.7rem, 9cqw, 3.8rem) clamp(0.7rem, 9cqw, 3.8rem); gap: 0.7cqw; align-items: start;"
                                        aria-label="Property colour groups left"
                                    >
                                        <div
                                            v-for="group in PROPERTY_GROUPS_WITH_PROPS.slice(0, 4)"
                                            :key="group.name"
                                            class="relative"
                                            :style="`height: calc(clamp(1rem, 11cqw, 4.8rem) + ${group.count - 1} * clamp(0.3rem, 3cqw, 1.3rem));`"
                                            :aria-label="group.name + ' property stack'"
                                        >
                                            <div
                                                v-for="(prop, idx) in group.properties"
                                                :key="prop.name"
                                                class="absolute inset-x-0 rounded border border-gray-600 bg-white overflow-hidden shadow flex flex-col"
                                                :style="`height: clamp(1rem, 11cqw, 4.8rem); top: calc(${idx} * clamp(0.3rem, 3cqw, 1.3rem)); z-index: ${idx + 1};`"
                                            >
                                                <div
                                                    :style="{ backgroundColor: group.color }"
                                                    class="flex items-center justify-center px-0.5 py-px"
                                                    style="min-height: 38%;"
                                                >
                                                    <span
                                                        class="font-bold text-center leading-tight"
                                                        :class="group.darkText ? 'text-gray-900' : 'text-white'"
                                                        style="font-size: clamp(0.15rem, 1cqw, 0.4rem); word-break: break-word;"
                                                    >{{ prop.name }}</span>
                                                </div>
                                                <div class="flex-1 flex items-center justify-center">
                                                    <span
                                                        class="text-gray-700 font-semibold"
                                                        style="font-size: clamp(0.13rem, 0.85cqw, 0.36rem);"
                                                    >${{ prop.price }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Centre column: railroads above logo, utilities below -->
                                    <div class="flex flex-col items-center" style="gap: 1.1cqw;">
                                        <!-- Railroad card deck (4 cards stacked) -->
                                        <div
                                            class="relative self-center"
                                            style="width: clamp(1rem, 14cqw, 5rem); height: calc(clamp(1rem, 10cqw, 4rem) + 3 * clamp(0.4rem, 4cqw, 1.6rem));"
                                            aria-label="Railroad cards deck"
                                        >
                                            <div
                                                v-for="(rr, idx) in RAILROADS"
                                                :key="rr.name"
                                                class="absolute inset-x-0 rounded border border-gray-500 bg-white overflow-hidden shadow flex flex-col"
                                                :style="`height: clamp(1rem, 10cqw, 4rem); top: calc(${idx} * clamp(0.4rem, 4cqw, 1.6rem)); z-index: ${idx + 1};`"
                                            >
                                                <div class="bg-gray-800 flex items-center justify-center px-0.5 py-px" style="min-height: 38%;">
                                                    <span class="text-white font-bold text-center leading-tight" style="font-size: clamp(0.13rem, 0.85cqw, 0.36rem); word-break: break-word;">{{ rr.name }}</span>
                                                </div>
                                                <div class="flex-1 flex flex-col items-center justify-center gap-0">
                                                    <span style="font-size: clamp(0.2rem, 1.4cqw, 0.55rem); line-height: 1;">{{ rr.icon }}</span>
                                                    <span class="text-gray-700 font-semibold" style="font-size: clamp(0.13rem, 0.85cqw, 0.36rem);">${{ rr.price }}</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Logo -->
                                        <img
                                            src="/favicon.svg"
                                            alt="Monopoly logo"
                                            style="width: clamp(1rem, 14cqw, 5rem); height: clamp(1rem, 14cqw, 5rem);"
                                            aria-label="Monopoly emblem"
                                        />

                                        <!-- Utility cards (2 side by side) -->
                                        <div class="flex flex-row" style="gap: 0.7cqw;" aria-label="Utility cards">
                                            <div
                                                v-for="util in UTILITIES"
                                                :key="util.name"
                                                class="rounded border border-gray-500 bg-white overflow-hidden shadow flex flex-col items-center"
                                                style="width: clamp(0.6rem, 6cqw, 2.6rem); height: clamp(0.8rem, 9cqw, 3.5rem);"
                                            >
                                                <div class="bg-sky-100 w-full flex items-center justify-center py-px" style="min-height: 40%;">
                                                    <span style="font-size: clamp(0.25rem, 1.8cqw, 0.7rem);">{{ util.icon }}</span>
                                                </div>
                                                <div class="flex-1 flex flex-col items-center justify-center px-0.5" style="gap: 0.35cqw;">
                                                    <span class="text-gray-700 font-bold text-center leading-tight" style="font-size: clamp(0.12rem, 0.75cqw, 0.32rem); word-break: break-word;">{{ util.name }}</span>
                                                    <span class="text-gray-900 font-bold text-center leading-none" style="font-size: clamp(0.12rem, 0.75cqw, 0.32rem);">${{ util.price }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Right property groups: Red, Yellow, Green, Dark Blue — 2×2 stacked deck grid -->
                                    <div
                                        class="grid"
                                        style="grid-template-columns: clamp(0.7rem, 9cqw, 3.8rem) clamp(0.7rem, 9cqw, 3.8rem); gap: 0.7cqw; align-items: start;"
                                        aria-label="Property colour groups right"
                                    >
                                        <div
                                            v-for="group in PROPERTY_GROUPS_WITH_PROPS.slice(4, 8)"
                                            :key="group.name"
                                            class="relative"
                                            :style="`height: calc(clamp(1rem, 11cqw, 4.8rem) + ${group.count - 1} * clamp(0.3rem, 3cqw, 1.3rem));`"
                                            :aria-label="group.name + ' property stack'"
                                        >
                                            <div
                                                v-for="(prop, idx) in group.properties"
                                                :key="prop.name"
                                                class="absolute inset-x-0 rounded border border-gray-600 bg-white overflow-hidden shadow flex flex-col"
                                                :style="`height: clamp(1rem, 11cqw, 4.8rem); top: calc(${idx} * clamp(0.3rem, 3cqw, 1.3rem)); z-index: ${idx + 1};`"
                                            >
                                                <div
                                                    :style="{ backgroundColor: group.color }"
                                                    class="flex items-center justify-center px-0.5 py-px"
                                                    style="min-height: 38%;"
                                                >
                                                    <span
                                                        class="font-bold text-center leading-tight"
                                                        :class="group.darkText ? 'text-gray-900' : 'text-white'"
                                                        style="font-size: clamp(0.15rem, 1cqw, 0.4rem); word-break: break-word;"
                                                    >{{ prop.name }}</span>
                                                </div>
                                                <div class="flex-1 flex items-center justify-center">
                                                    <span
                                                        class="text-gray-700 font-semibold"
                                                        style="font-size: clamp(0.13rem, 0.85cqw, 0.36rem);"
                                                    >${{ prop.price }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Chance deck -->
                                    <div
                                        class="flex flex-col items-center"
                                        style="gap: 0.7cqw;"
                                        aria-label="Chance deck"
                                    >
                                        <div
                                            class="rounded border-2 border-gray-700 bg-orange-50 flex items-center justify-center shadow"
                                            style="width: clamp(0.8rem, 11cqw, 4rem); height: clamp(1.2rem, 15cqw, 5.5rem);"
                                        >
                                            <div class="flex flex-col items-center px-1" style="gap: 0.7cqw;">
                                                <span class="text-orange-500 font-black leading-none" style="font-size: clamp(0.4rem, 3.5cqw, 1.1rem);">?</span>
                                                <span class="text-orange-700 font-bold text-center leading-tight" style="font-size: clamp(0.18rem, 2cqw, 0.6rem);">CHANCE</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Subtitle -->
                                <span
                                    class="text-[#1a7a2e] font-semibold tracking-wide uppercase"
                                    style="font-size: clamp(0.18rem, 2.5cqw, 0.75rem);"
                                >The Fast-Dealing Property Trading Game</span>
                            </div>

                            <!-- Every other interior cell is invisible (covered by the spanning centre div) -->
                            <div
                                v-else
                                :style="{ gridColumn: col, gridRow: row }"
                                class="bg-[#c8e6c9]"
                                aria-hidden="true"
                            />
                        </template>
                    </template>
                </template>
            </div>
        </div>
    </div>
</template>
