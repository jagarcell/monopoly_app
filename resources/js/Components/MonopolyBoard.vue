<script setup>
/**
 * MonopolyBoard
 *
 * Full-screen board overlay rendered as an 11×11 CSS grid.
 *
 * Props:
 *   game            – the game object returned by the API { id, name, user_id, status }
 *   invitationToken – optional UUID token for guest players; when present, draw
 *                     requests are sent to the unauthenticated guest endpoints
 *                     (/api/join/{token}/chance|community/draw) instead of the
 *                     authenticated owner endpoints.
 *
 * Logic:
 *   BOARD_SQUARES defines all 40 squares in clockwise order starting at square 0
 *   (GO, bottom-right corner).  The 11×11 grid has:
 *     - 4 corner squares occupying 1 cell each
 *     - 9 edge squares per side filling the remaining cells
 *     - A 9×9 interior area (grid columns/rows 2-10) for the centre panel
 *   The component uses BoardSquare for every individual cell.
 *   Clicking the Community Chest or Chance deck calls the draw API and shows the
 *   drawn card via CardRevealModal with a flip animation. The API moves the drawn
 *   card to the bottom of the deck automatically.
 */

import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import BoardSquare from '@/Components/BoardSquare.vue';import CardRevealModal from '@/Components/CardRevealModal.vue';
import DiceRoller from '@/Components/DiceRoller.vue';
import PendingInvitationsList from '@/Components/PendingInvitationsList.vue';
import PlayerHandCard from '@/Components/PlayerHandCard.vue';

const props = defineProps({
    game: {
        type: Object,
        required: true,
    },
    /** UUID token used by guest players to authenticate draw API calls. */
    invitationToken: {
        type: String,
        default: null,
    },
    /**
     * Array of player objects built by the API after game creation.
     * Each entry: { user_id, invitation_id, name, is_creator, capital, icon,
     * properties, chance_cards, community_chest_cards }.
     */
    players: {
        type: Array,
        default: () => [],
    },
    /**
     * The authenticated user's ID. Used to identify the current player's card
     * so their capital is shown only on their own card and nobody else's.
     * Null for guest players (who are identified via currentInvitationId).
     */
    currentUserId: {
        type: Number,
        default: null,
    },
    /**
     * The invitation ID of the current guest player. Used to identify the guest's
     * own card so their capital is shown only to them. Null for authenticated
     * creator/players (who are identified via currentUserId).
     */
    currentInvitationId: {
        type: Number,
        default: null,
    },
    /**
     * Invitations that have been sent but not yet accepted (and not expired).
     * Seeded from the server at page-load; updated in real time via the
     * PlayerJoined WebSocket event so entries disappear as guests join without
     * any page reload.
     */
    pendingInvitations: {
        type: Array,
        default: () => [],
    },
});

/**
 * Reactive local copy of the players array.
 *
 * Seeded from props.players on init; updated in real time when a PlayerJoined
 * WebSocket event arrives. All derived computeds read from this ref so the
 * panels and board tokens update without any page reload.
 */
const localPlayers = ref([...props.players]);

/**
 * Reactive local copy of the pending invitations list.
 *
 * Seeded from props.pendingInvitations on init; updated in real time when a
 * PlayerJoined WebSocket event arrives so the waiting-room list shrinks as
 * guests join without any page reload.
 */
const localPendingInvitations = ref([...props.pendingInvitations]);

/**
 * Keep localPlayers in sync when Inertia refreshes the page props (e.g. hard
 * refresh or back-navigation). We only replace if the incoming array has at
 * least as many players as the current local state, so a stale Inertia prop
 * does not clobber a real-time update that arrived earlier.
 */
watch(
    () => props.players,
    (incoming) => {
        if (incoming.length >= localPlayers.value.length) {
            localPlayers.value = [...incoming];
        }
    },
);

