<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Head, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { onUnmounted, ref } from 'vue';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{ isConnected: boolean; scanStatus: { total: number; processed: number; running: boolean } }>();

const scanning = ref(!!props.scanStatus?.running);
const total = ref<number>(props.scanStatus?.total ?? 0);
const processed = ref<number>(props.scanStatus?.processed ?? 0);
const progressMessage = ref<string | null>(null);

const breadcrumbItems: BreadcrumbItem[] = [
  { title: 'Spotify', href: '/settings/spotify' },
];

async function startScan() {
  if (scanning.value) return;
  scanning.value = true;
  total.value = 0;
  processed.value = 0;
  progressMessage.value = 'Starting scan...';
  try {
    await axios.post('/settings/spotify/scan-start');
  } catch {
    scanning.value = false;
  }
}

async function cancelScan() {
  try {
    await axios.post('/settings/spotify/scan-cancel');
  } catch {}
}

const userId = (usePage().props as any)?.auth?.user?.id;
if (userId) {
  const channel = `App.Models.User.${userId}`;
  useEcho(channel, '.spotify.scan.progress', (e: { total: number; processed: number; done: boolean; canceled: boolean; message?: string | null }) => {
    total.value = e.total ?? 0;
    processed.value = e.processed ?? 0;
    progressMessage.value = e.message ?? null;
    if (e.done) scanning.value = false;
  });
}

onUnmounted(() => {});
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbItems">
    <Head title="Spotify" />

    <SettingsLayout>
      <div class="space-y-6">
        <HeadingSmall title="Spotify" description="Sync your saved tracks into Atlas (metadata only)" />

        <div class="space-y-4">
          <div v-if="!props.isConnected" class="text-sm text-muted-foreground">
            Not connected. <a href="/spotify/connect" class="underline">Connect Spotify</a> first.
          </div>

          <div class="flex items-center gap-2">
            <Button variant="secondary" type="button" :disabled="scanning || !props.isConnected" @click="startScan" data-test="scan-spotify">Scan Spotify</Button>
            <Button v-if="scanning" variant="destructive" type="button" @click="cancelScan">Cancel</Button>
          </div>

          <div v-if="scanning || processed > 0" class="text-xs text-muted-foreground">
            <div v-if="total > 0">Scan: processed {{ processed }} of {{ total }}</div>
            <div v-if="progressMessage">{{ progressMessage }}</div>
          </div>
        </div>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>