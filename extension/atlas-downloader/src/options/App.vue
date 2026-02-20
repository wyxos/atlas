<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from 'vue';
import {
  Check,
  Eye,
  EyeOff,
  Pencil,
  Plus,
  Trash2,
  X,
} from 'lucide-vue-next';

type Settings = {
  atlasBaseUrl?: string;
  atlasToken?: string;
  atlasExcludedDomains?: string;
};

type ChromeStorageSync = {
  get: (keys: string[], callback: (data: Settings) => void) => void;
  set: (data: Settings, callback?: () => void) => void;
};

type ChromeApi = {
  runtime?: {
    getManifest?: () => { version?: string };
    sendMessage?: (message: unknown, callback?: (response: unknown) => void) => void;
    onMessage?: {
      addListener: (callback: (message: unknown) => void) => void;
      removeListener: (callback: (message: unknown) => void) => void;
    };
  };
  storage: {
    sync: ChromeStorageSync;
  };
};

declare const chrome: ChromeApi;

type EditableDomain = {
  value: string;
  isEditing: boolean;
  draft: string;
};

type RealtimeConnectionState =
  | 'not-configured'
  | 'loading'
  | 'connecting'
  | 'connected'
  | 'reconnecting'
  | 'error';

type RealtimeConnectionStatus = {
  state: RealtimeConnectionState;
  message: string;
  channel: string | null;
  host: string | null;
  updatedAt: number;
};

const MESSAGE_REALTIME_STATUS_REQUEST = 'atlas-realtime-status-request';
const MESSAGE_REALTIME_STATUS_CHANGED = 'atlas-realtime-status-changed';
const REALTIME_STATES: RealtimeConnectionState[] = [
  'not-configured',
  'loading',
  'connecting',
  'connected',
  'reconnecting',
  'error',
];

const baseUrl = ref('');
const token = ref('');
const tokenVisible = ref(false);
const status = ref('');
const extensionVersion = ref('');
const realtimeStatus = ref<RealtimeConnectionStatus>({
  state: 'loading',
  message: 'Checking realtime connection.',
  channel: null,
  host: null,
  updatedAt: Date.now(),
});

const addDomain = ref('');
const domains = ref<EditableDomain[]>([]);
let realtimePollTimer: ReturnType<typeof setInterval> | null = null;
let realtimeMessageListener: ((message: unknown) => void) | null = null;

const excludedDomainsString = computed(() =>
  domains.value
    .map((d) => d.value)
    .filter(Boolean)
    .join('\n')
);

const realtimeStateLabel = computed(() => {
  switch (realtimeStatus.value.state) {
    case 'connected':
      return 'Connected';
    case 'connecting':
      return 'Connecting';
    case 'reconnecting':
      return 'Reconnecting';
    case 'error':
      return 'Error';
    case 'not-configured':
      return 'Not configured';
    default:
      return 'Checking';
  }
});

const realtimeBadgeClasses = computed(() => {
  switch (realtimeStatus.value.state) {
    case 'connected':
      return 'border-emerald-500/50 bg-emerald-500/10 text-emerald-200';
    case 'error':
      return 'border-rose-500/50 bg-rose-500/10 text-rose-200';
    case 'reconnecting':
      return 'border-amber-500/50 bg-amber-500/10 text-amber-200';
    case 'connecting':
    case 'loading':
      return 'border-sky-500/50 bg-sky-500/10 text-sky-200';
    default:
      return 'border-slate-600/60 bg-slate-700/20 text-slate-300';
  }
});

const realtimeDotClasses = computed(() => {
  switch (realtimeStatus.value.state) {
    case 'connected':
      return 'bg-emerald-400';
    case 'error':
      return 'bg-rose-400';
    case 'reconnecting':
      return 'bg-amber-400';
    case 'connecting':
    case 'loading':
      return 'bg-sky-400 animate-pulse';
    default:
      return 'bg-slate-400';
  }
});

