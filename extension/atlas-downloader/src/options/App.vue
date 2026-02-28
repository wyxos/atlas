<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from 'vue';
import { DEFAULT_DOMAIN_INCLUDE_RULES, DEFAULT_DOMAIN_INCLUDE_RULES_TEXT } from '../shared/settingsDefaults';
import {
  isValidPatternSource,
  normalizeDomainInput,
  normalizePatternInput,
  parseDomainRules,
  serializeDomainRules,
  toEditableDomainRule,
  type EditableDomainRule,
} from './composables/useDomainIncludeRules';
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
  atlasDomainIncludeRules?: string;
  atlasMinMediaWidth?: unknown;
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
const domainRules = ref<EditableDomainRule[]>([]);
const minMediaWidth = ref('300');
let realtimePollTimer: ReturnType<typeof setInterval> | null = null;
let realtimeMessageListener: ((message: unknown) => void) | null = null;

const domainPlaceholder = computed(() => {
  const first = DEFAULT_DOMAIN_INCLUDE_RULES[0]?.domain || '';
  return first || 'example.com';
});

const patternPlaceholder = computed(() => {
  const first = DEFAULT_DOMAIN_INCLUDE_RULES[0]?.patterns?.[0] || '';
  return first || '.*\\/art\\/.*';
});

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
  }, 1800);
}

function normalizeDomain(input: string): string {
  return normalizeDomainInput(input);
}

function normalizePattern(input: string): string {
  return normalizePatternInput(input);
}

function isValidPattern(source: string): boolean {
  return isValidPatternSource(source);
}

function resolveDomainRulesSetting(value: unknown): string {
  return typeof value === 'string' ? value : DEFAULT_DOMAIN_INCLUDE_RULES_TEXT;
}

function addDomainRule(): void {
  const domain = normalizeDomain(addDomain.value);
  if (!domain) {
    return;
  }

  const exists = domainRules.value.some((rule) => rule.domain === domain);
  if (exists) {
    setStatus('Domain already exists.');
    return;
  }

  domainRules.value.push(toEditableDomainRule({ domain, patterns: [] }));
  domainRules.value.sort((a, b) => a.domain.localeCompare(b.domain));
  addDomain.value = '';
  setStatus('Domain added.');
}

function removeDomainRule(index: number): void {
  domainRules.value.splice(index, 1);
}

function startDomainEdit(index: number): void {
  const rule = domainRules.value[index];
  if (!rule) {
    return;
  }

  rule.isEditingDomain = true;
  rule.draftDomain = rule.domain;
}

function cancelDomainEdit(index: number): void {
  const rule = domainRules.value[index];
  if (!rule) {
    return;
  }

  rule.isEditingDomain = false;
  rule.draftDomain = rule.domain;
}

function saveDomainEdit(index: number): void {
  const rule = domainRules.value[index];
  if (!rule) {
    return;
  }

  const nextDomain = normalizeDomain(rule.draftDomain);
  if (!nextDomain) {
    setStatus('Domain cannot be empty.');
    rule.isEditingDomain = false;
    rule.draftDomain = rule.domain;
    return;
  }

  const duplicateIndex = domainRules.value.findIndex((item, i) => item.domain === nextDomain && i !== index);
  if (duplicateIndex !== -1) {
    setStatus('Domain already exists.');
    rule.isEditingDomain = false;
    rule.draftDomain = rule.domain;
    return;
  }

  rule.domain = nextDomain;
  rule.draftDomain = nextDomain;
  rule.isEditingDomain = false;
  domainRules.value.sort((a, b) => a.domain.localeCompare(b.domain));
}

function addPatternsToRule(index: number): void {
  const rule = domainRules.value[index];
  if (!rule) {
    return;
  }

  const parts = rule.addPattern
    .split(/[\n,]+/g)
    .map((value) => normalizePattern(value))
    .filter(Boolean);

  if (parts.length === 0) {
    return;
  }

  const existing = new Set(rule.patterns.map((pattern) => pattern.value));
  let added = 0;
  let invalid = 0;

  for (const source of parts) {
    if (!isValidPattern(source)) {
      invalid += 1;
      continue;
    }

    if (existing.has(source)) {
      continue;
    }

    rule.patterns.push({
      value: source,
      isEditing: false,
      draft: source,
    });
    existing.add(source);
    added += 1;
  }

  rule.patterns.sort((a, b) => a.value.localeCompare(b.value));
  rule.addPattern = '';

  if (invalid > 0 && added === 0) {
    setStatus('Pattern is not a valid regex.');
    return;
  }

  if (invalid > 0) {
    setStatus(`Added ${added} pattern${added === 1 ? '' : 's'} (${invalid} invalid).`);
    return;
  }

  if (added > 0) {
    setStatus(`Added ${added} pattern${added === 1 ? '' : 's'}.`);
  }
}

