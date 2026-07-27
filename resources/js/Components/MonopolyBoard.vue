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
import AvailableOperationsDialog from '@/Components/AvailableOperationsDialog.vue';
import BuildOperation from '@/Components/RequestMenu/BuildOperation.vue';
import BoardSquare from '@/Components/BoardSquare.vue';
import CardDrawnNotification from '@/Components/CardDrawnNotification.vue';
import CardRevealModal from '@/Components/CardRevealModal.vue';
import CardPickerModal from '@/Components/CardPickerModal.vue';
import DiceRoller from '@/Components/DiceRoller.vue';
import PendingInvitationsList from '@/Components/PendingInvitationsList.vue';
import PlayerHandCard from '@/Components/PlayerHandCard.vue';
import MortgageOptionsDialog from '@/Components/MortgageOptionsDialog.vue';
import MortgagedPropertyDialog from '@/Components/MortgagedPropertyDialog.vue';
import PropertyPurchasedNotificationDialog from '@/Components/PropertyPurchasedNotificationDialog.vue';
import RentNotificationDialog from '@/Components/RentNotificationDialog.vue';
import SquareActionModal from '@/Components/SquareActionModal.vue';
import UnmortgageCapitalShortfallDialog from '@/Components/UnmortgageCapitalShortfallDialog.vue';

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
    /**
     * Whether QA/debug-only board controls are enabled.
     */
    debugMode: {
        type: Boolean,
        default: false,
    },
});

// Reactive local copy of the players array.
//
// Seeded from props.players on init; updated in real time when a PlayerJoined
// WebSocket event arrives. All derived computeds read from this ref so the
// panels and board tokens update without any page reload.
// Keep a separate reactive map of previous capitals so transient debug values
// survive prop refreshes or incoming player arrays that lack the field.
const previousCapitals = ref({});
const localPlayers = ref(props.players.map(player => normalizePlayerForBoard({
    ...player,
    previous_capital: previousCapitals.value[player.join_order] ?? player.previous_capital ?? null,
}))); 

/**
 * Reactive local copy of the pending invitations list.
 *
 * Seeded from props.pendingInvitations on init; updated in real time when a
 * PlayerJoined WebSocket event arrives so the waiting-room list shrinks as
 * guests join without any page reload.
 */
const localPendingInvitations = ref([...props.pendingInvitations]);
const reinviteRequestInvitationIds = ref([]);

/**
 * Keep localPlayers in sync when Inertia refreshes the page props (e.g. hard
 * refresh or back-navigation). Merge incoming data with existing local state while
 * preserving updates from real-time broadcasts (CardDrawn, RentPaid, TokenMoved, etc.)
 * so that real-time updates are not lost when the parent component refreshes data.
 *
 * Logic: For each incoming player, find the matching local player by join_order.
 * If found, merge incoming fields while preserving capital, square_index, and
 * isInJail
 * (which reflect the most recent broadcast updates). Only accept incoming values
 * for new players. This ensures stale prop data does not overwrite real-time changes.
 */
watch(
    () => props.players,
    (incoming) => {
        if (incoming.length >= localPlayers.value.length) {
            // Merge incoming data with existing local state, preserving real-time updates.
            const merged = incoming.map((incomingPlayer) => {
                const existing = localPlayers.value.find(
                    (p) => p.join_order === incomingPlayer.join_order,
                );
                if (existing) {
                    // Player exists locally — merge, but preserve capital,
                    // square_index, and isInJail
                    // since they were updated by real-time broadcasts and are more current
                    // than the incoming props from a potentially stale HTTP response.
                    const mergedProperties = mergePlayerProperties(
                        existing.properties ?? [],
                        incomingPlayer.properties ?? [],
                    );

                    return normalizePlayerForBoard({
                        ...incomingPlayer,
                        // Preserve real-time derived fields from the existing
                        // local player so a prop refresh does not clear them.
                        capital: existing.capital,
                        square_index: existing.square_index,
                        isInJail: existing.isInJail,
                        properties: mergedProperties,
                        previous_capital: existing.previous_capital ?? incomingPlayer.previous_capital ?? null,
                    });
                }
                // New player — accept all incoming data but preserve any
                // previously-known previous_capital from the persistent map.
                return normalizePlayerForBoard({
                    ...incomingPlayer,
                    previous_capital: previousCapitals.value[incomingPlayer.join_order] ?? incomingPlayer.previous_capital ?? null,
                });
            });
            localPlayers.value = merged;
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

// Reactive bank inventory counts (seeded from props.game and updated via broadcasts)
const bankHousesAvailable = ref(props.game.bank_houses_available ?? 0);
const bankHotelsAvailable = ref(props.game.bank_hotels_available ?? 0);

watch(
    () => props.game,
    (g) => {
        bankHousesAvailable.value = g?.bank_houses_available ?? 0;
        bankHotelsAvailable.value = g?.bank_hotels_available ?? 0;
    },
    { immediate: true, deep: true },
);

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

// Ensure the active player's `previous_capital` is seeded from the
// persistent `previousCapitals` map when the viewer becomes the current
// turn player. Observers already receive this seeding via broadcast merges;
// mirror that behaviour so the player-in-turn sees the same debug value.
// NOTE: watcher moved below `isMyTurn` declaration to avoid TDZ access.

/**
 * Whether the current viewer is the authenticated creator of this game.
 *
 * @returns {boolean}
 */
const isCreatorViewingBoard = computed(() => {
    return props.currentUserId !== null && props.currentUserId === props.game.user_id;
});

/**
 * Whether it is this client's turn to roll.
 *
 * @returns {boolean}
 */
const isMyTurn = computed(
    () => myJoinOrder.value !== null && currentTurnJoinOrder.value === myJoinOrder.value,
);

// Ensure the active player's `previous_capital` is seeded from the
// persistent `previousCapitals` map when the viewer becomes the current
// turn player. Observers already receive this seeding via broadcast merges;
// mirror that behaviour so the player-in-turn sees the same debug value.
watch(
    () => isMyTurn.value,
    (isTurn) => {
        if (!isTurn || myJoinOrder.value === null) return;
        const target = Number(myJoinOrder.value);
        localPlayers.value = localPlayers.value.map((p) =>
            Number(p.join_order) === target
                ? { ...p, previous_capital: previousCapitals.value[target] ?? p.previous_capital ?? null }
                : p,
        );
    },
);

/**
 * Whether debug square click-to-move is available for this board.
 *
 * @returns {boolean}
 */
const canUseDebugClickMove = computed(() =>
    props.debugMode
    && isMyTurn.value
    && myJoinOrder.value !== null
    && !hasDebugMovedThisTurn.value
    && !debugMoveInFlight.value
    && !turnAdvanceInFlight.value
    && !hasPendingTurnResolution(),
);

/**
 * Token data for the player whose turn is currently active.
 *
 * Logic: Resolves the active player by current turn join_order, then returns
 * the token image URL and a readable token name for DiceRoller's waiting label.
 *
 * @returns {{ imageUrl: string|null, tokenName: string }|null}
 */
const activeTurnPlayerToken = computed(() => {
    const activePlayer = localPlayers.value.find(
        player => player.join_order === currentTurnJoinOrder.value,
    );

    if (!activePlayer) {
        return null;
    }

    return {
        imageUrl: activePlayer.icon?.image_url ?? null,
        tokenName: activePlayer.icon?.name ?? 'Active player',
    };
});

/**
 * Token data for the current viewer's player card.
 *
 * Logic: Resolves the local player by myJoinOrder and returns icon metadata
 * so personal notifications (e.g. GO bonus) can show the player's token.
 *
 * @returns {{ imageUrl: string|null, tokenName: string }|null}
 */
const myPlayerToken = computed(() => {
    if (myJoinOrder.value === null) {
        return null;
    }

    const me = localPlayers.value.find(
        player => player.join_order === myJoinOrder.value,
    );

    if (!me) {
        return null;
    }

    return {
        imageUrl: me.icon?.image_url ?? null,
        tokenName: me.icon?.name ?? 'Player token',
    };
});

/**
 * Server-authoritative face values for the dice display, updated after each roll.
 * Seeded from props.game.last_die1 / last_die2 so a page refresh restores the
 * dice to the values from the most recent roll. Null when no roll has occurred
 * in the current turn. Passed to DiceRoller so it can snap to the correct
 * values after the local animation ends.
 */
const currentDie1 = ref(props.game.last_die1 ?? null);
const currentDie2 = ref(props.game.last_die2 ?? null);

/**
 * Whether the active player has already rolled this turn, seeded from the
 * server-authoritative turn_phase so a page refresh keeps the Roll button
 * hidden until the turn advances away from this player.
 *
 * Logic: True when props.game.turn_phase is 'done' AND it is currently this
 * client's turn. Computed once at setup; kept in sync reactively thereafter
 * via the DiceRoller's own hasRolled state and the isMyTurn watch.
 *
 * @type {boolean}
 */
const initialHasRolled = props.game.turn_phase === 'done'
    && myJoinOrder.value !== null
    && props.game.current_turn_join_order === myJoinOrder.value;

/**
 * Monotonic counter incremented each time a DiceRolled WebSocket event arrives
 * from a remote player. Passed to DiceRoller as externalTrigger so every board
 * plays the shake animation in real-time when another player rolls.
 */
const externalRollTrigger = ref(0);

/**
 * Join order of the local player currently highlighted from hovering the dice area.
 *
 * Null when the dice area is not hovered. Used to reuse the existing token
 * highlight path so the player's current position is outlined while the dice
 * roller is under the pointer.
 *
 * @type {import('vue').Ref<number|null>}
 */
const hoveredDiceJoinOrder = ref(null);

/**
 * Buffered local move data received from the roll API while the dice shake
 * animation is still in progress. Null when no move is pending. Consumed by
 * handleRollSettled once the dice animation completes so the token only starts
 * moving after the dice have fully settled on screen.
 *
 * @type {import('vue').Ref<{joinOrder: number, fromIdx: number, toIdx: number, jailAnimationSource?: string|null, jailState?: boolean|null}|null>}
 */
const pendingLocalMove = ref(null);

/**
 * True once the current local dice animation has finished (roll-settled fired).
 * Used to detect the race where the API responds before the 700 ms animation
 * completes, so handleRollRequested knows to animate immediately instead of
 * buffering. Reset to false at the start of each new roll.
 *
 * @type {import('vue').Ref<boolean>}
 */
const localDiceSettled = ref(false);

/**
 * Monotonic signal used to reset DiceRoller's local hasRolled state.
 * Incremented when turn advancement is rejected and the player must reroll.
 *
 * @type {import('vue').Ref<number>}
 */
const resetHasRolledSignal = ref(0);
const awaitingExtraRoll = ref(false);

/**
 * Whether a debug click-to-move request is currently in flight.
 *
 * @type {import('vue').Ref<boolean>}
 */
const debugMoveInFlight = ref(false);

/**
 * Whether the current player already used debug click-to-move this turn.
 *
 * @type {import('vue').Ref<boolean>}
 */
const hasDebugMovedThisTurn = ref(false);

/**
 * Displayed board position (square index 0–39) for each player, keyed by join_order.
 *
 * This is the single authoritative source for where each token is rendered on
 * the board. It is seeded from props.players on init and updated step-by-step
 * during the movement animation so the token visually hops one square at a time.
 *
 * @type {import('vue').Ref<Record<number, number>>}
 */
const tokenPositions = ref(
    Object.fromEntries(props.players.map(p => [p.join_order, p.square_index ?? 0])),
);

/**
 * The join_order of the player whose token is currently being animated.
 *
 * Null when no animation is in progress. Used by squarePlayers to enrich each
 * player object with isAnimating=true so BoardSquare can apply the bounce/ring
 * class to the moving token.
 *
 * @type {import('vue').Ref<number|null>}
 */
const movingJoinOrder = ref(null);

/**
 * Join order currently showing the temporary police escort animation.
 *
 * Null when no escort animation is active.
 *
 * @type {import('vue').Ref<number|null>}
 */
const policeEscortJoinOrder = ref(null);

/**
 * The join_order of the player whose hand card is currently expanded.
 *
 * Null when no card is expanded. Used to highlight that player's token on the
 * board so card focus and token position are visually linked.
 *
 * @type {import('vue').Ref<number|null>}
 */
const expandedCardJoinOrder = ref(null);

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

const PROPERTY_COLOR_GROUP_COUNTS = {
    '#955436': 2,
    '#aae0fa': 3,
    '#d93a96': 3,
    '#f7941d': 3,
    '#ed1b24': 3,
    '#fef200': 3,
    '#1fb25a': 3,
    '#0072bb': 2,
};

const PROPERTY_COLOR_GROUP_SQUARE_INDEXES = {
    '#955436': [1, 3],
    '#aae0fa': [6, 8, 9],
    '#d93a96': [11, 13, 14],
    '#f7941d': [16, 18, 19],
    '#ed1b24': [21, 23, 24],
    '#fef200': [26, 27, 29],
    '#1fb25a': [31, 32, 34],
    '#0072bb': [37, 39],
};

const currentPlayerForOperations = computed(
    () => localPlayers.value.find(player => isCurrentPlayer(player)) ?? null,
);

const hasCompleteColorGroup = computed(() => {
    const playerProperties = Array.isArray(currentPlayerForOperations.value?.properties)
        ? currentPlayerForOperations.value.properties
        : [];

    const propertiesByColor = playerProperties.reduce((accumulator, property) => {
        const color = String(property?.color ?? '').toLowerCase();
        if (!color || !PROPERTY_COLOR_GROUP_COUNTS[color]) {
            return accumulator;
        }

        if (!Array.isArray(accumulator[color])) {
            accumulator[color] = [];
        }

        accumulator[color].push(property);
        return accumulator;
    }, {});

    return Object.entries(PROPERTY_COLOR_GROUP_COUNTS).some(([color, requiredCount]) => {
        const colorGroup = propertiesByColor[color] ?? [];

        return colorGroup.length >= Number(requiredCount)
            && colorGroup.every(property => property?.is_mortgaged !== true);
    });
});

const hasGetOutOfJailCard = computed(() => {
    const player = currentPlayerForOperations.value;
    if (!player) {
        return false;
    }

    const chanceCards = Array.isArray(player.chance_cards) ? player.chance_cards : [];
    const communityCards = Array.isArray(player.community_chest_cards) ? player.community_chest_cards : [];
    const heldCards = [...chanceCards, ...communityCards];

    return heldCards.some(card => String(card?.action ?? '') === 'get_out_of_jail_free');
});

const currentPlayerIsInJail = computed(
    () => Boolean(currentPlayerForOperations.value?.isInJail),
);

const currentPlayerHasPaidJailRelease = computed(
    () => Boolean(currentPlayerForOperations.value?.has_paid_jail_release),
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

/**
 * Track which player card is currently expanded.
 *
 * Logic: Stores the emitting player's join_order when expanded=true and clears
 * it when expanded=false. A collapse event from any non-highlighted card is
 * ignored so another expanded card's highlight remains intact.
 *
 * @param {{joinOrder: number|string|null|undefined, expanded: boolean}} payload
 * @returns {void}
 */
function handlePlayerCardExpandedChange(payload) {
    if (!payload) {
        return;
    }

    const joinOrder = Number(payload.joinOrder);
    if (!Number.isFinite(joinOrder)) {
        return;
    }

    if (payload.expanded) {
        expandedCardJoinOrder.value = joinOrder;
        return;
    }

    if (expandedCardJoinOrder.value === joinOrder) {
        expandedCardJoinOrder.value = null;
    }
}

/**
 * Determine whether a player card should expose the creator-only re-invite action.
 *
 * @param {Object} player
 * @return {boolean}
 * Logic: Only the authenticated creator can re-invite, never on the creator's
 * own card, and only for joined players that originated from an invitation.
 */
function canShowReinviteButton(player) {
    return isCreatorViewingBoard.value
        && player.is_creator !== true
        && player.invitation_id !== null;
}

/**
 * Determine whether a re-invite request is in flight for a player card.
 *
 * @param {Object} player
 * @return {boolean}
 */
function isReinvitingPlayer(player) {
    return player.invitation_id !== null
        && reinviteRequestInvitationIds.value.includes(player.invitation_id);
}

/**
 * Send a new invitation email for a previously joined invited player.
 *
 * @param {Object} player
 * @return {Promise<void>}
 * Logic: Posts to the authenticated re-invite endpoint keyed by the player's
 * original invitation ID, guards duplicate clicks per card, and surfaces any
 * API failure in the existing board-level error dialog.
 */
async function handleReinvitePlayer(player) {
    if (!canShowReinviteButton(player) || player.invitation_id === null) {
        return;
    }

    if (reinviteRequestInvitationIds.value.includes(player.invitation_id)) {
        return;
    }

    reinviteRequestInvitationIds.value = [
        ...reinviteRequestInvitationIds.value,
        player.invitation_id,
    ];

    // Hide the reveal modal now that we've applied the effect locally so the
    // player's `previous_capital` remains visible through the update and
    // only then the modal is dismissed.
    showCardModal.value = false;

    try {
        await window.axios.post(
            `/api/games/${props.game.id}/invitations/${player.invitation_id}/resend`,
        );
    } catch (err) {
        errorMessage.value = err.response?.data?.message ?? 'Failed to send invitation.';
        showErrorDialog.value = true;
    } finally {
        reinviteRequestInvitationIds.value = reinviteRequestInvitationIds.value.filter(
            (invitationId) => invitationId !== player.invitation_id,
        );
    }
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
                const normalizedPlayers = event.players.map((player) => {
                    const existing = localPlayers.value.find(p => p.join_order === player.join_order);
                    return normalizePlayerForBoard({
                        ...player,
                        previous_capital: existing?.previous_capital ?? previousCapitals.value[player.join_order] ?? player.previous_capital ?? null,
                    });
                });

                localPlayers.value = normalizedPlayers;
                // Seed token positions for any newly joined player that does not
                // already have an entry (preserves in-flight animation positions).
                // Use direct property mutation to avoid replacing the entire reactive
                // proxy, which would drop computed dependency tracking mid-animation.
                for (const p of normalizedPlayers) {
                    if (tokenPositions.value[p.join_order] === undefined) {
                        tokenPositions.value[p.join_order] = p.square_index ?? 0;
                    }
                }
            }
            if (Array.isArray(event.pending_invitations)) {
                localPendingInvitations.value = event.pending_invitations;
            }
        })
        .listen('DiceRolled', (event) => {
            currentDie1.value          = event.die1;
            currentDie2.value          = event.die2;
            // Turn does not advance on roll — current_turn_join_order stays the
            // same until the active player clicks Done.
            externalRollTrigger.value++;
            // Remote token animation is now driven by the TokenMoved event which
            // fires after the rolling player's local animation completes. No
            // animation is triggered here on observer boards.
        })
        .listen('TokenMoved', (event) => {
            // Animate the remote player's token to their final square when the
            // rolling player signals that their local animation has completed.
            // Skip our own token — handleRollRequested / handleRollSettled already
            // manages the local player's animation and issued this notification.
            const movingJoinOrderValue = event.join_order;
            const wasTokenPositionSeeded = tokenPositions.value[movingJoinOrderValue] !== undefined;
            // Ensure the key exists in tokenPositions before any animation or
            // escort logic runs. Using ?? 0 for fromIdx without writing back would
            // leave tokenPositions[joinOrder] === undefined, causing
            // policeEscortPosition to return null immediately (Number.isFinite(undefined)
            // is false) and suppress the escort icon for the entire first step.
            if (tokenPositions.value[movingJoinOrderValue] === undefined) {
                tokenPositions.value[movingJoinOrderValue] = 0;
            }
            const fromIdx = tokenPositions.value[movingJoinOrderValue];

            const eventJailState = typeof event?.isInJail === 'boolean'
                ? event.isInJail
                : null;

            if (movingJoinOrderValue !== undefined && eventJailState !== null) {
                setPlayerJailState(movingJoinOrderValue, eventJailState);
            }

            if (movingJoinOrderValue !== undefined && event.square_index !== undefined
                && movingJoinOrderValue !== myJoinOrder.value) {
                const jailAnimationSource = resolveJailAnimationSource(event);
                const shouldShowPoliceEscort = Number(event.square_index) === 10
                    && !(event.backward ?? false)
                    && (eventJailState === true || jailAnimationSource !== null);
                const policeEscortStartSquareIndex = jailAnimationSource === 'square' && wasTokenPositionSeeded
                    ? 30
                    : null;
                animateTokenMovement(
                    movingJoinOrderValue,
                    fromIdx,
                    event.square_index,
                    200,
                    event.backward ?? false,
                    {
                        showPoliceEscort: shouldShowPoliceEscort,
                        policeEscortStartSquareIndex,
                    },
                );
            }
        })
        .listen('TurnAdvanced', (event) => {
            currentTurnJoinOrder.value = event.current_turn_join_order;
        })
        .listen('RentPaid', (event) => {
            // Update both players' capitals reactively on every board.
            if (event.payer_join_order !== undefined && event.payer_capital !== undefined) {
                updatePlayerCapital(event.payer_join_order, event.payer_capital);
            }
            if (event.owner_join_order !== undefined && event.owner_capital !== undefined) {
                updatePlayerCapital(event.owner_join_order, event.owner_capital);
            }
            // Show the notification dialog to the owner and observers.
            // The payer already saw the confirmation from the API response in
            // handlePayRent, so skip the dialog for them here.
            if (event.payer_join_order !== myJoinOrder.value) {
                rentNotificationData.value = {
                    payerName:  event.payer_name  ?? 'Player',
                    payerIcon:  event.payer_icon
                        ?? getPlayerIconByJoinOrder(event.payer_join_order)
                        ?? null,
                    ownerName:  event.owner_name  ?? 'Player',
                    ownerIcon:  event.owner_icon
                        ?? getPlayerIconByJoinOrder(event.owner_join_order)
                        ?? null,
                    rentAmount: event.rent_amount ?? 0,
                    squareName: event.square_name ?? '',
                };
                rentNotificationFromPayerFlow.value = false;
                bringNotificationToFront(rentNotificationZIndex);
                showRentNotificationDialog.value = true;
            }
        })
        .listen('MortgagedPropertyNotified', (event) => {
            if (event.payer_join_order !== myJoinOrder.value) {
                mortgagedPropertyData.value = {
                    payerName:  event.payer_name  ?? 'Player',
                    payerIcon:  event.payer_icon
                        ?? getPlayerIconByJoinOrder(event.payer_join_order)
                        ?? null,
                    ownerName:  event.owner_name  ?? 'Player',
                    ownerIcon:  event.owner_icon
                        ?? getPlayerIconByJoinOrder(event.owner_join_order)
                        ?? null,
                    squareName: event.square_name ?? '',
                };
                mortgagedPropertyFromPayerFlow.value = false;
                bringNotificationToFront(mortgagedPropertyZIndex);
                showMortgagedPropertyDialog.value = true;
            }
        })
        .listen('PropertyPurchased', (event) => {
            if (event.buyer_join_order !== undefined && event.buyer_capital !== undefined) {
                updatePlayerCapital(event.buyer_join_order, event.buyer_capital);
            }

            if (event.buyer_join_order !== undefined && event.square_index !== undefined) {
                appendPropertyToPlayer(event.buyer_join_order, {
                    square_index: event.square_index,
                    name: event.square_name ?? squareNameByIndex(event.square_index),
                });
            }

            if (event.buyer_join_order !== myJoinOrder.value) {
                propertyPurchasedNotification.value = {
                    buyerName: event.buyer_name ?? 'Player',
                    buyerIcon: event.buyer_icon ?? null,
                    squareName: event.square_name ?? '',
                    purchasePrice: event.purchase_price ?? 0,
                };
                bringNotificationToFront(propertyPurchasedNotificationZIndex);
                showPropertyPurchasedNotification.value = true;
            }
        })
        .listen('PropertyBuilt', (event) => {
            // Apply building counts (houses / hotel) to the owner's property
            const ownerJoin = Number(event.owner_join_order);
            const squareIdx = Number(event.square_index);

            if (Number.isFinite(ownerJoin) && Number.isFinite(squareIdx)) {
                applyBuildingUpdate(ownerJoin, squareIdx, event.houses_count ?? null, event.has_hotel ?? null);
            }

            // Update owner capital if provided
            if (event.owner_capital !== undefined && event.owner_capital !== null) {
                updatePlayerCapital(ownerJoin, event.owner_capital);
            }

            // Update bank inventory counts if provided (reflect pending builds)
            if (event.bank_houses_available !== undefined && event.bank_houses_available !== null) {
                bankHousesAvailable.value = Number(event.bank_houses_available);
            }
            if (event.bank_hotels_available !== undefined && event.bank_hotels_available !== null) {
                bankHotelsAvailable.value = Number(event.bank_hotels_available);
            }
        })
        .listen('BuildAllocationFailed', (event) => {
            try {
                const ownerJoin = Number(event.owner_join_order);
                if (Number.isFinite(ownerJoin) && ownerJoin === myJoinOrder.value) {
                    const squares = Array.isArray(event.denied_squares) ? event.denied_squares.join(', ') : String(event.denied_squares);
                    // Simple UI feedback for owners whose pending builds were denied
                    alert((event.message ?? 'Pending builds could not be granted due to insufficient bank inventory.') + '\nSquares: ' + squares);
                }
            } catch (e) {
                console.error('Failed to handle BuildAllocationFailed event', e);
            }
        })
        .listen('CardDrawn', (event) => {
            const drawnByJoinOrder = Number(event.drawn_by_join_order);
            appendHeldCardToPlayer(drawnByJoinOrder, event.type, event.card);
            // Apply card-effect capital updates on every board so balances stay
            // in sync even before the drawing player dismisses the card modal.
            // The drawer will apply the same final values again on modal close,
            // which is safe because updates are absolute balances.
            if (event.card_effect) {
                const fx = event.card_effect;
                if (fx.new_capital != null) {
                    updatePlayerCapital(drawnByJoinOrder, fx.new_capital);
                }
                if (fx.other_player_capitals) {
                    for (const { join_order, capital } of fx.other_player_capitals) {
                        updatePlayerCapital(join_order, capital);
                    }
                }
            }
            // The drawing player already sees the full card via the HTTP roll
            // response (pendingSquareAction → showPendingSquareAction), so
            // skip the notification for their own board.
            // All other boards show a lightweight notification with the
            // drawer's name instead of the full card reveal modal.
            if (drawnByJoinOrder !== myJoinOrder.value) {
                const drawer = localPlayers.value.find(
                    (p) => Number(p.join_order) === drawnByJoinOrder,
                );
                cardDrawnNotification.value = {
                    playerName: event.drawn_by_name ?? 'Player',
                    playerIcon: drawer?.icon ?? null,
                    card:       event.card ?? null,
                    type:       event.type,
                };
                bringNotificationToFront(cardDrawnNotificationZIndex);
                showCardDrawnNotification.value = true;
            }
        })
        .listen('CardAccepted', (event) => {
            if (event?.payer?.join_order !== undefined && event?.payer?.capital !== undefined) {
                updatePlayerCapital(event.payer.join_order, event.payer.capital);
            }

            if (Array.isArray(event?.other_player_capitals)) {
                for (const { join_order, capital } of event.other_player_capitals) {
                    updatePlayerCapital(join_order, capital);
                }
            }

            // The drawing player dismissed their card reveal modal; auto-close
            // the observer notification on all other boards.
            // Observers who already dismissed manually are unaffected because
            // handleCardDrawnNotificationClose() is idempotent on a false/null state.
            handleCardDrawnNotificationClose();
        });
});