function setStatus(message: string): void {
  status.value = message;
  window.setTimeout(() => {
    if (status.value === message) status.value = '';
  }, 1600);
}

function parseDomains(raw: string): string[] {
  if (!raw) return [];
  return raw
    .split(/[\n,]/g)
    .map((v) => v.trim())
    .filter((v) => v && !v.startsWith('#'))
    .map(normalizeDomain)
    .filter(Boolean);
}

function normalizeDomain(input: string): string {
  let v = (input || '').trim().toLowerCase();
  if (!v) return '';

  if (v.startsWith('*.')) v = v.slice(2);

  if (/^https?:\/\//i.test(v)) {
    try {
      return new URL(v).hostname.toLowerCase();
    } catch {
      return '';
    }
  }

  v = v.replace(/\/.*$/, '');
  v = v.replace(/^\.+/, '');
  return v;
}

function addDomainsFromInput(): void {
  const parts = (addDomain.value || '')
    .split(/[\n, ]+/g)
    .map((v) => v.trim())
    .filter(Boolean)
    .map(normalizeDomain)
    .filter(Boolean);

  if (parts.length === 0) return;

  const existing = new Set(domains.value.map((d) => d.value));
  let added = 0;

  for (const part of parts) {
    if (existing.has(part)) continue;
    domains.value.push({value: part, isEditing: false, draft: part});
    existing.add(part);
    added += 1;
  }

  domains.value.sort((a, b) => a.value.localeCompare(b.value));
  addDomain.value = '';
  if (added > 0) setStatus(`Added ${added} domain${added === 1 ? '' : 's'}.`);
}

function startEdit(index: number): void {
  const item = domains.value[index];
  if (!item) return;
  item.isEditing = true;
  item.draft = item.value;
}

function cancelEdit(index: number): void {
  const item = domains.value[index];
  if (!item) return;
  item.isEditing = false;
  item.draft = item.value;
}

function saveEdit(index: number): void {
  const item = domains.value[index];
  if (!item) return;

  const next = normalizeDomain(item.draft);
  item.isEditing = false;

  if (!next) {
    domains.value.splice(index, 1);
    return;
  }

  const existsAt = domains.value.findIndex((d, i) => d.value === next && i !== index);
  if (existsAt !== -1) {
    domains.value.splice(index, 1);
    return;
  }

  item.value = next;
  item.draft = next;
  domains.value.sort((a, b) => a.value.localeCompare(b.value));
}

function removeDomain(index: number): void {
  domains.value.splice(index, 1);
}

function parseRealtimeStatus(value: unknown): RealtimeConnectionStatus | null {
  if (!value || typeof value !== 'object') {
    return null;
  }

  const payload = value as {
    channel?: unknown;
    host?: unknown;
    message?: unknown;
    state?: unknown;
    updatedAt?: unknown;
  };
  if (
    typeof payload.state !== 'string'
    || !REALTIME_STATES.includes(payload.state as RealtimeConnectionState)
    || typeof payload.message !== 'string'
  ) {
    return null;
  }

  return {
    state: payload.state as RealtimeConnectionState,
    message: payload.message,
    channel: typeof payload.channel === 'string' ? payload.channel : null,
    host: typeof payload.host === 'string' ? payload.host : null,
    updatedAt: typeof payload.updatedAt === 'number' ? payload.updatedAt : Date.now(),
  };
}

function applyRealtimeStatus(value: unknown): void {
  const parsed = parseRealtimeStatus(value);
  if (!parsed) {
    return;
  }

  realtimeStatus.value = parsed;
}

function requestRealtimeStatus(): void {
  if (!chrome.runtime?.sendMessage) {
    return;
  }

  try {
    chrome.runtime.sendMessage({type: MESSAGE_REALTIME_STATUS_REQUEST}, (response) => {
      if (!response || typeof response !== 'object') {
        return;
      }

      const statusPayload = (response as { status?: unknown }).status;
      applyRealtimeStatus(statusPayload);
    });
  } catch {
    // Ignore transient messaging errors.
  }
}

