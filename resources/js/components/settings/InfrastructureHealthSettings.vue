<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { CheckCircle2, CircleAlert, CircleHelp, RefreshCw } from 'lucide-vue-next';

type InfrastructureHealthStatus = 'healthy' | 'unhealthy' | 'unknown';

type HealthNamespace = {
    name: string;
    exists: boolean;
};

type TypesenseHealth = {
    ok: boolean;
    status: InfrastructureHealthStatus;
    endpoint: string;
    message: string;
    latency_ms: number;
    response_ok: boolean | null;
};

type StorageHealth = {
    ok: boolean;
    status: InfrastructureHealthStatus;
    disk: string;
    root: string;
    app_root: string;
    root_exists: boolean;
    app_root_exists: boolean;
    readable: boolean;
    writable: boolean;
    write_probe: boolean;
    read_probe: boolean;
    delete_probe: boolean;
    free_bytes: number | null;
    total_bytes: number | null;
    namespaces: HealthNamespace[];
    message: string;
    latency_ms: number;
};

type InfrastructureHealthResponse = {
    checked_at: string;
    typesense: TypesenseHealth;
    storage: StorageHealth;
};

const infrastructureHealth = ref<InfrastructureHealthResponse | null>(null);
const infrastructureNotice = ref('');
const infrastructureNoticeTone = ref<'success' | 'error' | 'neutral'>('neutral');
const isInfrastructureHealthLoading = ref(false);
const infrastructureOverallStatus = computed<InfrastructureHealthStatus>(() => {
    if (!infrastructureHealth.value) {
        return 'unknown';
    }

    return infrastructureHealth.value.typesense.ok && infrastructureHealth.value.storage.ok ? 'healthy' : 'unhealthy';
});

function setInfrastructureNotice(message: string, tone: 'success' | 'error' | 'neutral' = 'neutral'): void {
    infrastructureNotice.value = message;
    infrastructureNoticeTone.value = tone;
}

async function handlePingInfrastructure(): Promise<void> {
    infrastructureNotice.value = '';
    isInfrastructureHealthLoading.value = true;

    try {
        const { data } = await window.axios.get<InfrastructureHealthResponse>('/api/settings/infrastructure-health');
        infrastructureHealth.value = data;
        setInfrastructureNotice(
            data.typesense.ok && data.storage.ok
                ? 'Infrastructure checks passed.'
                : 'One or more infrastructure checks failed.',
            data.typesense.ok && data.storage.ok ? 'success' : 'error',
        );
    } catch (error: unknown) {
        const responseMessage = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
        setInfrastructureNotice(responseMessage || 'Failed to ping infrastructure health.', 'error');
    } finally {
        isInfrastructureHealthLoading.value = false;
    }
}

function healthStatusClass(status: InfrastructureHealthStatus): string {
    if (status === 'healthy') {
        return 'border-smart-blue-400 text-smart-blue-200 bg-smart-blue-500/10';
    }

    if (status === 'unhealthy') {
        return 'border-danger-400 text-danger-200 bg-danger-500/10';
    }

    return 'border-twilight-indigo-500 text-twilight-indigo-200 bg-prussian-blue-700/60';
}

