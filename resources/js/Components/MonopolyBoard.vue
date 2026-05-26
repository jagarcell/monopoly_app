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
import BoardSquare from '@/Components/BoardSquare.vue';
import CardDrawnNotification from '@/Components/CardDrawnNotification.vue';
import CardRevealModal from '@/Components/CardRevealModal.vue';
import DiceRoller from '@/Components/DiceRoller.vue';
import PendingInvitationsList from '@/Components/PendingInvitationsList.vue';
import PlayerHandCard from '@/Components/PlayerHandCard.vue';
import MortgageOptionsDialog from '@/Components/MortgageOptionsDialog.vue';
import MortgagedPropertyDialog from '@/Components/MortgagedPropertyDialog.vue';
import PropertyPurchasedNotificationDialog from '@/Components/PropertyPurchasedNotificationDialog.vue';
import RentNotificationDialog from '@/Components/RentNotificationDialog.vue';
import SquareActionModal from '@/Components/SquareActionModal.vue';

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
 * refresh or back-navigation). Merge incoming data with existing local state while
 * preserving updates from real-time broadcasts (CardDrawn, RentPaid, TokenMoved, etc.)
 * so that real-time updates are not lost when the parent component refreshes data.
 *
 * Logic: For each incoming player, find the matching local player by join_order.
 * If found, merge incoming fields while preserving capital and square_index
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
                    // Player exists locally — merge, but preserve capital and square_index
                    // since they were updated by real-time broadcasts and are more current
                    // than the incoming props from a potentially stale HTTP response.
                    const mergedProperties = mergePlayerProperties(
                        existing.properties ?? [],
                        incomingPlayer.properties ?? [],
                    );

                    return {
                        ...incomingPlayer,
                        capital: existing.capital,
                        square_index: existing.square_index,
                        properties: mergedProperties,
                    };
                }
                // New player — accept all incoming data.
                return incomingPlayer;
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
 * @type {import('vue').Ref<{joinOrder: number, fromIdx: number, toIdx: number}|null>}
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
                // Seed token positions for any newly joined player that does not
                // already have an entry (preserves in-flight animation positions).
                // Use direct property mutation to avoid replacing the entire reactive
                // proxy, which would drop computed dependency tracking mid-animation.
                for (const p of event.players) {
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
            if (movingJoinOrderValue !== undefined && event.square_index !== undefined
                && movingJoinOrderValue !== myJoinOrder.value) {
                const fromIdx = tokenPositions.value[movingJoinOrderValue] ?? 0;
                animateTokenMovement(movingJoinOrderValue, fromIdx, event.square_index, 200, event.backward ?? false);
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
function animateTokenMovement(joinOrder, fromIdx, toIdx, stepMs = 200, backward = false) {
    return new Promise((resolve) => {
        const totalSteps = backward
            ? ((fromIdx - toIdx) + 40) % 40
            : ((toIdx - fromIdx) + 40) % 40;

        if (totalSteps === 0) {
            resolve();
            return;
        }

        movingJoinOrder.value = joinOrder;
        let stepsCompleted = 0;

        const interval = setInterval(() => {
            const current = tokenPositions.value[joinOrder] ?? fromIdx;
            // Direct property mutation is more reliable in Vue 3 than replacing the
            // entire ref value — it mutates through the existing Proxy set trap so
            // all dependents (e.g. squarePlayers computed) are correctly notified.
            tokenPositions.value[joinOrder] = backward
                ? (current - 1 + 40) % 40
                : (current + 1) % 40;
            stepsCompleted++;

            if (stepsCompleted >= totalSteps) {
                clearInterval(interval);
                // Final snap: ensure the token is exactly at toIdx after the last
                // step regardless of any floating-point or rounding edge cases.
                tokenPositions.value[joinOrder] = toIdx;
                movingJoinOrder.value = null;
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
 * On failure, logs the error so the animation still settles gracefully.
 *
 * @returns {Promise<void>}
 */
async function handleRollRequested() {
    localDiceSettled.value = false;
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
        const res = await window.axios.post(url);
        currentDie1.value = res.data.die1;
        currentDie2.value = res.data.die2;

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
            const fromIdx = tokenPositions.value[myJoinOrder.value] ?? 0;
            if (localDiceSettled.value) {
                // Dice finished before the API responded — notify other boards the
                // token is starting to move, then animate locally.
                await notifyTokenMoved();
                await animateTokenMovement(myJoinOrder.value, fromIdx, res.data.square_index);
                showPostMoveDialogs();
            } else {
                // Dice still shaking — buffer the move for when roll-settled fires.
                pendingLocalMove.value = { joinOrder: myJoinOrder.value, fromIdx, toIdx: res.data.square_index };
            }
        }
        // current_turn_join_order is unchanged after rolling — only updated
        // when the player clicks Done (via the TurnAdvanced broadcast).
    } catch (err) {
        console.error('Failed to roll dice', err);
    }
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
        return;
    }

    const targetSquareIndex = BOARD_SQUARES.indexOf(square);

    if (targetSquareIndex < 0) {
        return;
    }

    const fromIdx = tokenPositions.value[myJoinOrder.value] ?? 0;

    if (fromIdx === targetSquareIndex) {
        return;
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
            if (localDiceSettled.value) {
                await notifyTokenMoved();
                await animateTokenMovement(myJoinOrder.value, fromIdx, res.data.square_index);
                showPostMoveDialogs();
            } else {
                pendingLocalMove.value = { joinOrder: myJoinOrder.value, fromIdx, toIdx: res.data.square_index };
            }
        }
    } catch (err) {
        console.error('Failed to move token by debug square click', err);
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
        const { joinOrder, fromIdx, toIdx } = pendingLocalMove.value;
        pendingLocalMove.value = null;
        // Notify other boards first so they begin animating in sync with the
        // local animation that is about to start.
        await notifyTokenMoved();
        await animateTokenMovement(joinOrder, fromIdx, toIdx);
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
 * @returns {Promise<void>}
 */
async function notifyTokenMoved(backward = false) {
    try {
        const url = props.invitationToken
            ? `/api/join/${props.invitationToken}/token-moved`
            : `/api/games/${props.game.id}/token-moved`;
        await window.axios.post(url, { backward });
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

/**
 * The card effect descriptor buffered from the roll API response.
 * Consumed by handleCardModalClose() to apply capital and movement changes
 * after the player dismisses the card reveal modal.
 *
 * @type {import('vue').Ref<object|null>}
 */
const pendingCardEffect = ref(null);

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
    showCardModal.value = false;

    // Consume the buffered card effect and apply all state changes before
    // notifying observers so the local board reflects the final state first.
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
            await animateTokenMovement(myJoinOrder.value, fromIdx, effect.new_square_index, 200, isBackward);
            await notifyTokenMoved(isBackward);
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

/** Properties available for mortgage selection. */
const mortgageProperties = ref([]);

/** Selected property squares for the active mortgage payment session. */
const mortgageSessionSelectedSquareIndexes = ref([]);

/** Active payment session metadata for mortgage planning. */
const mortgageSession = ref(null);

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
        return 'Pay now';
    }

    if (mortgageSession.value.actionType === 'card') {
        return `Pay $${mortgageSession.value.requiredAmount}`;
    }

    return mortgageSession.value.actionType === 'purchase'
        ? `Buy for $${mortgageSession.value.requiredAmount}`
        : `Pay $${mortgageSession.value.requiredAmount}`;
});

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
        if (action.type === 'chance' || action.type === 'community') {
            appendHeldCardToPlayer(myJoinOrder.value, action.type, action.card);
            drawnCard.value         = action.card;
            drawnCardType.value     = action.type;
            pendingCardEffect.value = action.effect ?? null;
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
 * @param {'purchase'|'rent'|'card'} actionType
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

    if (!selectedProperty || selectedProperty.is_mortgaged || isMortgageActionInFlight.value) {
        return;
    }

    const nextSet = new Set(mortgageSessionSelectedSquareIndexes.value.map(Number));

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
        if (mortgageSession.value.actionType === 'purchase') {
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
    mortgageSession.value = null;
    mortgageSessionSelectedSquareIndexes.value = [];
}

/** Close the mortgage options dialog and clear its local state. */
function handleMortgageOptionsClose() {
    if (mortgageSession.value?.actionType === 'card') {
        return;
    }

    closeMortgageSessionDialog();
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
            card_payment_amount: Number(pendingCardPayment.value.required_amount ?? pendingCardPayment.value.amount ?? 0),
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
        localPlayers.value = localPlayers.value.map((p, i) =>
            i === idx ? { ...p, capital: nextCapital } : p,
        );
    }
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
            isHighlighted: (
                expandedCardJoinOrder.value !== null
                && expandedCardJoinOrder.value === Number(player.join_order)
            ) || (
                hoveredDiceJoinOrder.value !== null
                && hoveredDiceJoinOrder.value === Number(player.join_order)
            ),
        });
    }
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
                    panel-anchor="start"
                    @expanded-change="handlePlayerCardExpandedChange"
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
                                        @roll-requested="handleRollRequested"
                                        @roll-settled="handleRollSettled"
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
                class="player-panel flex flex-col items-center py-2 px-2 gap-2 order-last overflow-visible"
                aria-label="Right player panel"
            >
                <PlayerHandCard
                    v-for="player in rightPanelPlayers"
                    :key="player.join_order"
                    :player="player"
                    :is-current-player="isCurrentPlayer(player)"
                    panel-anchor="end"
                    @expanded-change="handlePlayerCardExpandedChange"
                />
            </div>

        </div>
    </div>

    <!-- Card reveal animation overlay -->
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
        :is-loading="isMortgagePropertiesLoading"
        :is-submitting="isMortgageActionInFlight"
        :z-index="210"
        @toggle-property="handleToggleMortgageSessionProperty"
        @submit-payment="handleMortgageSessionSubmitPayment"
        @close="handleMortgageOptionsClose"
    />

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
