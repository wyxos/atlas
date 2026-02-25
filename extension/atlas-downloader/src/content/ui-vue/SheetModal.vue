<script setup lang="ts">
type AtlasReaction = {
  type?: unknown;
};

type AtlasInfo = {
  exists?: boolean;
  downloaded?: boolean;
  blacklisted?: boolean;
  reaction?: AtlasReaction | null;
};

type SheetItem = {
  index: number;
  tag_name: string;
  url: string;
  preview_url?: string;
  width?: number | null;
  height?: number | null;
  selected: boolean;
  status?: string;
  statusClass?: string;
  atlas?: AtlasInfo | null;
  reactionPending?: string | null;
  reactionQueued?: string | null;
};

type ReactionAction = {
  type: string;
  className: string;
  label: string;
  pathDs: string[];
};

const props = defineProps<{
  open: boolean;
  version: string;
  metaText: string;
  items: SheetItem[];
  queueDisabled: boolean;
  refreshDisabled: boolean;
  checkAtlasDisabled: boolean;
  selectAllDisabled: boolean;
  selectNoneDisabled: boolean;
  debugTargetUrl: string | null;
  debugPayloads: Record<string, unknown>;
  reactions: ReactionAction[];
  blacklistAction: ReactionAction;
}>();

const emit = defineEmits<{
  close: [];
  refresh: [];
  checkAtlas: [];
  selectAll: [];
  selectNone: [];
  queueSelected: [];
  toggleRow: [index: number];
  updateSelected: [index: number, selected: boolean];
  react: [index: number, type: string];
  blacklist: [index: number];
  deleteDownload: [index: number];
  toggleDebug: [index: number];
}>();

function formatSubline(item: SheetItem): string {
  const dims =
    item.width && item.height ? `${item.width}×${item.height}` : 'size unknown';
  const host = safeHost(item.url);
  return host ? `${dims} • ${host}` : dims;
}

function safeHost(url: string): string {
  try {
    return new URL(url).hostname;
  } catch {
    return '';
  }
}

function getDisplayStatus(item: SheetItem): { text: string; className: string } {
  if (item.status) {
    return {
      text: item.status,
      className: item.statusClass || '',
    };
  }

  if (item.atlas?.downloaded) {
    return { text: 'Downloaded', className: 'ok' };
  }

  if (item.atlas?.blacklisted) {
    return { text: 'Blacklisted', className: 'err' };
  }

  if (item.atlas?.exists) {
    return { text: 'In Atlas', className: '' };
  }

  return { text: '', className: '' };
}

function reactionButtonClass(item: SheetItem, reaction: ReactionAction): string {
  const currentReaction = item.atlas?.reaction?.type ? String(item.atlas.reaction.type) : null;
  const base = `atlas-downloader-reaction-btn ${reaction.className}`;
  const active = currentReaction === reaction.type ? ' active' : '';
  const pending = item.reactionPending === reaction.type ? ' pending' : '';
  const queued = item.reactionQueued === reaction.type ? ' queued' : '';
  return `${base}${active}${pending}${queued}`.trim();
}

function blacklistButtonClass(item: SheetItem): string {
  const base = `atlas-downloader-reaction-btn ${props.blacklistAction.className}`;
  const active = item.atlas?.blacklisted ? ' active' : '';
  const pending = item.reactionPending === props.blacklistAction.type ? ' pending' : '';
  const queued = item.reactionQueued === props.blacklistAction.type ? ' queued' : '';
  return `${base}${active}${pending}${queued}`.trim();
}

function deleteButtonClass(item: SheetItem): string {
  const pending = item.reactionPending === 'delete-download' ? ' pending' : '';
  return `atlas-downloader-reaction-btn delete${pending}`.trim();
}

function isItemBusy(item: SheetItem): boolean {
  return Boolean(item.reactionPending) || Boolean(item.reactionQueued);
}

function onSelectionChange(index: number, event: Event): void {
  const target = event.target;
  if (!(target instanceof HTMLInputElement)) {
    return;
  }

  emit('updateSelected', index, target.checked);
}
</script>

