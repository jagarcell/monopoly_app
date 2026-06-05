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
})
