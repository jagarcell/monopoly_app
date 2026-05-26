import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import MortgagedPropertyDialog from '@/Components/MortgagedPropertyDialog.vue';

describe('MortgagedPropertyDialog', () => {
    it('renders when visible is true', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Property Mortgaged');
        expect(wrapper.text()).toContain('Boardwalk');
    });

    it('does not render when visible is false', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: false,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    });

    it('displays payer name', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        expect(wrapper.text()).toContain('Alice');
    });

    it('displays owner name with owner label', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        expect(wrapper.text()).toContain('Bob');
        expect(wrapper.text()).toContain('Owner');
    });

    it('displays square name', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Park Place',
            },
        });

        expect(wrapper.text()).toContain('Park Place');
    });

    it('displays payer icon when provided', () => {
        const payerIcon = {
            image_url: '/alice.svg',
            name: 'Top Hat',
        };

        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                payerIcon,
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        const images = wrapper.findAll('img');
        const payerImg = images.find((img) => img.attributes('alt') === 'Top Hat');
        expect(payerImg).toBeDefined();
        expect(payerImg?.attributes('src')).toBe('/alice.svg');
    });

    it('displays owner icon when provided', () => {
        const ownerIcon = {
            image_url: '/bob.svg',
            name: 'Car',
        };

        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                ownerIcon,
                squareName: 'Boardwalk',
            },
        });

        const images = wrapper.findAll('img');
        const ownerImg = images.find((img) => img.attributes('alt') === 'Car');
        expect(ownerImg).toBeDefined();
        expect(ownerImg?.attributes('src')).toBe('/bob.svg');
    });

    it('displays both player icons side by side', () => {
        const payerIcon = {
            image_url: '/alice.svg',
            name: 'Top Hat',
        };
        const ownerIcon = {
            image_url: '/bob.svg',
            name: 'Car',
        };

        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                payerIcon,
                ownerName: 'Bob',
                ownerIcon,
                squareName: 'Boardwalk',
            },
        });

        const images = wrapper.findAll('img');
        expect(images.length).toBeGreaterThanOrEqual(2);
    });

    it('displays message that no rent is due', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        expect(wrapper.text()).toContain('No rent due');
        expect(wrapper.text()).toContain('mortgaged');
    });

    it('emits close event when OK button is clicked', async () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        const okButton = wrapper.find('button');
        await okButton.trigger('click');

        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('accepts custom z-index prop', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
                zIndex: 999,
                payerName: 'Alice',
                ownerName: 'Bob',
                squareName: 'Boardwalk',
            },
        });

        const dialog = wrapper.find('[role="alertdialog"]');
        expect(dialog.attributes('style')).toContain('z-index');
    });

    it('renders with default values when optional props are omitted', () => {
        const wrapper = mount(MortgagedPropertyDialog, {
            props: {
                visible: true,
            },
        });

        expect(wrapper.find('[role="alertdialog"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Player');
    });
});
