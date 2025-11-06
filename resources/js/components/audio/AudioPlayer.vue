<script setup lang="ts">
import { ChevronDown, ChevronUp, Menu, Pause, Play, Repeat, Repeat1, Shuffle, SkipBack, SkipForward, Sun, Moon, Volume2, VolumeX } from 'lucide-vue-next';
</script>

<template>
    <div class="sticky bottom-0 left-0 w-full border-t border-border bg-card px-4 py-2 md:p-4">
        <!-- Desktop -->
        <div class="hidden items-center gap-4 md:flex">
            <!-- Minimized -->
            <template v-if="false">
                <div class="flex flex-1 items-center gap-4">
                    <!-- Small cover -->
                    <div class="relative flex h-12 w-12 items-center justify-center">
                        <div class="h-full w-full rounded bg-muted"></div>
                    </div>
                    <div
                        class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                    >
                        <template>
                            <img src="" alt="Cover" class="h-full w-full object-contain" />
                        </template>
                        <template>
                            <div class="flex h-full w-full items-center justify-center rounded bg-muted text-muted-foreground">
                                <span class="text-xs">♪</span>
                            </div>
                        </template>
                        <button type="button" class="absolute inset-0" aria-label="Show current in list">
                            <span class="sr-only">Show current in list</span>
                        </button>
                    </div>

                    <div class="flex flex-1 items-center gap-4">
                        <div class="flex min-w-0 flex-col">
                            <div class="font-medium text-foreground">
                                <span
                                    class="truncate text-sm font-semibold"
                                    data-test="audio-player-title"
                                >Title</span
                                >
                                <span class="block truncate text-xs text-muted-foreground" data-test="audio-player-artist">Artist</span>
                            </div>
                        </div>

                        <!-- Basic playback controls -->
                        <div class="flex items-center gap-2">
                            <button class="button circular small empty" title="Previous">
                                <SkipBack :size="16" />
                                <span class="sr-only">Previous</span>
                            </button>
                            <button
                                class="button circular empty"
                                title="Play/Pause"
                            >
                                <Play :size="20" />
                            </button>
                            <button class="button circular small empty" title="Next">
                                <SkipForward :size="16" />
                                <span class="sr-only">Next</span>
                            </button>
                        </div>

                        <!-- Compact progress bar -->
                        <div class="mx-4 min-w-0 flex-1">
                            <div class="h-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" data-test="audio-player-progress">
                                <div
                                    class="h-full rounded-full bg-primary"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Full View -->
            <template v-if="true">
                <div class="flex w-100 items-center gap-4">
                    <div
                        class="relative flex h-18 w-18 shrink-0 items-center justify-center overflow-hidden bg-muted transition-all duration-300 md:h-32 md:w-32"
                    >
                        <template>
                            <img src="" alt="Cover" class="h-full w-full object-contain" />
                        </template>
                        <template>
                            <div class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                                <span class="text-xs">No Cover</span>
                            </div>
                        </template>
                        <button type="button" class="absolute inset-0" aria-label="Show current in list">
                            <span class="sr-only">Show current in list</span>
                        </button>
                    </div>

                    <div class="flex flex-col gap-2 truncate">
                        <div class="mb-2 flex flex-col gap-1 font-medium text-foreground">
                            <span class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">Artist</span>
                            <span
                                class="truncate font-semibold text-foreground"
                                data-test="audio-player-title"
                            >Title</span
                            >
                        </div>

                        <!-- Reactions placeholder -->
                        <div class="flex flex-1 items-center">
                            <div>
                                <!-- FileReactions component placeholder -->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex-1">
                    <div class="mb-2">
                        <div class="mb-2 flex items-center gap-4 text-xs text-muted-foreground">
                            <span>00:00</span>
                            <div class="h-2 flex-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" data-test="audio-player-progress">
                                <div
                                    class="h-full rounded-full bg-primary"
                                ></div>
                            </div>
                            <span>00:00</span>
                        </div>

                        <div class="mt-2 flex items-center justify-center gap-4">
                            <div class="flex items-center gap-4">
                                <button class="button circular small empty" title="Shuffle">
                                    <Shuffle :size="16" />
                                </button>
                                <button class="button circular small empty" title="Previous">
                                    <SkipBack :size="20" />
                                    <span class="sr-only">Previous</span>
                                </button>
                                <button
                                    class="button circular empty"
                                    title="Play/Pause"
                                >
                                    <Play :size="24" />
                                </button>
                                <button class="button circular small empty" title="Next">
                                    <SkipForward :size="20" />
                                    <span class="sr-only">Next</span>
                                </button>
                                <button
                                    class="button circular small empty"
                                    title="Repeat"
                                >
                                    <Repeat :size="16" />
                                </button>
                                <button
                                    class="button circular small empty"
                                    title="Keep screen awake while playing"
                                >
                                    <Moon :size="16" />
                                </button>
                                <!-- Volume -->
                                <div class="group ml-4 hidden items-center gap-2 md:flex">
                                    <Volume2 :size="16" class="text-muted-foreground group-hover:text-primary-foreground" />
                                    <input
                                        aria-label="Volume"
                                        class="h-1 w-28 cursor-pointer appearance-none rounded-full"
                                        max="1"
                                        min="0"
                                        step="0.01"
                                        type="range"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex w-100 justify-end gap-2">
                <button
                    class="button circular small empty"
                    title="Minimize Player"
                >
                    <ChevronUp :size="16" />
                </button>
                <button
                    class="button circular small empty"
                    title="Show Queue"
                >
                    <Menu :size="16" />
                </button>
            </div>
        </div>

        <!-- Queue Panel -->
        <div>
            <!-- AudioQueuePanel component placeholder -->
        </div>

        <!-- Mobile -->
        <div class="md:hidden">
            <div class="mb-4 flex items-center gap-2">
                <div class="relative flex h-16 w-16 items-center justify-center">
                    <div class="h-full w-full rounded bg-muted"></div>
                </div>
                <div
                    class="relative flex h-16 w-16 items-center justify-center overflow-hidden bg-muted transition-all duration-300"
                >
                    <template>
                        <img src="" alt="Cover" class="h-full w-full object-contain" />
                    </template>
                    <template>
                        <div class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                            <span class="text-xs">No Cover</span>
                        </div>
                    </template>
                    <button type="button" class="absolute inset-0" aria-label="Show current in list">
                        <span class="sr-only">Show current in list</span>
                    </button>
                </div>

                <div class="flex flex-1 flex-col gap-1 truncate font-medium text-foreground">
                    <span class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">Artist</span>
                    <span
                        class="truncate font-semibold text-foreground"
                        data-test="audio-player-title"
                    >Title</span
                    >
                </div>

                <button
                    class="button circular small empty"
                    title="Minimize Player"
                >
                    <ChevronUp :size="16" />
                </button>
            </div>

            <template>
                <div class="mb-2">
                    <div class="mb-2 h-2 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" data-test="audio-player-progress">
                        <div
                            class="h-full rounded-full bg-primary"
                        ></div>
                    </div>
                    <div class="mb-2 flex justify-between text-sm text-muted-foreground">
                        <span>00:00</span>
                        <span>00:00</span>
                    </div>
                </div>

                <div class="mb-4 flex items-center justify-center gap-6">
                    <button class="button circular small empty" title="Shuffle">
                        <Shuffle :size="18" />
                    </button>
                    <button class="button circular small empty" title="Previous">
                        <SkipBack :size="26" />
                        <span class="sr-only">Previous</span>
                    </button>
                    <button
                        class="button circular empty"
                        title="Play/Pause"
                    >
                        <Play :size="32" />
                    </button>
                    <button class="button circular small empty" title="Next">
                        <SkipForward :size="26" />
                        <span class="sr-only">Next</span>
                    </button>
                    <button
                        class="button circular small empty"
                        title="Repeat"
                    >
                        <Repeat :size="18" />
                    </button>
                    <button
                        class="button circular small empty"
                        title="Keep screen awake while playing"
                    >
                        <Moon :size="16" />
                    </button>
                </div>
                <!-- Volume (mobile) -->
                <div class="mb-4 flex items-center justify-center gap-2 md:hidden">
                    <Volume2 :size="16" />
                    <input
                        aria-label="Volume"
                        class="h-1 w-40 cursor-pointer appearance-none rounded-full bg-muted accent-primary"
                        max="1"
                        min="0"
                        step="0.01"
                        type="range"
                    />
                </div>
            </template>
        </div>
    </div>
</template>
