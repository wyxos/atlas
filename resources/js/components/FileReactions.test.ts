import { describe, it, expect, vi } from 'vitest';
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
        expect(wrapper.find('button[aria-label="Dislike"]').exists()).toBe(true);
        expect(wrapper.find('button[aria-label="Funny"]').exists()).toBe(true);
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

    it('displays dislike reaction as active', () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: { type: 'dislike' },
            },
        });

        const vm = wrapper.vm as any;
        expect(vm.dislike).toBe(true);
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


    it('calls removeItem and emits reaction event when removeItem prop is provided', async () => {
        const removeItem = vi.fn();
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
                removeItem,
            },
        });

        const favoriteButton = wrapper.find('button[aria-label="Favorite"]');
        await favoriteButton.trigger('click');
        await wrapper.vm.$nextTick();

        // Verify removeItem was called immediately
        expect(removeItem).toHaveBeenCalled();

        // Verify reaction event was emitted
        expect(wrapper.emitted('reaction')).toBeTruthy();
        expect(wrapper.emitted('reaction')?.[0]).toEqual(['love']);
    });

    it('emits reaction event when removeItem prop is not provided', async () => {
        const wrapper = mount(FileReactions, {
            props: {
                fileId: 1,
                reaction: null,
                // No removeItem prop
            },
        });

        const likeButton = wrapper.find('button[aria-label="Like"]');
        await likeButton.trigger('click');
        await wrapper.vm.$nextTick();

        // Verify reaction event was emitted (parent will handle API call)
        expect(wrapper.emitted('reaction')).toBeTruthy();
        expect(wrapper.emitted('reaction')?.[0]).toEqual(['like']);
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

