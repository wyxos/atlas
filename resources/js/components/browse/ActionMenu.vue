<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue';
import { ChevronRight, ChevronLeft, X as XIcon } from 'lucide-vue-next';

export interface ActionOption {
  label: string;
  action?: (e?: Event) => void;
  children?: ActionOption[];
  disabled?: boolean;
  // Optional right-side badge (e.g., counts)
  badge?: { text: string; class: string };
}

const props = defineProps<{
  open: boolean;
  options: ActionOption[];
  // Optional: navigate into this path (by labels) when opening
  initialPathLabels?: string[];
}>();

const emit = defineEmits<{ (e: 'close'): void; (e: 'path-change', path: string[]): void }>();

const menuPath = ref<number[]>([]);
const slideDir = ref<'forward' | 'back'>('forward');

const rootOptions = computed(() => props.options || []);

const currentMenu = computed(() => {
  let list = rootOptions.value;
  for (const idx of menuPath.value) {
    const opt = list[idx];
    if (!opt || !opt.children) return list;
    list = opt.children;
  }
  return list;
});

const currentTitle = computed(() => {
  if (menuPath.value.length === 0) return 'Options';
  let list = rootOptions.value;
  let title = 'Options';
  for (const idx of menuPath.value) {
    const opt = list[idx];
    if (!opt) break;
    title = opt.label;
    list = opt.children || [];
  }
  return title;
});

function emitPathChange() {
  const pathLabels: string[] = [];
  let list = rootOptions.value;
  for (const idx of menuPath.value) {
    const opt = list[idx];
    if (!opt) break;
    pathLabels.push(opt.label);
    list = opt.children || [];
  }
  emit('path-change', pathLabels);
}

let hoverTimer: number | null = null;

function openChildren(opt: ActionOption) {
  slideDir.value = 'forward';
  const idx = currentMenu.value.indexOf(opt);
  menuPath.value = [...menuPath.value, idx];
}

function clearHoverTimer() {
  if (hoverTimer !== null) {
    clearTimeout(hoverTimer);
    hoverTimer = null;
  }
}

function onItemEnter(opt: ActionOption) {
  clearHoverTimer();
  if (opt.children && opt.children.length) {
    hoverTimer = window.setTimeout(() => {
      openChildren(opt);
      hoverTimer = null;
    }, 700);
  }
}

function onItemLeave() {
  clearHoverTimer();
}

function onOptionClick(opt: ActionOption, e?: Event) {
  clearHoverTimer();
  if (opt.disabled) return;
  if (opt.children && opt.children.length) {
    openChildren(opt);
    return;
  }
  if (typeof opt.action === 'function') opt.action(e);
  emit('close');
}

function goBack() {
  if (menuPath.value.length) {
    slideDir.value = 'back';
    menuPath.value = menuPath.value.slice(0, -1);
    return;
  }
  emit('close');
}

function openToLabels(labels: string[] | undefined) {
  if (!labels || !labels.length) return;
  let list = rootOptions.value;
  menuPath.value = [];
  for (const label of labels) {
    const idx = list.findIndex((o) => o?.label === label);
    if (idx < 0) break;
    slideDir.value = 'forward';
    menuPath.value = [...menuPath.value, idx];
    const next = list[idx]?.children || [];
    list = next.length ? next : list;
  }
}

watch(() => props.open, (o) => {
  if (o) {
    menuPath.value = [];
    clearHoverTimer();
    // If requested, navigate immediately to a submenu when opened
    openToLabels(props.initialPathLabels);
    emitPathChange();
  } else {
    clearHoverTimer();
  }
}, { immediate: true });

watch(menuPath, () => {
  clearHoverTimer();
  emitPathChange();
});

// If initialPathLabels changes while open, navigate accordingly
watch(() => props.initialPathLabels, (labels) => {
  if (!props.open) return;
  menuPath.value = [];
  openToLabels(labels);
  emitPathChange();
});

