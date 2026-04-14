<script setup>
/**
 * PlayerHandCard
 *
 * Renders a single player's hand panel displayed in the free side space
 * outside the Monopoly board. Shows the player's icon image, display name,
 * a creator badge when applicable, and three labelled empty sections for the
 * properties, chance cards, and community chest cards the player holds during
 * the game.
 *
 * Props:
 *   player – {
 *     user_id:               number|null,
 *     name:                  string,
 *     is_creator:            boolean,
 *     icon:                  { id: number, name: string, image_url: string },
 *     properties:            Array,
 *     chance_cards:          Array,
 *     community_chest_cards: Array,
 *   }
 */
defineProps({
    player: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div
        class="w-full h-auto lg:h-[25%] rounded-xl border border-amber-300 bg-amber-50 shadow-md flex flex-col overflow-hidden shrink-0"
        style="container-type: inline-size;"
        :aria-label="`${player.name}'s hand`"
        data-testid="player-hand-card"
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

        <!-- Card sections: Properties / Chance / Community -->
        <div class="flex-1 flex flex-col divide-y divide-amber-100 min-h-0 overflow-hidden">
            <div class="flex-1 flex items-center px-3 gap-2 min-h-0 overflow-hidden">
                <span class="font-bold text-amber-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Properties
                </span>
                <span class="text-amber-300 leading-none" style="font-size: clamp(0.4rem, 2cqw, 0.65rem);">—</span>
            </div>
            <div class="flex-1 flex items-center px-3 gap-2 min-h-0 overflow-hidden">
                <span class="font-bold text-amber-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Chance
                </span>
                <span class="text-amber-300 leading-none" style="font-size: clamp(0.4rem, 2cqw, 0.65rem);">—</span>
            </div>
            <div class="flex-1 flex items-center px-3 gap-2 min-h-0 overflow-hidden">
                <span class="font-bold text-amber-700 uppercase tracking-wider shrink-0" style="font-size: clamp(0.5rem, 2.5cqw, 0.78rem);">
                    Community
                </span>
                <span class="text-amber-300 leading-none" style="font-size: clamp(0.4rem, 2cqw, 0.65rem);">—</span>
            </div>
        </div>
    </div>
</template>