onMounted(() => {
    if (initialHasRolled) {
        void maybeAdvanceTurn();
    }
});

onUnmounted(() => {
    if (typeof window.Echo === 'undefined') return;

    window.Echo.leaveChannel(`game.${props.game.id}`);
});

// ── Dice roll ──────────────────────────────────────────────────────────────

/**
 * Animate a player's token moving step-by-step across the board.
 *
 * Logic: Advances the player's entry in tokenPositions one square at a time
 * from fromIdx toward toIdx. When backward=false (default) the token steps
 * forward (+1 mod 40), which covers dice rolls and all forward-movement cards.
 * When backward=true the token steps backward (-1 mod 40), which covers the
 * 'Go Back 3 Spaces' Chance card. totalSteps is always computed as the number
 * of steps in the chosen direction so the token never takes the long way around.
 * Sets movingJoinOrder while in progress so squarePlayers enriches the player
 * with isAnimating=true, which BoardSquare uses to apply the bounce/ring visual.
 * Returns a Promise that resolves when the token reaches toIdx.
 *
 * @param {number}  joinOrder      The join_order of the player whose token to move.
 * @param {number}  fromIdx        The square index to start from (0–39).
 * @param {number}  toIdx          The destination square index (0–39).
 * @param {number}  [stepMs=200]   Milliseconds per square step.
 * @param {boolean} [backward=false] When true, step backward instead of forward.
 * @returns {Promise<void>}
 */
function animateTokenMovement(joinOrder, fromIdx, toIdx, stepMs = 200, backward = false, options = {}) {
    const {
        showPoliceEscort = false,
        policeEscortStartSquareIndex = null,
    } = options ?? {};

    return new Promise((resolve) => {
        const totalSteps = backward
            ? ((fromIdx - toIdx) + 40) % 40
            : ((toIdx - fromIdx) + 40) % 40;

        if (totalSteps === 0) {
            tokenPositions.value[joinOrder] = toIdx;
            movingJoinOrder.value = null;
            if (policeEscortJoinOrder.value === joinOrder) {
                policeEscortJoinOrder.value = null;
            }
            resolve();
            return;
        }

        let stepsCompleted = 0;

        if (showPoliceEscort && policeEscortStartSquareIndex === null) {
            policeEscortJoinOrder.value = joinOrder;
        }

        const interval = setInterval(() => {
            const current = tokenPositions.value[joinOrder] ?? fromIdx;
            // Direct property mutation is more reliable in Vue 3 than replacing the
            // entire ref value — it mutates through the existing Proxy set trap so
            // all dependents (e.g. squarePlayers computed) are correctly notified.
            tokenPositions.value[joinOrder] = backward
                ? (current - 1 + 40) % 40
                : (current + 1) % 40;

            if (
                showPoliceEscort
                && policeEscortStartSquareIndex !== null
                && policeEscortJoinOrder.value !== joinOrder
                && tokenPositions.value[joinOrder] === policeEscortStartSquareIndex
            ) {
                policeEscortJoinOrder.value = joinOrder;
            }

            stepsCompleted++;

            if (stepsCompleted >= totalSteps) {
                clearInterval(interval);
                // Final snap: ensure the token is exactly at toIdx after the last
                // step regardless of any floating-point or rounding edge cases.
                tokenPositions.value[joinOrder] = toIdx;
                movingJoinOrder.value = null;
                if (policeEscortJoinOrder.value === joinOrder) {
                    policeEscortJoinOrder.value = null;
                }
                resolve();
            }
        }, stepMs);
    });
}

/**
 * Handle the roll-requested event emitted by DiceRoller.
 *
 * Logic: Calls the appropriate roll endpoint — the authenticated owner endpoint
 * (/api/games/{id}/roll) or the guest endpoint (/api/join/{token}/roll) — and
 * updates currentDie1 and currentDie2 from the server response. Then animates
 * the local player's token from their current position to the new square_index
 * returned by the server, moving one square at a time so the movement is visible.
 * The turn does NOT advance on roll; current_turn_join_order remains unchanged
 * until the player clicks Done. Other connected clients receive the dice values
 * and square_index via the DiceRolled broadcast event and animate accordingly.
 * On failure, surfaces the API error message to the player via a board-level
 * dialog so validation failures are visible and actionable.
 *
 * @returns {Promise<void>}
 */
async function handleRollRequested(payload = null) {
    localDiceSettled.value = false;
    awaitingExtraRoll.value = false;
    hasDebugMovedThisTurn.value = true;
    pendingLocalMove.value = null;
    pendingSquareAction.value = null;
    pendingPassedGo.value = false;
    pendingGoNewCapital.value = null;
    pendingCardEffect.value = null;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/roll`
            : `/api/games/${props.game.id}/roll`;
        const forcedDie1 = Number(payload?.forcedDie1);
        const forcedDie2 = Number(payload?.forcedDie2);
        const hasForcedDice = Number.isInteger(forcedDie1)
            && Number.isInteger(forcedDie2)
            && forcedDie1 >= 1
            && forcedDie1 <= 6
            && forcedDie2 >= 1
            && forcedDie2 <= 6;

        const requestBody = hasForcedDice
            ? {
                forced_die1: forcedDie1,
                forced_die2: forcedDie2,
            }
            : null;
        const res = requestBody
            ? await window.axios.post(url, requestBody)
            : await window.axios.post(url);
        currentTurnJoinOrder.value = Number(res.data.current_turn_join_order ?? currentTurnJoinOrder.value);
        currentDie1.value = res.data.die1;
        currentDie2.value = res.data.die2;
        const responseJailState = resolveJailState(res.data);
        const sentToJailBySquareAction = res.data.square_action?.type === 'go_to_jail';
        const sentToJailByState = responseJailState === true;
        const shouldAllowExtraRoll = res.data.can_roll_again === true
            && !sentToJailBySquareAction
            && !sentToJailByState;

        if (shouldAllowExtraRoll) {
            awaitingExtraRoll.value = true;
            resetHasRolledSignal.value += 1;
        } else {
            awaitingExtraRoll.value = false;
        }

        if (myJoinOrder.value !== null) {
            applyJailReleaseState(myJoinOrder.value, res.data);
            if (responseJailState !== null) {
                setPlayerJailState(myJoinOrder.value, responseJailState);
            }
        }

        // Buffer any square action returned by the server to show after animation.
        if (res.data.square_action) {
            pendingSquareAction.value = res.data.square_action;
        }

        // Buffer GO bonus info — the dialog will surface after the animation.
        if (res.data.passed_go) {
            pendingPassedGo.value = true;
            pendingGoNewCapital.value = res.data.new_capital ?? null;
        }

        if (myJoinOrder.value !== null && res.data.square_index !== undefined) {
            if (res.data.moved === false) {
                tokenPositions.value[myJoinOrder.value] = Number(res.data.square_index);
                await maybeAdvanceTurn();
                return;
            }

            const fromIdx = tokenPositions.value[myJoinOrder.value] ?? 0;
            const jailAnimationSource = resolveJailAnimationSourceFromAction(
                res.data.square_action?.type ?? null,
                {
                    responseCurrentTurnJoinOrder: Number(res.data.current_turn_join_order),
                    rollerJoinOrder: myJoinOrder.value,
                },
            );
            if (responseJailState === null) {
                syncPlayerJailStateAfterMove(myJoinOrder.value, res.data.square_action?.type ?? null);
            }
            const policeEscortStartSquareIndex = jailAnimationSource === 'square' && fromIdx !== 0
                ? 30
                : null;
            if (localDiceSettled.value) {
                // Dice finished before the API responded — notify other boards the
                // token is starting to move, then animate locally.
                await notifyTokenMoved(false, jailAnimationSource);
                await animateTokenMovement(
                    myJoinOrder.value,
                    fromIdx,
                    res.data.square_index,
                    200,
                    false,
                    {
                        showPoliceEscort: jailAnimationSource !== null,
                        policeEscortStartSquareIndex,
                    },
                );
                showPostMoveDialogs();
            } else {
                // Dice still shaking — buffer the move for when roll-settled fires.
                pendingLocalMove.value = {
                    joinOrder: myJoinOrder.value,
                    fromIdx,
                    toIdx: res.data.square_index,
                    jailAnimationSource,
                    jailState: responseJailState,
                };
            }
        }
        // current_turn_join_order is unchanged after rolling — only updated
        // when the player clicks Done (via the TurnAdvanced broadcast).
    } catch (err) {
        console.error('Failed to roll dice', err);
        const message = err.response?.data?.message ?? 'Failed to roll dice.';
        errorMessage.value = message;
        showErrorDialog.value = true;

        if (message === 'You must pay $50 to leave jail before rolling.') {
            resetHasRolledSignal.value += 1;
        }
    }
}

/**
 * Close the board-level API error dialog.
 *
 * @returns {void}
 */
function handleErrorDialogClose() {
    showErrorDialog.value = false;
    errorMessage.value = '';
}

/**
 * Handle selecting a board square in debug click-to-move mode.
 *
 * Logic: Validates that debug click mode is currently enabled, calls the
 * debug move endpoint with the clicked square index, then reuses the normal
 * movement animation and post-move dialog flow.
 *
 * @param {object|null|undefined} square
 * @returns {Promise<void>}
 */
async function handleDebugSquareMove(square) {
    if (!canUseDebugClickMove.value || !square || myJoinOrder.value === null) {
        return false;
    }

    const targetSquareIndex = BOARD_SQUARES.findIndex(s => s.name === square?.name);

    if (targetSquareIndex < 0) {
        return false;
    }

    const fromIdx = tokenPositions.value[myJoinOrder.value] ?? 0;

    if (fromIdx === targetSquareIndex) {
        return false;
    }

    debugMoveInFlight.value = true;
    localDiceSettled.value = false;
    pendingLocalMove.value = null;
    pendingSquareAction.value = null;
    pendingPassedGo.value = false;
    pendingGoNewCapital.value = null;
    pendingCardEffect.value = null;

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/debug/move`
            : `/api/games/${props.game.id}/debug/move`;
        const res = await window.axios.post(url, { target_square_index: targetSquareIndex });

        if (res.data.square_action) {
            pendingSquareAction.value = res.data.square_action;
        }

        if (res.data.passed_go) {
            pendingPassedGo.value = true;
            pendingGoNewCapital.value = res.data.new_capital ?? null;
        }

        hasDebugMovedThisTurn.value = true;
        if (myJoinOrder.value !== null && res.data.square_index !== undefined) {
            const jailAnimationSource = resolveJailAnimationSourceFromAction(res.data.square_action?.type ?? null);
            syncPlayerJailStateAfterMove(myJoinOrder.value, res.data.square_action?.type ?? null);
            const policeEscortStartSquareIndex = jailAnimationSource === 'square' && fromIdx !== 0
                ? 30
                : null;
            if (localDiceSettled.value) {
                await notifyTokenMoved(false, jailAnimationSource);
                await animateTokenMovement(
                    myJoinOrder.value,
                    fromIdx,
                    res.data.square_index,
                    200,
                    false,
                    {
                        showPoliceEscort: jailAnimationSource !== null,
                        policeEscortStartSquareIndex,
                    },
                );
                showPostMoveDialogs();
            } else {
                pendingLocalMove.value = {
                    joinOrder: myJoinOrder.value,
                    fromIdx,
                    toIdx: res.data.square_index,
                    jailAnimationSource,
                };
            }
        }
        return true;
    } catch (err) {
        console.error('Failed to move token by debug square click', err);
        return false;
    } finally {
        debugMoveInFlight.value = false;
    }
}

