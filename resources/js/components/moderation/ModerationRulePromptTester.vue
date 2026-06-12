<script setup lang="ts">
import { computed } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import Textarea from '@/components/ui/Textarea.vue';

interface Props {
    modelValue: string;
    isTesting: boolean;
    error: string | null;
    highlightHits: string[];
}

interface HighlightSegment {
    key: string;
    text: string;
    highlighted: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const highlightedPromptSegments = computed(() => buildHighlightedSegments(props.modelValue, props.highlightHits));
const hasPromptHighlight = computed(() => highlightedPromptSegments.value.some((segment) => segment.highlighted));

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function buildHighlightedSegments(text: string, hits: string[]): HighlightSegment[] {
    if (!text || hits.length === 0) {
        return text ? [{ key: 'text-0', text, highlighted: false }] : [];
    }

    const intervals: Array<{ start: number; end: number }> = [];
    const uniqueHits = [...new Set(hits.map((hit) => hit.trim()).filter(Boolean))];

    for (const hit of uniqueHits) {
        const pattern = new RegExp(escapeRegExp(hit), 'gi');
        let match: RegExpExecArray | null;

        while ((match = pattern.exec(text)) !== null) {
            intervals.push({ start: match.index, end: match.index + match[0].length });

            if (match[0].length === 0) {
                pattern.lastIndex++;
            }
        }
    }

    if (intervals.length === 0) {
        return [{ key: 'text-0', text, highlighted: false }];
    }

    intervals.sort((a, b) => a.start - b.start || b.end - a.end);

    const mergedIntervals: Array<{ start: number; end: number }> = [];
    for (const interval of intervals) {
        const previous = mergedIntervals[mergedIntervals.length - 1];

        if (previous && interval.start <= previous.end) {
            previous.end = Math.max(previous.end, interval.end);
        } else {
            mergedIntervals.push({ ...interval });
        }
    }

    const segments: HighlightSegment[] = [];
    let cursor = 0;

    for (const interval of mergedIntervals) {
        if (interval.start > cursor) {
            segments.push({
                key: `text-${segments.length}`,
                text: text.slice(cursor, interval.start),
                highlighted: false,
            });
        }

        segments.push({
            key: `match-${segments.length}`,
            text: text.slice(interval.start, interval.end),
            highlighted: true,
        });
        cursor = interval.end;
    }

    if (cursor < text.length) {
        segments.push({
            key: `text-${segments.length}`,
            text: text.slice(cursor),
            highlighted: false,
        });
    }

    return segments;
}
</script>

<template>
    <div class="space-y-3 border-b border-twilight-indigo-500/30 p-4">
        <div class="flex items-center justify-between gap-3">
            <label class="text-sm font-medium text-regal-navy-100">Prompt Test</label>
            <div v-if="isTesting" class="flex items-center gap-2 text-xs text-twilight-indigo-200">
                <Loader2 :size="14" class="animate-spin text-smart-blue-400" />
                <span>Testing...</span>
            </div>
        </div>
        <Textarea
            :model-value="modelValue"
            :rows="5"
            placeholder="Paste prompt text..."
            data-test="moderation-rule-test-textarea"
            class="text-sm"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <div
            v-if="error"
            class="rounded border border-danger-500/40 bg-danger-500/10 p-2 text-xs text-danger-100"
            data-test="moderation-rule-test-error"
        >
            {{ error }}
        </div>
        <div
            v-if="hasPromptHighlight"
            class="max-h-32 overflow-y-auto whitespace-pre-wrap wrap-break-word rounded border border-amber-300/30 bg-prussian-blue-900/55 p-3 text-sm text-twilight-indigo-100"
            data-test="moderation-rule-highlighted-prompt"
        >
            <span
                v-for="segment in highlightedPromptSegments"
                :key="segment.key"
                :data-test="segment.highlighted ? 'moderation-rule-highlight' : undefined"
                :class="segment.highlighted ? 'rounded bg-amber-300/80 px-0.5 font-semibold text-prussian-blue-950' : ''"
            >{{ segment.text }}</span>
        </div>
    </div>
</template>
