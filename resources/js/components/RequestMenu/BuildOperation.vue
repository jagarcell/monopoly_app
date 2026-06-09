<template>
  <div class="build-operation">
    <h3 class="text-lg font-semibold">Build</h3>
    <div v-if="loading">Loading...</div>
    <div v-else>
      <p v-if="!canBuild">You cannot build on any properties right now.</p>
      <ul v-else>
        <li v-for="prop in properties" :key="prop.square_index" class="py-1">
          <div class="flex items-center justify-between">
            <div>
              <div class="font-medium">{{ prop.name }}</div>
              <div class="text-sm text-gray-500">Houses: {{ prop.houses_count }} Hotel: {{ prop.has_hotel ? 'Yes' : 'No' }}</div>
              <div class="text-xs text-blue-600" v-if="prop.pending_houses_delta || prop.pending_has_hotel">Pending:
                <span v-if="prop.pending_houses_delta"> +{{ prop.pending_houses_delta }} houses</span>
                <span v-if="prop.pending_has_hotel"> +hotel</span>
              </div>
            </div>
            <div class="flex gap-2">
              <button @click="buildHouse(prop)" class="btn btn-sm">Build House</button>
              <button @click="buildHotel(prop)" class="btn btn-sm" :disabled="prop.has_hotel">Build Hotel</button>
            </div>
          </div>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({ gameId: { type: [String, Number], required: true } })

const loading = ref(true)
const properties = ref([])
const canBuild = ref(false)
const ruleMessage = ref('')

async function fetchProperties() {
  loading.value = true
  try {
    const res = await axios.get(`/api/games/${props.gameId}/properties/player`)
    properties.value = res.data.properties || []
    canBuild.value = properties.value.length > 0
  } finally {
    loading.value = false
  }
}

async function buildHouse(prop) {
  try {
    const res = await axios.post(`/api/games/${props.gameId}/property/build`, { square_index: prop.square_index, action: 'house' })
    if (res.data?.result?.success) {
      await fetchProperties()
    } else {
      ruleMessage.value = res.data?.message ?? 'Build failed.'
      console.warn('build-operation (lowercase): buildHouse server rejected', res.data)
    }
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Failed to build house.'
    console.error('build-operation (lowercase): buildHouse error', e)
  }
}

async function buildHotel(prop) {
  try {
    const res = await axios.post(`/api/games/${props.gameId}/property/build`, { square_index: prop.square_index, action: 'hotel' })
    if (res.data?.result?.success) {
      await fetchProperties()
    } else {
      ruleMessage.value = res.data?.message ?? 'Build failed.'
      console.warn('build-operation (lowercase): buildHotel server rejected', res.data)
    }
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Failed to build hotel.'
    console.error('build-operation (lowercase): buildHotel error', e)
  }
}

onMounted(fetchProperties)
</script>

<style scoped>
.build-operation { padding: 0.5rem }
.btn { background: #1f2937; color: #fff; padding: 0.25rem 0.5rem; border-radius: 4px }
.btn:disabled { opacity: 0.5 }
</style>