async function saveSettings(): Promise<void> {
  const atlasBaseUrl = baseUrl.value.trim();
  const atlasToken = token.value.trim();
  const atlasExcludedDomains = excludedDomainsString.value.trim();

  chrome.storage.sync.set({atlasBaseUrl, atlasToken, atlasExcludedDomains}, () => {
    setStatus('Settings saved.');
    requestRealtimeStatus();
  });
}

onMounted(() => {
  try {
    extensionVersion.value = chrome.runtime?.getManifest?.().version ?? '';
  } catch {
    extensionVersion.value = '';
  }

  chrome.storage.sync.get(['atlasBaseUrl', 'atlasToken', 'atlasExcludedDomains'], (data) => {
    baseUrl.value = data.atlasBaseUrl || '';
    token.value = data.atlasToken || '';

    const parsed = parseDomains(data.atlasExcludedDomains || '');
    domains.value = parsed
      .sort((a, b) => a.localeCompare(b))
      .map((v) => ({value: v, isEditing: false, draft: v}));
  });

  realtimeMessageListener = (message: unknown) => {
    if (!message || typeof message !== 'object') {
      return;
    }

    const payload = message as { status?: unknown; type?: unknown };
    if (payload.type !== MESSAGE_REALTIME_STATUS_CHANGED) {
      return;
    }

    applyRealtimeStatus(payload.status);
  };

  if (chrome.runtime?.onMessage?.addListener) {
    chrome.runtime.onMessage.addListener(realtimeMessageListener);
  }

  requestRealtimeStatus();
  realtimePollTimer = setInterval(requestRealtimeStatus, 10_000);
});

onUnmounted(() => {
  if (realtimePollTimer) {
    clearInterval(realtimePollTimer);
    realtimePollTimer = null;
  }

  if (realtimeMessageListener && chrome.runtime?.onMessage?.removeListener) {
    chrome.runtime.onMessage.removeListener(realtimeMessageListener);
    realtimeMessageListener = null;
  }
});
</script>