/**
 * Handle the roll-settled event emitted by DiceRoller.
 *
 * Logic: Called once the 700 ms dice shake animation has fully completed for
 * the local player's roll. Sets localDiceSettled=true so that a late-arriving
 * API response in handleRollRequested knows to animate immediately. Consumes

 * pendingLocalMove when present (the local player's buffered move), kicks off
 * the token animation, and then notifies other boards via the token-moved
 * endpoint once the animation resolves. Remote player tokens are animated when
 * the TokenMoved WebSocket event arrives (dispatched by the rolling player's
 * board after this function completes). After animation, surfaces any buffered
 * square action as a modal dialog.
 *
 * @returns {Promise<void>}
 */
async function handleRollSettled() {
    localDiceSettled.value = true;

    if (pendingLocalMove.value !== null) {
        const {
            joinOrder,
            fromIdx,
            toIdx,
            jailAnimationSource = null,
            jailState = null,
        } = pendingLocalMove.value;
        pendingLocalMove.value = null;
        if (jailState !== null) {
            setPlayerJailState(joinOrder, jailState);
        } else {
            syncPlayerJailStateAfterMove(joinOrder, pendingSquareAction.value?.type ?? null);
        }
        const policeEscortStartSquareIndex = jailAnimationSource === 'square' && fromIdx !== 0
            ? 30
            : null;
        // Notify other boards first so they begin animating in sync with the
        // local animation that is about to start.
        await notifyTokenMoved(false, jailAnimationSource);
        await animateTokenMovement(joinOrder, fromIdx, toIdx, 200, false, {
            showPoliceEscort: jailAnimationSource !== null,
            policeEscortStartSquareIndex,
        });
        showPostMoveDialogs();
    }
}

/**
 * Notify all other board observers that the local player's token has finished moving.
 *
 * Logic: POSTs to the appropriate token-moved endpoint — the authenticated owner
 * endpoint (/api/games/{id}/token-moved) or the guest endpoint
 * (/api/join/{token}/token-moved). The server reads the authoritative square_index
 * from the database and dispatches the TokenMoved broadcast event so all connected
 * observer boards receive the final position and animate accordingly. The backward
 * flag is forwarded so observers animate in the correct direction.
 * On failure the error is logged but not re-thrown so a network hiccup does not
 * block any further game actions.
 *
 * @param {boolean} [backward=false] Whether the token moved backward.
 * @param {'square'|'card'|null} [jailAnimationSource=null] Escort timing source.
 * @returns {Promise<void>}
 */
async function notifyTokenMoved(backward = false, jailAnimationSource = null) {
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/token-moved`
            : `/api/games/${props.game.id}/token-moved`;
        const res = await window.axios.post(url, {
            backward,
            jail_animation_source: jailAnimationSource,
        });
        const responseJoinOrder = Number(res?.data?.join_order);
        const responseJailState = resolveJailState(res?.data);

        if (Number.isFinite(responseJoinOrder) && responseJailState !== null) {
            setPlayerJailState(responseJoinOrder, responseJailState);
        }
    } catch (err) {
        console.error('Failed to notify token movement', err);
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

// pendingCardEffect needs to be declared before watchers to avoid TDZ
const pendingCardEffect = ref(null);

// --- Persist open-card modal across page refreshes ---
/**
 * Storage key for persisting an open drawn card for this viewer and game.
 * Format: monopoly:game:{gameId}:open_card:{viewerId}
 */
function storageKeyForOpenCard() {
    const viewer = props.currentUserId !== null
        ? `user_${props.currentUserId}`
        : props.currentInvitationId !== null
            ? `inv_${props.currentInvitationId}`
            : 'anon';
    return `monopoly:game:${props.game.id}:open_card:${viewer}`;
}

function saveOpenCardState() {
    try {
        // Skip persisting during unit tests to avoid polluting test environment
        if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'test') {
            return;
        }
        const key = storageKeyForOpenCard();
        const payload = {
            drawnCard: drawnCard.value ?? null,
            drawnCardType: drawnCardType.value ?? null,
            pendingCardEffect: pendingCardEffect.value ?? null,
            // persist the previous capital for the current player so the
            // hand card shows the correct before-balance after reload.
            previousCapital: myJoinOrder.value !== null ? previousCapitals.value[Number(myJoinOrder.value)] ?? null : null,
            myJoinOrder: myJoinOrder.value ?? null,
            showCardModal: Boolean(showCardModal.value),
        };
        localStorage.setItem(key, JSON.stringify(payload));
    } catch (err) {
        // ignore storage errors
    }
}

function clearOpenCardState() {
    try {
        if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'test') {
            return;
        }
        const key = storageKeyForOpenCard();
        localStorage.removeItem(key);
    } catch (err) {
        // ignore
    }
}

function restoreOpenCardState() {
    try {
        // Skip restoring during unit tests to avoid unexpected UI state
        if (typeof process !== 'undefined' && process.env && process.env.NODE_ENV === 'test') {
            return;
        }
        const key = storageKeyForOpenCard();
        const raw = localStorage.getItem(key);
        if (!raw) return;
        const payload = JSON.parse(raw);
        if (!payload) return;

        // Restore previous capital for the expected join order if present.
        if (payload.myJoinOrder != null && payload.previousCapital != null) {
            previousCapitals.value[Number(payload.myJoinOrder)] = Number(payload.previousCapital);
            localPlayers.value = localPlayers.value.map((p) => Number(p.join_order) === Number(payload.myJoinOrder)
                ? { ...p, previous_capital: previousCapitals.value[Number(payload.myJoinOrder)] ?? p.previous_capital ?? null }
                : p);
        }

        // Restore the drawn card and open the modal for the local viewer when
        // appropriate.
        if (payload.drawnCard) {
            drawnCard.value = payload.drawnCard;
            drawnCardType.value = payload.drawnCardType ?? 'chance';
            pendingCardEffect.value = payload.pendingCardEffect ?? null;
            showCardModal.value = Boolean(payload.showCardModal ?? true);
        }
    } catch (err) {
        // ignore JSON parse or storage errors
    }
}

// Restore persisted open-card state on mount and keep storage in sync.
onMounted(() => {
    restoreOpenCardState();
});

watch([
    () => showCardModal.value,
    () => drawnCard.value,
    () => drawnCardType.value,
    () => pendingCardEffect.value,
], () => {
    if (showCardModal.value && drawnCard.value) {
        saveOpenCardState();
    } else {
        clearOpenCardState();
    }
}, { deep: true });

watch(() => previousCapitals.value, () => {
    // Persist updated previous capital while the modal is open so a reload
    // keeps the hand card showing the correct before-balance.
    if (showCardModal.value && drawnCard.value) {
        saveOpenCardState();
    }
}, { deep: true });


/** Debug card picker state */
const deckCards = ref([]);
const showCardPicker = ref(false);
const pickerType = ref(null);

/**
 * Fetch the ordered deck for the given type and show the picker modal.
 * deckType: 'chance' | 'community'
 */
async function fetchDeckAndShowPicker(deckType) {
    if (!props.debugMode) return;
    if (isDrawing.value) return;
    isDrawing.value = true;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/${deckType}/cards`
            : `/api/games/${props.game.id}/${deckType}/cards`;
        const res = await window.axios.get(url);
        deckCards.value = res.data.cards || [];
        pickerType.value = deckType;
        showCardPicker.value = true;
    } catch (err) {
        console.error('Failed to fetch deck', err);
    }
    finally {
        isDrawing.value = false;
    }
}

async function emulatePickedCard(card) {
    if (!props.debugMode) return;
    showCardPicker.value = false;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/${pickerType.value}/emulate`
            : `/api/games/${props.game.id}/${pickerType.value}/emulate`;
        const res = await window.axios.post(url, { card_id: card.id });

        const returnedCard = res.data.card ?? res.data.card ?? null;
        const effect = res.data.effect ?? null;

        if (!returnedCard || Object.keys(effect ?? {}).length === 0) {
            // No action defined for this card
            // Show message to player
            window.alert('Selected card has no action defined to execute.');
        }

        drawnCard.value = res.data.card;
        drawnCardType.value = pickerType.value === 'chance' ? 'chance' : 'community';
        pendingCardEffect.value = res.data.effect ?? null;
        // Seed the persistent previous capital for the local player so the
        // player's hand card preserves the before-value while the modal is
        // open. This mirrors the same map-update performed by
        // `updatePlayerCapital` when the effect is later applied.
        if (myJoinOrder.value !== null) {
            const target = Number(myJoinOrder.value);
            const idx = localPlayers.value.findIndex((p) => Number(p.join_order) === target);
            previousCapitals.value[target] = Number(localPlayers.value[idx]?.capital ?? 0);
            localPlayers.value = localPlayers.value.map((p, i) => Number(p.join_order) === target
                ? { ...p, previous_capital: previousCapitals.value[target] ?? p.previous_capital ?? null }
                : p);
        }
        showCardModal.value = true;
    } catch (err) {
        console.error('Failed to emulate picked card', err);
    }
}

// (declared above)

/**
 * Close the card reveal modal and signal to all observer boards that the
 * drawing player has accepted the card.
 *
 * Logic: Immediately hides the CardRevealModal, then fires a best-effort POST
 * to the card-accept endpoint.  On success the backend dispatches a
 * CardAccepted broadcast event which observer boards listen for to auto-close
 * their CardDrawnNotification.  Errors are swallowed and logged as warnings
 * only — the modal closes regardless, and any observer who has not yet
 * dismissed manually will simply retain their notification until they click OK.
 *
 * @returns {Promise<void>}
 */
async function handleCardModalClose() {
    // Hide the modal immediately (restore original behavior) so the UI
    // dismisses promptly. The `previous_capital` is seeded when the card
    // was shown and persisted in `previousCapitals`, so it will remain
    // visible until the subsequent capital update applies.
    showCardModal.value = false;
    // Clear any persisted open-card state so a subsequent refresh does not
    // reopen an already-acknowledged card.
    try { clearOpenCardState(); } catch (e) { /* ignore */ }

    // Consume the buffered card effect and apply all state changes.
    const effect = pendingCardEffect.value;
    pendingCardEffect.value = null;

    if (effect) {
        if (effect.type === 'pay' || effect.type === 'pay_each_player') {
            pendingCardPayment.value = effect;

            const requiredAmount = Number(effect.required_amount ?? effect.amount ?? 0);
            const currentPlayer = myJoinOrder.value === null
                ? null
                : localPlayers.value.find((player) => Number(player.join_order) === Number(myJoinOrder.value));
            const currentCapital = Number(currentPlayer?.capital ?? 0);

            if (currentCapital >= requiredAmount) {
                await submitCardPayment([]);
            } else {
                await handleOpenMortgageOptions('card', null, requiredAmount);
            }

            return;
        }

        const cardPassedGo = effect.passed_go === true && (effect.go_bonus ?? 0) > 0;
        const hasCardSquareAction = effect.square_action !== undefined;

        // Card effects that cross GO use the same pending GO dialog flow as
        // the dice-roll path so the bonus is surfaced consistently.
        if (cardPassedGo && myJoinOrder.value !== null) {
            const currentPlayer = localPlayers.value.find(
                (player) => player.join_order === myJoinOrder.value,
            );
            pendingPassedGo.value = true;
            pendingGoNewCapital.value = effect.new_capital
                ?? ((currentPlayer?.capital ?? 0) + (effect.go_bonus ?? 200));
        } else if (effect.new_capital != null && myJoinOrder.value !== null) {
            // Update the roller's capital when the card modified it without a
            // GO pass-through bonus.
            updatePlayerCapital(myJoinOrder.value, effect.new_capital);
        }
        // Update other players' capitals (pay_each_player / collect_from_each_player).
        if (effect.other_player_capitals) {
            for (const { join_order, capital } of effect.other_player_capitals) {
                updatePlayerCapital(join_order, capital);
            }
        }
        // Animate the token to its new square for movement cards
        // (advance_to, advance_to_nearest, go_to_jail, move_back).
        if (effect.new_square_index != null && myJoinOrder.value !== null) {
            const fromIdx    = tokenPositions.value[myJoinOrder.value] ?? 0;
            const isBackward = effect.type === 'move_back';
            syncPlayerJailStateAfterMove(myJoinOrder.value, effect.type ?? null);
            await animateTokenMovement(
                myJoinOrder.value,
                fromIdx,
                effect.new_square_index,
                200,
                isBackward,
                {
                    showPoliceEscort: effect.type === 'go_to_jail',
                    policeEscortStartSquareIndex: null,
                },
            );
            await notifyTokenMoved(
                isBackward,
                effect.type === 'go_to_jail' ? 'card' : null,
            );
        }

        if (hasCardSquareAction) {
            pendingSquareAction.value = effect.square_action;
        }

        if (cardPassedGo || hasCardSquareAction) {
            showPostMoveDialogs();
        }
    }

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/card/accept`
            : `/api/games/${props.game.id}/card/accept`;
        await window.axios.post(url);
    } catch (err) {
        console.warn(
            'Failed to notify card acceptance; observer notifications may require manual dismiss.',
            err,
        );
    }

    void maybeAdvanceTurn();

}

// ── Observer card-drawn notification state ───────────────────────────────────

/**
 * Data for the card-drawn observer notification.
 * Populated from the CardDrawn broadcast event when another player draws a card.
 * Contains the drawer's name, their token icon, the drawn card object, and the
 * card type ('chance' | 'community').
 *
 * @type {import('vue').Ref<{playerName: string, playerIcon: object|null, card: object|null, type: string}|null>}
 */
const cardDrawnNotification = ref(null);

/** Controls the CardDrawnNotification visibility for observer boards. */
const showCardDrawnNotification = ref(false);

/**
 * Dynamic z-index seed used to ensure newer notifications render above older
 * unacknowledged notifications.
 */
const notificationZSeed = ref(130);

/** Dynamic z-index for the GO bonus dialog container. */
const goDialogZIndex = ref(110);

/** Dynamic z-index for the observer card-drawn notification. */
const cardDrawnNotificationZIndex = ref(130);

/** Dynamic z-index for the rent-paid notification dialog. */
const rentNotificationZIndex = ref(120);

/** Dynamic z-index for the property-purchased notification dialog. */
const propertyPurchasedNotificationZIndex = ref(125);

/** Dynamic z-index for the mortgaged-property notification dialog. */
const mortgagedPropertyZIndex = ref(120);

/** Dynamic z-index for the available operations dialog. */
const availableOperationsZIndex = ref(220);

/**
 * Promote a popup to the top of the current notification stack.
 *
 * @param {import('vue').Ref<number>} dialogZIndexRef
 * @returns {void}
 */
function bringNotificationToFront(dialogZIndexRef) {
    notificationZSeed.value += 1;
    dialogZIndexRef.value = notificationZSeed.value;
}

/**
 * Close the card-drawn observer notification.
 *
 * Logic: Resets both the visibility flag and the notification data so the
 * component can be reused for subsequent card draws in the same session.
 */
function handleCardDrawnNotificationClose() {
    showCardDrawnNotification.value = false;
    cardDrawnNotification.value = null;
}

// ── Square action state ────────────────────────────────────────────────────

/**
 * Square action data buffered from the roll API response while the token
 * animation is still in progress. Consumed by showPendingSquareAction()
 * once the animation completes so the modal only appears after the token
 * has visually settled on its new square.
 *
 * @type {import('vue').Ref<object|null>}
 */
const pendingSquareAction = ref(null);

/** The square action currently being displayed in the modal. */
const activeSquareAction = ref(null);

/** Controls SquareActionModal visibility. */
const showSquareActionModal = ref(false);

/**
 * Whether the local player passed GO on the current roll.
 * Buffered from the roll API response and consumed by showPostMoveDialogs
 * after the token animation completes.
 *
 * @type {import('vue').Ref<boolean>}
 */
const pendingPassedGo = ref(false);

/**
 * The player's updated capital after collecting the $200 GO bonus.
 * Null when the player did not pass GO on the current roll.
 *
 * @type {import('vue').Ref<number|null>}
 */
const pendingGoNewCapital = ref(null);

/** Controls the GO-bonus notification dialog visibility. */
const showGoDialog = ref(false);

/**
 * Controls the rent-paid notification dialog visibility.
 * Shown to the payer (from the API response) and to the owner and observers
 * (from the RentPaid broadcast event).
 */
const showRentNotificationDialog = ref(false);