function startPatternEdit(ruleIndex: number, patternIndex: number): void {
  const pattern = domainRules.value[ruleIndex]?.patterns[patternIndex];
  if (!pattern) {
    return;
  }

  pattern.isEditing = true;
  pattern.draft = pattern.value;
}

function cancelPatternEdit(ruleIndex: number, patternIndex: number): void {
  const pattern = domainRules.value[ruleIndex]?.patterns[patternIndex];
  if (!pattern) {
    return;
  }

  pattern.isEditing = false;
  pattern.draft = pattern.value;
}

function savePatternEdit(ruleIndex: number, patternIndex: number): void {
  const rule = domainRules.value[ruleIndex];
  const pattern = rule?.patterns[patternIndex];
  if (!rule || !pattern) {
    return;
  }

  const next = normalizePattern(pattern.draft);
  if (!next) {
    rule.patterns.splice(patternIndex, 1);
    return;
  }

  if (!isValidPattern(next)) {
    pattern.isEditing = false;
    pattern.draft = pattern.value;
    setStatus('Pattern is not a valid regex.');
    return;
  }

  const duplicate = rule.patterns.findIndex((entry, index) => entry.value === next && index !== patternIndex);
  if (duplicate !== -1) {
    rule.patterns.splice(patternIndex, 1);
    return;
  }

  pattern.value = next;
  pattern.draft = next;
  pattern.isEditing = false;
  rule.patterns.sort((a, b) => a.value.localeCompare(b.value));
}

function removePattern(ruleIndex: number, patternIndex: number): void {
  const rule = domainRules.value[ruleIndex];
  if (!rule) {
    return;
  }

  rule.patterns.splice(patternIndex, 1);
}

function normalizeMinMediaWidth(value: unknown): number {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) {
    return 300;
  }

  return Math.max(0, Math.floor(parsed));
}

