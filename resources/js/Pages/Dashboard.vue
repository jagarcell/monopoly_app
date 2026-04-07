<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreateGameCard from '@/Components/CreateGameCard.vue';
import { Head } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

/**
 * Redirects the user to the game creation flow.
 * Logic: Uses Inertia router to visit the create-game route.
 */
function handleCreateGame() {
    router.visit(route('games.create'));
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h2>
        </template>

        <div class="py-8 sm:py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <CreateGameCard @create="handleCreateGame" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
