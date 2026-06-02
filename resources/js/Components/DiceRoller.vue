<script setup>
/**
 * DiceRoller
 *
 * Displays two animated dice in the top-right corner of the board's centre
 * panel. Each die renders faces 1–6 as SVG pip layouts.
 *
 * Props:
 *   isMyTurn        – controls whether the Roll button is rendered. When false the
 *                     button is hidden and a "Waiting…" label is shown instead.
 *   displayDie1 / displayDie2 – server-authoritative face values supplied by
 *                     the parent after the roll API returns. When a roll animation
 *                     is in progress the values are buffered and snapped in at the
 *                     end of the animation; when no animation is running they are
 *                     applied immediately.
 *   externalTrigger – a monotonic counter incremented by the parent each time a
 *                     remote player rolls (via the DiceRolled WebSocket event). A
 *                     change in value kicks off the same shake animation on every
 *                     connected board so all players see the dice move in real-time.
 *
 * Emits:
 *   roll-requested – fired when the player clicks Roll. The parent is
 *                    responsible for calling the API and updating displayDie1 /
 *                    displayDie2 with the server response.
 *
 * Logic:
 *   Clicking "Roll" triggers a 700 ms shake animation where both dice rapidly
 *   cycle through random faces every 80 ms. After the animation settles the
 *   final face values are snapped to the server-provided displayDie1 /
 *   displayDie2 (if already available) or kept as the last random values.
 *   Incoming prop changes are buffered while rolling and applied once the
 *   animation ends. All sizing uses cqw units so the component scales
 *   proportionally within the board's container-query context.
 */
import { computed, ref, watch } from 'vue';

const props = defineProps({    /**
     * Whether this client's player is the active roller. The Roll button is
     * only rendered when true; a waiting label is shown when false.
     */
    isMyTurn: {
        type: Boolean,
        default: true,
    },
    /**
     * Whether board debug mode is enabled.
     */
    debugMode: {
        type: Boolean,
        default: false,
    },
    /**
     * Server-authoritative face value for die 1 after a roll. Null until the
     * API responds. Applied immediately when not rolling; buffered until the
     * animation ends when rolling.
     */
    displayDie1: {
        type: Number,
        default: null,
    },
    /**
     * Server-authoritative face value for die 2 after a roll. Null until the
     * API responds. Applied immediately when not rolling; buffered until the
     * animation ends when rolling.
     */
    displayDie2: {
        type: Number,
        default: null,
    },
    /**
     * Monotonic counter incremented by the parent whenever a remote player's
     * DiceRolled WebSocket event arrives. A change from 0 to any positive value
     * (or any subsequent increment) triggers the shake animation on this board
     * without emitting roll-requested. The value 0 is reserved for initial mount
     * and does not start the animation.
     */
    externalTrigger: {
        type: Number,
        default: 0,
    },
    /**
    * Whether the active player has already rolled this turn. Seeded from the
    * server's turn_phase on page load so a hard refresh keeps the Roll button
    * hidden until the turn advances away from this player.
     * Defaults to false (fresh turn: Roll button shown).
     */
    initialHasRolled: {
        type: Boolean,
        default: false,
    },
    /**
     * External flag to force this turn into a post-roll state.
     * Used by debug click-to-move flows that consume the turn without pressing Roll.
     */
    forceHasRolled: {
        type: Boolean,
        default: false,
    },
    /**
     * Monotonic signal from the parent to reset hasRolled for the current turn.
     * Used when turn advancement is rejected and the player must roll again.
     */
    resetHasRolledSignal: {
        type: Number,
        default: 0,
    },
    /**
     * Token image URL for the player whose turn is currently active.
     * Rendered in the waiting label for off-turn viewers.
     */
    waitingForTokenImageUrl: {
        type: String,
        default: null,
    },
    /**
     * Token name used for waiting-label image alt text.
     */
    waitingForTokenName: {
        type: String,
        default: 'Active player',
    },
});

const emit = defineEmits(['roll-requested', 'roll-settled']);

/**
 * SVG pip (cx, cy) coordinates for each die face value.
 * All coordinates are within a 60×60 SVG viewport.
 *
 * @type {Record<number, Array<[number, number]>>}
 */