/**
 * Tracks whether the active rent notification came from the local payer flow.
 * Only payer-origin dialogs should trigger turn advancement on close.
 */
const rentNotificationFromPayerFlow = ref(false);

/** Controls the property-purchased notification dialog visibility. */
const showPropertyPurchasedNotification = ref(false);

/**
 * Data for the rent-paid notification dialog.
 * Populated either from the pay-rent API response (payer) or from the
 * RentPaid broadcast event (owner / observers).
 *
 * @type {import('vue').Ref<{payerName: string, payerIcon: object|null, ownerName: string, ownerIcon: object|null, rentAmount: number, squareName: string}|null>}
 */
const rentNotificationData = ref(null);

/** Controls the mortgaged-property notification dialog visibility. */
const showMortgagedPropertyDialog = ref(false);

/**
 * Tracks whether the active mortgaged-property notification came from the
 * local payer flow. Only payer-origin dialogs should trigger turn advancement
 * on close.
 */
const mortgagedPropertyFromPayerFlow = ref(false);

/**
 * Data for the mortgaged-property notification dialog.
 * Populated when a player lands on a mortgaged property.
 *
 * @type {import('vue').Ref<{payerName: string, payerIcon: object|null, ownerName: string, ownerIcon: object|null, squareName: string}|null>}
 */
const mortgagedPropertyData = ref(null);

/**
 * Data for the property-purchased notification dialog.
 * Populated from the PropertyPurchased broadcast event when another player
 * buys a property.
 *
 * @type {import('vue').Ref<{buyerName: string, buyerIcon: object|null, squareName: string, purchasePrice: number}|null>}
 */
const propertyPurchasedNotification = ref(null);

/**
 * Whether a property action API call (purchase or pay-rent) is in flight.
 * Used to disable buttons and prevent double-submission.
 */
const isPropertyActionInFlight = ref(false);

/** Controls the mortgage options dialog visibility. */
const showMortgageOptionsDialog = ref(false);

/** Controls the unmortgage-capital shortfall dialog visibility. */
const showUnmortgageShortfallDialog = ref(false);

/** Controls the available operations dialog visibility. */
const showAvailableOperationsDialog = ref(false);
/** Controls the build operation dialog visibility. */
const showBuildOperationDialog = ref(false);

/** Controls the board-level API error dialog visibility. */
const showErrorDialog = ref(false);

/** Message shown in the board-level API error dialog. */
const errorMessage = ref('');

const hasUnmortgagedOperationProperty = ref(false);
const hasMortgagedOperationProperty = ref(false);
const hasFullyUnmortgagedOperationColorGroup = ref(false);

/** Properties available for mortgage selection. */
const mortgageProperties = ref([]);

/** Selected property squares for the active mortgage payment session. */
const mortgageSessionSelectedSquareIndexes = ref([]);

/** Active payment session metadata for mortgage planning. */
const mortgageSession = ref(null);

/** Selected property square index pending unmortgage completion. */
const pendingUnmortgageSquareIndex = ref(null);

/** The amount required to unmortgage the currently selected property. */
const pendingUnmortgageRequiredAmount = ref(0);

/** Buffered card payment effect that still needs mortgage resolution. */
const pendingCardPayment = ref(null);

/** Whether the mortgage property list is being fetched. */
const isMortgagePropertiesLoading = ref(false);

/** Whether a mortgage mutation request is currently in flight. */
const isMortgageActionInFlight = ref(false);

/** Current player's capital while mortgage session planning is open. */
const mortgageSessionCurrentCapital = computed(() => {
    if (myJoinOrder.value === null) {
        return 0;
    }

    const player = localPlayers.value.find((entry) => entry.join_order === myJoinOrder.value);

    return Number(player?.capital ?? 0);
});

/** Total raised by currently selected mortgages in this session. */
const mortgageSessionSelectedMortgageValue = computed(() => {
    const selectedSet = new Set(mortgageSessionSelectedSquareIndexes.value.map(Number));

    return mortgageProperties.value.reduce((sum, property) => {
        if (property.is_mortgaged) {
            return sum;
        }

        if (!selectedSet.has(Number(property.square_index))) {
            return sum;
        }

        return sum + Number(property.mortgage_value ?? 0);
    }, 0);
});

/** Projected capital after applying selected mortgages for this payment session. */
const mortgageSessionProjectedCapital = computed(
    () => mortgageSessionCurrentCapital.value + mortgageSessionSelectedMortgageValue.value,
);

/** Remaining amount needed to cover the pending payment. */
const mortgageSessionShortfall = computed(() => {
    const required = Number(mortgageSession.value?.requiredAmount ?? 0);

    return Math.max(0, required - mortgageSessionProjectedCapital.value);
});

/** Primary action label in the mortgage session dialog. */
const mortgageSessionActionLabel = computed(() => {
    if (!mortgageSession.value) {
        return 'Apply Mortgages';
    }

    if (mortgageSession.value.actionType === 'unmortgage') {
        return 'Unmortgage Property';
    }

    if (mortgageSession.value.actionType === 'unmortgage-funding') {
        return 'Unmortgage Selected Properties';
    }

    if (mortgageSession.value.actionType === 'operation') {
        return 'Apply Mortgages';
    }

    if (mortgageSession.value.actionType === 'card') {
        return `Pay $${mortgageSession.value.requiredAmount}`;
    }

    return mortgageSession.value.actionType === 'purchase'
        ? `Buy for $${mortgageSession.value.requiredAmount}`
        : `Pay $${mortgageSession.value.requiredAmount}`;
});

/** Selection mode for the mortgage options dialog. */
const mortgageSessionSelectionMode = computed(() => {
    if (mortgageSession.value?.actionType === 'unmortgage') {
        return 'unmortgage';
    }

    return 'mortgage';
});

/** Whether the session allows multiple selected properties. */
const mortgageSessionAllowMultipleSelection = computed(
    () => mortgageSession.value?.actionType !== 'unmortgage',
);

/** Current capital for the active square action, when present. */
const currentCapitalForActiveSquareAction = computed(() => {
    if (!activeSquareAction.value || myJoinOrder.value === null) {
        return 0;
    }

    const player = localPlayers.value.find((entry) => Number(entry.join_order) === Number(myJoinOrder.value));

    return Number(player?.capital ?? 0);
});

/** Required amount for the active square action, when present. */
const currentRequiredAmountForActiveSquareAction = computed(() => {
    if (!activeSquareAction.value) {
        return 0;
    }

    return activeSquareAction.value.type === 'purchase'
        ? Number(activeSquareAction.value?.price ?? 0)
        : Number(activeSquareAction.value?.rent ?? 0);
});

const enabledAvailableOperationKeys = computed(() => {
    const enabledKeys = [];

    if (hasCompleteColorGroup.value && hasFullyUnmortgagedOperationColorGroup.value) {
        enabledKeys.push('build');
    }

    if (hasUnmortgagedOperationProperty.value) {
        enabledKeys.push('mortgage-property');
    }

    if (hasMortgagedOperationProperty.value) {
        enabledKeys.push('unmortgage-property');
    }

    if (currentPlayerIsInJail.value && hasGetOutOfJailCard.value) {
        enabledKeys.push('use-get-out-of-jail-card');
    }

    if (currentPlayerIsInJail.value && !currentPlayerHasPaidJailRelease.value) {
        enabledKeys.push('pay-jail-release');
    }

    return enabledKeys;
});

/**
 * Surface post-move dialogs in the correct sequence after the token animation.
 *
 * Logic:
 *   1. If the player passed GO, update their capital reactively and open the
 *      GO-bonus dialog first. The square-action modal (if any) will only open
 *      after the player dismisses the GO dialog via handleGoOk.
 *   2. If the player did not pass GO, surface the square-action modal directly
 *      (delegates to showPendingSquareAction).
 */
function showPostMoveDialogs() {
    if (pendingPassedGo.value) {
        // Apply the capital update immediately so the player's card reflects $200
        // before the dialog even opens.
        if (myJoinOrder.value !== null && pendingGoNewCapital.value !== null) {
            updatePlayerCapital(myJoinOrder.value, pendingGoNewCapital.value);
        }
        pendingPassedGo.value = false;
        pendingGoNewCapital.value = null;
        bringNotificationToFront(goDialogZIndex);
        showGoDialog.value = true;
        // showPendingSquareAction() is deferred to handleGoOk so the GO dialog
        // remains the first post-move interaction when the player passes GO.
        return;
    }

    showPendingSquareAction();
}

/**
 * Close the GO-bonus dialog and surface any pending square-action dialog.
 *
 * Logic: Called only by the OK button inside the GO dialog. This is the sole
 * dismiss path — there is no backdrop click or Escape handler so the player
 * cannot bypass this step.
 */
function handleGoOk() {
    showGoDialog.value = false;
    showPendingSquareAction();
}

/**
 * Surface the buffered square action as a modal if one is pending.
 *
 * Logic: Checks the action type. For Chance and Community Chest squares the
 * drawn card is surfaced via CardRevealModal (reusing the existing card-reveal
 * flip animation). For server-resolved rent (type='rent_paid'), balances are
 * updated immediately and the rent notification dialog is shown. For mortgaged
 * properties (type='mortgaged'), shows the mortgaged-property notification to
 * all players. For purchasable and other manual actions the SquareActionModal
 * is opened as before. Called after the token animation finishes so the dialog
 * appears only once the player can see their final landing square.
 */
function showPendingSquareAction() {
    if (pendingSquareAction.value) {
        const action = pendingSquareAction.value;
        pendingSquareAction.value = null;
        if (action.type === 'go_to_jail') {
            // Move local token to Jail corner (square 10) and mark the player
            // as jailed. No modal is shown — the token position change is
            // self-explanatory and already animated by the roll response.
            if (myJoinOrder.value !== null) {
                setPlayerJailState(myJoinOrder.value, true);
            }
            void maybeAdvanceTurn();
            return;
        }
        if (action.type === 'chance' || action.type === 'community') {
            appendHeldCardToPlayer(myJoinOrder.value, action.type, action.card);
            drawnCard.value         = action.card;
            drawnCardType.value     = action.type;
            pendingCardEffect.value = action.effect ?? null;
            // Seed persistent previous capital for the active player so the
            // player's hand card shows the before-balance while the card
            // reveal modal is visible. This prevents the previous capital
            // disappearing during the modal acknowledgement flow.
            if (myJoinOrder.value !== null) {
                const target = Number(myJoinOrder.value);
                const idx = localPlayers.value.findIndex((p) => Number(p.join_order) === target);
                previousCapitals.value[target] = Number(localPlayers.value[idx]?.capital ?? 0);
                localPlayers.value = localPlayers.value.map((p) => Number(p.join_order) === target
                    ? { ...p, previous_capital: previousCapitals.value[target] ?? p.previous_capital ?? null }
                    : p);
            }
            showCardModal.value     = true;
        } else if (action.type === 'rent_paid') {
            if (action.payer_join_order !== undefined && action.payer_capital !== undefined) {
                updatePlayerCapital(action.payer_join_order, action.payer_capital);
            }
            if (action.owner_join_order !== undefined && action.owner_capital !== undefined) {
                updatePlayerCapital(action.owner_join_order, action.owner_capital);
            }

            rentNotificationData.value = {
                payerName:  getPlayerByJoinOrder(action.payer_join_order)?.name ?? 'Player',
                payerIcon:  getPlayerIconByJoinOrder(action.payer_join_order),
                ownerName:  action.owner_name
                    ?? getPlayerByJoinOrder(action.owner_join_order)?.name
                    ?? 'Player',
                ownerIcon:  getPlayerIconByJoinOrder(action.owner_join_order),
                rentAmount: action.rent_amount ?? 0,
                squareName: action.square_name ?? '',
            };
            rentNotificationFromPayerFlow.value = true;
            bringNotificationToFront(rentNotificationZIndex);
            showRentNotificationDialog.value = true;
        } else if (action.type === 'mortgaged') {
            mortgagedPropertyData.value = {
                payerName:  getPlayerByJoinOrder(action.payer_join_order)?.name ?? 'Player',
                payerIcon:  getPlayerIconByJoinOrder(action.payer_join_order),
                ownerName:  action.owner_name
                    ?? getPlayerByJoinOrder(action.owner_join_order)?.name
                    ?? 'Player',
                ownerIcon:  getPlayerIconByJoinOrder(action.owner_join_order),
                squareName: action.square_name ?? '',
            };
            mortgagedPropertyFromPayerFlow.value = true;
            bringNotificationToFront(mortgagedPropertyZIndex);
            showMortgagedPropertyDialog.value = true;
        } else {
            if (action.type === 'rent') {
                activeSquareAction.value = {
                    ...action,
                    payer_icon: getPlayerIconByJoinOrder(myJoinOrder.value),
                    owner_icon: getPlayerIconByJoinOrder(action.owner_join_order),
                };
            } else {
                activeSquareAction.value = action;
            }
            showSquareActionModal.value = true;
        }
    } else {
        void maybeAdvanceTurn();
    }
}

/**
 * Handle the player choosing to purchase the landed property.
 *
 * Logic: Calls the appropriate purchase endpoint — the authenticated owner
 * endpoint (/api/games/{id}/property/purchase) or the guest endpoint
 * (/api/join/{token}/property/purchase) — with the current square_index.
 * On success, updates the purchasing player's capital in localPlayers
 * reactively from the response, then closes the modal. On failure, logs
 * the error; the modal stays open so the player can retry.
 *
 * @returns {Promise<void>}
 */
async function handlePurchase() {
    if (isPropertyActionInFlight.value || !activeSquareAction.value) return;

    const squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
    const currentPlayer = myJoinOrder.value !== null
        ? localPlayers.value.find((player) => player.join_order === myJoinOrder.value)
        : null;
    const purchasePrice = Number(activeSquareAction.value?.price ?? 0);

    if ((currentPlayer?.capital ?? 0) < purchasePrice) {
        void handleOpenMortgageOptions('purchase', squareIndex, purchasePrice);
        return;
    }

    await submitPurchasePayment([]);
}

/**
 * Handle the player choosing to skip purchasing the landed property.
 *
 * Logic: Simply closes the modal without making any API call. The square
 * remains unowned and another player may purchase it on a future landing.
 */
function handleSkip() {
    showSquareActionModal.value = false;
    activeSquareAction.value = null;
    void maybeAdvanceTurn();
}

/**
 * Handle the player paying rent on the landed property.
 *
 * Logic: Calls the appropriate pay-rent endpoint — the authenticated owner
 * endpoint (/api/games/{id}/property/pay-rent) or the guest endpoint
 * (/api/join/{token}/property/pay-rent) — with the current square_index.
 * On success, updates both the payer's and the owner's capital in
 * localPlayers reactively from the response, then closes the SquareActionModal
 * and immediately shows the rent-paid notification dialog so the payer sees a
 * confirmation. The RentPaid broadcast event will arrive shortly after; the
 * event listener skips showing the dialog again for the payer since it was
 * already surfaced here from the API response.
 * On failure, logs the error; the modal stays open (with no dismiss option)
 * so the player must resolve it before continuing.
 *
 * @returns {Promise<void>}
 */
async function handlePayRent() {
    if (isPropertyActionInFlight.value || !activeSquareAction.value) return;

    const squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
    const currentPlayer = myJoinOrder.value !== null
        ? localPlayers.value.find((player) => player.join_order === myJoinOrder.value)
        : null;
    const rentAmount = Number(activeSquareAction.value?.rent ?? 0);

    if ((currentPlayer?.capital ?? 0) < rentAmount) {
        void handleOpenMortgageOptions('rent', squareIndex, rentAmount);
        return;
    }

    await submitRentPayment([]);
}

/**
 * Execute property purchase with optional session mortgages.
 *
 * @param {number[]} mortgageSquareIndexes
 * @returns {Promise<boolean>}
 */