// Number keys 1..9 to trigger current menu items while open
const onKeyNumber = (e: KeyboardEvent) => {
  if (!props.open) return;
  // ignore when typing
  const tag = (document.activeElement?.tagName || '').toLowerCase();
  if (tag === 'input' || tag === 'textarea' || tag === 'select' || (document.activeElement as any)?.isContentEditable) return;
  const k = e.key;
  if (!/^[1-9]$/.test(k)) return;
  const idx = parseInt(k, 10) - 1;
  const list = currentMenu.value || [];
  const target = list[idx];
  if (!target) return;
  e.preventDefault();
  // Defer heavy work triggered by option click so the keydown handler returns fast (improves INP)
  const chosen = target;
  requestAnimationFrame(() => {
    // microtask to sequence after paint if needed
    Promise.resolve().then(() => onOptionClick(chosen));
  });
};

onMounted(() => {
  window.addEventListener('keydown', onKeyNumber, { passive: false });
  // Ensure initial path is applied on mount when component first appears
  openToLabels(props.initialPathLabels);
  emitPathChange();
});

onBeforeUnmount(() => {
  clearHoverTimer();
  window.removeEventListener('keydown', onKeyNumber as any);
});
</script>

<template>
<div v-if="open" class="rounded-b-md border bg-popover text-popover-foreground shadow-md overflow-hidden">
    <div class="rounded-b-md border bg-popover text-popover-foreground shadow-md overflow-hidden">
      <div class="flex items-center justify-between border-b px-2 py-1">
        <button v-if="menuPath.length" class="p-1" aria-label="Back" @click="goBack">
          <ChevronLeft :size="16" class="text-muted-foreground" />
        </button>
        <div class="text-xs font-medium truncate">
          {{ currentTitle }}
        </div>
        <button class="p-1" aria-label="Close" @click="$emit('close')">
          <XIcon :size="16" class="text-muted-foreground" />
        </button>
      </div>
      <Transition :name="slideDir === 'forward' ? 'slide-forward' : 'slide-back'" mode="out-in">
        <ul :key="menuPath.join('-')" class="grid gap-0.5 p-1">
          <li v-for="opt in currentMenu" :key="opt.label">
            <button
              class="w-full text-left rounded px-2 py-1.5 text-sm hover:bg-accent hover:text-accent-foreground flex items-center justify-between disabled:opacity-50"
              :disabled="!!opt.disabled"
              @mouseenter="onItemEnter(opt)"
              @mouseleave="onItemLeave"
              @click="onOptionClick(opt, $event)"
            >
              <span class="truncate mr-2">{{ opt.label }}</span>
              <span class="ml-auto inline-flex items-center gap-1">
                <span v-if="opt.badge" class="rounded px-1.5 py-0.5 text-[11px] font-medium text-background" :class="opt.badge.class">{{ opt.badge.text }}</span>
                <ChevronRight v-if="opt.children" :size="14" class="text-muted-foreground" />
              </span>
            </button>
          </li>
        </ul>
      </Transition>
    </div>
  </div>
</template>

<style scoped>
.slide-forward-enter-active,
.slide-forward-leave-active,
.slide-back-enter-active,
.slide-back-leave-active {
  transition: transform 200ms ease, opacity 200ms ease;
}
.slide-forward-enter-from { transform: translateX(100%); opacity: 0; }
.slide-forward-enter-to { transform: translateX(0%); opacity: 1; }
.slide-forward-leave-from { transform: translateX(0%); opacity: 1; }
.slide-forward-leave-to { transform: translateX(-50%); opacity: 0; }
.slide-back-enter-from { transform: translateX(-50%); opacity: 0; }
.slide-back-enter-to { transform: translateX(0%); opacity: 1; }
.slide-back-leave-from { transform: translateX(0%); opacity: 1; }
.slide-back-leave-to { transform: translateX(100%); opacity: 0; }
</style>
