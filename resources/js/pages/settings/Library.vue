<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Head, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { edit as storageEdit, update as storageUpdate } from '@/routes/storage';
import { onUnmounted, ref } from 'vue';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
  hostname: string;
  effectivePath: string;
  machineOverride: string | null;
  scanStatus: { total: number; processed: number; running: boolean };
  processing: null | { id: string; total: number; processed: number; failed: number; progress: number; cancelled: boolean };
  spotifyConnected: boolean;
  spotifyScanStatus: { total: number; processed: number; running: boolean };
}>();

// Path editing
const path = ref(props.machineOverride ?? props.effectivePath ?? '');
const baselinePath = ref(path.value);
const saving = ref(false);

// Local scan (filesystem)
const scanning = ref(!!props.scanStatus?.running);
const total = ref<number>(props.scanStatus?.total ?? 0);
const processed = ref<number>(props.scanStatus?.processed ?? 0);
const progressMessage = ref<string | null>(null);
const processing = ref<null | { id: string; total: number; processed: number; failed: number; progress: number; cancelled: boolean }>(props.processing ?? null);

// Spotify scan
const spotifyConnected = ref(!!props.spotifyConnected);
const scanningSpotify = ref(!!props.spotifyScanStatus?.running);
const spotifyTotal = ref<number>(props.spotifyScanStatus?.total ?? 0);
const spotifyProcessed = ref<number>(props.spotifyScanStatus?.processed ?? 0);
const spotifyProgressMessage = ref<string | null>(null);

const breadcrumbItems: BreadcrumbItem[] = [
  {
    title: 'Library',
    href: (storageEdit() as any).url ?? (storageEdit() as any),
  },
];

async function save() {
  saving.value = true;
  try {
    const action = storageUpdate();
    await axios.put(typeof action === 'string' ? action : action.url, { path: path.value });
    baselinePath.value = path.value;
  } finally {
    saving.value = false;
  }
}

// Storage scan actions
async function startScan() {
  if (scanning.value) return;
  scanning.value = true;
  total.value = 0;
  processed.value = 0;
  progressMessage.value = 'Starting scan...';
  processing.value = null;
  try {
    await axios.post('/settings/storage/scan-start');
  } catch {
    scanning.value = false;
  }
}
async function cancelScan() {
  try {
    await axios.post('/settings/storage/scan-cancel');
    scanning.value = false;
    total.value = 0;
    processed.value = 0;
    progressMessage.value = 'Scan canceled';
    processing.value = null;
  } catch {
    progressMessage.value = 'Failed to cancel scan';
  }
}

// Spotify scan actions
async function startSpotifyScan() {
  if (!spotifyConnected.value || scanningSpotify.value) return;
  scanningSpotify.value = true;
  spotifyTotal.value = 0;
  spotifyProcessed.value = 0;
  spotifyProgressMessage.value = 'Starting Spotify scan...';
  try {
    await axios.post('/settings/spotify/scan-start');
  } catch {
    scanningSpotify.value = false;
  }
}
async function cancelSpotifyScan() {
  try {
    await axios.post('/settings/spotify/scan-cancel');
    scanningSpotify.value = false;
    spotifyTotal.value = 0;
    spotifyProcessed.value = 0;
    spotifyProgressMessage.value = 'Spotify scan canceled';
  } catch {
    spotifyProgressMessage.value = 'Failed to cancel Spotify scan';
  }
}
async function disconnectSpotify() {
  if (!confirm('Are you sure you want to disconnect Spotify? This will not delete your synced tracks.')) {
    return;
  }
  try {
    await axios.post('/spotify/disconnect');
    spotifyConnected.value = false;
    scanningSpotify.value = false;
    spotifyTotal.value = 0;
    spotifyProcessed.value = 0;
    spotifyProgressMessage.value = null;
  } catch {
    alert('Failed to disconnect Spotify');
  }
}

