<template>
    <div class="build-operation-dialog">
    <div class="absolute inset-0 flex items-center justify-center p-4" :style="{ zIndex: 9999 }" role="dialog">
      <div class="absolute inset-0 bg-black/60" @click="$emit('close')" />
      <div class="relative z-10 w-full max-w-md rounded-2xl border bg-white p-4" :style="{ maxHeight: 'calc(100% - 2rem)', display: 'flex', flexDirection: 'column' }">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-3">
            <select
              v-model="selectedOperation"
              class="text-lg font-semibold rounded px-3 py-1 border"
              :style="{ minWidth: '10rem' }"
            >
              <option value="build">Build</option>
              <option v-if="props.isMyTurn" value="sale">Sale</option>
            </select>
          </div>
          <button @click="$emit('close')" class="text-sm text-gray-600">Close</button>
        </div>

        <div v-if="ruleMessage" class="mb-2 p-2 rounded bg-red-100 border border-red-400 text-red-800 font-semibold">{{ ruleMessage }}</div>

        <div style="display: flex; flex-direction: column; flex: 1; min-height: 0;">
          <div v-if="loading">Loading...</div>
          <div v-else class="flex-1 overflow-y-auto">
          <template v-if="groups.length === 0">
            <p>You do not own any complete colour groups to build on.</p>
          </template>

          <div class="space-y-3">
            <div v-for="group in groups" :key="group.name" class="border rounded p-2" :class="group.disabled ? 'opacity-50' : ''">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <div class="w-4 h-4 rounded-sm" :style="{ backgroundColor: group.color }"></div>
                  <div class="font-medium">{{ group.name }}</div>
                </div>
                <div class="text-sm text-red-600" v-if="group.message">{{ group.message }}</div>
              </div>

              <ul class="mt-2 space-y-2">
                <li v-for="prop in group.properties" :key="prop.square_index" class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: prop.color }" />
                    <div>
                      <div :class="prop.is_mortgaged ? 'text-gray-400' : 'font-medium'">{{ prop.name }}</div>
                      <div class="text-xs text-gray-500">Price: ${{ prop.purchase_price }}</div>
                    </div>
                    <div class="flex items-center gap-1">
                      <span v-for="n in prop.houses_count" :key="`h-${prop.square_index}-${n}`" class="text-sm">🏠</span>
                      <span v-if="prop.has_hotel" class="text-sm">🏨</span>
                    </div>
                    <div class="text-xs text-blue-600 mt-1" v-if="prop.pending_houses_delta || prop.pending_has_hotel">
                      <span class="font-medium">Pending:</span>
                      <span v-if="prop.pending_houses_delta"> +{{ prop.pending_houses_delta }} 🏠</span>
                      <span v-if="prop.pending_has_hotel"> <span class="ml-1">+🏨 (pending)</span></span>
                    </div>
                  </div>

                    <div class="flex gap-2">
                      <button v-if="prop.is_mortgaged" @click="unmortgage(prop)" class="btn btn-sm unmortgage-btn">Unmortgage</button>

                      <!-- House: show price above the icon, keep button element for tests -->
                      <div class="flex flex-col items-center">
                        <div class="text-xs text-gray-700 mb-1">
                          <span v-if="selectedOperation === 'build'">${{ buildPrice(prop) }}</span>
                          <span v-else>${{ salePrice(prop) }}</span>
                        </div>
                        <button
                          @click="selectedOperation === 'sale' ? sellHouse(prop) : buildHouse(prop)"
                          class="btn btn-sm"
                          :disabled="group.disabled || (selectedOperation === 'sale' ? (!props.isMyTurn || !canSellHouse(prop)) : !canBuildHouse(prop))"
                          :aria-disabled="String(group.disabled || (selectedOperation === 'sale' ? (!props.isMyTurn || !canSellHouse(prop)) : !canBuildHouse(prop)))"
                          :style="(group.disabled || (selectedOperation === 'sale' ? (!props.isMyTurn || !canSellHouse(prop)) : !canBuildHouse(prop))) ? { backgroundColor: '#e5e7eb', color: '#4b5563' } : {}"
                        >
                          <span class="text-sm">🏠</span>
                        </button>
                      </div>

                      <!-- Hotel: show price above the icon, keep button element for tests -->
                      <div class="flex flex-col items-center">
                        <div class="text-xs text-gray-700 mb-1">
                          <span v-if="selectedOperation === 'build'">${{ buildPrice(prop) }}</span>
                          <span v-else>${{ salePrice(prop) }}</span>
                        </div>
                        <button
                          @click="selectedOperation === 'sale' ? sellHotel(prop) : buildHotel(prop)"
                          class="btn btn-sm"
                          :disabled="group.disabled || (selectedOperation === 'sale' ? (!props.isMyTurn || !canSellHotel(prop)) : !canBuildHotel(prop))"
                          :aria-disabled="String(group.disabled || (selectedOperation === 'sale' ? (!props.isMyTurn || !canSellHotel(prop)) : !canBuildHotel(prop)))"
                          :style="(group.disabled || (selectedOperation === 'sale' ? (!props.isMyTurn || !canSellHotel(prop)) : !canBuildHotel(prop))) ? { backgroundColor: '#e5e7eb', color: '#4b5563' } : {}"
                        >
                          <span class="text-sm">🏨</span>
                        </button>
                      </div>
                    </div>
                </li>
              </ul>
            </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
  gameId: { type: [String, Number], required: true },
  invitationToken: { type: String, default: null },
  isMyTurn: { type: Boolean, default: false },
})