const PIP_POSITIONS = {
    1: [[30, 30]],
    2: [[42, 18], [18, 42]],
    3: [[42, 18], [30, 30], [18, 42]],
    4: [[18, 18], [42, 18], [18, 42], [42, 42]],
    5: [[18, 18], [42, 18], [30, 30], [18, 42], [42, 42]],
    6: [[18, 15], [42, 15], [18, 30], [42, 30], [18, 45], [42, 45]],
};

/** Current face value of die 1 (1–6). Seeded from displayDie1 on mount so a
 * page refresh restores the dice to the most recently rolled values. @type {import('vue').Ref<number>} */
const die1 = ref(props.displayDie1 ?? 1);

/** Current face value of die 2 (1–6). Seeded from displayDie2 on mount so a
 * page refresh restores the dice to the most recently rolled values. @type {import('vue').Ref<number>} */
const die2 = ref(props.displayDie2 ?? 1);

/** Whether a roll animation is currently in progress. @type {import('vue').Ref<boolean>} */
const rolling = ref(false);

/**
 * Whether the current player has already rolled this turn. When true the Roll
 * button is hidden until the turn advances away from this player. Seeded from
 * initialHasRolled so a page refresh preserves that state for an in-flight turn.
 * Reset to false whenever isMyTurn transitions to false (the turn passed to
 * another player).
 * @type {import('vue').Ref<boolean>}
 */
const hasRolled = ref(props.initialHasRolled);

/**
 * Server values received while an animation is in progress. Applied once the
 * animation completes so the dice settle on the authoritative result.
 * @type {import('vue').Ref<number|null>}
 */
const pendingDie1 = ref(null);

/**
 * @type {import('vue').Ref<number|null>}
 */
const pendingDie2 = ref(null);

/**
 * Whether parent requested a reroll reset while dice were still animating.
 * @type {import('vue').Ref<boolean>}
 */
const resetAfterCurrentRoll = ref(false);

/**
 * Sum of both dice.
 * @returns {number}
 */
// Computed total reads from die1/die2 directly
// (imported from vue but total is used inline in template)

/**
 * Watch for incoming server-authoritative die values.
 *
 * Logic: When rolling is in progress, buffer the incoming value so it is
 * snapped in at animation end. When not rolling, apply it immediately so
 * the board updates in real time when another player's roll arrives via
 * the WebSocket event.
 */
watch(() => props.displayDie1, (val) => {
    if (val === null) return;
    if (rolling.value) {
        pendingDie1.value = val;
    } else {
        die1.value = val;
    }
});

watch(() => props.displayDie2, (val) => {
    if (val === null) return;
    if (rolling.value) {
        pendingDie2.value = val;
    } else {
        die2.value = val;
    }
});

/**
 * Watch for an externally triggered roll animation (i.e. another player rolled).
 *
 * Logic: Fires whenever externalTrigger changes. Skips the initial value of 0
 * (mount) and skips when a local roll animation is already running so the two
 * animations never overlap. Clears any pending server buffers, sets rolling=true,
 * then runs the same 700 ms randomisation interval as the local roll path. At
 * animation end, snaps to displayDie1/displayDie2 if they are already present
 * (they arrive via the same DiceRolled event that incremented externalTrigger),
 * or to any buffered pending values that arrived mid-animation.
 */
watch(() => props.externalTrigger, (val) => {
    if (val === 0 || rolling.value) return;

    rolling.value     = true;
    pendingDie1.value = null;
    pendingDie2.value = null;

    const start    = Date.now();
    const duration = 700;

    const interval = setInterval(() => {
        die1.value = randomFace();
        die2.value = randomFace();

        if (Date.now() - start >= duration) {
            clearInterval(interval);

            if (pendingDie1.value !== null)     die1.value = pendingDie1.value;
            else if (props.displayDie1 !== null) die1.value = props.displayDie1;

            if (pendingDie2.value !== null)     die2.value = pendingDie2.value;
            else if (props.displayDie2 !== null) die2.value = props.displayDie2;

            pendingDie1.value = null;
            pendingDie2.value = null;
            rolling.value     = false;
            emit('roll-settled');
        }
    }, 80);
});

