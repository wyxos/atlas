<script setup lang="ts">
import { computed, ref } from 'vue';
import { Play, Pause } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import { router } from '@inertiajs/vue3';
import AudioReactions from '@/components/audio/AudioReactions.vue';

const props = defineProps<{
  item: any;
  index: number;
  loadedFile: any | null;
  isPlaying: boolean;
  currentFileId: number | null;
  isSwipedOpen: boolean;
}>();

const emit = defineEmits<{
  (e: 'play', file: any): void;
  (e: 'touchStart', event: TouchEvent | MouseEvent): void;
  (e: 'touchMove', event: TouchEvent | MouseEvent): void;
  (e: 'touchEnd', item: any): void;
  (e: 'favorite', item: any, event: Event): void;
  (e: 'like', item: any, event: Event): void;
  (e: 'dislike', item: any, event: Event): void;
  (e: 'laughedAt', item: any, event: Event): void;
}>();

// Computed property to get the cover image with priority: album covers first, then file covers
const coverImage = computed((): string | null => {
  if (!props.loadedFile) return null;

  // First check for album covers
  if (props.loadedFile.albums && props.loadedFile.albums.length > 0) {
    for (const album of props.loadedFile.albums) {
      if (album.covers && album.covers.length > 0) {
        return album.covers[0].path;
      }
    }
  }

  // Fall back to file covers
  if (props.loadedFile.covers && props.loadedFile.covers.length > 0) {
    return props.loadedFile.covers[0].path;
  }

  return null;
});

// Handle play button click
function handlePlay(event: Event): void {
  event.stopPropagation();
  emit('play', props.item);
}


// Handle track title click to navigate to FileShow
function handleTitleClick(event: Event): void {
  event.stopPropagation();
  if (props.loadedFile?.id) {
    router.visit(route('files.show', { file: props.loadedFile.id }));
  }
}

