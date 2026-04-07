import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AppleLoginButton from '@/Components/AppleLoginButton.vue';

const globalMocks = {
    global: {
        mocks: {
            route: (name) => `/mocked/${name}`,
        },
    },
};

describe('AppleLoginButton', () => {
    it('renders the default label', () => {
        const wrapper = mount(AppleLoginButton, globalMocks);
        expect(wrapper.text()).toContain('Sign in with Apple');
    });

    it('renders a custom label when provided', () => {
        const wrapper = mount(AppleLoginButton, {
            ...globalMocks,
            props: { label: 'Sign up with Apple' },
        });
        expect(wrapper.text()).toContain('Sign up with Apple');
    });

    it('renders as an anchor element', () => {
        const wrapper = mount(AppleLoginButton, globalMocks);
        const link = wrapper.find('a');
        expect(link.exists()).toBe(true);
    });

    it('links to the Apple auth route', () => {
        const wrapper = mount(AppleLoginButton, globalMocks);
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('/mocked/auth.apple');
    });

    it('renders the Apple SVG icon', () => {
        const wrapper = mount(AppleLoginButton, globalMocks);
        const svg = wrapper.find('svg');
        expect(svg.exists()).toBe(true);
    });

    it('applies black background styling', () => {
        const wrapper = mount(AppleLoginButton, globalMocks);
        const link = wrapper.find('a');
        expect(link.classes()).toContain('bg-black');
        expect(link.classes()).toContain('text-white');
    });
});
