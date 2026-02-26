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
  buttons: ToolbarButton[];
}>();

const emit = defineEmits<{
  pointerEnter: [];
  pointerLeave: [];
  reaction: [type: string];
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
