import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import CreateGameCard from '@/Components/CreateGameCard.vue';

describe('CreateGameCard', () => {
    it('renders the "Start a New Game" heading', () => {
        const wrapper = mount(CreateGameCard);
        expect(wrapper.text()).toContain('Start a New Game');
    });

    it('renders the "Create Game" button', () => {
        const wrapper = mount(CreateGameCard);
        const button = wrapper.find('button');
        expect(button.exists()).toBe(true);
        expect(button.text()).toContain('Create Game');
    });

    it('emits "create" when the Create Game button is clicked', async () => {
        const wrapper = mount(CreateGameCard);
        await wrapper.find('button').trigger('click');
        expect(wrapper.emitted('create')).toBeTruthy();
        expect(wrapper.emitted('create').length).toBe(1);
    });

    it('emits "create" when the card wrapper is clicked', async () => {
        const wrapper = mount(CreateGameCard);
        // The outermost div triggers the create emit
        await wrapper.find('div').trigger('click');
        expect(wrapper.emitted('create')).toBeTruthy();
    });

    it('renders the Monopoly board background elements', () => {
        const wrapper = mount(CreateGameCard);
        // Board corners should be present
        expect(wrapper.text()).toContain('GO');
        expect(wrapper.text()).toContain('JAIL');
        expect(wrapper.text()).toContain('Monopoly');
    });
});