/**
 * Keep localPendingInvitations in sync when Inertia refreshes the page props.
 * Pending invitations only ever shrink as players join, so we always accept the
 * incoming value from a prop refresh (it reflects the latest server state).
 */
watch(
    () => props.pendingInvitations,
    (incoming) => {
        localPendingInvitations.value = [...incoming];
    },
);

// ── Turn tracking ──────────────────────────────────────────────────────────

/**
 * The join_order of the player whose turn it currently is.
 *
 * Seeded from game.current_turn_join_order (defaults to 1 — the creator —
 * when the field is absent on older records). Updated reactively after each
 * roll (from the API response) and when a DiceRolled WebSocket event arrives
 * from another player.
 */
const currentTurnJoinOrder = ref(props.game.current_turn_join_order ?? 1);

/**
 * The join_order of the player viewing this board, or null when not yet known.
 *
 * Logic: Finds the entry in localPlayers where isCurrentPlayer returns true and
 * reads its join_order. Returns null when the current viewer is not yet in the
 * players list (e.g. during the joining flow before the full list has loaded).
 *
 * @returns {number|null}
 */
const myJoinOrder = computed(() => {
    const me = localPlayers.value.find(p => isCurrentPlayer(p));
    return me ? me.join_order : null;
});

/**
 * Whether it is this client's turn to roll.
 *
 * @returns {boolean}
 */
const isMyTurn = computed(
    () => myJoinOrder.value !== null && currentTurnJoinOrder.value === myJoinOrder.value,
);

/**
 * Server-authoritative face values for the dice display, updated after each roll.
 * Null until the first roll. Passed to DiceRoller so it can snap to the correct
 * values after the local animation ends.
 */
const currentDie1 = ref(null);
const currentDie2 = ref(null);

/**
 * Monotonic counter incremented each time a DiceRolled WebSocket event arrives
 * from a remote player. Passed to DiceRoller as externalTrigger so every board
 * plays the shake animation in real-time when another player rolls.
 */
const externalRollTrigger = ref(0);

/**
 * Players assigned to the left (or portrait-top) panel.
 *
 * Logic: Filters localPlayers whose join_order is odd (1, 3, 5, 7...). The
 * creator always has join_order 1 so they always appear here. Players are
 * already ordered by join_order from the API so no extra sort is needed.
 *
 * @returns {Array}
 */
const leftPanelPlayers = computed(
    () => localPlayers.value.filter(p => p.join_order % 2 !== 0),
);

/**
 * Players assigned to the right (or portrait-bottom) panel.
 *
 * Logic: Filters localPlayers whose join_order is even (2, 4, 6, 8...).
 *
 * @returns {Array}
 */
const rightPanelPlayers = computed(
    () => localPlayers.value.filter(p => p.join_order % 2 === 0),
);

/**
 * Determine whether a given player object represents the current viewer.
 *
 * Logic: For authenticated players the comparison is made against currentUserId
 * (user_id field). For guests the comparison is made against currentInvitationId
 * (invitation_id field). Returns false when neither identifier matches so the
 * capital section stays hidden for all other players' cards.
 *
 * @param {Object} player - A player entry from localPlayers.
 * @returns {boolean}
 */
function isCurrentPlayer(player) {
    if (props.currentUserId !== null && player.user_id !== null) {
        return player.user_id === props.currentUserId;
    }
    if (props.currentInvitationId !== null && player.invitation_id !== null) {
        return player.invitation_id === props.currentInvitationId;
    }
    return false;
}

// ── Real-time player join subscription ────────────────────────────────────

/**
 * Subscribe to the public game channel and update localPlayers when a new
 * player joins.
 *
 * Logic: On mount, subscribes to `game.{gameId}` via window.Echo and listens
 * for the PlayerJoined event. When the event arrives the full players array
 * from the payload replaces localPlayers, immediately updating all panels and
 * board tokens reactively. On unmount the channel is left so the WS connection
 * is not leaked. Echo is only used when window.Echo exists (guards against
 * SSR or test environments where it may be absent).
 */