<template>
  <main class="min-h-screen bg-slate-950 text-slate-100">
    <div class="mx-auto w-full max-w-4xl px-5 py-8">
      <header class="flex items-center gap-3">
        <div
          class="grid size-10 place-items-center rounded-xl border border-slate-700/60 bg-slate-900/60"
        >
          <img src="../../icon.svg" alt="" class="size-6" />
        </div>
        <div class="min-w-0">
          <div class="flex items-baseline gap-2">
            <h1 class="text-lg font-semibold tracking-tight">Atlas Downloader</h1>
            <span v-if="extensionVersion" class="text-xs text-slate-400">v{{ extensionVersion }}</span>
          </div>
          <p class="mt-0.5 text-xs text-slate-400">
            Configure where downloads are sent and which sites are ignored.
          </p>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <div
              class="inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide"
              :class="realtimeBadgeClasses"
            >
              <span class="size-2 rounded-full" :class="realtimeDotClasses" />
              <span>{{ realtimeStateLabel }}</span>
            </div>
            <span v-if="realtimeStatus.channel" class="text-[11px] font-mono text-slate-300">
              {{ realtimeStatus.channel }}
            </span>
            <span v-if="realtimeStatus.host" class="text-[11px] text-slate-400">
              {{ realtimeStatus.host }}
            </span>
          </div>
          <p class="mt-1 text-xs text-slate-400">
            {{ realtimeStatus.message }}
          </p>
        </div>
      </header>

      <section class="mt-6 rounded-2xl border border-slate-700/50 bg-slate-900/40 p-4">
        <div class="grid gap-4">
          <div>
            <label for="baseUrl" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-400">
              Atlas base URL
            </label>
            <input
              id="baseUrl"
              v-model="baseUrl"
              class="w-full rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
              placeholder="https://atlas.test"
            />
          </div>

          <div>
            <label for="token" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-400">
              Extension token
            </label>
            <div class="flex items-center gap-2">
              <input
                id="token"
                v-model="token"
                :type="tokenVisible ? 'text' : 'password'"
                class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                placeholder="ATLAS_EXTENSION_TOKEN"
              />
              <button
                type="button"
                class="grid size-10 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 text-slate-100 hover:bg-slate-800/70"
                @click="tokenVisible = !tokenVisible"
                :aria-label="tokenVisible ? 'Hide token' : 'Show token'"
                :title="tokenVisible ? 'Hide token' : 'Show token'"
              >
                <EyeOff v-if="tokenVisible" class="size-5" />
                <Eye v-else class="size-5" />
              </button>
            </div>
            <p class="mt-2 text-xs text-slate-400">
              This must match <span class="font-semibold">ATLAS_EXTENSION_TOKEN</span> in your Atlas
              <span class="font-semibold">.env</span>.
            </p>
          </div>

          <div>
            <label for="addDomain" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-400">
              Excluded domains
            </label>
            <div class="flex items-center gap-2">
              <input
                id="addDomain"
                v-model="addDomain"
                class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                placeholder="example.com (subdomains match automatically)"
                @keydown.enter.prevent="addDomainsFromInput"
              />
              <button
                type="button"
                class="grid size-10 place-items-center rounded-xl bg-sky-400 text-slate-950 hover:bg-sky-300"
                @click="addDomainsFromInput"
                aria-label="Add domain"
                title="Add domain"
              >
                <Plus class="size-5" />
              </button>
            </div>

            <div class="mt-3 overflow-hidden rounded-2xl border border-slate-700/50 bg-slate-950/25">
              <div v-if="domains.length === 0" class="px-3 py-3 text-xs text-slate-400">
                No excluded domains.
              </div>
              <ul v-else class="divide-y divide-slate-700/50">
                <li v-for="(d, i) in domains" :key="d.value" class="flex items-center gap-2 px-3 py-2.5">
                  <template v-if="d.isEditing">
                    <input
                      v-model="d.draft"
                      class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                      aria-label="Edit domain"
                      @keydown.enter.prevent="saveEdit(i)"
                      @keydown.esc.prevent="cancelEdit(i)"
                    />
                    <div class="flex gap-2">
                      <button
                        type="button"
                        class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                        @click="saveEdit(i)"
                        aria-label="Save"
                        title="Save"
                      >
                        <Check class="size-4" />
                      </button>
                      <button
                        type="button"
                        class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                        @click="cancelEdit(i)"
                        aria-label="Cancel"
                        title="Cancel"
                      >
                        <X class="size-4" />
                      </button>
                    </div>
                  </template>
                  <template v-else>
                    <div class="min-w-0 flex-1 truncate font-mono text-sm text-slate-100" :title="d.value">
                      {{ d.value }}
                    </div>
                    <div class="flex gap-2">
                      <button
                        type="button"
                        class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                        @click="startEdit(i)"
                        aria-label="Edit"
                        title="Edit"
                      >
                        <Pencil class="size-4" />
                      </button>
                      <button
                        type="button"
                        class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                        @click="removeDomain(i)"
                        aria-label="Delete"
                        title="Delete"
                      >
                        <Trash2 class="size-4" />
                      </button>
                    </div>
                  </template>
                </li>
              </ul>
            </div>

            <p class="mt-2 text-xs text-slate-400">
              Tip: paste a comma/newline-separated list into the add box and click +.
            </p>
          </div>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-xl bg-sky-400 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-sky-300"
            @click="saveSettings"
          >
            <Check class="size-4" />
            Save settings
          </button>
          <span class="text-sm text-emerald-200">{{ status }}</span>
        </div>
      </section>
    </div>
  </main>
</template>
