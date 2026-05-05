<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Ban, Heart, PanelRightClose, Smile, ThumbsUp, Undo2, X } from 'lucide-vue-next';

type DemoReactionType = 'blacklist' | 'funny' | 'like' | 'love';

type DemoReactionCard = {
    createdAt: number;
    fileId: number;
    id: number;
    progress: number;
    reactionType: DemoReactionType;
    thumbnailClass: string;
};

const emit = defineEmits<{
    close: [];
}>();

const cards = ref<DemoReactionCard[]>([]);
const nextCardId = ref(1);
const timeouts = new Set<number>();
const reactions: DemoReactionType[] = ['love', 'like', 'funny', 'blacklist'];
const thumbnailClasses = [
    'from-smart-blue-300 via-sapphire-500 to-prussian-blue-800',
    'from-danger-300 via-danger-500 to-prussian-blue-900',
    'from-warning-300 via-amber-600 to-prussian-blue-800',
    'from-success-300 via-success-600 to-prussian-blue-900',
    'from-fuchsia-300 via-smart-blue-500 to-prussian-blue-800',
    'from-twilight-indigo-200 via-regal-navy-500 to-prussian-blue-900',
];

function randomInt(min: number, max: number): number {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function getReactionMeta(type: DemoReactionType): { color: string; icon: typeof Heart; label: string } {
    const metas: Record<DemoReactionType, { color: string; icon: typeof Heart; label: string }> = {
        love: { color: 'text-red-500', icon: Heart, label: 'Loved' },
        like: { color: 'text-blue-500', icon: ThumbsUp, label: 'Liked' },
        funny: { color: 'text-yellow-500', icon: Smile, label: 'Funny' },
        blacklist: { color: 'text-danger-400', icon: Ban, label: 'Blacklisted' },
    };

    return metas[type];
}

function createCard(): DemoReactionCard {
    const id = nextCardId.value;
    nextCardId.value += 1;

    return {
        createdAt: Date.now(),
        fileId: 5400 + id,
        id,
        progress: randomInt(18, 92),
        reactionType: reactions[randomInt(0, reactions.length - 1)],
        thumbnailClass: thumbnailClasses[randomInt(0, thumbnailClasses.length - 1)],
    };
}

function removeCard(id: number): void {
    cards.value = cards.value.filter((card) => card.id !== id);
}

function scheduleRemoval(id: number): void {
    const timeoutId = window.setTimeout(() => {
        timeouts.delete(timeoutId);
        removeCard(id);
    }, randomInt(5000, 24000));

    timeouts.add(timeoutId);
}

function scheduleNextInsert(): void {
    const timeoutId = window.setTimeout(() => {
        timeouts.delete(timeoutId);
        const card = createCard();
        cards.value.unshift(card);
        scheduleRemoval(card.id);
        scheduleNextInsert();
    }, randomInt(700, 2600));

    timeouts.add(timeoutId);
}

function seedCards(): void {
    cards.value = Array.from({ length: 100 }, () => createCard()).reverse();
    cards.value.forEach((card) => scheduleRemoval(card.id));
}

function dismissCard(id: number): void {
    removeCard(id);
}

function formatCountdown(card: DemoReactionCard): string {
    const seconds = Math.max(0, 9 - ((Date.now() - card.createdAt) / 1000));
    const whole = Math.floor(seconds);
    const centiseconds = Math.floor((seconds % 1) * 100);

    return `${whole.toString().padStart(2, '0')}:${centiseconds.toString().padStart(2, '0')}`;
}

const visibleCards = computed(() => cards.value);

onMounted(() => {
    seedCards();
    scheduleNextInsert();
});

onUnmounted(() => {
    timeouts.forEach((timeoutId) => window.clearTimeout(timeoutId));
    timeouts.clear();
});
</script>

<template>
    <div class="flex h-full w-full justify-end bg-prussian-blue-900/70">
        <aside class="flex h-full w-[min(100%,30rem)] flex-col border-l border-white/10 bg-prussian-blue-700 shadow-2xl shadow-prussian-blue-900/60">
            <header class="flex justify-end border-b border-white/10 bg-prussian-blue-800/80 px-4 py-3">
                <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center border border-white/10 bg-prussian-blue-900/40 text-twilight-indigo-100 transition hover:border-white/20 hover:bg-prussian-blue-800/80"
                    aria-label="Close browse setup"
                    data-test="browse-global-start-panel-close-button"
                    @click="emit('close')"
                >
                    <PanelRightClose :size="16" />
                </button>
            </header>
            <div class="min-h-0 flex-1 overflow-y-auto bg-prussian-blue-700 p-4" data-test="browse-global-start-panel-list">
                <TransitionGroup
                    name="reaction-card"
                    tag="div"
                    class="relative space-y-3"
                    data-test="browse-global-start-panel-cards"
                >
                    <article
                        v-for="card in visibleCards"
                        :key="card.id"
                        class="reaction-panel-card group relative flex min-w-[300px] items-center gap-4 rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-600 p-4 shadow-xl"
                        data-test="browse-global-start-panel-card"
                    >
                        <div class="shrink-0">
                            <div
                                class="size-16 rounded bg-gradient-to-br object-cover"
                                :class="card.thumbnailClass"
                                :aria-label="`File ${card.fileId}`"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <div :class="['shrink-0', getReactionMeta(card.reactionType).color]">
                                        <component :is="getReactionMeta(card.reactionType).icon" class="size-4" />
                                    </div>
                                    <p class="truncate text-sm font-semibold text-twilight-indigo-100">
                                        {{ getReactionMeta(card.reactionType).label }} file #{{ card.fileId }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100"
                                    aria-label="Dismiss"
                                    @click="dismissCard(card.id)"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>

                            <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-twilight-indigo-500/20">
                                <div
                                    class="h-full bg-smart-blue-400 transition-all duration-100 ease-linear"
                                    :style="{ width: `${card.progress}%` }"
                                />
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="font-mono text-xs text-twilight-indigo-300">
                                    {{ formatCountdown(card) }}
                                </p>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded bg-twilight-indigo-500/20 px-2 py-1 text-xs font-medium text-twilight-indigo-200 transition-colors hover:bg-twilight-indigo-500/30 hover:text-twilight-indigo-100"
                                >
                                    <Undo2 class="size-3" />
                                    Undo
                                </button>
                            </div>
                        </div>
                    </article>
                </TransitionGroup>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.reaction-panel-card {
    max-width: 600px;
}

.reaction-card-move,
.reaction-card-enter-active,
.reaction-card-leave-active {
    transition-duration: 260ms;
    transition-property: opacity, transform;
    transition-timing-function: cubic-bezier(0.2, 0, 0, 1);
}

.reaction-card-enter-from {
    opacity: 0;
    transform: translate3d(0, -18px, 0) scale(0.98);
}

.reaction-card-leave-active {
    position: absolute;
    width: calc(100% - 2rem);
}

.reaction-card-leave-to {
    opacity: 0;
    transform: translate3d(120%, 0, 0) scale(0.96);
}
</style>