// Convert duration from e.g 177.99836734693878 to a human-readable format
function convertToDuration(seconds: number): string {
    const totalSeconds = Math.floor(seconds);
    const minutes = Math.floor(totalSeconds / 60);
    const secs = totalSeconds % 60;
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`;
}

// Drag and drop state
const isDragging = ref(false);

// Hover state for play/pause button
const isHovered = ref(false);


// Drag and drop functions
const handleDragEnter = (event: DragEvent): void => {
    event.preventDefault();
    isDragging.value = true;
};

const handleDragOver = (event: DragEvent): void => {
    event.preventDefault();
};

const handleDragLeave = (event: DragEvent): void => {
    event.preventDefault();
    // Only set isDragging to false if we're actually leaving the drop zone
    // Check if the related target is outside the current target
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const x = event.clientX;
    const y = event.clientY;

    if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
        isDragging.value = false;
    }
};

const handleDrop = async (event: DragEvent): Promise<void> => {
    event.preventDefault();
    isDragging.value = false;

    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) return;

    const file = files[0];
    if (!file.type.startsWith('image/')) {
        alert('Please drop an image file');
        return;
    }

    if (!props.loadedFile) return;

    // Get the cover ID - prioritize album covers first, then file covers
    let coverId: number | null = null;

    // First check for album covers
    if (props.loadedFile.albums && props.loadedFile.albums.length > 0) {
        for (const album of props.loadedFile.albums) {
            if (album.covers && album.covers.length > 0) {
                coverId = album.covers[0].id;
                break;
            }
        }
    }

    // Fall back to file covers
    if (!coverId && props.loadedFile.covers && props.loadedFile.covers.length > 0) {
        coverId = props.loadedFile.covers[0].id;
    }

    try {
        if (coverId) {
            // Update existing cover
            router.post(route('covers.update', { coverId: coverId }), {
                file: file,
            }, {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    // The page will be refreshed with updated covers
                },
                onError: (errors) => {
                    console.error('Error uploading cover:', errors);
                    alert('Failed to upload cover image');
                }
            });
        } else {
            // Create new cover for the file
            router.post(route('covers.create', { fileId: props.loadedFile.id }), {
                file: file,
            }, {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    // The page will be refreshed with new cover
                },
                onError: (errors) => {
                    console.error('Error creating cover:', errors);
                    alert('Failed to create cover image');
                }
            });
        }
    } catch (error) {
        console.error('Error handling cover:', error);
        alert('Failed to handle cover image');
    }
};


</script>

<template>
  <div
    class="file px-4 py-1 flex justify-between items-center rounded border-b border-border transition-all duration-300 relative hover:bg-accent/50"
    :class="{
      'bg-primary text-primary-foreground': currentFileId === item.id,
      'transform -translate-x-32': isSwipedOpen
    }"
    @touchstart="emit('touchStart', $event)"
    @touchmove="emit('touchMove', $event)"
    @touchend="emit('touchEnd', item)"
    @mousedown="emit('touchStart', $event)"
    @mousemove="emit('touchMove', $event)"
    @mouseup="emit('touchEnd', item)"
    @mouseenter="isHovered = true"
    @mouseleave="isHovered = false; $event.buttons && emit('touchEnd', item)"
  >
    <div class="flex gap-2 items-center group">
        <button
            class="cursor-pointer text-white w-10 mr-6 flex items-center justify-center"
            @click.stop="handlePlay($event)"
        >
            <!-- Show play/pause button on hover -->
            <template v-if="isHovered">
                <Pause v-if="isPlaying && currentFileId === item.id" :size="20" />
                <Play v-else :size="20" />
            </template>
            <!-- Show index or animated wave when not hovering -->
            <template v-else>
                <!-- Show animated wave if this file is currently playing -->
                <div class="boxContainer" v-if="isPlaying && currentFileId === item.id">
                    <div class="box box1"></div>
                    <div class="box box2"></div>
                    <div class="box box3"></div>
                    <div class="box box4"></div>
                    <div class="box box5"></div>
                </div>
                <!-- Show index number when not playing -->
                <span v-else class="text-sm font-medium">{{ index }}</span>
            </template>
        </button>
      <div
        class="w-16 h-16 flex-shrink-0 overflow-hidden rounded relative transition-all duration-300"
        :class="isDragging ? 'border-2 border-dashed border-blue-300 bg-blue-50' : ''"
        @dragenter="handleDragEnter"
        @dragover="handleDragOver"
        @dragleave="handleDragLeave"
        @drop="handleDrop"
      >
        <!-- Loading skeleton for cover -->
        <Skeleton v-if="!loadedFile" class="w-full h-full" />
        <!-- Actual cover image when loaded -->
        <template v-else>
          <img
            v-if="coverImage"
            :src="`/atlas/${coverImage}`"
            alt="Cover"
            class="w-full h-full object-cover"
            :class="isDragging ? 'opacity-50' : ''"
          />
          <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
            <span class="text-xs">No Cover</span>
          </div>

          <!-- Drag Overlay -->
          <div v-if="isDragging" class="absolute inset-0 flex items-center justify-center rounded bg-blue-50/80">
            <div class="text-center">
              <span class="text-xs font-medium text-blue-700">Drop to replace</span>
            </div>
          </div>
        </template>
      </div>
      <div class="flex flex-col w-100">
        <!-- Loading skeleton for artist name -->
        <Skeleton v-if="!loadedFile" class="h-4 w-24 mb-1" />
        <span v-else class="text-xs font-semibold">{{
          loadedFile.artists && loadedFile.artists.length > 0
            ? loadedFile.artists[0]?.name
            : 'Unknown Artist'
        }}</span>

        <!-- Loading skeleton for title -->
        <Skeleton v-if="!loadedFile" class="h-4 w-32" />
        <span v-else class="cursor-pointer hover:text-primary transition-colors" @click="handleTitleClick">{{ loadedFile?.metadata?.payload?.title || 'Untitled' }}</span>
      </div>

        <div class="hidden md:block ml-auto w-100">
            <Skeleton v-if="!loadedFile" class="h-4 w-24 mb-1" />
            <span class="text-sm" v-else>
                {{ loadedFile?.albums[0]?.name || 'Unknown Album' }}
            </span>
        </div>

        <div class="hidden md:block">
            <Skeleton v-if="!loadedFile" class="h-4 w-16 mb-1" />
            <span class="text-xs" v-else>
                {{ loadedFile?.metadata?.payload?.duration ? convertToDuration(loadedFile.metadata.payload.duration) : 'Unknown Duration' }}
            </span>
        </div>
    </div>

    <!-- Action buttons container -->
    <div class="absolute top-0 left-full md:static h-full items-center flex p-4">
      <AudioReactions
        :file="loadedFile"
        :icon-size="20"
        variant="list"
        @favorite="(file, event) => emit('favorite', file, event)"
        @like="(file, event) => emit('like', file, event)"
        @dislike="(file, event) => emit('dislike', file, event)"
        @laughed-at="(file, event) => emit('laughedAt', file, event)"
      />
    </div>
  </div>

</template>

<style>
@keyframes quiet {
    25%{
        transform: scaleY(.6);
    }
    50%{
        transform: scaleY(.4);
    }
    75%{
        transform: scaleY(.8);
    }
}

@keyframes normal {
    25%{
        transform: scaleY(1);
    }
    50%{
        transform: scaleY(.4);
    }
    75%{
        transform: scaleY(.6);
    }
}
@keyframes loud {
    25%{
        transform: scaleY(1);
    }
    50%{
        transform: scaleY(.4);
    }
    75%{
        transform: scaleY(1.7);
    }
}

.boxContainer{
    display: flex;
    justify-content: space-between;
    height: 30px;
    --boxSize: 6px;
    --gutter: 4px;
    width: calc((var(--boxSize) + var(--gutter)) * 5);
}

.box{
    transform: scaleY(.4);
    height: 100%;
    width: var(--boxSize);
    background: #12E2DC;
    animation-duration: 1s;
    animation-timing-function: ease-in-out;
    animation-iteration-count: infinite;
    border-radius: 4px;
}

.box1{
    animation-name: quiet;
}

.box2{
    animation-name: normal;
}

.box3{
    animation-name: quiet;
}

.box4{
    animation-name: loud;
}

.box5{
    animation-name: quiet;
}
</style>
