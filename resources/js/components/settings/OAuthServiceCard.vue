<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

type OAuthServiceStatus = {
    label: string;
    configured: boolean;
    missing_configuration: string[];
    connected: boolean;
    session_valid: boolean;
    needs_reconnect: boolean;
    scopes: string[];
    expires_at: string | null;
    last_error: string | null;
};

const props = withDefaults(
    defineProps<{
        service: OAuthServiceStatus | null;
        description: string;
        accountName: string;
        isLoading: boolean;
        isRefreshing: boolean;
        isDisconnecting: boolean;
        loadingLabel?: string;
    }>(),
    {
        loadingLabel: 'Loading service status...',
    },
);

const emit = defineEmits<{
    connect: [];
    refresh: [];
    disconnect: [];
}>();

const isConnected = computed(() => props.service?.connected === true);
const needsReconnect = computed(() => props.service?.needs_reconnect === true);
const isConfigured = computed(() => props.service?.configured === true);
const hasValidSession = computed(() => props.service?.session_valid === true);
const label = computed(() => props.service?.label ?? 'Service');
const statusLabel = computed(() => {
    if (isConnected.value && hasValidSession.value) {
        return 'Connected';
    }

    if (needsReconnect.value) {
        return 'Reconnect required';
    }

    return 'Disconnected';
});
const statusClass = computed(() => {
    if (isConnected.value && hasValidSession.value) {
        return 'border-smart-blue-400 text-smart-blue-200 bg-smart-blue-500/10';
    }

    if (needsReconnect.value) {
        return 'border-danger-400 text-danger-200 bg-danger-500/10';
    }

    return 'border-twilight-indigo-500 text-twilight-indigo-200 bg-prussian-blue-700/60';
});
const accountSummary = computed(() => (isConnected.value ? props.accountName : 'Not connected'));
const scopeSummary = computed(() => {
    const scopes = props.service?.scopes ?? [];

    return scopes.length > 0 ? scopes.join(', ') : 'No scopes granted yet.';
});
const expirySummary = computed(() => {
    const expiresAt = props.service?.expires_at ?? null;
    if (!expiresAt) {
        return 'No active session expiry set.';
    }

    const date = new Date(expiresAt);
    if (Number.isNaN(date.getTime())) {
        return 'Unable to parse session expiry.';
    }

    return date.toLocaleString();
});
</script>

<template>
    <div class="border border-twilight-indigo-500/60 rounded-lg p-4 bg-prussian-blue-600/60 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h6 class="text-base font-semibold text-regal-navy-100">{{ label }}</h6>
                <p class="text-sm text-twilight-indigo-200">
                    {{ description }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xs px-2 py-1 rounded-full border" :class="statusClass">
                    {{ statusLabel }}
                </span>

                <Button
                    variant="outline"
                    size="sm"
                    :disabled="isLoading || isRefreshing || isDisconnecting || !isConfigured"
                    @click="emit('connect')"
                >
                    {{ isConnected ? 'Reconnect' : 'Connect' }}
                </Button>

                <Button
                    variant="secondary"
                    size="sm"
                    :loading="isRefreshing"
                    :disabled="!isConnected || isDisconnecting"
                    @click="emit('refresh')"
                >
                    Refresh Session
                </Button>

                <Button
                    variant="destructive"
                    size="sm"
                    :loading="isDisconnecting"
                    :disabled="!isConnected || isRefreshing"
                    @click="emit('disconnect')"
                >
                    Disconnect
                </Button>
            </div>
        </div>

        <div v-if="isLoading" class="text-sm text-twilight-indigo-200">
            {{ loadingLabel }}
        </div>

        <template v-else>
            <div class="grid gap-3 text-sm text-twilight-indigo-100 md:grid-cols-2">
                <p>
                    <span class="text-twilight-indigo-300">Configured:</span>
                    {{ isConfigured ? 'Yes' : 'No' }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Session valid:</span>
                    {{ hasValidSession ? 'Yes' : 'No' }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Account:</span>
                    {{ accountSummary }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Session expires:</span>
                    {{ expirySummary }}
                </p>
            </div>

            <p class="text-xs text-twilight-indigo-300">
                <span class="text-twilight-indigo-200">Granted scopes:</span> {{ scopeSummary }}
            </p>

            <p v-if="service?.missing_configuration?.length" class="text-xs text-danger-200">
                Missing server configuration: {{ service.missing_configuration.join(', ') }}
            </p>

            <p v-if="service?.last_error" class="text-xs text-danger-200">
                {{ service.last_error }}
            </p>
        </template>
    </div>
</template>