/**
 * Returns a random integer between 1 and 6 inclusive.
 *
 * @returns {number}
 */
function randomFace() {
    return Math.floor(Math.random() * 6) + 1;
}

/**
 * Triggers the dice roll animation and emits roll-requested.
 *
 * Logic: Guards against concurrent rolls with `rolling` and against off-turn
 * clicks with `isMyTurn`. Clears any buffered pending values, starts a
 * setInterval that randomises both dice faces every 80 ms for 700 ms total.
 * When the animation ends, snaps to the buffered server values if they have
 * arrived, otherwise keeps the last random values. Emits `roll-requested`
 * immediately so the parent can fire the API call in parallel with the
 * animation.
 *
 * @returns {void}
 */
function roll() {
    if (rolling.value || !props.isMyTurn) return;
    rolling.value    = true;
    pendingDie1.value = null;
    pendingDie2.value = null;

    emit('roll-requested');

    const start    = Date.now();
    const duration = 700;

    const interval = setInterval(() => {
        die1.value = randomFace();
        die2.value = randomFace();

        if (Date.now() - start >= duration) {
            clearInterval(interval);

            if (pendingDie1.value !== null) die1.value = pendingDie1.value;
            if (pendingDie2.value !== null) die2.value = pendingDie2.value;
            pendingDie1.value = null;
            pendingDie2.value = null;
            rolling.value     = false;
            hasRolled.value   = resetAfterCurrentRoll.value
                ? false
                : props.isMyTurn;
            resetAfterCurrentRoll.value = false;
            emit('roll-settled');
        }
    }, 80);
}

/**
 * Reset hasRolled when the turn passes to another player.
 *
 * Logic: Watches isMyTurn and clears hasRolled whenever it transitions to
 * false so that when this player's turn comes around again the Roll button
 * is shown fresh.
 */
watch(() => props.isMyTurn, (val) => {
    if (!val) {
        hasRolled.value = false;
        resetAfterCurrentRoll.value = false;
    }
});

/**
 * Force the local rolled state when instructed by the parent.
 */
watch(() => props.forceHasRolled, (val) => {
    if (val) {
        hasRolled.value = true;
    }
});

/**
 * Reset rolled state when parent requests a reroll within the same turn.
 */
watch(() => props.resetHasRolledSignal, () => {
    if (!props.isMyTurn) {
        return;
    }

    if (rolling.value) {
        resetAfterCurrentRoll.value = true;
    } else {
        hasRolled.value = false;
    }
});

/**
 * Whether waiting label can render the active player's token image.
 *
 * @returns {boolean}
 */
const hasWaitingToken = computed(
    () => Boolean(props.waitingForTokenImageUrl),
);

/**
 * Waiting token alt text shown when this client is off-turn.
 *
 * @returns {string}
 */
const waitingTokenAlt = computed(() => `${props.waitingForTokenName} token`);

</script>