function normalizeMinMediaWidthInput(): void {
  minMediaWidth.value = String(normalizeMinMediaWidth(minMediaWidth.value));
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
  const atlasDomainIncludeRules = JSON.stringify(serializeDomainRules(domainRules.value));
  const atlasMinMediaWidth = normalizeMinMediaWidth(minMediaWidth.value);
  minMediaWidth.value = String(atlasMinMediaWidth);

  chrome.storage.sync.set({atlasBaseUrl, atlasToken, atlasDomainIncludeRules, atlasMinMediaWidth}, () => {
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

  chrome.storage.sync.get(['atlasBaseUrl', 'atlasToken', 'atlasDomainIncludeRules', 'atlasMinMediaWidth'], (data) => {
    baseUrl.value = data.atlasBaseUrl || '';
    token.value = data.atlasToken || '';
    minMediaWidth.value = String(normalizeMinMediaWidth(data.atlasMinMediaWidth));

    const parsedRules = parseDomainRules(resolveDomainRulesSetting(data.atlasDomainIncludeRules));
    domainRules.value = parsedRules
      .sort((a, b) => a.domain.localeCompare(b.domain))
      .map((rule) => toEditableDomainRule(rule));
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
            Configure where downloads are sent.
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
            <label for="minMediaWidth" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-400">
              Minimum media width (px)
            </label>
            <input
              id="minMediaWidth"
              v-model="minMediaWidth"
              type="number"
              min="0"
              step="1"
              class="w-full rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
              placeholder="300"
              @blur="normalizeMinMediaWidthInput"
            />
            <p class="mt-2 text-xs text-slate-400">
              Media narrower than this value are ignored. Default is <span class="font-mono">300</span>.
            </p>
          </div>

          <div>
            <label for="addDomain" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-400">
              Domain include regex rules
            </label>
            <div class="flex items-center gap-2">
              <input
                id="addDomain"
                v-model="addDomain"
                class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                :placeholder="domainPlaceholder"
                @keydown.enter.prevent="addDomainRule"
              />
              <button
                type="button"
                class="grid size-10 place-items-center rounded-xl bg-sky-400 text-slate-950 hover:bg-sky-300"
                @click="addDomainRule"
                aria-label="Add domain"
                title="Add domain"
              >
                <Plus class="size-5" />
              </button>
            </div>

            <div class="mt-3 space-y-3">
              <div v-if="domainRules.length === 0" class="rounded-xl border border-slate-700/50 bg-slate-950/25 px-3 py-3 text-xs text-slate-400">
                No domains configured.
              </div>

              <div
                v-for="(rule, ruleIndex) in domainRules"
                :key="rule.domain"
                class="rounded-2xl border border-slate-700/50 bg-slate-950/25 p-3"
              >
                <div class="mb-3 flex items-center gap-2">
                  <template v-if="rule.isEditingDomain">
                    <input
                      v-model="rule.draftDomain"
                      class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                      @keydown.enter.prevent="saveDomainEdit(ruleIndex)"
                      @keydown.esc.prevent="cancelDomainEdit(ruleIndex)"
                    />
                    <button
                      type="button"
                      class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                      @click="saveDomainEdit(ruleIndex)"
                      aria-label="Save domain"
                      title="Save domain"
                    >
                      <Check class="size-4" />
                    </button>
                    <button
                      type="button"
                      class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                      @click="cancelDomainEdit(ruleIndex)"
                      aria-label="Cancel domain edit"
                      title="Cancel domain edit"
                    >
                      <X class="size-4" />
                    </button>
                  </template>

                  <template v-else>
                    <div class="min-w-0 flex-1 truncate font-mono text-sm text-slate-100" :title="rule.domain">
                      {{ rule.domain }}
                    </div>
                    <button
                      type="button"
                      class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                      @click="startDomainEdit(ruleIndex)"
                      aria-label="Edit domain"
                      title="Edit domain"
                    >
                      <Pencil class="size-4" />
                    </button>
                    <button
                      type="button"
                      class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                      @click="removeDomainRule(ruleIndex)"
                      aria-label="Delete domain"
                      title="Delete domain"
                    >
                      <Trash2 class="size-4" />
                    </button>
                  </template>
                </div>

                <div class="flex items-center gap-2">
                  <input
                    v-model="rule.addPattern"
                    class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                    :placeholder="patternPlaceholder"
                    @keydown.enter.prevent="addPatternsToRule(ruleIndex)"
                  />
                  <button
                    type="button"
                    class="grid size-9 place-items-center rounded-xl bg-sky-400 text-slate-950 hover:bg-sky-300"
                    @click="addPatternsToRule(ruleIndex)"
                    aria-label="Add pattern"
                    title="Add pattern"
                  >
                    <Plus class="size-4" />
                  </button>
                </div>

                <div class="mt-3 overflow-hidden rounded-xl border border-slate-700/50 bg-slate-950/40">
                  <div v-if="rule.patterns.length === 0" class="px-3 py-3 text-xs text-slate-400">
                    No patterns defined. Fallback scanning will apply for this domain.
                  </div>
                  <ul v-else class="divide-y divide-slate-700/50">
                    <li
                      v-for="(pattern, patternIndex) in rule.patterns"
                      :key="pattern.value"
                      class="flex items-center gap-2 px-3 py-2.5"
                    >
                      <template v-if="pattern.isEditing">
                        <input
                          v-model="pattern.draft"
                          class="min-w-0 flex-1 rounded-xl border border-slate-700 bg-slate-950/40 px-3 py-2 text-sm outline-none focus:border-sky-400/70"
                          @keydown.enter.prevent="savePatternEdit(ruleIndex, patternIndex)"
                          @keydown.esc.prevent="cancelPatternEdit(ruleIndex, patternIndex)"
                        />
                        <button
                          type="button"
                          class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                          @click="savePatternEdit(ruleIndex, patternIndex)"
                          aria-label="Save pattern"
                          title="Save pattern"
                        >
                          <Check class="size-4" />
                        </button>
                        <button
                          type="button"
                          class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                          @click="cancelPatternEdit(ruleIndex, patternIndex)"
                          aria-label="Cancel pattern edit"
                          title="Cancel pattern edit"
                        >
                          <X class="size-4" />
                        </button>
                      </template>

                      <template v-else>
                        <div class="min-w-0 flex-1 truncate font-mono text-sm text-slate-100" :title="pattern.value">
                          {{ pattern.value }}
                        </div>
                        <button
                          type="button"
                          class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                          @click="startPatternEdit(ruleIndex, patternIndex)"
                          aria-label="Edit pattern"
                          title="Edit pattern"
                        >
                          <Pencil class="size-4" />
                        </button>
                        <button
                          type="button"
                          class="grid size-9 place-items-center rounded-xl border border-slate-700 bg-slate-800/40 hover:bg-slate-800/70"
                          @click="removePattern(ruleIndex, patternIndex)"
                          aria-label="Delete pattern"
                          title="Delete pattern"
                        >
                          <Trash2 class="size-4" />
                        </button>
                      </template>
                    </li>
                  </ul>
                </div>
              </div>
            </div>

            <p class="mt-2 text-xs text-slate-400">
              Rules are selected by the current page domain. Regex patterns are matched against full candidate URLs regardless of candidate host.
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