const loading = ref(true)
const properties = ref([])
const canBuild = ref(false)
const groups = ref([])
const ruleMessage = ref('')

// Selected operation mode for the dialog: 'build' or 'sale'
const selectedOperation = ref('build')
const headerLabel = computed(() => (selectedOperation.value === 'build' ? 'Build' : 'Sale'))

function baseApiPath() {
  return props.invitationToken ? `/api/join/${props.invitationToken}` : `/api/games/${props.gameId}`
}

async function fetchProperties() {
  loading.value = true
  try {
    const res = await axios.get(`${baseApiPath()}/properties/player`)
    properties.value = res.data.properties || []
    canBuild.value = properties.value.length > 0
    computeGroups()
  } finally {
    loading.value = false
  }
}

async function buildHouse(prop) {
  try {
    const price = Math.floor((prop.purchase_price || 0) / 2)
    const res = await axios.post(`${baseApiPath()}/property/build`, { square_index: prop.square_index, action: 'house', price_per_unit: price })
    if (res.data?.result?.success) {
      await fetchProperties()
    } else {
      ruleMessage.value = res.data?.message ?? 'Build failed.'
      console.warn('BuildOperation: buildHouse server rejected', res.data)
    }
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Failed to build house.'
    console.error('BuildOperation: buildHouse error', e)
  }
}

async function buildHotel(prop) {
  try {
    const price = Math.floor((prop.purchase_price || 0) / 2)
    const res = await axios.post(`${baseApiPath()}/property/build`, { square_index: prop.square_index, action: 'hotel', price_per_unit: price })
    if (res.data?.result?.success) {
      await fetchProperties()
    } else {
      ruleMessage.value = res.data?.message ?? 'Build failed.'
      console.warn('BuildOperation: buildHotel server rejected', res.data)
    }
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Failed to build hotel.'
    console.error('BuildOperation: buildHotel error', e)
  }
}

// SELL MODE HANDLERS
async function sellHouse(prop) {
  try {
    const res = await axios.post(`${baseApiPath()}/property/sell`, { square_index: prop.square_index, action: 'house' })
    if (res.data?.result?.success) {
      await fetchProperties()
    } else {
      ruleMessage.value = res.data?.message ?? 'Sale failed.'
      console.warn('BuildOperation: sellHouse server rejected', res.data)
    }
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Failed to sell house.'
    console.error('BuildOperation: sellHouse error', e)
  }
}

async function sellHotel(prop) {
  try {
    const res = await axios.post(`${baseApiPath()}/property/sell`, { square_index: prop.square_index, action: 'hotel' })
    if (res.data?.result?.success) {
      await fetchProperties()
    } else {
      ruleMessage.value = res.data?.message ?? 'Sale failed.'
      console.warn('BuildOperation: sellHotel server rejected', res.data)
    }
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Failed to sell hotel.'
    console.error('BuildOperation: sellHotel error', e)
  }
}

function canSellHotel(prop) {
  return Boolean(prop.has_hotel)
}

function canSellHouse(prop) {
  // Can't sell houses if there is a hotel
  if (prop.has_hotel) return false

  // Must have at least one committed house (ignore pending)
  const current = (prop.houses_count ?? 0)
  if (current <= 0) return false

  // Determine group's effective houses and ensure even-selling rule
  const group = groups.value.find(g => g.properties.some(p => Number(p.square_index) === Number(prop.square_index)))
  if (!group) return false

  const eff = group.properties.map(p => ({
    square: Number(p.square_index),
    houses: Number(p.houses_count ?? 0),
    hotel: Boolean(p.has_hotel),
  }))

  const target = eff.find(e => e.square === Number(prop.square_index))
  if (!target) return false

  // simulate removing one house
  const simulated = eff.map(e => ({ ...e }))
  const t = simulated.find(s => s.square === target.square)
  t.houses--
  const min = Math.min(...simulated.map(s => s.houses))
  const max = Math.max(...simulated.map(s => s.houses))
  if (max - min > 1) return false

  return true
}

