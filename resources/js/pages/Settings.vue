<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import PageLayout from '../components/PageLayout.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from '@/components/ui/dialog';
import { deleteAll as deleteAllTabs } from '@/actions/App/Http/Controllers/TabController';
import { deleteAll as deleteAllFiles } from '@/actions/App/Http/Controllers/FilesController';
import { AlertTriangle } from 'lucide-vue-next';

const router = useRouter();
const resetDialogOpen = ref(false);
const isResetting = ref(false);
const servicesNotice = ref('');
const servicesNoticeTone = ref<'success' | 'error' | 'neutral'>('neutral');
const isServicesLoading = ref(false);
const isSpotifyRefreshing = ref(false);
const isSpotifyDisconnecting = ref(false);
const browserExtensionDownloadUrl = '/settings/browser-extension/download';

type SpotifyServiceAccount = {
    id: string | null;
    display_name: string | null;
    email: string | null;
    product: string | null;
    country: string | null;
};

type SpotifyServiceStatus = {
    key: 'spotify';
    label: string;
    configured: boolean;
    missing_configuration: string[];
    connected: boolean;
    session_valid: boolean;
    needs_reconnect: boolean;
    can_refresh: boolean;
    scopes: string[];
    expires_at: string | null;
    expires_in_seconds: number | null;
    account: SpotifyServiceAccount | null;
    last_error: string | null;
    connect_url: string;
};

type SettingsServicesResponse = {
    spotify: SpotifyServiceStatus;
    extension: {
        api_key_configured: boolean;
        default_domain: string;
    };
};

const spotifyService = ref<SpotifyServiceStatus | null>(null);
const extensionApiKeyConfigured = ref(false);
const extensionDefaultDomain = ref('https://atlas.test');
const generatedExtensionApiKey = ref('');
const showGeneratedExtensionApiKey = ref(false);
const extensionNotice = ref('');
const extensionNoticeTone = ref<'success' | 'error' | 'neutral'>('neutral');
const isExtensionApiKeyGenerating = ref(false);
const spotifyIsConnected = computed(() => spotifyService.value?.connected === true);
const spotifyNeedsReconnect = computed(() => spotifyService.value?.needs_reconnect === true);
const spotifyIsConfigured = computed(() => spotifyService.value?.configured === true);
const spotifyHasValidSession = computed(() => spotifyService.value?.session_valid === true);
const spotifyAccountName = computed(() => {
    const displayName = spotifyService.value?.account?.display_name?.trim() ?? '';
    if (displayName !== '') {
        return displayName;
    }

    return spotifyService.value?.account?.id ?? 'Connected account';
});
const spotifyScopeSummary = computed(() => {
    const scopes = spotifyService.value?.scopes ?? [];

    return scopes.length > 0 ? scopes.join(', ') : 'No scopes granted yet.';
});
const spotifyExpirySummary = computed(() => {
    const expiresAt = spotifyService.value?.expires_at ?? null;
    if (!expiresAt) {
        return 'No active session expiry set.';
    }

    const date = new Date(expiresAt);
    if (Number.isNaN(date.getTime())) {
        return 'Unable to parse session expiry.';
    }

    return date.toLocaleString();
});

function setServicesNotice(message: string, tone: 'success' | 'error' | 'neutral' = 'neutral'): void {
    servicesNotice.value = message;
    servicesNoticeTone.value = tone;
}

function setExtensionNotice(message: string, tone: 'success' | 'error' | 'neutral' = 'neutral'): void {
    extensionNotice.value = message;
    extensionNoticeTone.value = tone;
}