// Echo listeners
const userId = (usePage().props as any)?.auth?.user?.id;
if (userId) {
  const channel = `App.Models.User.${userId}`;
  // Local scan
  useEcho(channel, '.storage.scan.progress', (e: { total: number; processed: number; done: boolean; canceled: boolean; message?: string | null }) => {
    total.value = e.total ?? 0;
    processed.value = e.processed ?? 0;
    progressMessage.value = e.message ?? null;
    if (e.canceled) {
      total.value = 0;
      processed.value = 0;
      processing.value = null;
      if (!progressMessage.value) {
        progressMessage.value = 'Scan canceled';
      }
    }
    if (e.done) {
      scanning.value = false;
    }
  });
  useEcho(channel, '.storage.processing.progress', (e: { total: number; processed: number; failed: number; progress: number }) => {
    if (!e.total) {
      processing.value = null;

      return;
    }

    processing.value = {
      id: `${userId}`,
      total: Number(e.total ?? 0),
      processed: Number(e.processed ?? 0),
      failed: Number(e.failed ?? 0),
      progress: Number(e.progress ?? 0),
      cancelled: false,
    };
  });
  // Spotify scan
  useEcho(channel, '.spotify.scan.progress', (e: { total: number; processed: number; done: boolean; canceled: boolean; message?: string | null }) => {
    spotifyTotal.value = e.total ?? 0;
    spotifyProcessed.value = e.processed ?? 0;
    spotifyProgressMessage.value = e.message ?? null;
    if (e.canceled) {
      spotifyTotal.value = 0;
      spotifyProcessed.value = 0;
      if (!spotifyProgressMessage.value) {
        spotifyProgressMessage.value = 'Spotify scan canceled';
      }
    }
    if (e.done) {
      scanningSpotify.value = false;
    }
  });
}

onUnmounted(() => {});
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbItems">
    <Head title="Library" />
    <SettingsLayout>
      <div class="space-y-10">
        <!-- Local library path -->
        <HeadingSmall title="Library path" :description="`Configure the media root for this machine (${hostname})`" />
        <form class="space-y-6" @submit.prevent="save">
          <div class="grid gap-2">
            <Label for="atlas-path">Path</Label>
            <div class="flex items-center gap-2">
              <Input id="atlas-path" v-model="path" type="text" class="mt-1 block w-full" placeholder="C:\\media\\atlas or /mnt/media/atlas" :disabled="saving || scanning" />
              <Button variant="secondary" type="button" :disabled="scanning || path !== baselinePath" @click="startScan" data-test="scan-storage">Scan library</Button>
              <Button v-if="scanning" variant="destructive" type="button" @click="cancelScan">Cancel</Button>
            </div>
            <div v-if="scanning || processed > 0" class="text-xs text-muted-foreground">
              <div v-if="total > 0">Scan: discovered and enqueued {{ processed }} of {{ total }}</div>
              <div v-if="progressMessage">{{ progressMessage }}</div>
            </div>
            <div v-if="processing" class="text-xs text-muted-foreground">
              <div>
                Processing: {{ processing.processed }} of {{ processing.total }}
                <span v-if="processing.failed">(failed: {{ processing.failed }})</span>
                <span>— {{ Math.floor(processing.progress) }}%</span>
              </div>
            </div>
          </div>
          <div class="flex items-center gap-4">
            <Button :disabled="saving || scanning" type="submit">Save</Button>
          </div>
        </form>

        <Separator />

        <!-- Spotify saved tracks -->
        <HeadingSmall title="Spotify" description="Sync your saved tracks into the library (metadata only)" />
        <div class="space-y-4">
          <!-- Not connected state -->
          <div v-if="!spotifyConnected" class="space-y-4">
            <p class="text-sm text-muted-foreground">Connect your Spotify account to sync your saved tracks.</p>
            <Button variant="default" type="button" as="a" href="/spotify/connect?return_to=/settings/library" data-test="connect-spotify">
              Connect to Spotify
            </Button>
          </div>

          <!-- Connected state -->
          <div v-else class="space-y-4">
            <div class="flex items-center gap-2 text-sm">
              <div class="h-2 w-2 rounded-full bg-green-500"></div>
              <span class="text-muted-foreground">Spotify connected</span>
            </div>
            <div class="flex items-center gap-2">
              <Button variant="secondary" type="button" :disabled="scanningSpotify" @click="startSpotifyScan" data-test="scan-spotify">
                Scan Spotify Library
              </Button>
              <Button v-if="scanningSpotify" variant="destructive" type="button" @click="cancelSpotifyScan">Cancel</Button>
              <Button variant="outline" type="button" @click="disconnectSpotify" data-test="disconnect-spotify">
                Disconnect
              </Button>
            </div>
            <div v-if="scanningSpotify || spotifyProcessed > 0" class="text-xs text-muted-foreground">
              <div v-if="spotifyTotal > 0">Scan: processed {{ spotifyProcessed }} of {{ spotifyTotal }}</div>
              <div v-if="spotifyProgressMessage">{{ spotifyProgressMessage }}</div>
            </div>
          </div>
        </div>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>