<template>
    <div
        class="dice-roller"
        aria-label="Dice roller"
        data-testid="dice-roller"
    >
        <!-- Dice row -->
        <div class="dice-row">
            <!-- Die 1 -->
            <div
                :class="['die', { rolling }]"
                :data-die-value="die1"
                aria-label="Die 1"
                data-testid="die-1"
            >
                <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg" class="die-svg">
                    <rect x="2" y="2" width="56" height="56" rx="9" ry="9" fill="white" stroke="#444" stroke-width="2.5"/>
                    <circle
                        v-for="([cx, cy], i) in PIP_POSITIONS[die1]"
                        :key="i"
                        :cx="cx"
                        :cy="cy"
                        r="5.5"
                        fill="#1a1a1a"
                    />
                </svg>
            </div>

            <!-- Die 2 -->
            <div
                :class="['die', { rolling }]"
                :data-die-value="die2"
                aria-label="Die 2"
                data-testid="die-2"
            >
                <svg viewBox="0 0 60 60" xmlns="http://www.w3.org/2000/svg" class="die-svg">
                    <rect x="2" y="2" width="56" height="56" rx="9" ry="9" fill="white" stroke="#444" stroke-width="2.5"/>
                    <circle
                        v-for="([cx, cy], i) in PIP_POSITIONS[die2]"
                        :key="i"
                        :cx="cx"
                        :cy="cy"
                        r="5.5"
                        fill="#1a1a1a"
                    />
                </svg>
            </div>
        </div>

        <!-- Total -->
        <div
            class="dice-total"
            data-testid="dice-total"
        >
            {{ die1 + die2 }}
        </div>

        <!-- Roll button — only rendered when it is this player's turn and they
             have not yet rolled this turn -->
        <button
            v-if="isMyTurn && !hasRolled"
            type="button"
            class="roll-btn"
            :class="{ rolling }"
            :disabled="rolling"
            aria-label="Roll dice"
            data-testid="roll-button"
            @click="roll"
        >
            Roll
        </button>

        <!-- Debug indicator: shown only to the player in turn, below Roll -->
        <span
            v-if="debugMode && isMyTurn"
            class="debug-mode-indicator"
            data-testid="debug-mode-indicator"
        >
            Debug Mode: Click Any Square To Move
        </span>

        <!-- Waiting label — shown for all other players -->
        <span
            v-else-if="!isMyTurn"
            class="waiting-label"
            data-testid="waiting-label"
        >
            <template v-if="hasWaitingToken">
                waiting for
                <img
                    :src="waitingForTokenImageUrl"
                    :alt="waitingTokenAlt"
                    class="waiting-token"
                    data-testid="waiting-token-image"
                >
                ...
            </template>
            <template v-else>
                waiting...
            </template>
        </span>
    </div>
</template>

<style scoped>
.dice-roller {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.6cqw;
}

.dice-row {
    display: flex;
    flex-direction: row;
    align-items: center;
    gap: 0.8cqw;
}

.die {
    width: clamp(0.9rem, 7cqw, 2.8rem);
    height: clamp(0.9rem, 7cqw, 2.8rem);
    filter: drop-shadow(0 1px 3px rgba(0, 0, 0, 0.45));
    flex-shrink: 0;
}

.die-svg {
    width: 100%;
    height: 100%;
    display: block;
}

.die.rolling {
    animation: diceShake 0.12s ease-in-out infinite;
}

.dice-total {
    font-weight: 800;
    color: #1a7a2e;
    line-height: 1;
    font-size: clamp(0.28rem, 2.2cqw, 0.85rem);
}

.roll-btn {
    font-weight: 800;
    color: #fff;
    background: #1a7a2e;
    border-radius: 0.45rem;
    border: none;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-size: clamp(0.18rem, 1.4cqw, 0.52rem);
    padding: 0.32cqw 0.7cqw;
    line-height: 1.2;
    cursor: pointer;
    transition: background 0.15s, opacity 0.15s;
}

.roll-btn:hover:not(:disabled) {
    background: #155c23;
}

.roll-btn.rolling {
    opacity: 0.5;
    cursor: not-allowed;
}

.debug-mode-indicator {
    margin-top: 0.1cqw;
    padding: 0.12cqw 0.45cqw;
    border: 1px solid #92400e;
    border-radius: 0.35rem;
    background: rgba(254, 243, 199, 0.95);
    color: #78350f;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    font-size: clamp(0.16rem, 1.2cqw, 0.48rem);
    line-height: 1.2;
}

.waiting-label {
    display: inline-flex;
    align-items: center;
    gap: 0.22cqw;
    font-weight: 600;
    color: #777;
    font-size: clamp(0.18rem, 1.4cqw, 0.55rem);
    letter-spacing: 0.03em;
}

.waiting-token {
    width: clamp(0.32rem, 2.4cqw, 0.9rem);
    height: clamp(0.32rem, 2.4cqw, 0.9rem);
    object-fit: contain;
}

@keyframes diceShake {
    0%   { transform: rotate(-9deg) scale(1.1); }
    25%  { transform: rotate(9deg)  scale(0.95); }
    50%  { transform: rotate(-7deg) scale(1.07); }
    75%  { transform: rotate(7deg)  scale(0.97); }
    100% { transform: rotate(-9deg) scale(1.1); }
}
</style>
