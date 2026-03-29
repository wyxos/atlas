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
    navigateToItem: (itemId: number, dir?: 'up' | 'down') => void | Promise<void>;
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

        const previousIndex = currentItemIndex.value;
        const preferredNextItemId = previousIndex === null
            ? null
            : params.items.value[previousIndex + 1]?.id ?? null;

        params.emitReaction(item.id, type);
        await nextTick();

        if (params.items.value.length === 0) {
            await params.ensureMoreItems();

            if (params.items.value.length === 0) {
                params.closeOverlay();
                return;
            }
        }

        const resolveTargetItemId = (): number | null => {
            if (preferredNextItemId !== null) {
                const preferredItem = params.items.value.find((candidate) => candidate.id === preferredNextItemId);
                if (preferredItem) {
                    return preferredItem.id;
                }
            }

            const currentIndexInList = params.items.value.findIndex((candidate) => candidate.id === item.id);
            if (currentIndexInList !== -1) {
                return params.items.value[currentIndexInList + 1]?.id ?? null;
            }

            if (previousIndex === null || params.items.value.length === 0) {
                return null;
            }

            return params.items.value[Math.min(previousIndex, params.items.value.length - 1)]?.id ?? null;
        };

        let targetItemId = resolveTargetItemId();

        if (targetItemId === null) {
            await params.ensureMoreItems();

            if (params.items.value.length === 0) {
                params.closeOverlay();
                return;
            }

            targetItemId = resolveTargetItemId();
        }

        if (targetItemId === null) {
            params.closeOverlay();
            return;
        }

        void params.navigateToItem(targetItemId, 'down');
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
