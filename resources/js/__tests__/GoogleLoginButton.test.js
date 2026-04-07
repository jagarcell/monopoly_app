import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import GoogleLoginButton from '@/Components/GoogleLoginButton.vue';

const globalMocks = {
    global: {
        mocks: {
            route: (name) => `/mocked/${name}`,
        },
    },
};

describe('GoogleLoginButton', () => {
    it('renders the default label', () => {
        const wrapper = mount(GoogleLoginButton, globalMocks);
        expect(wrapper.text()).toContain('Sign in with Google');
    });

    it('renders a custom label when provided', () => {
        const wrapper = mount(GoogleLoginButton, {
            ...globalMocks,
            props: { label: 'Sign up with Google' },
        });
        expect(wrapper.text()).toContain('Sign up with Google');
    });

    it('renders as an anchor element', () => {
        const wrapper = mount(GoogleLoginButton, globalMocks);
        const link = wrapper.find('a');
        expect(link.exists()).toBe(true);
    });

    it('links to the Google auth route', () => {
        const wrapper = mount(GoogleLoginButton, globalMocks);
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('/mocked/auth.google');
    });

    it('renders the Google SVG icon', () => {
        const wrapper = mount(GoogleLoginButton, globalMocks);
        const svg = wrapper.find('svg');
        expect(svg.exists()).toBe(true);
        expect(svg.findAll('path').length).toBe(4);
    });
});