async function unmortgage(prop) {
  try {
    await axios.post(`${baseApiPath()}/property/unmortgage`, { square_index: prop.square_index })
    await fetchProperties()
  } catch (e) {
    ruleMessage.value = e.response?.data?.message ?? 'Unmortgage failed.'
    console.error('BuildOperation: unmortgage error', e)
  }
}

function computeGroups() {
  // colour group mapping (square indices)
  const groupsMap = {
    brown: [1,3],
    light_blue: [6,8,9],
    pink: [11,13,14],
    orange: [16,18,19],
    red: [21,23,24],
    yellow: [26,27,29],
    green: [31,32,34],
    dark_blue: [37,39],
  }

  const colorName = {
    brown: 'Brown', light_blue: 'Light Blue', pink: 'Pink', orange: 'Orange', red: 'Red', yellow: 'Yellow', green: 'Green', dark_blue: 'Dark Blue'
  }

  const colorHex = {
    brown: '#955436', light_blue: '#aae0fa', pink: '#d93a96', orange: '#f7941d', red: '#ed1b24', yellow: '#fef200', green: '#1fb25a', dark_blue: '#0072bb'
  }

  // build quick lookup by square index
  const byIndex = new Map(properties.value.map(p => [p.square_index, p]))

  const out = []

  for (const [k, squares] of Object.entries(groupsMap)) {
    const propsInGroup = squares.map(idx => byIndex.get(idx)).filter(Boolean)
    if (propsInGroup.length !== squares.length) continue // not fully owned

    const anyMortgaged = propsInGroup.some(p => p.is_mortgaged)

    out.push({
      name: colorName[k],
      color: colorHex[k],
      properties: propsInGroup,
      disabled: anyMortgaged,
      message: anyMortgaged ? 'A property in this set is mortgaged' : '',
    })
  }

  groups.value = out
}

function canBuildHouse(prop) {
  // Find the colour group for this property
  const group = groups.value.find(g => g.properties.some(p => Number(p.square_index) === Number(prop.square_index)))
  if (!group || group.disabled) return false

  // Build effective state (include pending deltas)
  const eff = group.properties.map(p => ({
    square: Number(p.square_index),
    houses: Number(p.houses_count ?? 0) + Number(p.pending_houses_delta ?? 0),
    hotel: Boolean(p.has_hotel || p.pending_has_hotel),
  }))

  const target = eff.find(e => e.square === Number(prop.square_index))
  if (!target) return false

  // Disabled if target already has a hotel or already 4 houses
  if (target.hotel || target.houses >= 4) return false

  // Disabled if any property in the group already has a hotel
  if (eff.some(e => e.hotel)) return false

  // Simulate adding one house to target and enforce even-building rule
  const simulated = eff.map(e => ({ ...e }))
  const t = simulated.find(s => s.square === target.square)
  t.houses++
  const min = Math.min(...simulated.map(s => s.houses))
  const max = Math.max(...simulated.map(s => s.houses))
  if (max - min > 1) return false

  return true
}

function canBuildHotel(prop) {
  const group = groups.value.find(g => g.properties.some(p => Number(p.square_index) === Number(prop.square_index)))
  if (!group || group.disabled) return false

  const eff = group.properties.map(p => ({
    square: Number(p.square_index),
    houses: Number(p.houses_count ?? 0) + Number(p.pending_houses_delta ?? 0),
    hotel: Boolean(p.has_hotel || p.pending_has_hotel),
  }))

  const target = eff.find(e => e.square === Number(prop.square_index))
  if (!target) return false

  // Hotel disabled if already hotel
  if (target.hotel) return false

  // Hotel requires every property in the group to have at least 4 houses
  // Ignore squares that already have a hotel when enforcing the 4-house rule
  if (eff.some(e => !e.hotel && e.houses < 4)) return false

  return true
}

/**
 * Get the build price shown in the UI for a house/hotel on a property.
 * Uses the same formula as the build handlers (half of `purchase_price`).
 */
function buildPrice(prop) {
  return Math.floor((prop.purchase_price || 0) / 2)
}

/**
 * Get the sale price shown in the UI for selling a house/hotel on a property.
 * Sellers receive half the build cost (quarter of the property's purchase price).
 */
function salePrice(prop) {
  return Math.floor((prop.purchase_price || 0) / 4)
}

onMounted(fetchProperties)

// If the player loses the turn while the dialog is open, force back to Build mode
watch(() => props.isMyTurn, (val) => {
  if (!val && selectedOperation.value === 'sale') {
    selectedOperation.value = 'build'
  }
})
</script>

<style scoped>
.btn { background: #1f2937; color: #fff; padding: 0.25rem 0.5rem; border-radius: 4px }
.unmortgage-btn { background: #000 !important; color: #fff }
</style>