function consumeSpotifyNoticeFromUrl(): void {
    const params = new URLSearchParams(window.location.search);
    const notice = params.get('spotify_notice');
    const reason = (params.get('spotify_reason') ?? '').trim();

    if (!notice) {
        return;
    }

    if (notice === 'connected') {
        setServicesNotice('Spotify connected successfully.', 'success');
    } else if (notice === 'error') {
        const reasonMessages: Record<string, string> = {
            not_configured: 'Spotify is not configured on the server yet.',
            invalid_state: 'Spotify login state expired or was invalid. Please try again.',
            missing_code_or_state: 'Spotify callback was incomplete. Please retry connection.',
            token_exchange_failed: 'Failed to finalize Spotify connection.',
            access_denied: 'Spotify authorization was cancelled.',
        };
        const mapped = reasonMessages[reason] ?? reason;
        setServicesNotice(mapped !== '' ? mapped : 'Spotify connection failed.', 'error');
    }

    params.delete('spotify_notice');
    params.delete('spotify_reason');
    const queryString = params.toString();
    const nextUrl = `${window.location.pathname}${queryString ? `?${queryString}` : ''}${window.location.hash}`;
    window.history.replaceState({}, '', nextUrl);
}

async function fetchServices(): Promise<void> {
    isServicesLoading.value = true;
    try {
        const { data } = await window.axios.get<SettingsServicesResponse>('/api/settings/services');
        spotifyService.value = data.spotify;
        extensionApiKeyConfigured.value = data.extension.api_key_configured === true;
        extensionDefaultDomain.value = data.extension.default_domain || 'https://atlas.test';
    } catch (error) {
        console.error('Failed to fetch settings services:', error);
        setServicesNotice('Failed to load services status.', 'error');
    } finally {
        isServicesLoading.value = false;
    }
}

async function handleGenerateExtensionApiKey(): Promise<void> {
    extensionNotice.value = '';
    isExtensionApiKeyGenerating.value = true;

    try {
        const { data } = await window.axios.post<{ api_key: string; api_key_configured: boolean }>(
            '/api/settings/extension/generate',
        );

        extensionApiKeyConfigured.value = data.api_key_configured === true;
        generatedExtensionApiKey.value = data.api_key;
        showGeneratedExtensionApiKey.value = false;

        try {
            await navigator.clipboard.writeText(data.api_key);
            setExtensionNotice('New API key generated, saved, and copied to clipboard.', 'success');
        } catch {
            setExtensionNotice('New API key generated and saved, but clipboard copy failed.', 'error');
        }
    } catch (error: unknown) {
        const responseMessage = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
        setExtensionNotice(responseMessage || 'Failed to generate extension API key.', 'error');
    } finally {
        isExtensionApiKeyGenerating.value = false;
    }
}

function handleConnectSpotify(): void {
    const connectUrl = spotifyService.value?.connect_url || '/auth/spotify/redirect';
    window.location.assign(connectUrl);
}

async function handleRefreshSpotify(): Promise<void> {
    isSpotifyRefreshing.value = true;
    try {
        const { data } = await window.axios.post<{ spotify: SpotifyServiceStatus; message: string }>(
            '/api/settings/services/spotify/refresh',
        );
        spotifyService.value = data.spotify;
        setServicesNotice(data.message || 'Spotify session refreshed.', 'success');
    } catch (error: unknown) {
        const responseMessage = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
        setServicesNotice(responseMessage || 'Failed to refresh Spotify session.', 'error');
        await fetchServices();
    } finally {
        isSpotifyRefreshing.value = false;
    }
}

async function handleDisconnectSpotify(): Promise<void> {
    if (!window.confirm('Disconnect Spotify from this account?')) {
        return;
    }

    isSpotifyDisconnecting.value = true;
    try {
        const { data } = await window.axios.delete<{ spotify: SpotifyServiceStatus; message: string }>(
            '/api/settings/services/spotify',
        );
        spotifyService.value = data.spotify;
        setServicesNotice(data.message || 'Spotify disconnected.', 'success');
    } catch (error: unknown) {
        const responseMessage = (error as { response?: { data?: { message?: string } } })?.response?.data?.message;
        setServicesNotice(responseMessage || 'Failed to disconnect Spotify.', 'error');
        await fetchServices();
    } finally {
        isSpotifyDisconnecting.value = false;
    }
}