async function submitPurchasePayment(mortgageSquareIndexes = []) {
    if (!activeSquareAction.value) return false;

    const squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
    isPropertyActionInFlight.value = true;

    try {
        const purchasedSquareName = activeSquareAction.value?.square_name ?? squareNameByIndex(squareIndex);
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/property/purchase`
            : `/api/games/${props.game.id}/property/purchase`;
        const res = await window.axios.post(url, {
            square_index: squareIndex,
            mortgage_square_indices: mortgageSquareIndexes,
        });
        updatePlayerCapital(res.data.player.join_order, res.data.player.capital);
        appendPropertyToPlayer(res.data.player.join_order, {
            square_index: res.data.player?.property?.square_index ?? squareIndex,
            name: res.data.player?.property?.name ?? purchasedSquareName,
        });
        applySessionMortgageStateToLocalPlayerProperties(mortgageSquareIndexes);
        closeMortgageSessionDialog();
        showSquareActionModal.value = false;
        activeSquareAction.value = null;
        void maybeAdvanceTurn();

        return true;
    } catch (err) {
        console.error('Failed to purchase property', err);
        if (isCapitalShortfallError(err)) {
            const purchasePrice = Number(activeSquareAction.value?.price ?? 0);
            void handleOpenMortgageOptions('purchase', squareIndex, purchasePrice);
        }

        return false;
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/**
 * Execute rent payment with optional session mortgages.
 *
 * @param {number[]} mortgageSquareIndexes
 * @returns {Promise<boolean>}
 */
async function submitRentPayment(mortgageSquareIndexes = []) {
    if (!activeSquareAction.value) return false;

    isPropertyActionInFlight.value = true;
    try {
        const squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/property/pay-rent`
            : `/api/games/${props.game.id}/property/pay-rent`;
        const res = await window.axios.post(url, {
            square_index: squareIndex,
            mortgage_square_indices: mortgageSquareIndexes,
        });
        updatePlayerCapital(res.data.payer.join_order, res.data.payer.capital);
        updatePlayerCapital(res.data.owner.join_order, res.data.owner.capital);
        applySessionMortgageStateToLocalPlayerProperties(mortgageSquareIndexes);
        closeMortgageSessionDialog();
        showSquareActionModal.value = false;
        rentNotificationData.value = {
            payerName:  getPlayerByJoinOrder(res.data.payer.join_order)?.name ?? 'Player',
            payerIcon:  getPlayerIconByJoinOrder(res.data.payer.join_order),
            ownerName:  activeSquareAction.value?.owner_name ?? 'Player',
            ownerIcon:  getPlayerIconByJoinOrder(res.data.owner.join_order),
            rentAmount: res.data.rent_amount,
            squareName: res.data.square_name,
        };
        rentNotificationFromPayerFlow.value = true;
        activeSquareAction.value = null;
        bringNotificationToFront(rentNotificationZIndex);
        showRentNotificationDialog.value = true;

        return true;
    } catch (err) {
        console.error('Failed to pay rent', err);
        if (isCapitalShortfallError(err)) {
            const rentAmount = Number(activeSquareAction.value?.rent ?? 0);
            const squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
            void handleOpenMortgageOptions('rent', squareIndex, rentAmount);
        }

        return false;
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/**
 * Declare bankruptcy for the local player from the mortgage dialog.
 * Sends optional creditor when the bankruptcy resulted from a rent.
 */
async function handleDeclareBankruptcy() {
    if (!mortgageSession.value || isMortgageActionInFlight.value || isPropertyActionInFlight.value) return;

    isMortgageActionInFlight.value = true;

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/bankruptcy`
            : `/api/games/${props.game.id}/bankruptcy`;

        const payload = {};
        const actionType = String(mortgageSession.value.actionType || '');
        if (actionType === 'rent' && activeSquareAction.value?.owner_join_order) {
            payload.owner_join_order = Number(activeSquareAction.value.owner_join_order);
        }

        const res = await window.axios.post(url, payload);

        const result = res.data.result ?? {};

        const debtorJoin = Number(myJoinOrder.value);
        const recipientJoin = Number(result.recipient_join_order ?? 0);

        // Set debtor capital to zero locally
        updatePlayerCapital(debtorJoin, 0);

        // Remove transferred properties from debtor and add to recipient if player
        const transferred = Array.isArray(result.transferred_properties) ? result.transferred_properties.map(Number) : [];

        if (transferred.length > 0) {
            // Remove from debtor
            localPlayers.value = localPlayers.value.map((p) => {
                if (Number(p.join_order) !== debtorJoin) return p;
                const props = Array.isArray(p.properties) ? p.properties.filter(pr => !transferred.includes(Number(pr.square_index))) : [];
                return { ...p, properties: props };
            });

            if (Number.isFinite(recipientJoin) && recipientJoin > 0) {
                // Add to recipient
                transferred.forEach((sq) => {
                    appendPropertyToPlayer(recipientJoin, { square_index: sq, name: squareNameByIndex(sq) });
                });
                // Mark received properties as mortgaged in local state
                applySessionMortgageStateToLocalPlayerProperties(transferred);
            }
        }

        // Remove any held get-out-of-jail-free card from debtor and append to recipient where applicable
        const chanceCount = Number(result.chance_transferred ?? 0);
        const communityCount = Number(result.community_transferred ?? 0);

        for (let i = 0; i < chanceCount; i++) {
            consumeGetOutOfJailCard(debtorJoin);
            if (Number.isFinite(recipientJoin) && recipientJoin > 0) {
                // No card payload returned; rely on server-side broadcasting to fully sync held cards.
            }
        }

        for (let i = 0; i < communityCount; i++) {
            consumeGetOutOfJailCard(debtorJoin);
        }

        closeMortgageSessionDialog();
    } catch (err) {
        console.error('Failed to declare bankruptcy', err);
    } finally {
        isMortgageActionInFlight.value = false;
    }
}

/**
 * Close the rent-paid notification dialog.
 *
 * Logic: Resets both the visibility flag and the notification data so the
 * dialog can be reused for subsequent rent payments in the same session.
 * Attempts to advance the turn if all other pending actions have been resolved.
 */
function handleRentNotificationClose() {
    const shouldAdvanceTurn = rentNotificationFromPayerFlow.value;
    showRentNotificationDialog.value = false;
    rentNotificationData.value = null;
    rentNotificationFromPayerFlow.value = false;
    if (shouldAdvanceTurn) {
        void maybeAdvanceTurn();
    }
}

/**
 * Close the mortgaged-property notification dialog.
 *
 * Logic: Resets both the visibility flag and the notification data so the
 * dialog can be reused for subsequent mortgaged-property events in the same
 * session. Attempts to advance the turn only when the dialog originated from
 * the local payer flow.
 */
function handleMortgagedPropertyNotificationClose() {
    const shouldAdvanceTurn = mortgagedPropertyFromPayerFlow.value;
    showMortgagedPropertyDialog.value = false;
    mortgagedPropertyData.value = null;
    mortgagedPropertyFromPayerFlow.value = false;
    if (shouldAdvanceTurn) {
        void maybeAdvanceTurn();
    }
}

/**
 * Close the property-purchased notification dialog.
 *
 * Logic: Resets both the visibility flag and the notification data so the
 * dialog can be reused for subsequent purchase broadcasts in the same session.
 */
function handlePropertyPurchasedNotificationClose() {
    showPropertyPurchasedNotification.value = false;
    propertyPurchasedNotification.value = null;
}

/**
 * Determine whether an API error indicates the player needs to raise capital.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
function isCapitalShortfallError(error) {
    return Boolean(
        error?.response?.status === 422
        && String(error?.response?.data?.message ?? '').includes('enough capital'),
    );
}

/** Open mortgage planning from the currently active square action modal. */
function handleOpenMortgageOptionsFromAction() {
    if (!activeSquareAction.value) {
        return;
    }

    const squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
    const actionType = activeSquareAction.value.type === 'purchase' ? 'purchase' : 'rent';
    const requiredAmount = actionType === 'purchase'
        ? Number(activeSquareAction.value?.price ?? 0)
        : Number(activeSquareAction.value?.rent ?? 0);

    void handleOpenMortgageOptions(actionType, squareIndex, requiredAmount);
}

/**
 * Open a payment-scoped mortgage planning session and fetch owned properties.
 *
 * @param {'purchase'|'rent'|'card'|'operation'} actionType
 * @param {number} squareIndex
 * @param {number} requiredAmount
 * @returns {Promise<void>}
 */
async function handleOpenMortgageOptions(actionType = 'rent', squareIndex = null, requiredAmount = 0) {
    if (isMortgagePropertiesLoading.value) return;

    if (squareIndex === null) {
        squareIndex = tokenPositions.value[myJoinOrder.value] ?? 0;
    }

    mortgageSession.value = {
        actionType,
        squareIndex,
        requiredAmount: Number(requiredAmount ?? 0),
    };
    mortgageSessionSelectedSquareIndexes.value = [];

    showUnmortgageShortfallDialog.value = false;
    showMortgageOptionsDialog.value = true;
    isMortgagePropertiesLoading.value = true;

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/properties/player`
            : `/api/games/${props.game.id}/properties/player`;
        const res = await window.axios.get(url);
        mortgageProperties.value = Array.isArray(res.data.properties) ? res.data.properties : [];
    } catch (error) {
        console.error('Failed to load mortgage options', error);
        mortgageProperties.value = [];
    } finally {
        isMortgagePropertiesLoading.value = false;
    }
}

/**
 * Toggle one non-mortgaged property in the current mortgage session.
 *
 * @param {number} squareIndex
 * @returns {void}
 */
function handleToggleMortgageSessionProperty(squareIndex) {
    const selectedProperty = mortgageProperties.value.find(
        (property) => Number(property.square_index) === Number(squareIndex),
    );

    if (!selectedProperty || isMortgageActionInFlight.value) {
        return;
    }

    const selectingUnmortgageProperty = mortgageSessionSelectionMode.value === 'unmortgage';
    if (selectingUnmortgageProperty && selectedProperty.is_mortgaged !== true) {
        return;
    }

    if (!selectingUnmortgageProperty && selectedProperty.is_mortgaged) {
        return;
    }

    const nextSet = new Set(mortgageSessionSelectedSquareIndexes.value.map(Number));

    if (!mortgageSessionAllowMultipleSelection.value) {
        if (nextSet.has(Number(squareIndex))) {
            mortgageSessionSelectedSquareIndexes.value = [];
            return;
        }

        mortgageSessionSelectedSquareIndexes.value = [Number(squareIndex)];
        return;
    }

    if (nextSet.has(Number(squareIndex))) {
        nextSet.delete(Number(squareIndex));
    } else {
        nextSet.add(Number(squareIndex));
    }

    mortgageSessionSelectedSquareIndexes.value = Array.from(nextSet);
}

/**
 * Submit the pending payment request with selected session mortgages.
 *
 * @returns {Promise<void>}
 */
async function handleMortgageSessionSubmitPayment() {
    if (!mortgageSession.value || isMortgageActionInFlight.value || mortgageSessionShortfall.value > 0) {
        return;
    }

    isMortgageActionInFlight.value = true;

    const selectedSquareIndexes = [...mortgageSessionSelectedSquareIndexes.value];

    try {
        if (mortgageSession.value.actionType === 'operation') {
            await submitOperationMortgageSelection(selectedSquareIndexes);
        } else if (mortgageSession.value.actionType === 'unmortgage') {
            await submitOperationUnmortgageSelection(selectedSquareIndexes);
        } else if (mortgageSession.value.actionType === 'unmortgage-funding') {
            await submitOperationUnmortgageFunding(selectedSquareIndexes);
        } else if (mortgageSession.value.actionType === 'purchase') {
            await submitPurchasePayment(selectedSquareIndexes);
        } else if (mortgageSession.value.actionType === 'card') {
            await submitCardPayment(selectedSquareIndexes);
        } else {
            await submitRentPayment(selectedSquareIndexes);
        }
    } finally {
        isMortgageActionInFlight.value = false;
    }
}

/**
 * Submit an unmortgage request selected from the operation dialog.
 *
 * @param {number[]} selectedSquareIndexes
 * @returns {Promise<boolean>}
 */
async function submitOperationUnmortgageSelection(selectedSquareIndexes = []) {
    if (selectedSquareIndexes.length !== 1) {
        return false;
    }

    const [squareIndex] = selectedSquareIndexes.map(Number);
    const targetProperty = mortgageProperties.value.find(
        (property) => Number(property.square_index) === Number(squareIndex),
    );
    pendingUnmortgageSquareIndex.value = squareIndex;
    pendingUnmortgageRequiredAmount.value = Number(targetProperty?.unmortgage_cost ?? 0);

    return submitOperationUnmortgageProperty(squareIndex);
}

/**
 * Raise missing capital via mortgages and retry the pending unmortgage.
 *
 * @param {number[]} mortgageSquareIndexes
 * @returns {Promise<boolean>}
 */
async function submitOperationUnmortgageFunding(mortgageSquareIndexes = []) {
    const targetSquareIndex = Number(pendingUnmortgageSquareIndex.value);
    if (!Number.isFinite(targetSquareIndex)) {
        return false;
    }

    const raisedCapital = await submitOperationMortgageSelection(
        mortgageSquareIndexes,
        { closeDialogOnSuccess: false, refreshStateAfterRequest: false },
    );

    if (!raisedCapital) {
        return false;
    }

    showMortgageOptionsDialog.value = false;

    return submitOperationUnmortgageProperty(targetSquareIndex);
}

/**
 * Execute the unmortgage operation request and update local state.
 *
 * @param {number} squareIndex
 * @returns {Promise<boolean>}
 */
async function submitOperationUnmortgageProperty(squareIndex) {
    if (isPropertyActionInFlight.value) {
        return false;
    }

    isPropertyActionInFlight.value = true;

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/property/unmortgage`
            : `/api/games/${props.game.id}/property/unmortgage`;
        const res = await window.axios.post(url, {
            square_index: Number(squareIndex),
        });

        if (res.data.player?.join_order !== undefined && res.data.player?.capital !== undefined) {
            updatePlayerCapital(res.data.player.join_order, res.data.player.capital);
        }

        applySessionUnmortgageStateToLocalPlayerProperties([Number(squareIndex)]);
        pendingUnmortgageSquareIndex.value = null;
        closeMortgageSessionDialog();
        await refreshAvailableOperationMortgageState();

        return true;
    } catch (error) {
        if (isCapitalShortfallError(error)) {
            showMortgageOptionsDialog.value = false;
            showUnmortgageShortfallDialog.value = true;
        }

        console.error('Failed to unmortgage property from requested operation', error);
        return false;
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/** Return from the shortfall dialog to the unmortgage property selection dialog. */
function handleUnmortgageShortfallBack() {
    if (!Number.isFinite(Number(pendingUnmortgageSquareIndex.value))) {
        return;
    }

    mortgageSession.value = {
        actionType: 'unmortgage',
        squareIndex: Number(pendingUnmortgageSquareIndex.value),
        requiredAmount: 0,
    };
    mortgageSessionSelectedSquareIndexes.value = [Number(pendingUnmortgageSquareIndex.value)];
    showUnmortgageShortfallDialog.value = false;
    showMortgageOptionsDialog.value = true;
}

/** Continue from the shortfall dialog into mortgage-funding mode. */
function handleUnmortgageShortfallMortgageOthers() {
    if (!Number.isFinite(Number(pendingUnmortgageSquareIndex.value))) {
        return;
    }

    mortgageSession.value = {
        actionType: 'unmortgage-funding',
        squareIndex: Number(pendingUnmortgageSquareIndex.value),
        requiredAmount: Number(pendingUnmortgageRequiredAmount.value ?? 0),
    };
    mortgageSessionSelectedSquareIndexes.value = [];
    showUnmortgageShortfallDialog.value = false;
    showMortgageOptionsDialog.value = true;
}

/**
 * Apply selected mortgages from the Requested Operation context.
 *
 * @param {number[]} mortgageSquareIndexes
 * @returns {Promise<boolean>}
 */
async function submitOperationMortgageSelection(
    mortgageSquareIndexes = [],
    options = { closeDialogOnSuccess: true, refreshStateAfterRequest: true },
) {
    if (mortgageSquareIndexes.length === 0 || isPropertyActionInFlight.value) {
        return false;
    }

    const closeDialogOnSuccess = options?.closeDialogOnSuccess !== false;
    const refreshStateAfterRequest = options?.refreshStateAfterRequest !== false;
    isPropertyActionInFlight.value = true;
    const normalizedIndexes = mortgageSquareIndexes.map(Number);
    const successfullyMortgaged = [];

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/property/mortgage`
            : `/api/games/${props.game.id}/property/mortgage`;

        for (const squareIndex of normalizedIndexes) {
            const res = await window.axios.post(url, {
                square_index: squareIndex,
            });

            if (res.data.player?.join_order !== undefined && res.data.player?.capital !== undefined) {
                updatePlayerCapital(res.data.player.join_order, res.data.player.capital);
            }

            successfullyMortgaged.push(squareIndex);
        }

        applySessionMortgageStateToLocalPlayerProperties(successfullyMortgaged);
        if (closeDialogOnSuccess) {
            closeMortgageSessionDialog();
        }
        if (refreshStateAfterRequest) {
            await refreshAvailableOperationMortgageState();
        }

        return true;
    } catch (error) {
        applySessionMortgageStateToLocalPlayerProperties(successfullyMortgaged);
        if (refreshStateAfterRequest) {
            await refreshAvailableOperationMortgageState();
        }
        console.error('Failed to apply requested operation mortgages', error);

        return false;
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/** Mark selected properties as unmortgaged in local player state after success. */
function applySessionUnmortgageStateToLocalPlayerProperties(unmortgagedSquareIndexes) {
    if (myJoinOrder.value === null || unmortgagedSquareIndexes.length === 0) {
        return;
    }

    const unmortgagedSet = new Set(unmortgagedSquareIndexes.map(Number));

    mortgageProperties.value = mortgageProperties.value.map((property) => {
        if (unmortgagedSet.has(Number(property.square_index))) {
            return { ...property, is_mortgaged: false };
        }

        return property;
    });

    localPlayers.value = localPlayers.value.map((player) => {
        if (Number(player.join_order) !== Number(myJoinOrder.value)) {
            return player;
        }

        const nextProperties = (player.properties ?? []).map((property) => {
            if (unmortgagedSet.has(Number(property.square_index))) {
                return { ...property, is_mortgaged: false };
            }

            return property;
        });

        return {
            ...player,
            properties: nextProperties,
        };
    });
}

/** Mark selected properties as mortgaged in local player state after successful payment. */
function applySessionMortgageStateToLocalPlayerProperties(mortgagedSquareIndexes) {
    if (myJoinOrder.value === null || mortgagedSquareIndexes.length === 0) {
        return;
    }

    const mortgagedSet = new Set(mortgagedSquareIndexes.map(Number));

    mortgageProperties.value = mortgageProperties.value.map((property) => {
        if (mortgagedSet.has(Number(property.square_index))) {
            return { ...property, is_mortgaged: true };
        }

        return property;
    });

    localPlayers.value = localPlayers.value.map((player) => {
        if (Number(player.join_order) !== Number(myJoinOrder.value)) {
            return player;
        }

        const nextProperties = (player.properties ?? []).map((property) => {
            if (mortgagedSet.has(Number(property.square_index))) {
                return { ...property, is_mortgaged: true };
            }

            return property;
        });

        return {
            ...player,
            properties: nextProperties,
        };
    });
}

/** Close mortgage session dialog and clear transient planning state. */
function closeMortgageSessionDialog() {
    showMortgageOptionsDialog.value = false;
    showUnmortgageShortfallDialog.value = false;
    mortgageSession.value = null;
    mortgageSessionSelectedSquareIndexes.value = [];
    pendingUnmortgageSquareIndex.value = null;
    pendingUnmortgageRequiredAmount.value = 0;
}

/** Close the mortgage options dialog and clear its local state. */
function handleMortgageOptionsClose() {
    if (mortgageSession.value?.actionType === 'card') {
        return;
    }

    closeMortgageSessionDialog();
}

/**
 * Sell a house from the mortgage dialog context and refresh state.
 * @param {number} squareIndex
 * @returns {Promise<void>}
 */
async function handleSellHouseFromMortgage(squareIndex) {
    if (isPropertyActionInFlight.value || !Number.isFinite(Number(squareIndex))) return;

    isPropertyActionInFlight.value = true;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/property/sell`
            : `/api/games/${props.game.id}/property/sell`;
        const res = await window.axios.post(url, { square_index: Number(squareIndex), action: 'house' });

        if (res.data.player?.join_order !== undefined && res.data.player?.capital !== undefined) {
            updatePlayerCapital(res.data.player.join_order, res.data.player.capital);
        }

        // Refresh mortgage properties list so dialog reflects new building counts
        try {
            const fetchUrl = props.invitationToken
                ? `/api/join/${props.invitationToken}/properties/player`
                : `/api/games/${props.game.id}/properties/player`;
            const listRes = await window.axios.get(fetchUrl);
            mortgageProperties.value = Array.isArray(listRes.data.properties) ? listRes.data.properties : [];
        } catch (e) {
            console.error('Failed to refresh properties after selling house', e);
        }
    } catch (err) {
        console.error('Failed to sell house from mortgage dialog', err);
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/**
 * Sell a hotel from the mortgage dialog context and refresh state.
 * @param {number} squareIndex
 * @returns {Promise<void>}
 */
async function handleSellHotelFromMortgage(squareIndex) {
    if (isPropertyActionInFlight.value || !Number.isFinite(Number(squareIndex))) return;

    isPropertyActionInFlight.value = true;
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/property/sell`
            : `/api/games/${props.game.id}/property/sell`;
        const res = await window.axios.post(url, { square_index: Number(squareIndex), action: 'hotel' });

        if (res.data.player?.join_order !== undefined && res.data.player?.capital !== undefined) {
            updatePlayerCapital(res.data.player.join_order, res.data.player.capital);
        }

        // Refresh mortgage properties list so dialog reflects new building counts
        try {
            const fetchUrl = props.invitationToken
                ? `/api/join/${props.invitationToken}/properties/player`
                : `/api/games/${props.game.id}/properties/player`;
            const listRes = await window.axios.get(fetchUrl);
            mortgageProperties.value = Array.isArray(listRes.data.properties) ? listRes.data.properties : [];
        } catch (e) {
            console.error('Failed to refresh properties after selling hotel', e);
        }
    } catch (err) {
        console.error('Failed to sell hotel from mortgage dialog', err);
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/**
 * Submit a deferred card payment after the player has selected mortgages.
 *
 * @param {number[]} mortgageSquareIndexes
 * @returns {Promise<boolean>}
 */
async function submitCardPayment(mortgageSquareIndexes = []) {
    if (!pendingCardPayment.value || isPropertyActionInFlight.value) {
        return false;
    }

    isPropertyActionInFlight.value = true;

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/card/accept`
            : `/api/games/${props.game.id}/card/accept`;
        const res = await window.axios.post(url, {
            mortgage_square_indices: mortgageSquareIndexes,
            card_payment_type: pendingCardPayment.value.type,
            card_payment_amount: Number(pendingCardPayment.value.amount ?? pendingCardPayment.value.required_amount ?? 0),
        });

        if (res.data.payer?.join_order !== undefined && res.data.payer?.capital !== undefined) {
            updatePlayerCapital(res.data.payer.join_order, res.data.payer.capital);
        }

        if (Array.isArray(res.data.other_player_capitals)) {
            for (const { join_order, capital } of res.data.other_player_capitals) {
                updatePlayerCapital(join_order, capital);
            }
        }

        applySessionMortgageStateToLocalPlayerProperties(mortgageSquareIndexes);
        closeMortgageSessionDialog();
        pendingCardPayment.value = null;
        await maybeAdvanceTurn();

        return true;
    } catch (err) {
        console.error('Failed to resolve card payment', err);
        return false;
    } finally {
        isPropertyActionInFlight.value = false;
    }
}

/** Prevents duplicate end-turn requests while the last dialog is closing. */
const turnAdvanceInFlight = ref(false);

watch(
    () => isMyTurn.value,
    (isTurn) => {
        if (!isTurn) {
            hasDebugMovedThisTurn.value = false;
            awaitingExtraRoll.value = false;
        }
    },
);

/**
 * Determine whether the player still has any turn-resolving dialogs open.
 *
 * Logic: Turn advancement is deferred while any modal/dialog that requires a
 * player decision or acknowledgement remains visible.
 *
 * @returns {boolean}
 */
function hasPendingTurnResolution() {
    return showGoDialog.value
        || showSquareActionModal.value
        || showCardModal.value
        || showRentNotificationDialog.value
        || showMortgageOptionsDialog.value
        || showUnmortgageShortfallDialog.value
    || awaitingExtraRoll.value
        || pendingSquareAction.value !== null;
}

/**
 * Send the end-turn request for the current player.
 *
 * Logic: Posts to the authenticated owner endpoint or guest endpoint, then
 * updates currentTurnJoinOrder from the server response so the local board
 * switches to the next player's turn immediately.
 *
 * @returns {Promise<void>}
 */
async function advanceTurnNow() {
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/turn/end`
            : `/api/games/${props.game.id}/turn/end`;
        const res = await window.axios.post(url);
        currentTurnJoinOrder.value = res.data.current_turn_join_order;
    } catch (err) {
        console.error('Failed to end turn', err);
        const message = err.response?.data?.message ?? 'Failed to end turn.';
        errorMessage.value = message;
        showErrorDialog.value = true;

        if (message === 'You must pay $50 to leave jail before rolling.') {
            resetHasRolledSignal.value += 1;
        }
    }
}

/**
 * Advance the turn once no more player actions remain.
 *
 * Logic: Guards against duplicate requests, off-turn updates, and any open
 * decision/acknowledgement dialog. When the last pending dialog closes, this
 * sends the end-turn API call and relies on the broadcast response to update
 * every board.
 *
 * @returns {Promise<void>}
 */
async function maybeAdvanceTurn() {
    if (!isMyTurn.value || turnAdvanceInFlight.value || hasPendingTurnResolution()) {
        return;
    }

    turnAdvanceInFlight.value = true;
    try {
        await advanceTurnNow();
    } finally {
        turnAdvanceInFlight.value = false;
    }
}

/**
 * Reactively update a single player's capital in localPlayers.
 *
 * Logic: Finds the entry in localPlayers matching the given join_order and
 * replaces it with a new object carrying the updated capital. Using object
 * spread preserves all other player fields. No page reload required.
 *
 * @param {number|string} joinOrder  The join_order of the player to update.
 * @param {number|string} capital    The new capital balance.
 */
function updatePlayerCapital(joinOrder, capital) {
    const targetJoinOrder = Number(joinOrder);
    const nextCapital = Number(capital);

    if (!Number.isFinite(targetJoinOrder) || !Number.isFinite(nextCapital)) {
        return;
    }

    const idx = localPlayers.value.findIndex(
        p => Number(p.join_order) === targetJoinOrder,
    );
    if (idx !== -1) {
        // Store the previous capital in the persistent map so future
        // prop-merges or player-array replacements can reapply it.
        previousCapitals.value[targetJoinOrder] = Number(localPlayers.value[idx].capital ?? 0);

        localPlayers.value = localPlayers.value.map((p, i) => {
            if (i === idx) {
                // Preserve the existing capital as previous_capital for debug
                // inspection before applying the updated balance.
                return { ...p, previous_capital: Number(p.capital ?? 0), capital: nextCapital };
            }
            return p;
        });
    }
}

/**
 * Reactively update jail-turn and paid-release metadata for one player.
 *
 * @param {number|string} joinOrder
 * @param {object|null|undefined} payload
 * @returns {void}
 */
function applyJailReleaseState(joinOrder, payload) {
    const targetJoinOrder = Number(joinOrder);

    if (!Number.isFinite(targetJoinOrder) || !payload) {
        return;
    }

    const hasTurns = payload.jail_turns !== undefined && payload.jail_turns !== null;
    const hasPaid = payload.has_paid_jail_release !== undefined && payload.has_paid_jail_release !== null;
    const hasCapital = payload.capital !== undefined && payload.capital !== null;

    if (!hasTurns && !hasPaid && !hasCapital) {
        return;
    }

    const nextJailTurns = hasTurns ? Number(payload.jail_turns) : null;
    const nextHasPaid = hasPaid ? Boolean(payload.has_paid_jail_release) : null;
    const nextCapital = hasCapital ? Number(payload.capital) : null;

    localPlayers.value = localPlayers.value.map((player) => {
        if (Number(player.join_order) !== targetJoinOrder) {
            return player;
        }

        return {
            ...player,
            jail_turns: Number.isFinite(nextJailTurns) ? nextJailTurns : player.jail_turns,
            has_paid_jail_release: nextHasPaid === null ? player.has_paid_jail_release : nextHasPaid,
            // Preserve previous capital when replacing with a new value so
            // debug inspection can show the before/after balance.
            previous_capital: Number.isFinite(nextCapital) ? Number(player.capital ?? 0) : player.previous_capital ?? null,
            capital: Number.isFinite(nextCapital) ? nextCapital : player.capital,
        };
    });
}

/**
 * Reactively update the isInJail flag for a player in localPlayers.
 *
 * Logic: Finds the player by join_order and replaces their entry with an
 * object carrying the updated isInJail boolean. Uses object spread to
 * preserve all other player fields. No page reload required.
 *
 * @param {number|string} joinOrder  The join_order of the player to update.
 * @param {boolean}       inJail     True to mark the player as jailed, false to release.
 */
function setPlayerJailState(joinOrder, inJail) {
    const targetJoinOrder = Number(joinOrder);

    if (!Number.isFinite(targetJoinOrder)) {
        return;
    }

    const idx = localPlayers.value.findIndex(
        p => Number(p.join_order) === targetJoinOrder,
    );

    if (idx !== -1) {
        localPlayers.value = localPlayers.value.map((p, i) =>
            i === idx ? { ...p, isInJail: Boolean(inJail) } : p,
        );
    }
}

/**
 * Align local jail state with the move result currently being applied.
 *
 * @param {number|string|null|undefined} joinOrder
 * @param {string|null|undefined} actionType
 * @returns {void}
 */
function syncPlayerJailStateAfterMove(joinOrder, actionType) {
    if (joinOrder === null || joinOrder === undefined) {
        return;
    }

    setPlayerJailState(joinOrder, actionType === 'go_to_jail');
}

/**
 * Resolve local jail animation source from a square action type.
 *
 * @param {string|null|undefined} actionType
 * @returns {'square'|null}
 */
function resolveJailAnimationSourceFromAction(actionType, context = null) {
    if (actionType !== 'go_to_jail') {
        return null;
    }

    const responseCurrentTurnJoinOrder = Number(context?.responseCurrentTurnJoinOrder);
    const rollerJoinOrder = Number(context?.rollerJoinOrder);
    const didTurnAdvanceFromRoll = Number.isFinite(responseCurrentTurnJoinOrder)
        && Number.isFinite(rollerJoinOrder)
        && responseCurrentTurnJoinOrder !== rollerJoinOrder;

    // Triple-doubles jail advances turn on roll; show immediate escort to jail.
    return didTurnAdvanceFromRoll ? 'card' : 'square';
}

/**
 * Resolve jail animation source from a realtime token-moved payload.
 *
 * @param {object|null|undefined} payload
 * @returns {'square'|'card'|null}
 */
function resolveJailAnimationSource(payload) {
    const source = String(payload?.jail_animation_source ?? '').trim().toLowerCase();

    if (source === 'square' || source === 'card') {
        return source;
    }

    return null;
}

/**
 * Resolve jail-state boolean from camelCase or snake_case payload keys.
 *
 * @param {object|null|undefined} payload
 * @returns {boolean|null}
 */
function resolveJailState(payload) {
    const jailState = payload?.isInJail ?? payload?.is_in_jail;

    if (jailState === undefined || jailState === null) {
        return null;
    }

    if (typeof jailState === 'string') {
        const normalized = jailState.trim().toLowerCase();
        if (normalized === 'true' || normalized === '1') {
            return true;
        }
        if (normalized === 'false' || normalized === '0') {
            return false;
        }
    }

    return Boolean(jailState);
}

/**
 * Normalize player payload into a stable board-state shape.
 *
 * Logic: Canonicalizes jail state to a single boolean field `isInJail`.
 *
 * @param {object} player
 * @returns {object}
 */
function normalizePlayerForBoard(player) {
    const normalizedJailState = resolveJailState(player);

    return {
        ...player,
        // Track previous capital for debug-only display. Preserve any
        // incoming value (from real-time merges) or initialize to null.
        previous_capital: player?.previous_capital ?? null,
        isInJail: normalizedJailState === null ? false : normalizedJailState,
        jail_turns: Number(player?.jail_turns ?? 0),
        has_paid_jail_release: Boolean(player?.has_paid_jail_release ?? false),
    };
}

/**
 * Normalize any property-like payload into a stable shape.
 *
 * @param {object} property
 * @returns {{ square_index: number, name: string, color: string|null }|null}
 */
function normalizeOwnedProperty(property) {
    if (!property || property.square_index === undefined || property.square_index === null) {
        return null;
    }

    const squareIndex = Number(property.square_index);

    if (!Number.isFinite(squareIndex)) {
        return null;
    }

    return {
        square_index: squareIndex,
        name: property.name ?? squareNameByIndex(squareIndex),
        color: BOARD_SQUARES[squareIndex]?.color ?? property.color ?? null,
    };
}

/**
 * Merge two property arrays without duplicates, keyed by square_index.
 *
 * @param {Array<object>} existingProperties
 * @param {Array<object>} incomingProperties
 * @returns {Array<{ square_index: number, name: string, color: string|null }>}
 */
function mergePlayerProperties(existingProperties = [], incomingProperties = []) {
    const merged = new Map();

    for (const property of [...existingProperties, ...incomingProperties]) {
        const normalized = normalizeOwnedProperty(property);

        if (!normalized) {
            continue;
        }

        merged.set(normalized.square_index, normalized);
    }

    return Array.from(merged.values()).sort((a, b) => a.square_index - b.square_index);
}

/**
 * Append a purchased property to a player without duplicating existing entries.
 *
 * @param {number|string} joinOrder
 * @param {object} property
 * @returns {void}
 */
function appendPropertyToPlayer(joinOrder, property) {
    const targetJoinOrder = Number(joinOrder);

    if (!Number.isFinite(targetJoinOrder)) {
        return;
    }

    const normalizedProperty = normalizeOwnedProperty(property);

    if (!normalizedProperty) {
        return;
    }

    const idx = localPlayers.value.findIndex(
        p => Number(p.join_order) === targetJoinOrder,
    );

    if (idx === -1) {
        return;
    }

    const existingPlayer = localPlayers.value[idx];
    const nextProperties = mergePlayerProperties(existingPlayer.properties ?? [], [normalizedProperty]);

    localPlayers.value = localPlayers.value.map((p, i) =>
        i === idx ? { ...p, properties: nextProperties } : p,
    );
}

/**
 * Apply building updates (houses_count / has_hotel) to an owned property.
 * If the property is not present in the owner's list, add it so the board
 * can render the buildings immediately.
 */
function applyBuildingUpdate(joinOrder, squareIndex, housesCount, hasHotel) {
    const targetJoinOrder = Number(joinOrder);
    const sqIdx = Number(squareIndex);

    if (!Number.isFinite(targetJoinOrder) || !Number.isFinite(sqIdx)) {
        return;
    }

    const idx = localPlayers.value.findIndex(
        p => Number(p.join_order) === targetJoinOrder,
    );

    if (idx === -1) return;

    const existingPlayer = localPlayers.value[idx];
    const props = Array.isArray(existingPlayer.properties) ? existingPlayer.properties.slice() : [];

    const pIdx = props.findIndex(p => Number(p.square_index) === sqIdx);

    if (pIdx === -1) {
        // Add a new property entry with building data
        const newProp = {
            square_index: sqIdx,
            name: squareNameByIndex(sqIdx),
            color: BOARD_SQUARES[sqIdx]?.color ?? null,
            houses_count: housesCount ?? 0,
            has_hotel: Boolean(hasHotel ?? false),
        };
        props.push(newProp);
    } else {
        const existing = props[pIdx];
        props[pIdx] = {
            ...existing,
            houses_count: housesCount ?? (existing.houses_count ?? 0),
            has_hotel: Boolean(hasHotel ?? existing.has_hotel ?? false),
        };
    }

    localPlayers.value = localPlayers.value.map((p, i) =>
        i === idx ? { ...p, properties: props } : p,
    );
}

/**
 * Normalize any held-card payload into a stable shape.
 *
 * @param {object|null|undefined} card
 * @returns {{ id: number, action: string, text: string }|null}
 */
function normalizeHeldCard(card) {
    if (!card || card.id === undefined || card.id === null) {
        return null;
    }

    const cardId = Number(card.id);

    if (!Number.isFinite(cardId)) {
        return null;
    }

    return {
        id: cardId,
        action: String(card.action ?? ''),
        text: String(card.text ?? ''),
    };
}

/**
 * Append a held get-out-of-jail-free card to the drawing player's hand.
 *
 * Logic: Only get_out_of_jail_free cards are persisted as held cards. This
 * helper updates the corresponding hand array in localPlayers reactively using
 * the draw type (chance/community), and deduplicates by card id so handling
 * both local and broadcast paths never creates duplicate tags.
 *
 * @param {number|string|null|undefined} joinOrder
 * @param {string|null|undefined} drawType
 * @param {object|null|undefined} card
 * @returns {void}
 */
function appendHeldCardToPlayer(joinOrder, drawType, card) {
    const targetJoinOrder = Number(joinOrder);

    if (!Number.isFinite(targetJoinOrder)) {
        return;
    }

    if (drawType !== 'chance' && drawType !== 'community') {
        return;
    }

    const normalizedCard = normalizeHeldCard(card);

    if (!normalizedCard || normalizedCard.action !== 'get_out_of_jail_free') {
        return;
    }

    const cardListField = drawType === 'chance' ? 'chance_cards' : 'community_chest_cards';

    localPlayers.value = localPlayers.value.map((player) => {
        if (Number(player.join_order) !== targetJoinOrder) {
            return player;
        }

        const existingCards = Array.isArray(player[cardListField]) ? player[cardListField] : [];
        const cardAlreadyHeld = existingCards.some(
            (existingCard) => Number(existingCard?.id) === normalizedCard.id,
        );

        if (cardAlreadyHeld) {
            return player;
        }

        return {
            ...player,
            [cardListField]: [...existingCards, normalizedCard],
        };
    });
}

/**
 * Remove one held get-out-of-jail-free card from a player's hand.
 *
 * @param {number|string} joinOrder
 * @returns {void}
 */
function consumeGetOutOfJailCard(joinOrder) {
    const targetJoinOrder = Number(joinOrder);

    if (!Number.isFinite(targetJoinOrder)) {
        return;
    }

    localPlayers.value = localPlayers.value.map((player) => {
        if (Number(player.join_order) !== targetJoinOrder) {
            return player;
        }

        const chanceCards = Array.isArray(player.chance_cards) ? [...player.chance_cards] : [];
        const communityCards = Array.isArray(player.community_chest_cards) ? [...player.community_chest_cards] : [];
        const chanceIndex = chanceCards.findIndex((card) => String(card?.action ?? '') === 'get_out_of_jail_free');

        if (chanceIndex !== -1) {
            chanceCards.splice(chanceIndex, 1);

            return {
                ...player,
                chance_cards: chanceCards,
            };
        }

        const communityIndex = communityCards.findIndex((card) => String(card?.action ?? '') === 'get_out_of_jail_free');

        if (communityIndex !== -1) {
            communityCards.splice(communityIndex, 1);

            return {
                ...player,
                community_chest_cards: communityCards,
            };
        }

        return player;
    });
}

/**
 * Resolve a player object by join_order from local reactive state.
 *
 * @param {number|string|null|undefined} joinOrder
 * @returns {object|null}
 */
function getPlayerByJoinOrder(joinOrder) {
    const targetJoinOrder = Number(joinOrder);
    if (!Number.isFinite(targetJoinOrder)) {
        return null;
    }

    return localPlayers.value.find(
        p => Number(p.join_order) === targetJoinOrder,
    ) ?? null;
}

/**
 * Resolve a player's token icon by join_order.
 *
 * @param {number|string|null|undefined} joinOrder
 * @returns {object|null}
 */
function getPlayerIconByJoinOrder(joinOrder) {
    return getPlayerByJoinOrder(joinOrder)?.icon ?? null;
}

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
 * Wrapper for the Community Chest deck click that enforces debug-mode.
 *
 * Logic: Only calls `drawCommunityChestCard` when `props.debugMode` is true.
 */
function handleCommunityDeckClick() {
    void fetchDeckAndShowPicker('community');
}

/**
 * Wrapper for the Chance deck click that enforces debug-mode.
 *
 * Logic: Only calls `drawChanceCard` when `props.debugMode` is true.
 */
function handleChanceDeckClick() {
    void fetchDeckAndShowPicker('chance');
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
    { name: 'Water Works',       type: 'utility',   icon: '💧',  price: 150,       col: 9,  row: 1 },
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

const BOARD_TRACK_WEIGHTS = [1.1, ...Array.from({ length: 9 }, () => 1), 1.1];
const BOARD_TRACK_TOTAL_WEIGHT = BOARD_TRACK_WEIGHTS.reduce((sum, weight) => sum + weight, 0);

/**
 * Build a lookup map { 'col-row': square } for fast template binding.
 *
 * Logic: Iterates BOARD_SQUARES and keys each entry by its CSS grid position
 * string so the grid template can reference any cell in O(1).
 */
const squareMap = computed(() => {
    const map = {};
    // Build a quick lookup of building state from localPlayers' properties
    const buildings = {};
    for (const player of localPlayers.value) {
        for (const p of player.properties ?? []) {
            const normalized = normalizeOwnedProperty(p);
            if (!normalized) continue;
            // preserve any houses_count / has_hotel if present on the property payload
            buildings[normalized.square_index] = {
                houses_count: p.houses_count ?? 0,
                has_hotel: p.has_hotel ?? false,
            };
        }
    }

    for (const sq of BOARD_SQUARES) {
        const sqIndex = sq.col != null && sq.row != null ? BOARD_SQUARES.findIndex(s => s.name === sq.name) : null;
        const idx = BOARD_SQUARES.indexOf(sq);
        const building = buildings[idx] ?? null;
        map[`${sq.col}-${sq.row}`] = {
            ...sq,
            houses_count: building ? building.houses_count : 0,
            has_hotel: building ? building.has_hotel : false,
        };
    }
    return map;
});

/**
 * Determine whether a player's current board position should be highlighted.
 *
 * @param {Object} player
 * @returns {boolean}
 */
function isPlayerPositionHighlighted(player) {
    return (
        expandedCardJoinOrder.value !== null
        && expandedCardJoinOrder.value === Number(player.join_order)
    ) || (
        hoveredDiceJoinOrder.value !== null
        && hoveredDiceJoinOrder.value === Number(player.join_order)
    );
}

/**
 * Resolve the center position (as percent) of a board grid track.
 *
 * @param {number} trackIndexOneBased
 * @returns {number}
 */
function boardTrackCenterPercent(trackIndexOneBased) {
    const index = Number(trackIndexOneBased);

    if (!Number.isInteger(index) || index < 1 || index > BOARD_TRACK_WEIGHTS.length) {
        return 50;
    }

    const startWeight = BOARD_TRACK_WEIGHTS
        .slice(0, index - 1)
        .reduce((sum, weight) => sum + weight, 0);
    const centerWeight = startWeight + (BOARD_TRACK_WEIGHTS[index - 1] / 2);

    return (centerWeight / BOARD_TRACK_TOTAL_WEIGHT) * 100;
}

/**
 * Resolve the start position (as percent) of a board grid track.
 *
 * @param {number} trackIndexOneBased
 * @returns {number}
 */
function boardTrackStartPercent(trackIndexOneBased) {
    const index = Number(trackIndexOneBased);

    if (!Number.isInteger(index) || index < 1 || index > BOARD_TRACK_WEIGHTS.length) {
        return 0;
    }

    const startWeight = BOARD_TRACK_WEIGHTS
        .slice(0, index - 1)
        .reduce((sum, weight) => sum + weight, 0);

    return (startWeight / BOARD_TRACK_TOTAL_WEIGHT) * 100;
}

/**
 * Resolve the end position (as percent) of a board grid track.
 *
 * @param {number} trackIndexOneBased
 * @returns {number}
 */
function boardTrackEndPercent(trackIndexOneBased) {
    const index = Number(trackIndexOneBased);

    if (!Number.isInteger(index) || index < 1 || index > BOARD_TRACK_WEIGHTS.length) {
        return 100;
    }

    const endWeight = BOARD_TRACK_WEIGHTS
        .slice(0, index)
        .reduce((sum, weight) => sum + weight, 0);

    return (endWeight / BOARD_TRACK_TOTAL_WEIGHT) * 100;
}

const POSITION_INDICATOR_GAP_PERCENT = 0.9;
const POSITION_INDICATOR_SHAFT_LENGTH_PERCENT = 8;
const POSITION_INDICATOR_CORNER_SHAFT_LENGTH_PERCENT = 7;

/**
 * Build a short arrow segment that stays outside the target square.
 *
 * Logic: For edge squares, the arrow is forced perpendicular to the edge
 * nearest the board centre. For corners, the arrow follows the centre-to-
 * corner bisector and still keeps its head outside the square.
 *
 * @param {{col: number, row: number}} square
 * @returns {{x1: number, y1: number, x2: number, y2: number, isCorner: boolean}}
 */
function positionIndicatorGeometry(square) {
    const isLeftEdge = square.col === 1;
    const isRightEdge = square.col === 11;
    const isTopEdge = square.row === 1;
    const isBottomEdge = square.row === 11;
    const isCorner = (isLeftEdge || isRightEdge) && (isTopEdge || isBottomEdge);

    if (isCorner) {
        const innerCornerX = isLeftEdge
            ? boardTrackEndPercent(square.col)
            : boardTrackStartPercent(square.col);
        const innerCornerY = isTopEdge
            ? boardTrackEndPercent(square.row)
            : boardTrackStartPercent(square.row);

        const x2 = innerCornerX + (isLeftEdge ? POSITION_INDICATOR_GAP_PERCENT : -POSITION_INDICATOR_GAP_PERCENT);
        const y2 = innerCornerY + (isTopEdge ? POSITION_INDICATOR_GAP_PERCENT : -POSITION_INDICATOR_GAP_PERCENT);
        const dx = x2 - 50;
        const dy = y2 - 50;
        const magnitude = Math.hypot(dx, dy) || 1;

        return {
            x1: x2 - ((dx / magnitude) * POSITION_INDICATOR_CORNER_SHAFT_LENGTH_PERCENT),
            y1: y2 - ((dy / magnitude) * POSITION_INDICATOR_CORNER_SHAFT_LENGTH_PERCENT),
            x2,
            y2,
            isCorner,
        };
    }

    if (isTopEdge) {
        const x2 = boardTrackCenterPercent(square.col);
        const y2 = boardTrackEndPercent(square.row) + POSITION_INDICATOR_GAP_PERCENT;

        return {
            x1: x2,
            y1: y2 + POSITION_INDICATOR_SHAFT_LENGTH_PERCENT,
            x2,
            y2,
            isCorner,
        };
    }

    if (isBottomEdge) {
        const x2 = boardTrackCenterPercent(square.col);
        const y2 = boardTrackStartPercent(square.row) - POSITION_INDICATOR_GAP_PERCENT;

        return {
            x1: x2,
            y1: y2 - POSITION_INDICATOR_SHAFT_LENGTH_PERCENT,
            x2,
            y2,
            isCorner,
        };
    }

    if (isLeftEdge) {
        const y2 = boardTrackCenterPercent(square.row);
        const x2 = boardTrackEndPercent(square.col) + POSITION_INDICATOR_GAP_PERCENT;

        return {
            x1: x2 + POSITION_INDICATOR_SHAFT_LENGTH_PERCENT,
            y1: y2,
            x2,
            y2,
            isCorner,
        };
    }

    const y2 = boardTrackCenterPercent(square.row);
    const x2 = boardTrackStartPercent(square.col) - POSITION_INDICATOR_GAP_PERCENT;

    return {
        x1: x2 - POSITION_INDICATOR_SHAFT_LENGTH_PERCENT,
        y1: y2,
        x2,
        y2,
        isCorner,
    };
}

/**
 * Arrow indicator targets for currently highlighted player positions.
 *
 * Logic: Deduplicates by square index so multiple highlighted players sharing
 * the same square render a single arrow.
 *
 * @returns {Array<{squareIndex: number, x1: number, y1: number, x2: number, y2: number, isCorner: boolean}>}
 */
const highlightedPositionIndicators = computed(() => {
    const seenSquareIndexes = new Set();
    const indicators = [];

    for (const player of localPlayers.value) {
        if (!isPlayerPositionHighlighted(player)) {
            continue;
        }

        const squareIndex = tokenPositions.value[player.join_order] ?? (player.square_index ?? 0);

        if (seenSquareIndexes.has(squareIndex)) {
            continue;
        }

        const square = BOARD_SQUARES[squareIndex];
        if (!square) {
            continue;
        }

        seenSquareIndexes.add(squareIndex);

        indicators.push({
            squareIndex,
            ...positionIndicatorGeometry(square),
        });
    }

    return indicators;
});

/**
 * Pixel-free board coordinates for the temporary police escort icon.
 *
 * Logic: Anchors to the currently escorted player's square center using the
 * same board track math used by other overlays. Returns null when no escort
 * animation is active.
 *
 * @returns {{ x: number, y: number }|null}
 */
const policeEscortPosition = computed(() => {
    if (policeEscortJoinOrder.value === null) {
        return null;
    }

    const squareIndex = tokenPositions.value[policeEscortJoinOrder.value];
    if (!Number.isFinite(squareIndex)) {
        return null;
    }

    const square = BOARD_SQUARES[squareIndex];
    if (!square) {
        return null;
    }

    return {
        x: boardTrackCenterPercent(square.col),
        y: boardTrackCenterPercent(square.row),
    };
});

/**
 * Map of board-square key ('col-row') to the array of players standing there.
 *
 * Logic: Iterates localPlayers and groups each player by its displayed square
 * index read from tokenPositions (which is updated step-by-step during animation).
 * Defaults to the player's square_index prop then 0 (GO) when no entry exists in
 * tokenPositions yet. Each player object is enriched with isAnimating so
 * BoardSquare can apply the bounce/ring visual to the moving token.
 *
 * @returns {Record<string, Array>}
 */
const squarePlayers = computed(() => {
    const map = {};
    for (const player of localPlayers.value) {
        const idx = tokenPositions.value[player.join_order] ?? (player.square_index ?? 0);
        const sq = BOARD_SQUARES[idx];
        if (!sq) continue;
        const key = `${sq.col}-${sq.row}`;
        if (!map[key]) map[key] = [];
        map[key].push({
            ...player,
            isAnimating: movingJoinOrder.value === player.join_order,
            isHighlighted: isPlayerPositionHighlighted(player),
        });
    }

    const jailSquare = BOARD_SQUARES[10];
    const jailKey = jailSquare ? `${jailSquare.col}-${jailSquare.row}` : null;
    const jailTokens = jailKey ? (map[jailKey] ?? []) : [];

    return map;
});

/**
 * Highlight the local player's current board position while the dice area is hovered.
 *
 * Logic: Only the active player can roll, so hover state is ignored when it is
 * not this client's turn or when the player identity is not yet known.
 *
 * @returns {void}
 */
function handleDiceRollerHoverEnter() {
    if (!isMyTurn.value || myJoinOrder.value === null) {
        return;
    }

    hoveredDiceJoinOrder.value = myJoinOrder.value;
}

/**
 * Clear the dice-area hover highlight.
 *
 * @returns {void}
 */
function handleDiceRollerHoverLeave() {
    hoveredDiceJoinOrder.value = null;
}

/**
 * Handle clicks on the centre-panel Request Operation button.
 *
 * Logic: Any participant can request an operation at any point in the match.
 * The chooser remains decoupled from concrete operation flows so specific
 * handlers can be wired in without changing this modal contract.
 *
 * @returns {void}
 */
async function handleRequestOperationClick() {
    await refreshAvailableOperationMortgageState();
    showAvailableOperationsDialog.value = true;
}

/**
 * Refresh mortgage-based availability used in the operation chooser.
 *
 * @returns {Promise<void>}
 */
async function refreshAvailableOperationMortgageState() {
    hasUnmortgagedOperationProperty.value = false;
    hasMortgagedOperationProperty.value = false;
    hasFullyUnmortgagedOperationColorGroup.value = false;

    if (myJoinOrder.value === null || typeof window.axios?.get !== 'function') {
        return;
    }

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/properties/player`
            : `/api/games/${props.game.id}/properties/player`;
        const res = await window.axios.get(url);
        const properties = Array.isArray(res?.data?.properties) ? res.data.properties : [];

        hasUnmortgagedOperationProperty.value = properties.some(
            property => property?.is_mortgaged === false,
        );
        hasMortgagedOperationProperty.value = properties.some(
            property => property?.is_mortgaged === true,
        );
        const propertiesBySquareIndex = new Map(
            properties
                .filter(property => Number.isFinite(Number(property?.square_index)))
                .map(property => [Number(property.square_index), property]),
        );

        hasFullyUnmortgagedOperationColorGroup.value = Object.values(
            PROPERTY_COLOR_GROUP_SQUARE_INDEXES,
        ).some((squareIndexes) => {
            if (!Array.isArray(squareIndexes) || squareIndexes.length === 0) {
                return false;
            }

            return squareIndexes.every((squareIndex) => {
                const property = propertiesBySquareIndex.get(Number(squareIndex));

                return property && property?.is_mortgaged === false;
            });
        });
    } catch (error) {
        hasUnmortgagedOperationProperty.value = false;
        hasMortgagedOperationProperty.value = false;
        hasFullyUnmortgagedOperationColorGroup.value = false;
    }
}

/**
 * Close the available operations dialog.
 *
 * @returns {void}
 */
function handleCloseAvailableOperationsDialog() {
    showAvailableOperationsDialog.value = false;
}

/**
 * Handle operation selection from the available operations dialog.
 *
 * Logic: Closes the chooser dialog and keeps room for future operation-specific
 * handlers to be attached without changing the modal contract.
 *
 * @param {string} operationKey
 * @returns {void}
 */
function handleAvailableOperationSelection(operationKey) {
    if (!operationKey) {
        return;
    }

    showAvailableOperationsDialog.value = false;

    if (operationKey === 'mortgage-property') {
        void handleOpenMortgageOptions('operation', null, 0);
        return;
    }

    if (operationKey === 'unmortgage-property') {
        void handleOpenMortgageOptions('unmortgage', null, 0);
        return;
    }

    if (operationKey === 'use-get-out-of-jail-card') {
        void handleUseGetOutOfJailCardOperation();
        return;
    }

    if (operationKey === 'pay-jail-release') {
        void handlePayJailReleaseOperation();
    }
    if (operationKey === 'build') {
        showBuildOperationDialog.value = true;
        return;
    }
}

function handleCloseBuildOperation() {
    showBuildOperationDialog.value = false;
}

/**
 * Use one held get-out-of-jail-free card from request operations.
 *
 * @returns {Promise<void>}
 */
async function handleUseGetOutOfJailCardOperation() {
    if (myJoinOrder.value === null) {
        return;
    }

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/jail/use-card`
            : `/api/games/${props.game.id}/jail/use-card`;
        const res = await window.axios.post(url);
        const jailRelease = res?.data?.jail_release ?? null;

        if (jailRelease) {
            applyJailReleaseState(myJoinOrder.value, jailRelease);
            consumeGetOutOfJailCard(myJoinOrder.value);
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Failed to use get out of jail card.';
        showErrorDialog.value = true;
    }
}

/**
 * Pay the $50 jail-release fee from request operations.
 *
 * @returns {Promise<void>}
 */
async function handlePayJailReleaseOperation() {
    if (myJoinOrder.value === null) {
        return;
    }

    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/jail/pay-release`
            : `/api/games/${props.game.id}/jail/pay-release`;
        const res = await window.axios.post(url);
        const jailRelease = res?.data?.jail_release ?? null;

        if (jailRelease) {
            applyJailReleaseState(myJoinOrder.value, jailRelease);
        }
    } catch (error) {
        errorMessage.value = error.response?.data?.message ?? 'Failed to pay jail release.';
        showErrorDialog.value = true;
    }
}

/**
 * Derive the square orientation from its grid position.
 *

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
            sq => sq.type === 'property'
                && sq.color === group.color
                && !ownedSquareIndexSet.value.has(squareIndexByName(sq.name)),
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
    BOARD_SQUARES.filter(
        sq => sq.type === 'railroad' && !ownedSquareIndexSet.value.has(squareIndexByName(sq.name)),
    )
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
    BOARD_SQUARES.filter(
        sq => sq.type === 'utility' && !ownedSquareIndexSet.value.has(squareIndexByName(sq.name)),
    )
);

/**
 * Resolve a board square index from its unique square name.
 *
 * @param {string} squareName
 * @returns {number}
 */
function squareIndexByName(squareName) {
    return BOARD_SQUARES.findIndex(square => square.name === squareName);
}

/**
 * Resolve a square name by index.
 *
 * @param {number|string} squareIndex
 * @returns {string}
 */
function squareNameByIndex(squareIndex) {
    const targetSquareIndex = Number(squareIndex);
    const square = BOARD_SQUARES[targetSquareIndex];

    return square?.name ?? `Square ${targetSquareIndex}`;
}

/**
 * All owned square indices across players.
 *
 * @returns {Set<number>}
 */
const ownedSquareIndexSet = computed(() => {
    const ownedIndices = new Set();

    for (const player of localPlayers.value) {
        for (const property of player.properties ?? []) {
            const normalizedProperty = normalizeOwnedProperty(property);

            if (!normalizedProperty) {
                continue;
            }

            ownedIndices.add(normalizedProperty.square_index);
        }
    }

    return ownedIndices;
});

/** Rows 1-11, columns 1-11 used in the grid template. */
const GRID_INDICES = Array.from({ length: 11 }, (_, i) => i + 1);
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
        <div class="flex-1 flex flex-col landscape:flex-row items-center landscape:items-center size-auto min-h-0 p-1 sm:p-2 lg:p-4 gap-1 lg:gap-2">

            <!-- Left panel: odd join_order players (creator + slots 3, 5, 7) -->
            <!-- Portrait: above the board. Landscape/desktop: left column. -->
            <div
                class="player-panel flex flex-col items-center py-2 px-2 gap-2 landscape:order-first overflow-visible"
                aria-label="Left player panel"
            >
                <PlayerHandCard
                    v-for="player in leftPanelPlayers"
                    :key="player.join_order"
                    :player="player"
                    :is-current-player="isCurrentPlayer(player)"
                    :debug-mode="props.debugMode"
                    :can-reinvite="canShowReinviteButton(player)"
                    :is-reinviting="isReinvitingPlayer(player)"
                    panel-anchor="start"
                    @expanded-change="handlePlayerCardExpandedChange"
                    @reinvite="handleReinvitePlayer"
                />
            </div>

            <!-- Board grid – square, centered, sizing via scoped CSS per orientation -->
            <div
                class="board-grid relative shrink-0 self-center"
                style="
                    display: grid;
                    grid-template-columns: 1.1fr repeat(9, 1fr) 1.1fr;
                    grid-template-rows:    1.1fr repeat(9, 1fr) 1.1fr;
                    aspect-ratio: 1 / 1;
                "
            >
                <svg
                    v-if="highlightedPositionIndicators.length"
                    class="absolute inset-0 z-30 pointer-events-none"
                    viewBox="0 0 100 100"
                    preserveAspectRatio="none"
                    aria-hidden="true"
                    data-testid="position-indicator-overlay"
                >
                    <defs>
                        <marker
                            id="position-indicator-arrowhead"
                            markerWidth="6"
                            markerHeight="6"
                            refX="5"
                            refY="3"
                            orient="auto"
                            markerUnits="strokeWidth"
                        >
                            <path d="M0,0 L6,3 L0,6 z" fill="#dc2626" />
                        </marker>
                    </defs>

                    <g v-for="indicator in highlightedPositionIndicators" :key="indicator.squareIndex">
                        <line
                            :x1="indicator.x1"
                            :y1="indicator.y1"
                            :x2="indicator.x2"
                            :y2="indicator.y2"
                            stroke="#dc2626"
                            :stroke-width="indicator.isCorner ? 1.4 : 1.2"
                            stroke-linecap="round"
                            marker-end="url(#position-indicator-arrowhead)"
                            data-testid="position-indicator-arrow"
                        />
                    </g>
                </svg>

                <div
                    v-if="policeEscortPosition"
                    class="absolute z-30 pointer-events-none"
                    :style="{
                        left: `${policeEscortPosition.x}%`,
                        top: `${policeEscortPosition.y}%`,
                        transform: 'translate(-105%, -55%)',
                    }"
                    data-testid="police-escort-animation"
                >
                    <img
                        src="/images/police.svg"
                        alt="Police escort"
                        class="h-auto w-[clamp(0.65rem,2.4cqw,1.35rem)] drop-shadow"
                    >
                </div>

                <!-- Render all 121 cells -->
                <template v-for="row in GRID_INDICES" :key="row">
                    <template v-for="col in GRID_INDICES" :key="`${col}-${row}`">
                        <!-- Edge / corner square -->
                        <template v-if="squareMap[`${col}-${row}`]">
                            <BoardSquare
                                :square="squareMap[`${col}-${row}`]"
                                :orientation="orientation(col, row)"
                                :player-tokens="squarePlayers[`${col}-${row}`] ?? []"
                                :debug-click-enabled="canUseDebugClickMove"
                                :style="{
                                    gridColumn: col,
                                    gridRow: row,
                                }"
                                @debug-square-clicked="handleDebugSquareMove"
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
                                    @mouseenter="handleDiceRollerHoverEnter"
                                    @mouseleave="handleDiceRollerHoverLeave"
                                >
                                    <DiceRoller
                                        :is-my-turn="isMyTurn"
                                        :debug-mode="props.debugMode"
                                        :waiting-for-token-image-url="activeTurnPlayerToken?.imageUrl ?? null"
                                        :waiting-for-token-name="activeTurnPlayerToken?.tokenName ?? 'Active player'"
                                        :display-die1="currentDie1"
                                        :display-die2="currentDie2"
                                        :external-trigger="externalRollTrigger"
                                        :initial-has-rolled="initialHasRolled"
                                        :force-has-rolled="hasDebugMovedThisTurn"
                                        :reset-has-rolled-signal="resetHasRolledSignal"
                                        @roll-requested="handleRollRequested"
                                        @roll-settled="handleRollSettled"
                                    />
                                </div>

                                <!-- Request operation button – top-left corner opposite dice -->
                                <div
                                    class="absolute z-20"
                                    style="top: 1.5cqw; left: 1.5cqw;"
                                    aria-label="Request operation area"
                                    data-testid="request-operation-area"
                                >
                                    <button
                                        type="button"
                                        class="font-extrabold text-white bg-[#1a7a2e] rounded border-none uppercase tracking-[0.05em] transition-opacity"
                                        style="font-size: clamp(0.18rem, 1.4cqw, 0.52rem); padding: 0.32cqw 0.7cqw; line-height: 1.2;"
                                        :class="'cursor-pointer hover:opacity-90'"
                                        aria-label="Request Operation"
                                        data-testid="request-operation-button"
                                        @click="handleRequestOperationClick"
                                    >
                                        Request Operation
                                    </button>
                                </div>

                                <!-- Bank inventory – top-center between request and dice -->
                                <div
                                    class="absolute z-20"
                                    style="top: 1.5cqw; left: 50%; transform: translateX(-50%);"
                                    aria-label="Bank inventory"
                                    data-testid="bank-inventory"
                                >
                                    <div class="flex justify-evenly gap-2 bg-white/60 rounded px-2 py-1 border border-gray-200 shadow">
                                        <div class="flex items-center gap-1">
                                            <span class="font-black text-green-700" style="font-size: clamp(0.35rem,2cqw,0.9rem);">🏠</span>
                                            <span class="font-bold text-gray-800" style="font-size: clamp(0.15rem,1cqw,0.4rem);">{{ bankHousesAvailable }}</span>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="font-black text-red-700" style="font-size: clamp(0.35rem,2cqw,0.9rem);">🏨</span>
                                            <span class="font-bold text-gray-800" style="font-size: clamp(0.15rem,1cqw,0.4rem);">{{ bankHotelsAvailable }}</span>
                                        </div>
                                    </div>
                                    <div>Building Inventory</div>
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
                                            :class="isDrawing ? 'opacity-50 cursor-not-allowed' : (props.debugMode ? 'hover:opacity-80 cursor-pointer' : 'cursor-default')"
                                            style="width: clamp(0.8rem, 11cqw, 4rem); height: clamp(1.2rem, 15cqw, 5.5rem);"
                                            :disabled="isDrawing"
                                            aria-label="Draw Community Chest card"
                                            data-testid="community-deck"
                                            @click="handleCommunityDeckClick"
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
                                                :data-testid="`center-property-card-${squareIndexByName(prop.name)}`"
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
                                                :data-testid="`center-railroad-card-${squareIndexByName(rr.name)}`"
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
                                                :data-testid="`center-utility-card-${squareIndexByName(util.name)}`"
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
                                                :data-testid="`center-property-card-${squareIndexByName(prop.name)}`"
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
                                            :class="isDrawing ? 'opacity-50 cursor-not-allowed' : (props.debugMode ? 'hover:opacity-80 cursor-pointer' : 'cursor-default')"
                                            style="width: clamp(0.8rem, 11cqw, 4rem); height: clamp(1.2rem, 15cqw, 5.5rem);"
                                            :disabled="isDrawing"
                                            aria-label="Draw Chance card"
                                            data-testid="chance-deck"
                                            @click="handleChanceDeckClick"
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
                                <!-- Build operation dialog placed inside centre panel to avoid overlapping edge property squares -->
                                <BuildOperation
                                    v-if="showBuildOperationDialog"
                                    :gameId="props.game.id"
                                    :invitation-token="invitationToken"
                                    :is-my-turn="isMyTurn"
                                    :bank-houses-available="bankHousesAvailable"
                                    :bank-hotels-available="bankHotelsAvailable"
                                    @close="handleCloseBuildOperation"
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
                class="player-panel flex flex-col items-center py-2 px-2 gap-2 order-last overflow-visible"
                aria-label="Right player panel"
            >
                <PlayerHandCard
                    v-for="player in rightPanelPlayers"
                    :key="player.join_order"
                    :player="player"
                    :is-current-player="isCurrentPlayer(player)"
                    :debug-mode="props.debugMode"
                    :can-reinvite="canShowReinviteButton(player)"
                    :is-reinviting="isReinvitingPlayer(player)"
                    panel-anchor="end"
                    @expanded-change="handlePlayerCardExpandedChange"
                    @reinvite="handleReinvitePlayer"
                />
            </div>

        </div>
    </div>

    <!-- Card reveal animation overlay -->
    <CardPickerModal
        :cards="deckCards"
        :type="pickerType"
        :visible="showCardPicker"
        @close="() => { showCardPicker = false; }"
        @pick="emulatePickedCard"
    />

    <CardRevealModal
        :card="drawnCard"
        :type="drawnCardType"
        :visible="showCardModal"
        @close="handleCardModalClose"
    />

    <!-- Square landing action dialog -->
    <SquareActionModal
        :visible="showSquareActionModal"
        :square-action="activeSquareAction"
        :show-mortgage-options-button="currentCapitalForActiveSquareAction < currentRequiredAmountForActiveSquareAction"
        @purchase="handlePurchase"
        @skip="handleSkip"
        @pay="handlePayRent"
        @mortgage-options="handleOpenMortgageOptionsFromAction"
    />

    <MortgageOptionsDialog
        :visible="showMortgageOptionsDialog"
        :properties="mortgageProperties"
        :selected-square-indexes="mortgageSessionSelectedSquareIndexes"
        :current-capital="mortgageSessionCurrentCapital"
        :required-amount="Number(mortgageSession?.requiredAmount ?? 0)"
        :action-label="mortgageSessionActionLabel"
        :action-type="mortgageSession?.actionType"
        :show-status-block="mortgageSession?.actionType !== 'operation' && mortgageSession?.actionType !== 'unmortgage'"
        :show-required-amount="mortgageSession?.actionType !== 'operation' && mortgageSession?.actionType !== 'unmortgage'"
        :selection-mode="mortgageSessionSelectionMode"
        :allow-multiple-selection="mortgageSessionAllowMultipleSelection"
        :is-loading="isMortgagePropertiesLoading"
        :is-submitting="isMortgageActionInFlight"
        :z-index="210"
        @toggle-property="handleToggleMortgageSessionProperty"
        @submit-payment="handleMortgageSessionSubmitPayment"
        @close="handleMortgageOptionsClose"
        @sell-house="handleSellHouseFromMortgage"
        @sell-hotel="handleSellHotelFromMortgage"
        @declare-bankruptcy="handleDeclareBankruptcy"
    />

    <UnmortgageCapitalShortfallDialog
        :visible="showUnmortgageShortfallDialog"
        :required-amount="pendingUnmortgageRequiredAmount"
        :z-index="230"
        @back="handleUnmortgageShortfallBack"
        @mortgage-others="handleUnmortgageShortfallMortgageOthers"
    />

    <AvailableOperationsDialog
        :visible="showAvailableOperationsDialog"
        :enabled-operation-keys="enabledAvailableOperationKeys"
        :z-index="availableOperationsZIndex"
        @close="handleCloseAvailableOperationsDialog"
        @select-operation="handleAvailableOperationSelection"
    />

    <!-- Build operation dialog is rendered inside the centre panel so it does not overlap property edge squares -->

    <!-- GO bonus notification dialog -->
    <!-- Intentionally has no backdrop-click or Escape handler. The only way to
         dismiss it is the OK button so the player cannot accidentally skip the
         payment confirmation. -->
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
    >
        <div
            v-if="showGoDialog"
            class="fixed inset-0 flex items-center justify-center p-4"
            :style="{ zIndex: goDialogZIndex }"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="go-dialog-title"
            data-testid="go-bonus-dialog"
        >
            <!-- Non-interactive backdrop — no click handler -->
            <div class="absolute inset-0 bg-black/60 cursor-default" aria-hidden="true" />

            <!-- Dialog panel -->
            <div class="relative z-10 w-full max-w-xs rounded-2xl shadow-2xl overflow-hidden">
                <!-- Header -->
                <div class="bg-[#1a7a2e] px-6 pt-6 pb-4 text-center">
                    <div class="text-5xl mb-3" aria-hidden="true">🎉</div>
                    <h2
                        id="go-dialog-title"
                        class="text-white font-black text-xl tracking-wide uppercase"
                    >Passed GO!</h2>
                </div>

                <!-- Body -->
                <div class="bg-white px-6 py-4 text-center">
                    <div class="flex items-center justify-center mb-3">
                        <img
                            v-if="myPlayerToken?.imageUrl"
                            :src="myPlayerToken.imageUrl"
                            :alt="myPlayerToken.tokenName"
                            class="w-10 h-10 object-contain"
                            data-testid="go-dialog-player-token"
                        />
                        <div
                            v-else
                            class="w-10 h-10 rounded-full bg-gray-200"
                            data-testid="go-dialog-player-token"
                            aria-hidden="true"
                        />
                    </div>
                    <p class="text-gray-700 text-sm leading-relaxed">
                        You collected
                        <span class="font-black text-[#1a7a2e] text-base">$200</span>
                        for passing GO.
                    </p>
                </div>

                <!-- Footer — OK is the only dismiss action -->
                <div class="bg-white px-6 pb-6">
                    <button
                        type="button"
                        class="w-full py-2.5 rounded-xl bg-[#1a7a2e] text-white font-black text-base uppercase tracking-wide hover:bg-[#145f23] active:scale-95 transition focus:outline-none focus:ring-2 focus:ring-[#1a7a2e] focus:ring-offset-2"
                        data-testid="go-dialog-ok"
                        @click="handleGoOk"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <!-- Observer card-drawn notification (z-130, above rent notification) -->
    <CardDrawnNotification
        :visible="showCardDrawnNotification"
        :z-index="cardDrawnNotificationZIndex"
        :player-name="cardDrawnNotification?.playerName ?? 'Player'"
        :player-icon="cardDrawnNotification?.playerIcon ?? null"
        :card="cardDrawnNotification?.card ?? null"
        :type="cardDrawnNotification?.type ?? 'chance'"
        @close="handleCardDrawnNotificationClose"
    />

    <!-- Rent paid notification dialog (z-120, above GO dialog) -->
    <RentNotificationDialog
        :visible="showRentNotificationDialog"
        :z-index="rentNotificationZIndex"
        :payer-name="rentNotificationData?.payerName ?? 'Player'"
        :payer-icon="rentNotificationData?.payerIcon ?? null"
        :owner-name="rentNotificationData?.ownerName ?? 'Player'"
        :owner-icon="rentNotificationData?.ownerIcon ?? null"
        :rent-amount="rentNotificationData?.rentAmount ?? 0"
        :square-name="rentNotificationData?.squareName ?? ''"
        @close="handleRentNotificationClose"
    />

    <!-- Mortgaged property notification dialog (z-120, above GO dialog) -->
    <MortgagedPropertyDialog
        :visible="showMortgagedPropertyDialog"
        :z-index="mortgagedPropertyZIndex"
        :payer-name="mortgagedPropertyData?.payerName ?? 'Player'"
        :payer-icon="mortgagedPropertyData?.payerIcon ?? null"
        :owner-name="mortgagedPropertyData?.ownerName ?? 'Player'"
        :owner-icon="mortgagedPropertyData?.ownerIcon ?? null"
        :square-name="mortgagedPropertyData?.squareName ?? ''"
        @close="handleMortgagedPropertyNotificationClose"
    />

    <!-- Property purchase notification dialog (z-125, above rent and below card-drawn) -->
    <PropertyPurchasedNotificationDialog
        :visible="showPropertyPurchasedNotification"
        :z-index="propertyPurchasedNotificationZIndex"
        :buyer-name="propertyPurchasedNotification?.buyerName ?? 'Player'"
        :buyer-icon="propertyPurchasedNotification?.buyerIcon ?? null"
        :square-name="propertyPurchasedNotification?.squareName ?? ''"
        :purchase-price="propertyPurchasedNotification?.purchasePrice ?? 0"
        @close="handlePropertyPurchasedNotificationClose"
    />

    <!-- Board-level API error dialog -->
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
    >
        <div
            v-if="showErrorDialog"
            class="fixed inset-0 z-[260] flex items-center justify-center p-4"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="board-error-dialog-title"
            data-testid="board-error-dialog"
        >
            <div
                class="absolute inset-0 bg-black/60"
                aria-hidden="true"
                @click="handleErrorDialogClose"
            />

            <div class="relative z-10 w-full max-w-sm rounded-2xl bg-white shadow-2xl overflow-hidden">
                <div class="bg-[#8b1d1d] px-5 py-4">
                    <h2
                        id="board-error-dialog-title"
                        class="text-white font-black text-lg tracking-wide uppercase"
                    >Action Failed</h2>
                </div>

                <div class="px-5 py-4">
                    <p
                        class="text-sm text-gray-800 leading-relaxed"
                        data-testid="board-error-message"
                    >{{ errorMessage }}</p>
                </div>

                <div class="px-5 pb-5">
                    <button
                        type="button"
                        class="w-full py-2.5 rounded-xl bg-[#8b1d1d] text-white font-bold uppercase tracking-wide hover:bg-[#6f1717] transition"
                        data-testid="board-error-close"
                        @click="handleErrorDialogClose"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </Transition>
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
    width: auto;
    height: auto;
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
        width: auto;
        height: auto;
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
        width: auto;
        height: auto;
    }
}
</style>
