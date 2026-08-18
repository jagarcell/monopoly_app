<script setup>
const props = defineProps({
    debugMode: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['dismiss']);
</script>

<template>
    <button
        v-if="props.debugMode"
        type="button"
        class="ml-auto inline-flex shrink-0 items-center rounded-full border border-red-200 bg-red-100 px-2 py-1 text-[10px] font-black uppercase tracking-[0.18em] text-red-700 shadow-sm transition hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2"
        data-testid="debug-dismiss-badge"
        aria-label="Dismiss dialog in debug mode"
        @click.stop.prevent="emit('dismiss')"
    >
        DEBUG CLOSE
    </button>
</template>
