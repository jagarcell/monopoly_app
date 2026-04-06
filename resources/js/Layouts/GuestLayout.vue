<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-gray-100 pt-6 sm:justify-center sm:pt-0"
    >
        <div class="flex flex-col items-center">
            <Link href="/">
                <ApplicationLogo class="h-24 w-24 fill-current text-gray-800" />
            </Link>
            <span class="mt-1 text-2xl font-bold tracking-[0.25em] text-gray-800 sm:text-3xl">MONOPOLY</span>
        </div>

        <div
            class="mt-6 w-full overflow-hidden bg-white px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>
    </div>
</template>
