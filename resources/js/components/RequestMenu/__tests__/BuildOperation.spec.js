import { mount } from '@vue/test-utils'
import { describe, it, expect, vi } from 'vitest'
import BuildOperation from '@/Components/RequestMenu/BuildOperation.vue'
import axios from 'axios'

vi.mock('axios')

describe('BuildOperation', () => {
  it('renders and shows loading state', async () => {
    axios.get.mockResolvedValue({ data: { properties: [] } })
    const wrapper = mount(BuildOperation, { props: { gameId: 1 } })
    // initial render shows loading or empty state
    expect(wrapper.text()).toMatch(/Loading|You cannot build/)
    await wrapper.vm.$nextTick()
  })

  it('enables hotel button when pending houses make all group have 4 houses', async () => {
    // light blue group: squares 6,8,9
    axios.get.mockResolvedValue({ data: {
      properties: [
        { square_index: 6, name: 'Oriental Ave', color: '#aae0fa', houses_count: 4, has_hotel: false, pending_houses_delta: 0, pending_has_hotel: false, is_mortgaged: false, purchase_price: 100 },
        { square_index: 8, name: 'Vermont Ave', color: '#aae0fa', houses_count: 3, has_hotel: false, pending_houses_delta: 1, pending_has_hotel: false, is_mortgaged: false, purchase_price: 100 },
        { square_index: 9, name: 'Connecticut Ave', color: '#aae0fa', houses_count: 4, has_hotel: false, pending_houses_delta: 0, pending_has_hotel: false, is_mortgaged: false, purchase_price: 120 },
      ]
    } })

    const wrapper = mount(BuildOperation, { props: { gameId: 1 } })
    await wrapper.vm.$nextTick()
    await new Promise(r => setTimeout(r, 0))

    // find the property card for square 8 and its hotel button
    const hotelBtn = wrapper.findAll('button').find(b => b.text().includes('🏨') && b.attributes('disabled') === undefined)
    expect(hotelBtn).toBeTruthy()
  })

  it('disables house button when any property in the group has a pending hotel', async () => {
    // brown group: squares 1,3
    axios.get.mockResolvedValue({ data: {
      properties: [
        { square_index: 1, name: 'Mediterranean Ave', color: '#955436', houses_count: 0, has_hotel: false, pending_houses_delta: 0, pending_has_hotel: true, is_mortgaged: false, purchase_price: 60 },
        { square_index: 3, name: 'Baltic Ave', color: '#955436', houses_count: 0, has_hotel: false, pending_houses_delta: 0, pending_has_hotel: false, is_mortgaged: false, purchase_price: 60 },
      ]
    } })

    const wrapper = mount(BuildOperation, { props: { gameId: 1 } })
    await wrapper.vm.$nextTick()
    await new Promise(r => setTimeout(r, 0))

    // house buttons should be disabled due to a pending hotel in the group
    const houseBtns = wrapper.findAll('button').filter(b => b.text().includes('🏠'))
    expect(houseBtns.length).toBeGreaterThan(0)
    houseBtns.forEach(b => expect(b.attributes('disabled')).toBeDefined())
  })
})