function healthStatusLabel(status: InfrastructureHealthStatus): string {
    if (status === 'healthy') {
        return 'Healthy';
    }

    if (status === 'unhealthy') {
        return 'Unhealthy';
    }

    return 'Not checked';
}

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return 'Unknown';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function formatCheckedAt(value: string | null): string {
    if (!value) {
        return 'Not checked yet';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
}
</script>

<template>
    <div class="border border-smart-blue-500/30 rounded-lg p-6 bg-prussian-blue-700/50" data-test="infrastructure-health">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Infrastructure Health</h5>
                <p class="text-twilight-indigo-200">
                    Ping Typesense and Atlas storage from the app runtime.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs px-2 py-1 rounded-full border" :class="healthStatusClass(infrastructureOverallStatus)">
                    {{ healthStatusLabel(infrastructureOverallStatus) }}
                </span>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    :loading="isInfrastructureHealthLoading"
                    data-test="ping-infrastructure-health"
                    @click="handlePingInfrastructure"
                >
                    <RefreshCw :size="14" />
                    Ping Health
                </Button>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-twilight-indigo-500/60 bg-prussian-blue-600/60 p-4" data-test="typesense-health">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h6 class="text-base font-semibold text-regal-navy-100">Typesense</h6>
                    <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full border" :class="healthStatusClass(infrastructureHealth?.typesense.status ?? 'unknown')">
                        <CheckCircle2 v-if="infrastructureHealth?.typesense.status === 'healthy'" :size="14" />
                        <CircleAlert v-else-if="infrastructureHealth?.typesense.status === 'unhealthy'" :size="14" />
                        <CircleHelp v-else :size="14" />
                        {{ healthStatusLabel(infrastructureHealth?.typesense.status ?? 'unknown') }}
                    </span>
                </div>
                <dl class="space-y-2 text-sm text-twilight-indigo-100">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Endpoint</dt>
                        <dd class="truncate font-mono" :title="infrastructureHealth?.typesense.endpoint ?? 'Not checked yet'">
                            {{ infrastructureHealth?.typesense.endpoint ?? 'Not checked yet' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Result</dt>
                        <dd>{{ infrastructureHealth?.typesense.message ?? 'Run a ping to check Typesense.' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Latency</dt>
                        <dd>{{ infrastructureHealth ? `${infrastructureHealth.typesense.latency_ms} ms` : 'Not checked yet' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-twilight-indigo-500/60 bg-prussian-blue-600/60 p-4" data-test="storage-health">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h6 class="text-base font-semibold text-regal-navy-100">Storage</h6>
                    <span class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full border" :class="healthStatusClass(infrastructureHealth?.storage.status ?? 'unknown')">
                        <CheckCircle2 v-if="infrastructureHealth?.storage.status === 'healthy'" :size="14" />
                        <CircleAlert v-else-if="infrastructureHealth?.storage.status === 'unhealthy'" :size="14" />
                        <CircleHelp v-else :size="14" />
                        {{ healthStatusLabel(infrastructureHealth?.storage.status ?? 'unknown') }}
                    </span>
                </div>
                <dl class="space-y-2 text-sm text-twilight-indigo-100">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">App Root</dt>
                        <dd class="truncate font-mono" :title="infrastructureHealth?.storage.app_root ?? 'Not checked yet'">
                            {{ infrastructureHealth?.storage.app_root ?? 'Not checked yet' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Result</dt>
                        <dd>{{ infrastructureHealth?.storage.message ?? 'Run a ping to check storage.' }}</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Write</dt>
                            <dd>{{ infrastructureHealth?.storage.write_probe ? 'Passed' : 'Not checked' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Free</dt>
                            <dd>{{ formatBytes(infrastructureHealth?.storage.free_bytes ?? null) }}</dd>
                        </div>
                    </div>
                    <div v-if="infrastructureHealth?.storage.namespaces.length">
                        <dt class="text-xs uppercase tracking-wide text-twilight-indigo-300">Namespaces</dt>
                        <dd class="flex flex-wrap gap-2">
                            <span
                                v-for="namespace in infrastructureHealth.storage.namespaces"
                                :key="namespace.name"
                                class="rounded border px-2 py-0.5 text-xs"
                                :class="namespace.exists
                                    ? 'border-smart-blue-500/40 text-smart-blue-200'
                                    : 'border-danger-500/40 text-danger-200'"
                            >
                                {{ namespace.name }} {{ namespace.exists ? 'ok' : 'missing' }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <p class="mt-4 text-xs text-twilight-indigo-300">
            Last checked: {{ formatCheckedAt(infrastructureHealth?.checked_at ?? null) }}.
        </p>

        <p
            v-if="infrastructureNotice"
            class="mt-3 text-sm"
            :class="infrastructureNoticeTone === 'success'
                ? 'text-smart-blue-200'
                : infrastructureNoticeTone === 'error'
                    ? 'text-danger-200'
                    : 'text-twilight-indigo-200'"
        >
            {{ infrastructureNotice }}
        </p>
    </div>
</template>