onMounted(() => {
    if (typeof window.Echo === 'undefined') return;

    window.Echo
        .channel(`game.${props.game.id}`)
        .listen('PlayerJoined', (event) => {
            if (Array.isArray(event.players)) {
                localPlayers.value = event.players;
            }
            if (Array.isArray(event.pending_invitations)) {
                localPendingInvitations.value = event.pending_invitations;
            }
        })
        .listen('DiceRolled', (event) => {
            currentDie1.value            = event.die1;
            currentDie2.value            = event.die2;
            currentTurnJoinOrder.value   = event.current_turn_join_order;
            externalRollTrigger.value++;
        });
});

onUnmounted(() => {
    if (typeof window.Echo === 'undefined') return;

    window.Echo.leaveChannel(`game.${props.game.id}`);
});

// ── Dice roll ──────────────────────────────────────────────────────────────

/**
 * Handle the roll-requested event emitted by DiceRoller.
 *
 * Logic: Calls the appropriate roll endpoint — the authenticated owner endpoint
 * (/api/games/{id}/roll) or the guest endpoint (/api/join/{token}/roll) — and
 * updates currentDie1, currentDie2, and currentTurnJoinOrder from the server
 * response. These refs are passed to DiceRoller as displayDie1/displayDie2 so
 * the dice snap to the authoritative values after the animation ends. Other
 * connected clients receive the same update via the DiceRolled broadcast event.
 * On failure, logs the error so the animation still settles gracefully on random
 * values.
 *
 * @returns {Promise<void>}
 */
async function handleRollRequested() {
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/roll`
            : `/api/games/${props.game.id}/roll`;
        const res = await window.axios.post(url);
        currentDie1.value          = res.data.die1;
        currentDie2.value          = res.data.die2;
        currentTurnJoinOrder.value = res.data.current_turn_join_order;
    } catch (err) {
        console.error('Failed to roll dice', err);
    }
}

// ── Card draw state ────────────────────────────────────────────────────────
/** Whether a draw API call is currently in flight. */
const isDrawing = ref(false);

/** The card returned by the most recent draw call. */
const drawnCard = ref(null);

/** Which deck was drawn ('chance' | 'community'). */
const drawnCardType = ref('chance');

/** Controls the CardRevealModal visibility. */
const showCardModal = ref(false);

/**
 * Draw the next Chance card for this game.
 *
 * Logic: Guards against concurrent calls with `isDrawing`. When invitationToken
 * is present the guest endpoint is used; otherwise the authenticated owner
 * endpoint is called. Stores the returned card and opens the reveal modal. On
 * failure the error is logged and isDrawing is reset so the deck remains clickable.
 *
 * @returns {Promise<void>}
 */
async function drawChanceCard() {
    if (isDrawing.value) return;
    isDrawing.value = true;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/chance/draw`
            : `/api/games/${props.game.id}/chance/draw`;
        const res = await window.axios.post(url);
        drawnCard.value     = res.data.card;
        drawnCardType.value = 'chance';
        showCardModal.value = true;
    } catch (err) {
        console.error('Failed to draw Chance card', err);
    } finally {
        isDrawing.value = false;
    }
}

/**
 * Draw the next Community Chest card for this game.
 *
 * Logic: Guards against concurrent calls with `isDrawing`. When invitationToken
 * is present the guest endpoint is used; otherwise the authenticated owner
 * endpoint is called. Stores the returned card and opens the reveal modal. On
 * failure the error is logged and isDrawing is reset so the deck remains clickable.
 *
 * @returns {Promise<void>}
 */
