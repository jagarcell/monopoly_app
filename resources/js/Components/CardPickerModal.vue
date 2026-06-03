<script setup>
import { ref } from 'vue';

const props = defineProps({
    cards: {
        type: Array,
        default: () => [],
    },
    type: {
        type: String,
        default: 'chance',
    },
    visible: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close', 'pick']);

function handlePick(card) {
    emit('pick', card);
}
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="visible" class="fixed inset-0 z-[210] flex items-center justify-center bg-black/60">
                <div class="bg-white rounded-lg shadow-xl max-w-xl w-full p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="font-bold">{{ type === 'chance' ? 'Chance Deck' : 'Community Chest Deck' }}</h3>
                        <button @click="emit('close')" class="text-sm text-gray-500">Close</button>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[60vh] overflow-auto">
                        <button v-for="card in cards" :key="card.id" @click="handlePick(card)"
                            class="text-left p-3 rounded border hover:shadow active:scale-95 transition-transform bg-gray-50">
                            <div class="font-semibold">{{ card.text }}</div>
                            <div class="text-xs text-gray-600">Action: {{ card.action }}</div>
                        </button>
                    </div>

                    <div class="mt-4 text-sm text-gray-600">Click a card to emulate drawing it; it will be moved to the bottom after execution.</div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
