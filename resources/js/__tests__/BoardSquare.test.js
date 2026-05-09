import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import BoardSquare from '@/Components/BoardSquare.vue';

const propertySquare = {
    name: 'Boardwalk',
    type: 'property',
    color: '#0072bb',
    price: 400,
};

const cornerSquare = {
    name: 'GO',
    type: 'go',
};

const railroadSquare = {
    name: 'Reading Railroad',
    type: 'railroad',
};

const chanceSquare = {
    name: 'Chance',
    type: 'chance',
};

describe('BoardSquare', () => {
    it('renders a corner square with its name', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: cornerSquare, orientation: 'corner' },
        });
        expect(wrapper.text()).toContain('GO');
    });

    it('renders a property square name and price', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom' },
        });
        expect(wrapper.text()).toContain('Boardwalk');
        expect(wrapper.text()).toContain('400');
    });

    it('renders the colour band for property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom' },
        });
        const band = wrapper.find('[style*="background-color"]');
        expect(band.exists()).toBe(true);
    });

    it('renders railroad icon for railroad squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: railroadSquare, orientation: 'bottom' },
        });
        expect(wrapper.text()).toContain('🚂');
    });

    it('does not show a price for non-property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: { name: 'Free Parking', type: 'free' }, orientation: 'corner' },
        });
        expect(wrapper.text()).not.toContain('$');
    });

    it('applies vertical writing mode for left-edge squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'left' },
        });
        // writing-mode is now applied directly to the name span (no wrapper div).
        const nameSpan = wrapper.findAll('span').find(s => s.text().includes('Boardwalk'));
        expect(nameSpan?.classes().join(' ')).toContain('writing-mode');
        expect(nameSpan?.text()).toContain('Boardwalk');
    });

    it('applies justify-between to the text body for bottom property squares with a price', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom' },
        });
        const textBody = wrapper.findAll('div').find(d =>
            d.classes().includes('justify-between'),
        );
        expect(textBody?.exists()).toBe(true);
    });

    it('applies rotate-180 and justify-between to the text body for top property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'top' },
        });
        const textBody = wrapper.findAll('div').find(d =>
            d.classes().includes('rotate-180') && d.classes().includes('justify-between'),
        );
        expect(textBody?.exists()).toBe(true);
    });

    it('places the name span with order-last and no rotate-180 for left-edge property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'left' },
        });
        const nameSpan = wrapper.findAll('span').find(s => s.text().includes('Boardwalk'));
        expect(nameSpan?.classes()).toContain('order-last');
        expect(nameSpan?.classes()).not.toContain('rotate-180');
    });

    it('does not apply order-last to the name span for right-edge property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'right' },
        });
        const nameSpan = wrapper.findAll('span').find(s => s.text().includes('Boardwalk'));
        expect(nameSpan?.classes()).not.toContain('order-last');
    });

    it('applies rotate-180 to the name span for right-edge property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'right' },
        });
        const nameSpan = wrapper.findAll('span').find(s => s.text().includes('Boardwalk'));
        expect(nameSpan?.classes()).toContain('rotate-180');
    });

    it('rotates the icon span for top-row chance squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: chanceSquare, orientation: 'top' },
        });
        const iconSpan = wrapper.findAll('span').find(s => s.text() === '?');
        expect(iconSpan?.classes()).toContain('rotate-180');
    });

    it('applies sideways-rl writing-mode to icon span for left-edge chance squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: chanceSquare, orientation: 'left' },
        });
        const iconSpan = wrapper.findAll('span').find(s => s.text() === '?');
        expect(iconSpan?.classes().join(' ')).toContain('sideways-rl');
        expect(iconSpan?.classes()).not.toContain('rotate-180');
    });

    it('applies sideways-lr writing-mode to icon span for right-edge chance squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: chanceSquare, orientation: 'right' },
        });
        const iconSpan = wrapper.findAll('span').find(s => s.text() === '?');
        expect(iconSpan?.classes().join(' ')).toContain('sideways-lr');
        expect(iconSpan?.classes()).not.toContain('rotate-180');
    });

    it('does not rotate the icon span for bottom-row chance squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: chanceSquare, orientation: 'bottom' },
        });
        const iconSpan = wrapper.findAll('span').find(s => s.text() === '?');
        expect(iconSpan?.classes()).not.toContain('rotate-180');
        expect(iconSpan?.classes().join(' ')).not.toContain('writing-mode');
    });

    it('applies justify-start (not justify-center) to the text body for bottom chance squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: chanceSquare, orientation: 'bottom' },
        });
        const textBody = wrapper.findAll('div').find(d =>
            d.classes().includes('flex-1'),
        );
        expect(textBody?.classes()).toContain('justify-start');
        expect(textBody?.classes()).not.toContain('justify-center');
    });

    it('applies justify-end to the text body for left-edge priceless squares (e.g. Community Chest)', () => {
        const communitySquare = { name: 'Community Chest', type: 'community' };
        const wrapper = mount(BoardSquare, {
            props: { square: communitySquare, orientation: 'left' },
        });
        const textBody = wrapper.findAll('div').find(d =>
            d.classes().includes('flex-1'),
        );
        expect(textBody?.classes()).toContain('justify-end');
        expect(textBody?.classes()).not.toContain('justify-between');
    });

    it('renders a police image for the Go To Jail corner square', () => {
        const goToJailSquare = { name: 'Go To Jail', type: 'gotojail' };
        const wrapper = mount(BoardSquare, {
            props: { square: goToJailSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="Police officer"]');
        expect(img.exists()).toBe(true);
        // Vite may inline the SVG as a data URL in the test environment;
        // assert only that a non-empty src is present.
        expect(img.attributes('src')).toBeTruthy();
    });

    it('does not render a police image for non-gotojail corner squares', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="Police officer"]');
        expect(img.exists()).toBe(false);
    });

    it('renders IN JAIL, JUST, and VISITING text for the jail corner square', () => {
        const jailSquare = { name: 'Jail / Just Visiting', type: 'jail' };
        const wrapper = mount(BoardSquare, {
            props: { square: jailSquare, orientation: 'corner' },
        });
        expect(wrapper.text()).toContain('IN JAIL');
        expect(wrapper.text()).toContain('JUST');
        expect(wrapper.text()).toContain('VISITING');
    });

    it('renders jail bars image for the jail corner square', () => {
        const jailSquare = { name: 'Jail / Just Visiting', type: 'jail' };
        const wrapper = mount(BoardSquare, {
            props: { square: jailSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="Jail bars"]');
        expect(img.exists()).toBe(true);
        // Vite may inline the SVG as a data URL in the test environment;
        // assert only that a non-empty src is present.
        expect(img.attributes('src')).toBeTruthy();
    });

    it('does not render jail bars for non-jail corner squares', () => {
        const freeSquare = { name: 'Free Parking', type: 'free' };
        const wrapper = mount(BoardSquare, {
            props: { square: freeSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="Jail bars"]');
        expect(img.exists()).toBe(false);
    });

    it('renders a car image for the Free Parking corner square', () => {
        const freeSquare = { name: 'Free Parking', type: 'free' };
        const wrapper = mount(BoardSquare, {
            props: { square: freeSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="Car"]');
        expect(img.exists()).toBe(true);
        // Vite may inline the SVG as a data URL in the test environment;
        // assert only that a non-empty src is present.
        expect(img.attributes('src')).toBeTruthy();
    });

    it('does not render police image for the jail corner square', () => {
        const jailSquare = { name: 'Jail / Just Visiting', type: 'jail' };
        const wrapper = mount(BoardSquare, {
            props: { square: jailSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="Police officer"]');
        expect(img.exists()).toBe(false);
    });

    it('renders GO arrow image for the GO corner square', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="GO arrow"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBeTruthy();
    });

    it('renders COLLECT and $200 SALARY text for the GO corner square', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner' },
        });
        expect(wrapper.text()).toContain('COLLECT');
        expect(wrapper.text()).toContain('$200 SALARY');
        expect(wrapper.text()).toContain('GO');
    });

    it('does not render GO arrow for non-go corner squares', () => {
        const jailSquare = { name: 'Jail / Just Visiting', type: 'jail' };
        const wrapper = mount(BoardSquare, {
            props: { square: jailSquare, orientation: 'corner' },
        });
        const img = wrapper.find('img[alt="GO arrow"]');
        expect(img.exists()).toBe(false);
    });

    it('renders player token images in the GO corner square when playerTokens is provided', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const playerTokens = [
            { user_id: 1, name: 'Alice', icon: { image_url: '/images/icons/top-hat.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner', playerTokens },
        });
        const img = wrapper.find('[data-testid="player-token-1"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('/images/icons/top-hat.svg');
        expect(img.attributes('alt')).toBe('Alice');
    });

    it('renders one token per player when multiple playerTokens are provided', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const playerTokens = [
            { user_id: 1, name: 'Alice', icon: { image_url: '/images/icons/top-hat.svg' } },
            { user_id: 2, name: 'Bob',   icon: { image_url: '/images/icons/car.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner', playerTokens },
        });
        expect(wrapper.find('[data-testid="player-token-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="player-token-2"]').exists()).toBe(true);
    });

    it('does not render the player-tokens container when playerTokens is empty', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner', playerTokens: [] },
        });
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(false);
    });

    it('does not render player tokens on non-GO corner squares even when playerTokens is provided', () => {
        const freeSquare = { name: 'Free Parking', type: 'free' };
        const playerTokens = [
            { user_id: 1, name: 'Alice', icon: { image_url: '/images/icons/top-hat.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: freeSquare, orientation: 'corner', playerTokens },
        });
        expect(wrapper.find('[data-testid="go-player-tokens"]').exists()).toBe(false);
    });

    it('does not apply rotate-180 to the price span for left-edge property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'left' },
        });
        const priceSpan = wrapper.findAll('span').find(s => s.text().includes('400'));
        expect(priceSpan?.classes()).not.toContain('rotate-180');
    });

    it('applies rotate-180 to the price span for right-edge property squares', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'right' },
        });
        const priceSpan = wrapper.findAll('span').find(s => s.text().includes('400'));
        expect(priceSpan?.classes()).toContain('rotate-180');
    });

    // ── Edge square token rendering ───────────────────────────────────────────

    it('renders player token images on an edge square when playerTokens is provided', () => {
        const playerTokens = [
            { user_id: 5, name: 'Carlos', icon: { image_url: '/images/icons/car.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom', playerTokens },
        });
        const container = wrapper.find('[data-testid="edge-player-tokens"]');
        expect(container.exists()).toBe(true);
        const img = wrapper.find('[data-testid="player-token-5"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('/images/icons/car.svg');
        expect(img.attributes('alt')).toBe('Carlos');
    });

    it('does not render edge-player-tokens container when playerTokens is empty on an edge square', () => {
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom', playerTokens: [] },
        });
        expect(wrapper.find('[data-testid="edge-player-tokens"]').exists()).toBe(false);
    });

    it('renders multiple tokens on an edge square', () => {
        const playerTokens = [
            { user_id: 1, name: 'Alice', icon: { image_url: '/images/icons/top-hat.svg' } },
            { user_id: 2, name: 'Bob',   icon: { image_url: '/images/icons/car.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom', playerTokens },
        });
        expect(wrapper.find('[data-testid="player-token-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="player-token-2"]').exists()).toBe(true);
    });

    it('applies animate-bounce classes to an animating token on an edge square', () => {
        const playerTokens = [
            { user_id: 7, name: 'Diana', icon: { image_url: '/images/icons/hat.svg' }, isAnimating: true },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom', playerTokens },
        });
        const img = wrapper.find('[data-testid="player-token-7"]');
        expect(img.classes()).toContain('animate-bounce');
        expect(img.classes()).toContain('ring-2');
        expect(img.classes()).toContain('ring-yellow-400');
        expect(img.classes()).toContain('scale-125');
    });

    it('does not apply animate-bounce classes when isAnimating is false on an edge square', () => {
        const playerTokens = [
            { user_id: 8, name: 'Eve', icon: { image_url: '/images/icons/hat.svg' }, isAnimating: false },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom', playerTokens },
        });
        const img = wrapper.find('[data-testid="player-token-8"]');
        expect(img.classes()).not.toContain('animate-bounce');
    });

    // ── Non-GO corner square token rendering ─────────────────────────────────

    it('renders player tokens on a non-GO corner square (corner-player-tokens)', () => {
        const jailSquare = { name: 'Jail / Just Visiting', type: 'jail' };
        const playerTokens = [
            { user_id: 3, name: 'Frank', icon: { image_url: '/images/icons/iron.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: jailSquare, orientation: 'corner', playerTokens },
        });
        const container = wrapper.find('[data-testid="corner-player-tokens"]');
        expect(container.exists()).toBe(true);
        const img = wrapper.find('[data-testid="player-token-3"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('/images/icons/iron.svg');
    });

    it('does not render corner-player-tokens when playerTokens is empty on a non-GO corner', () => {
        const jailSquare = { name: 'Jail / Just Visiting', type: 'jail' };
        const wrapper = mount(BoardSquare, {
            props: { square: jailSquare, orientation: 'corner', playerTokens: [] },
        });
        expect(wrapper.find('[data-testid="corner-player-tokens"]').exists()).toBe(false);
    });

    it('does not render corner-player-tokens on a GO corner square', () => {
        const goSquare = { name: 'GO', type: 'go' };
        const playerTokens = [
            { user_id: 1, name: 'Alice', icon: { image_url: '/images/icons/top-hat.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: goSquare, orientation: 'corner', playerTokens },
        });
        expect(wrapper.find('[data-testid="corner-player-tokens"]').exists()).toBe(false);
    });

    it('applies animate-bounce classes to an animating token on a non-GO corner square', () => {
        const freeSquare = { name: 'Free Parking', type: 'free' };
        const playerTokens = [
            { user_id: 9, name: 'Grace', icon: { image_url: '/images/icons/thimble.svg' }, isAnimating: true },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: freeSquare, orientation: 'corner', playerTokens },
        });
        const img = wrapper.find('[data-testid="player-token-9"]');
        expect(img.classes()).toContain('animate-bounce');
        expect(img.classes()).toContain('ring-yellow-400');
    });

    it('uses invitation_id as token key when user_id is null', () => {
        const playerTokens = [
            { user_id: null, invitation_id: 42, name: 'Guest', icon: { image_url: '/images/icons/car.svg' } },
        ];
        const wrapper = mount(BoardSquare, {
            props: { square: propertySquare, orientation: 'bottom', playerTokens },
        });
        const img = wrapper.find('[data-testid="player-token-42"]');
        expect(img.exists()).toBe(true);
        expect(img.attributes('alt')).toBe('Guest');
    });
});