async function handleResetApp(): Promise<void> {
    isResetting.value = true;
    try {
        // Delete all files first
        await window.axios.delete(deleteAllFiles.url());

        // Then delete all tabs
        await window.axios.delete(deleteAllTabs.url());

        // Close dialog
        resetDialogOpen.value = false;

        // Redirect to home page
        router.push('/');
    } catch (error) {
        console.error('Failed to reset app:', error);
        // Error is logged, user can try again
    } finally {
        isResetting.value = false;
    }
}

onMounted(() => {
    consumeSpotifyNoticeFromUrl();
    void fetchServices();
});
</script>

<template>
    <PageLayout>
        <div>
            <h4 class="text-2xl font-semibold text-regal-navy-100 mb-4">Settings</h4>

            <div class="space-y-6">
                <div class="border border-smart-blue-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div>
                            <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Services</h5>
                            <p class="text-twilight-indigo-200">
                                Connect external providers to unlock API feeds and playback integrations.
                            </p>
                        </div>
                        <div class="text-xs px-3 py-1 rounded-full border border-twilight-indigo-500 text-twilight-indigo-100 bg-prussian-blue-600/70">
                            {{ spotifyIsConnected ? '1 connected' : 'No connected services' }}
                        </div>
                    </div>

                    <div class="border border-twilight-indigo-500/60 rounded-lg p-4 bg-prussian-blue-600/60 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h6 class="text-base font-semibold text-regal-navy-100">Spotify</h6>
                                <p class="text-sm text-twilight-indigo-200">
                                    OAuth for playlists, playback state, and Web Playback SDK features.
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="text-xs px-2 py-1 rounded-full border"
                                    :class="spotifyIsConnected && spotifyHasValidSession
                                        ? 'border-smart-blue-400 text-smart-blue-200 bg-smart-blue-500/10'
                                        : spotifyNeedsReconnect
                                            ? 'border-danger-400 text-danger-200 bg-danger-500/10'
                                            : 'border-twilight-indigo-500 text-twilight-indigo-200 bg-prussian-blue-700/60'"
                                >
                                    {{
                                        spotifyIsConnected && spotifyHasValidSession
                                            ? 'Connected'
                                            : spotifyNeedsReconnect
                                                ? 'Reconnect required'
                                                : 'Disconnected'
                                    }}
                                </span>

                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="isServicesLoading || isSpotifyRefreshing || isSpotifyDisconnecting || !spotifyIsConfigured"
                                    @click="handleConnectSpotify"
                                >
                                    {{ spotifyIsConnected ? 'Reconnect' : 'Connect' }}
                                </Button>

                                <Button
                                    variant="secondary"
                                    size="sm"
                                    :loading="isSpotifyRefreshing"
                                    :disabled="!spotifyIsConnected || isSpotifyDisconnecting"
                                    @click="handleRefreshSpotify"
                                >
                                    Refresh Session
                                </Button>

                                <Button
                                    variant="destructive"
                                    size="sm"
                                    :loading="isSpotifyDisconnecting"
                                    :disabled="!spotifyIsConnected || isSpotifyRefreshing"
                                    @click="handleDisconnectSpotify"
                                >
                                    Disconnect
                                </Button>
                            </div>
                        </div>

                        <div v-if="isServicesLoading" class="text-sm text-twilight-indigo-200">
                            Loading Spotify status...
                        </div>

                        <template v-else>
                            <div class="grid gap-3 text-sm text-twilight-indigo-100 md:grid-cols-2">
                                <p>
                                    <span class="text-twilight-indigo-300">Configured:</span>
                                    {{ spotifyIsConfigured ? 'Yes' : 'No' }}
                                </p>
                                <p>
                                    <span class="text-twilight-indigo-300">Session valid:</span>
                                    {{ spotifyHasValidSession ? 'Yes' : 'No' }}
                                </p>
                                <p>
                                    <span class="text-twilight-indigo-300">Account:</span>
                                    {{ spotifyIsConnected ? spotifyAccountName : 'Not connected' }}
                                </p>
                                <p>
                                    <span class="text-twilight-indigo-300">Session expires:</span>
                                    {{ spotifyExpirySummary }}
                                </p>
                            </div>

                            <p class="text-xs text-twilight-indigo-300">
                                <span class="text-twilight-indigo-200">Granted scopes:</span> {{ spotifyScopeSummary }}
                            </p>

                            <p
                                v-if="spotifyService?.missing_configuration?.length"
                                class="text-xs text-danger-200"
                            >
                                Missing server configuration: {{ spotifyService.missing_configuration.join(', ') }}
                            </p>

                            <p v-if="spotifyService?.last_error" class="text-xs text-danger-200">
                                {{ spotifyService.last_error }}
                            </p>
                        </template>
                    </div>

                    <p
                        v-if="servicesNotice"
                        class="mt-4 text-sm"
                        :class="servicesNoticeTone === 'success'
                            ? 'text-smart-blue-200'
                            : servicesNoticeTone === 'error'
                                ? 'text-danger-200'
                                : 'text-twilight-indigo-200'"
                    >
                        {{ servicesNotice }}
                    </p>
                </div>

                <div class="border border-smart-blue-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
                    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
                        <div>
                            <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Browser Extension</h5>
                            <p class="text-twilight-indigo-200">
                                Configure extension API access and download the latest package.
                            </p>
                        </div>
                        <Button as-child variant="secondary">
                            <a :href="browserExtensionDownloadUrl">
                                Download Extension
                            </a>
                        </Button>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mb-4">
                        <Button
                            type="button"
                            size="sm"
                            :loading="isExtensionApiKeyGenerating"
                            @click="handleGenerateExtensionApiKey"
                        >
                            Generate API Key
                        </Button>
                        <span
                            class="text-xs px-2 py-1 rounded-full border"
                            :class="extensionApiKeyConfigured
                                ? 'border-smart-blue-400 text-smart-blue-200 bg-smart-blue-500/10'
                                : 'border-twilight-indigo-500 text-twilight-indigo-200 bg-prussian-blue-700/60'"
                        >
                            {{ extensionApiKeyConfigured ? 'Configured' : 'Not configured' }}
                        </span>
                    </div>

                    <div v-if="generatedExtensionApiKey" class="space-y-2 mb-4">
                        <label class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">
                            Generated API Key
                        </label>
                        <div class="flex items-center gap-2">
                            <input
                                :type="showGeneratedExtensionApiKey ? 'text' : 'password'"
                                :value="generatedExtensionApiKey"
                                readonly
                                class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none"
                            />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="showGeneratedExtensionApiKey = !showGeneratedExtensionApiKey"
                            >
                                {{ showGeneratedExtensionApiKey ? 'Hide' : 'Show' }}
                            </Button>
                        </div>
                    </div>

                    <p
                        v-if="extensionNotice"
                        class="text-sm mb-3"
                        :class="extensionNoticeTone === 'success'
                            ? 'text-smart-blue-200'
                            : extensionNoticeTone === 'error'
                                ? 'text-danger-200'
                                : 'text-twilight-indigo-200'"
                    >
                        {{ extensionNotice }}
                    </p>

                    <p class="text-xs text-twilight-indigo-300">
                        Default extension domain: {{ extensionDefaultDomain }}.
                    </p>
                </div>

                <div class="border border-danger-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
                    <h5 class="text-lg font-semibold text-danger-400 mb-2">Danger Zone</h5>
                    <p class="text-twilight-indigo-200 mb-4">
                        Reset the app to its initial state. This will permanently delete all tabs and files.
                    </p>
                    <Button @click="resetDialogOpen = true" variant="destructive" data-test="reset-app-button">
                        Reset App
                    </Button>
                </div>
            </div>
        </div>

        <!-- Reset App Confirmation Dialog -->
        <Dialog v-model="resetDialogOpen">
            <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                <DialogHeader>
                    <DialogTitle class="text-danger-400 flex items-center gap-2">
                        <AlertTriangle :size="20" />
                        Reset App
                    </DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Are you sure you want to reset the app? This will permanently delete all tabs and files. This action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="resetDialogOpen = false" :disabled="isResetting">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button @click="handleResetApp" variant="destructive" :loading="isResetting" data-test="confirm-reset-button">
                        Reset
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

    </PageLayout>
</template>
