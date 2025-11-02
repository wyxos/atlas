<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { edit as storageEdit, update as storageUpdate } from '@/routes/storage';
import { onUnmounted, ref } from 'vue';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{ hostname: string; effectivePath: string; machineOverride: string | null, scanStatus: { total: number; processed: number; running: boolean }, processing: null | { id: string; total: number; processed: number; failed: number; progress: number; cancelled: boolean } }>();

const path = ref(props.machineOverride ?? props.effectivePath ?? '');
const baselinePath = ref(path.value); // track last-saved value to enable Scan only when unchanged
const saving = ref(false);
const scanning = ref(!!props.scanStatus?.running);
const recentlySuccessful = ref(false);

// Scan progress state (phase 1)
const total = ref<number>(props.scanStatus?.total ?? 0);
const processed = ref<number>(props.scanStatus?.processed ?? 0);
const progressMessage = ref<string | null>(null);

// Processing progress state (phase 2)
const processing = ref<null | { id: string; total: number; processed: number; failed: number; progress: number; cancelled: boolean }>(props.processing ?? null);

const breadcrumbItems: BreadcrumbItem[] = [
  {
    title: 'Storage settings',
    href: (storageEdit() as any).url ?? (storageEdit() as any),
  },
];

async function save() {
  saving.value = true;
  recentlySuccessful.value = false;
  try {
    const action = storageUpdate();
    await axios.put(typeof action === 'string' ? action : action.url, { path: path.value });
    recentlySuccessful.value = true;
    baselinePath.value = path.value;
    setTimeout(() => { recentlySuccessful.value = false; }, 1500);
  } catch (e) {
    console.error(e);
  } finally {
    saving.value = false;
  }
}

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
  } catch {
    // ignore
  }
}

// Listen for progress events on the user's private channel
const userId = (usePage().props as any)?.auth?.user?.id;
if (userId) {
  const channel = `App.Models.User.${userId}`;
  // Scan phase events
  useEcho(channel, '.storage.scan.progress', (e: { total: number; processed: number; done: boolean; canceled: boolean; message?: string | null }) => {
    total.value = e.total ?? 0;
    processed.value = e.processed ?? 0;
    progressMessage.value = e.message ?? null;
    if (e.done) scanning.value = false;
  });
  // Processing phase events
  useEcho(channel, '.storage.processing.progress', (e: { total: number; processed: number; failed: number; progress: number }) => {
    processing.value = {
      id: `${userId}`,
      total: Number(e.total ?? 0),
      processed: Number(e.processed ?? 0),
      failed: Number(e.failed ?? 0),
      progress: Number(e.progress ?? 0),
      cancelled: false,
    };
  });
}


onUnmounted(() => {
  // nothing
});
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbItems">
    <Head title="Storage settings" />

    <SettingsLayout>
      <div class="space-y-6">
        <HeadingSmall title="Storage path" :description="`Configure the media root for this machine (${hostname})`" />

        <form class="space-y-6" @submit.prevent="save">
          <div class="grid gap-2">
            <Label for="atlas-path">Atlas path</Label>
            <div class="flex items-center gap-2">
              <Input
                id="atlas-path"
                v-model="path"
                type="text"
                class="mt-1 block w-full"
                placeholder="C:\\media\\atlas or /mnt/media/atlas"
                :disabled="saving || scanning"
              />
              <Button variant="secondary" type="button" :disabled="scanning || path !== baselinePath" @click="startScan">Scan</Button>
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
            <Transition
              enter-active-class="transition ease-in-out"
              enter-from-class="opacity-0"
              leave-active-class="transition ease-in-out"
              leave-to-class="opacity-0"
            >
              <p v-show="recentlySuccessful" class="text-sm text-neutral-600">Saved.</p>
            </Transition>
          </div>
        </form>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>
