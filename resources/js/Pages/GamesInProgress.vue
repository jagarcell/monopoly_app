<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

const props = defineProps({
    games: {
        type: Array,
        default: () => [],
    },
});

function resumeGame(gameId) {
    router.visit(`/games/${gameId}`);
}
</script>

<template>
    <Head title="Games in Progress" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Games in Progress
            </h2>
        </template>

        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div v-if="games.length === 0" class="grid grid-cols-1 lg:grid-cols-3">
                    <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center shadow-sm lg:col-span-3">
                        <p class="text-lg font-medium text-gray-700">No games in progress</p>
                        <p class="mt-2 text-sm text-gray-500">Create a new game from the dashboard to get started.</p>
                    </div>
                </div>

                <div v-else class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <button
                        v-for="game in games"
                        :key="game.id"
                        type="button"
                        class="group rounded-xl border border-gray-200 bg-white p-5 text-left shadow-sm transition hover:border-indigo-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        @click="resumeGame(game.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium uppercase tracking-wide text-indigo-600">Resume</p>
                                <h3 class="mt-2 text-xl font-semibold text-gray-900">{{ game.name }}</h3>
                            </div>
                            <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                {{ game.player_count }}/{{ game.max_players }} players
                            </span>
                        </div>

                        <div class="mt-5 space-y-2 text-sm text-gray-600">
                            <p>Last updated: {{ game.updated_at ? new Date(game.updated_at).toLocaleString() : 'Recently' }}</p>
                        </div>

                        <div class="mt-5 flex items-center justify-between text-sm font-medium text-indigo-600">
                            <span>Open game board</span>
                            <span aria-hidden="true">→</span>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
