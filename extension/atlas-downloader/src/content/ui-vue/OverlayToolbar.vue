<script setup lang="ts">
import { computed } from 'vue';

type ToolbarButton = {
  type: string;
  className: string;
  label: string;
  pathDs: string[];
  active: boolean;
  pending: boolean;
  queued: boolean;
  disabled: boolean;
};

const props = defineProps<{
  open: boolean;
  left: number | null;
  top: number | null;
  resolutionText: string;
  statusText: string;
  progressVisible: boolean;
  progressPercent: number;
  progressState: 'queued' | 'active' | 'done' | null;
  postVisible: boolean;
  postDisabled: boolean;
  postPending: boolean;
  postCount: number;
  buttons: ToolbarButton[];
}>();

const emit = defineEmits<{
  pointerEnter: [];
  pointerLeave: [];
  reaction: [type: string];
  postQueue: [reactionType: 'like' | 'love' | 'funny'];
  postHint: [];
}>();

const toolbarStyle = computed(() => {
  if (!props.open || props.left === null || props.top === null) {
    return {};
  }

  return {
    left: `${props.left}px`,
    top: `${props.top}px`,
  };
});

function swallow(event: Event): void {
  event.preventDefault();
  event.stopPropagation();
  (event as Event & { stopImmediatePropagation?: () => void }).stopImmediatePropagation?.();
}

function buttonClass(button: ToolbarButton): string {
  const active = button.active ? ' active' : '';
  const pending = button.pending ? ' pending' : '';
  const queued = button.queued ? ' queued' : '';
  return `atlas-downloader-reaction-btn ${button.className}${active}${pending}${queued}`.trim();
}

function onReactionClick(type: string, event: MouseEvent): void {
  swallow(event);
  emit('reaction', type);
}

function onPostPointerDown(event: PointerEvent): void {
  if (!event.altKey || (event.button !== 0 && event.button !== 1)) {
    return;
  }

  swallow(event);
  emit('postQueue', event.button === 1 ? 'love' : 'like');
}

function onPostContextMenu(event: MouseEvent): void {
  if (!event.altKey) {
    return;
  }

  swallow(event);
  emit('postQueue', 'like');
}

function onPostAuxClick(event: MouseEvent): void {
  if (!event.altKey || event.button !== 1) {
    return;
  }

  swallow(event);
}

function onPostClick(event: MouseEvent): void {
  swallow(event);
  if (event.altKey) {
    return;
  }

  emit('postHint');
}
</script>

<template>
  <div
    class="atlas-downloader-media-toolbar"
    :class="{ open }"
    role="toolbar"
    aria-label="Atlas reactions"
    :style="toolbarStyle"
    @pointerenter="emit('pointerEnter')"
    @pointerleave="emit('pointerLeave')"
  >
    <span class="atlas-downloader-media-resolution" :hidden="!resolutionText">
      {{ resolutionText }}
    </span>

    <button
      type="button"
      class="atlas-downloader-post-indicator"
      :class="{ pending: postPending }"
      :hidden="!postVisible"
      :disabled="postDisabled"
      title="DeviantArt post: Alt+Left=Like, Alt+Middle=Love (favorite) queue"
      @pointerdown="onPostPointerDown"
      @contextmenu="onPostContextMenu"
      @auxclick="onPostAuxClick"
      @click="onPostClick"
    >
      {{ postVisible ? `POST x${postCount}` : 'POST' }}
    </button>

    <button
      v-for="button in buttons"
      :key="button.type"
      type="button"
      :class="buttonClass(button)"
      :aria-label="button.label"
      :title="button.label"
      :disabled="button.disabled"
      @pointerdown="swallow"
      @mousedown="swallow"
      @click="onReactionClick(button.type, $event)"
    >
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path
          v-for="pathD in button.pathDs"
          :key="pathD"
          :d="pathD"
        />
      </svg>
    </button>

    <span class="atlas-downloader-media-status" :hidden="!statusText">
      {{ statusText }}
    </span>

    <div
      class="atlas-downloader-media-progress"
      :hidden="!progressVisible"
      :data-state="progressVisible ? progressState : null"
    >
      <span class="atlas-downloader-media-progress-bar">
        <span
          class="atlas-downloader-media-progress-fill"
          :style="{ width: `${progressPercent}%` }"
        />
      </span>
    </div>
  </div>
</template>