<template>
  <div class="atlas-shadow-root" :class="{ 'atlas-open': open }">
    <div class="atlas-downloader-overlay" @click="emit('close')" />

    <div
      class="atlas-downloader-modal"
      role="dialog"
      aria-modal="true"
      aria-label="Atlas Media Picker"
    >
      <div class="atlas-downloader-header">
        <div class="atlas-downloader-title">Atlas Media Picker</div>
        <div class="atlas-downloader-version">{{ version ? `v${version}` : '' }}</div>
        <button
          type="button"
          class="atlas-downloader-close"
          aria-label="Close"
          @click="emit('close')"
        >
          x
        </button>
      </div>

      <div class="atlas-downloader-toolbar">
        <button
          type="button"
          class="atlas-downloader-btn"
          :disabled="refreshDisabled"
          @click="emit('refresh')"
        >
          Rescan
        </button>
        <button
          type="button"
          class="atlas-downloader-btn"
          :disabled="checkAtlasDisabled"
          @click="emit('checkAtlas')"
        >
          Check Atlas
        </button>
        <button
          type="button"
          class="atlas-downloader-btn"
          :disabled="selectAllDisabled"
          @click="emit('selectAll')"
        >
          Select all
        </button>
        <button
          type="button"
          class="atlas-downloader-btn"
          :disabled="selectNoneDisabled"
          @click="emit('selectNone')"
        >
          Select none
        </button>
        <span class="spacer" />
        <button
          type="button"
          class="atlas-downloader-btn primary"
          :disabled="queueDisabled"
          @click="emit('queueSelected')"
        >
          Queue selected
        </button>
      </div>

      <div class="atlas-downloader-meta">{{ metaText }}</div>
      <div class="atlas-downloader-list">
        <div
          v-if="items.length === 0"
          style="padding: 10px 12px; color: #94a3b8; font-size: 12px;"
        >
          No matching images/videos found.
        </div>

        <template v-else>
          <div
            v-for="item in items"
            :key="`${item.index}-${item.url}`"
            class="atlas-downloader-item"
            :class="{ selected: item.selected }"
            @click="emit('toggleRow', item.index)"
          >
          <input
            type="checkbox"
            :checked="item.selected"
            @click.stop
            @change="onSelectionChange(item.index, $event)"
          />

          <div class="atlas-downloader-preview">
            <img
              v-if="item.preview_url"
              :src="item.preview_url"
              loading="lazy"
              alt=""
            >
          </div>

          <div class="atlas-downloader-info">
            <div class="atlas-downloader-kind">{{ item.tag_name }}</div>
            <div class="atlas-downloader-url" :title="item.url">{{ item.url }}</div>
            <div class="atlas-downloader-sub">{{ formatSubline(item) }}</div>

            <div class="atlas-downloader-reactions">
              <button
                v-for="reaction in reactions"
                :key="reaction.type"
                type="button"
                :class="reactionButtonClass(item, reaction)"
                :aria-label="reaction.label"
                :title="reaction.label"
                :disabled="isItemBusy(item)"
                @click.stop="emit('react', item.index, reaction.type)"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path
                    v-for="pathD in reaction.pathDs"
                    :key="pathD"
                    :d="pathD"
                  />
                </svg>
              </button>

              <button
                type="button"
                :class="blacklistButtonClass(item)"
                :aria-label="blacklistAction.label"
                :title="blacklistAction.label"
                :disabled="isItemBusy(item)"
                @click.stop="emit('blacklist', item.index)"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path
                    v-for="pathD in blacklistAction.pathDs"
                    :key="pathD"
                    :d="pathD"
                  />
                </svg>
              </button>

              <button
                v-if="item.atlas?.downloaded"
                type="button"
                :class="deleteButtonClass(item)"
                aria-label="Delete download"
                title="Delete download"
                :disabled="isItemBusy(item)"
                @click.stop="emit('deleteDownload', item.index)"
              >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M3 6h18" />
                  <path d="M8 6V4h8v2" />
                  <path d="M8 10v8" />
                  <path d="M12 10v8" />
                  <path d="M16 10v8" />
                  <path d="M6 6l1 14h10l1-14" />
                </svg>
              </button>

              <button
                type="button"
                class="atlas-downloader-debug-toggle"
                :class="{ active: debugTargetUrl === item.url }"
                @click.stop="emit('toggleDebug', item.index)"
              >
                {{ debugTargetUrl === item.url ? 'Hide debug' : 'Debug' }}
              </button>
            </div>

            <details
              v-if="debugTargetUrl === item.url"
              class="atlas-downloader-debug"
              open
            >
              <summary>Debug payload</summary>
              <pre class="atlas-downloader-json-tree">{{ JSON.stringify(debugPayloads[item.url] ?? {}, null, 2) }}</pre>
            </details>
          </div>

          <div class="atlas-downloader-status" :class="getDisplayStatus(item).className">
            {{ getDisplayStatus(item).text }}
          </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
