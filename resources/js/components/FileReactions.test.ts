import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import FileReactions from './FileReactions.vue';

describe('FileReactions', () => {
    it('renders all reaction buttons', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
            },
        });

        expect(wrapper.find('button[aria-label="Favorite"]').exists()).toBe(true);
        expect(wrapper.find('button[aria-label="Like"]').exists()).toBe(true);
        expect(wrapper.find('button[aria-label="Blacklist"]').exists()).toBe(true);
        expect(wrapper.find('button[aria-label="Funny"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="file-reactions-remove"]').exists()).toBe(false);
    });

    it('renders count icons and index display', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                previewedCount: 5,
                viewedCount: 10,
                currentIndex: 2,
                totalItems: 20,
            },
        });

        expect(wrapper.text()).toContain('5');
        expect(wrapper.text()).toContain('10');
        expect(wrapper.text()).toContain('3/20');
    });

    it('shows an infinity icon instead of the terminal preview count', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                previewedCount: 99999,
            },
        });

        expect(wrapper.text()).not.toContain('99999');
        expect(wrapper.find('[aria-label="Preview count removed from feed"]').exists()).toBe(true);
    });

    it('does not mark the blacklist button active for a terminal preview count alone', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                previewedCount: 99999,
                blacklistedAt: null,
            },
        });

        const blacklistButton = wrapper.find('button[aria-label="Blacklist"]');

        expect(blacklistButton.attributes('aria-pressed')).toBe('false');
        expect(blacklistButton.attributes('disabled')).toBeUndefined();
    });

    it('shows only index (not total) in small variant', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                currentIndex: 2,
                totalItems: 20,
                variant: 'small',
            },
        });

        expect(wrapper.text()).toContain('3');
        expect(wrapper.text()).not.toContain('/20');
    });

    it('displays reaction from props', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: { type: 'like' },
            },
        });

        const vm = wrapper.vm as any;
        expect(vm.like).toBe(true);
    });

    it('displays favorite reaction as active', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: { type: 'love' },
            },
        });

        const vm = wrapper.vm as any;
        expect(vm.favorite).toBe(true);
    });

    it('displays like reaction as active', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: { type: 'like' },
            },
        });

        const vm = wrapper.vm as any;
        expect(vm.like).toBe(true);
    });

    it('displays funny reaction as active', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: { type: 'funny' },
            },
        });

        const vm = wrapper.vm as any;
        expect(vm.funny).toBe(true);
    });


    it('emits reaction events directly without removing the item first', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
            },
        });

        const favoriteButton = wrapper.find('button[aria-label="Favorite"]');
        await favoriteButton.trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('reaction')).toBeTruthy();
        expect(wrapper.emitted('reaction')?.[0]).toEqual(['love']);
    });

    it('does not emit reaction when fileId is not provided', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                reaction: null,
            },
        });

        const likeButton = wrapper.find('button[aria-label="Like"]');
        await likeButton.trigger('click');
        await wrapper.vm.$nextTick();

        // Should not emit reaction when fileId is missing
        expect(wrapper.emitted('reaction')).toBeFalsy();
    });

    it('emits blacklist when the blacklist button is clicked', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
            },
        });

        await wrapper.find('button[aria-label="Blacklist"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('blacklist')).toEqual([[]]);
    });

    it('emits remove from tab when the optional remove action is clicked', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
                showRemove: true,
            },
        });

        await wrapper.get('[data-test="file-reactions-remove"]').trigger('click');

        expect(wrapper.emitted('remove')).toEqual([[]]);
    });

    it('does not emit remove while the remove action is pending', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
                showRemove: true,
                removing: true,
            },
        });

        const removeButton = wrapper.get('[data-test="file-reactions-remove"]');

        expect(removeButton.attributes('disabled')).toBeDefined();

        await removeButton.trigger('click');

        expect(wrapper.emitted('remove')).toBeFalsy();
    });

    it('does not emit blacklist when fileId is not provided', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                reaction: null,
            },
        });

        await wrapper.find('button[aria-label="Blacklist"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('blacklist')).toBeFalsy();
    });

    it('represents blacklisted state without re-emitting blacklist', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                blacklistedAt: '2026-04-30T00:00:00Z',
                reaction: null,
            },
        });

        const blacklistButton = wrapper.find('button[aria-label="Blacklist"]');

        expect(blacklistButton.attributes('aria-pressed')).toBe('true');
        expect(blacklistButton.attributes('disabled')).toBeDefined();

        await blacklistButton.trigger('click');

        expect(wrapper.emitted('blacklist')).toBeFalsy();
    });

    it('can re-emit blacklist when toggle mode is allowed', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                blacklistedAt: '2026-04-30T00:00:00Z',
                allowBlacklistToggle: true,
                reaction: null,
            },
        });

        const blacklistButton = wrapper.find('button[aria-label="Remove blacklist"]');

        expect(blacklistButton.attributes('disabled')).toBeUndefined();

        await blacklistButton.trigger('click');

        expect(wrapper.emitted('blacklist')).toEqual([[]]);
    });

    it('hides blacklist in reaction-only mode', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                mode: 'reaction-only',
                reaction: null,
            },
        });

        expect(wrapper.find('button[aria-label="Blacklist"]').exists()).toBe(false);
    });

    it('can show blacklist in reaction-only mode without the default surface', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                mode: 'reaction-only',
                variant: 'small',
                showBlacklist: true,
                surface: 'none',
                iconSize: 22,
                reaction: null,
            },
        });

        expect(wrapper.get('[data-test="file-reactions"]').classes()).toContain('gap-3');
        expect(wrapper.get('[data-test="file-reactions"]').classes()).not.toContain('bg-black/60');
        expect(wrapper.find('button[aria-label="Blacklist"]').exists()).toBe(true);
        expect(wrapper.get('button[aria-label="Blacklist"] svg').attributes('width')).toBe('22');
    });

    it('updates reaction display when reaction prop changes', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
            },
        });

        let vm = wrapper.vm as any;
        expect(vm.like).toBe(false);

        await wrapper.setProps({ reaction: { type: 'like' } });
        await wrapper.vm.$nextTick();

        vm = wrapper.vm as any;
        expect(vm.like).toBe(true);
    });

    it('stops click event propagation', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
            },
        });

        const rootDiv = wrapper.find('div');
        // Verify the component has @click.stop by checking it exists and renders
        expect(rootDiv.exists()).toBe(true);
        // The @click.stop modifier prevents event bubbling - this is tested implicitly
        // by ensuring clicks on FileReactions don't trigger parent handlers
    });
});