async function drawCommunityChestCard() {
    if (isDrawing.value) return;
    isDrawing.value = true;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/community/draw`
            : `/api/games/${props.game.id}/community/draw`;
        const res = await window.axios.post(url);
        drawnCard.value     = res.data.card;
        drawnCardType.value = 'community';
        showCardModal.value = true;
    } catch (err) {
        console.error('Failed to draw Community Chest card', err);
    } finally {
        isDrawing.value = false;
    }
}

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
 * Map of board-square key ('col-row') to the array of players standing there.
 *
 * Logic: Iterates localPlayers and groups each player by its square_index
 * (defaulting to 0 = GO when the property is absent, which is the case at game
 * creation). The BOARD_SQUARES entry at that index supplies the col/row key used
 * to look up the correct BoardSquare cell in the template.
 *
 * @returns {Record<string, Array>}
 */
const squarePlayers = computed(() => {
    const map = {};
    for (const player of localPlayers.value) {
        const idx = player.square_index ?? 0;
        const sq = BOARD_SQUARES[idx];
        if (!sq) continue;
        const key = `${sq.col}-${sq.row}`;
        if (!map[key]) map[key] = [];
        map[key].push(player);
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

        <!-- Board area – flex-col on portrait, flex-row on landscape / lg+ -->
        <div class="flex-1 flex flex-col landscape:flex-row items-center landscape:items-center w-full min-h-0 p-1 sm:p-2 lg:p-4 gap-1 lg:gap-2">

            <!-- Left panel: odd join_order players (creator + slots 3, 5, 7) -->
            <!-- Portrait: above the board. Landscape/desktop: left column. -->
            <div
                class="player-panel flex flex-col items-center py-2 px-2 gap-2 landscape:order-first overflow-y-auto"
                aria-label="Left player panel"
            >
                <PlayerHandCard
                    v-for="player in leftPanelPlayers"
                    :key="player.join_order"
                    :player="player"
                    :is-current-player="isCurrentPlayer(player)"
                />
            </div>

            <!-- Board grid – square, centered, sizing via scoped CSS per orientation -->
            <div
                class="board-grid shrink-0 self-center"
                style="
                    display: grid;
                    grid-template-columns: 1.1fr repeat(9, 1fr) 1.1fr;
                    grid-template-rows:    1.1fr repeat(9, 1fr) 1.1fr;
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
                                :player-tokens="squarePlayers[`${col}-${row}`] ?? []"
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
                                <!-- Dice roller – top-right corner of the centre panel -->
                                <div
                                    class="absolute z-20"
                                    style="top: 1.5cqw; right: 1.5cqw;"
                                    aria-label="Dice roller area"
                                    data-testid="dice-roller-area"
                                >
                                    <DiceRoller
                                        :is-my-turn="isMyTurn"
                                        :display-die1="currentDie1"
                                        :display-die2="currentDie2"
                                        :external-trigger="externalRollTrigger"
                                        @roll-requested="handleRollRequested"
                                    />
                                </div>

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
                                        <button
                                            type="button"
                                            class="rounded border-2 border-gray-700 bg-amber-100 flex items-center justify-center shadow transition-opacity active:scale-95 overflow-hidden"
                                            :class="isDrawing ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-80 cursor-pointer'"
                                            style="width: clamp(0.8rem, 11cqw, 4rem); height: clamp(1.2rem, 15cqw, 5.5rem);"
                                            :disabled="isDrawing"
                                            aria-label="Draw Community Chest card"
                                            data-testid="community-deck"
                                            @click="drawCommunityChestCard"
                                        >
                                            <div class="flex flex-col items-center px-1" style="gap: 0.7cqw;">
                                                <span class="text-amber-700 font-black leading-none" style="font-size: clamp(0.35rem, 3.5cqw, 1.1rem);">🏛</span>
                                                <span class="text-amber-800 font-bold text-center leading-tight" style="font-size: clamp(0.18rem, 1.5cqw, 0.6rem);">COMMUNITY<br>CHEST</span>
                                            </div>
                                        </button>
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
                                        <button
                                            type="button"
                                            class="rounded border-2 border-gray-700 bg-orange-50 flex items-center justify-center shadow transition-opacity active:scale-95 overflow-hidden"
                                            :class="isDrawing ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-80 cursor-pointer'"
                                            style="width: clamp(0.8rem, 11cqw, 4rem); height: clamp(1.2rem, 15cqw, 5.5rem);"
                                            :disabled="isDrawing"
                                            aria-label="Draw Chance card"
                                            data-testid="chance-deck"
                                            @click="drawChanceCard"
                                        >
                                            <div class="flex flex-col items-center px-1" style="gap: 0.7cqw;">
                                                <span class="text-orange-500 font-black leading-none" style="font-size: clamp(0.4rem, 3.5cqw, 1.1rem);">?</span>
                                                <span class="text-orange-700 font-bold text-center leading-tight" style="font-size: clamp(0.18rem, 1.5cqw, 0.6rem);">CHANCE</span>
                                            </div>
                                        </button>
                                    </div>
                                </div>

                                <!-- Subtitle -->
                                <span
                                    class="text-[#1a7a2e] font-semibold tracking-wide uppercase"
                                    style="font-size: clamp(0.18rem, 2.5cqw, 0.75rem);"
                                >The Fast-Dealing Property Trading Game</span>

                                <!-- Pending invitations list -->
                                <div
                                    class="w-full px-1"
                                    style="font-size: clamp(0.15rem, 1.6cqw, 0.6rem);"
                                >
                                    <PendingInvitationsList
                                        :pending-invitations="localPendingInvitations"
                                    />
                                </div>
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

            <!-- Right panel: even join_order players (slots 2, 4, 6, 8) -->
            <!-- Portrait: below the board. Landscape/desktop: right column. -->
            <div
                class="player-panel flex flex-col items-center py-2 px-2 gap-2 order-last overflow-y-auto"
                aria-label="Right player panel"
            >
                <PlayerHandCard
                    v-for="player in rightPanelPlayers"
                    :key="player.join_order"
                    :player="player"
                    :is-current-player="isCurrentPlayer(player)"
                />
            </div>

        </div>
    </div>

    <!-- Card reveal animation overlay -->
    <CardRevealModal
        :card="drawnCard"
        :type="drawnCardType"
        :visible="showCardModal"
        @close="showCardModal = false"
    />
</template>

<style scoped>
/* Portrait / default: board constrained so spacers above and below always have visible height */
.board-grid {
    width: min(94vw, 74vh);
    height: min(94vw, 74vh);
}

/*
 * Portrait player panel: sits above (and mirrored below) the board.
 * Both width and height are 1/4 of the board size.
 */
.player-panel {
    width: calc(min(94vw, 74vh) / 4);
    height: calc(min(94vw, 74vh) / 4);
    flex: none;
}

/* Mobile landscape: board is height-constrained, side panels get the remaining width */
@media (orientation: landscape) and (max-width: 1023px) {
    .board-grid {
        width: min(86vh, 52vw);
        height: min(86vh, 52vw);
    }

    /*
     * Landscape side panel: sits left/right of the board.
     * Both width and height are 1/4 of the board size.
     */
    .player-panel {
        width: calc(min(86vh, 52vw) / 4);
        height: calc(min(86vh, 52vw) / 4);
    }
}

/* Desktop lg+: maximize the board while accounting for the header (~2.5rem) and
   vertical padding (lg:p-4 = 2rem top+bottom) so the board never overflows the
   viewport and the corner-row prices are never clipped. */
@media (min-width: 1024px) {
    .board-grid {
        width: min(calc(100vw - 2rem), calc(100vh - 5rem));
        height: min(calc(100vw - 2rem), calc(100vh - 5rem));
    }

    .player-panel {
        width: calc(min(calc(100vw - 2rem), calc(100vh - 5rem)) / 4);
        height: calc(min(calc(100vw - 2rem), calc(100vh - 5rem)) / 4);
    }
}
</style>
