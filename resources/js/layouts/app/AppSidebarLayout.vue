<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import GlobalAudioPlayer from '@/components/GlobalAudioPlayer.vue';
import UndoToast from '@/components/ui/UndoToast.vue';
import ModerationToast from '@/components/ui/ModerationToast.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<script lang="ts">
export default {
  mounted() {
    const onKey = (e: KeyboardEvent) => {
      const key = (e.key || '').toLowerCase();
      const isMeta = e.ctrlKey || e.metaKey;
      const tag = (document.activeElement?.tagName || '').toLowerCase();
      const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || (document.activeElement as any)?.isContentEditable;
      if (!typing && isMeta && key === 'z') {
        e.preventDefault();
        // Lazy import to avoid circular refs
        import('@/lib/undo').then(m => m.undoManager.undo()).catch(() => {});
      }
    };
    (this as any)._undoKey = onKey;
    window.addEventListener('keydown', onKey, { passive: false });
  },
  beforeUnmount() {
    const onKey = (this as any)._undoKey as any;
    if (onKey) window.removeEventListener('keydown', onKey);
  }
}
</script>

<template>
    <AppShell variant="sidebar" class="relative overflow-hidden">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
            <!-- Global audio player overlay -->
            <GlobalAudioPlayer />
            <!-- Global undo toast -->
            <UndoToast />
            <!-- Global moderation toast -->
            <ModerationToast />
        </AppContent>
    </AppShell>
</template>
