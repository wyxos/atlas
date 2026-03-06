import { computed, nextTick, ref, watch, toRefs, type Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';

export function useFileViewerReactionFlow(params: {
    items: Ref<FeedItem[]>;
    navigation: {
        currentItemIndex: number | null;
    };
    overlay: {
        fillComplete: boolean;
    };
    ensureMoreItems: () => Promise<boolean>;
    closeOverlay: () => void;
    navigateToIndex: (index: number, dir?: 'up' | 'down') => void | Promise<void>;
    emitReaction: (fileId: number, type: ReactionType) => void;
}) {
    const { currentItemIndex } = toRefs(params.navigation);
    const { fillComplete } = toRefs(params.overlay);

    const currentItem = computed(() => {
        const index = currentItemIndex.value;

        if (index === null || index < 0 || index >= params.items.value.length) {
            return null;
        }

        return params.items.value[index] ?? null;
    });

    const currentItemId = ref<number | null>(null);

    async function reactAndAdvance(type: ReactionType): Promise<void> {
        const item = currentItem.value;
        if (!item) {
            return;
        }

        params.emitReaction(item.id, type);
        await nextTick();

        if (params.items.value.length === 0) {
            await params.ensureMoreItems();

            if (params.items.value.length === 0) {
                params.closeOverlay();
                return;
            }
        }

        const previousIndex = currentItemIndex.value;
        const currentIndexInList = params.items.value.findIndex((candidate) => candidate.id === item.id);
        let targetIndex: number | null = null;

        if (currentIndexInList === -1) {
            if (previousIndex !== null) {
                targetIndex = Math.min(previousIndex, params.items.value.length - 1);
            }
        } else {
            const nextIndex = currentIndexInList + 1;

            if (nextIndex < params.items.value.length) {
                targetIndex = nextIndex;
            }
        }

        if (targetIndex === null) {
            await params.ensureMoreItems();

            if (params.items.value.length === 0) {
                params.closeOverlay();
                return;
            }

            targetIndex = Math.min(previousIndex ?? 0, params.items.value.length - 1);
        }

        currentItemIndex.value = targetIndex;
        currentItemId.value = params.items.value[targetIndex]?.id ?? null;
        void params.navigateToIndex(targetIndex, 'down');
    }

    watch(
        () => [currentItemIndex.value, fillComplete.value] as const,
        ([newIndex, isFilled]: readonly [number | null, boolean]) => {
            if (newIndex === null || !isFilled) {
                return;
            }

            if (params.items.value.length - 1 - newIndex <= 1) {
                void params.ensureMoreItems();
            }
        },
    );

    watch(currentItemIndex, (index) => {
        if (index === null || index < 0 || index >= params.items.value.length) {
            currentItemId.value = null;
            return;
        }

        currentItemId.value = params.items.value[index]?.id ?? null;
    });

    watch(
        () => params.items.value.map((item) => item.id),
        () => {
            if (currentItemId.value === null) {
                const index = currentItemIndex.value;

                if (index !== null && index >= 0 && index < params.items.value.length) {
                    currentItemId.value = params.items.value[index]?.id ?? null;
                }

                return;
            }

            const nextIndex = params.items.value.findIndex((item) => item.id === currentItemId.value);

            if (nextIndex !== -1 && nextIndex !== currentItemIndex.value) {
                currentItemIndex.value = nextIndex;
            }
        },
    );

    return {
        currentItem,
        currentItemId,
        reactAndAdvance,
    };
}
