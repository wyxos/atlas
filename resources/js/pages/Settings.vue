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
import extensionManifest from '../../../extension/atlas-downloader/manifest.json';

const router = useRouter();
const resetDialogOpen = ref(false);
const isResetting = ref(false);
const extensionDialogOpen = ref(false);
const extensionVersion = computed(() => extensionManifest.version ?? 'dev');
const extensionDownloadUrl = '/downloads/atlas-extension.zip';
const extensionCopyStatus = ref('');
const servicesNotice = ref('');
const servicesNoticeTone = ref<'success' | 'error' | 'neutral'>('neutral');
const isServicesLoading = ref(false);
const isSpotifyRefreshing = ref(false);
const isSpotifyDisconnecting = ref(false);

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
};

const spotifyService = ref<SpotifyServiceStatus | null>(null);
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
    } catch (error) {
        console.error('Failed to fetch settings services:', error);
        setServicesNotice('Failed to load services status.', 'error');
    } finally {
        isServicesLoading.value = false;
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

async function copyToClipboard(value: string, label: string): Promise<void> {
    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(value);
        } else {
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }

        extensionCopyStatus.value = `${label} copied.`;
    } catch (error) {
        console.error('Failed to copy:', error);
        extensionCopyStatus.value = `Unable to copy ${label.toLowerCase()}.`;
    } finally {
        window.setTimeout(() => {
            extensionCopyStatus.value = '';
        }, 2000);
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
                    <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Atlas Browser Extension</h5>
                    <p class="text-twilight-indigo-200 mb-4">
                        Install the extension to send large images and videos directly to Atlas from any site.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button @click="extensionDialogOpen = true" variant="outline">
                            Install Extension
                        </Button>
                        <Button as="a" :href="extensionDownloadUrl" variant="secondary">
                            Download Zip
                        </Button>
                        <span class="text-xs text-twilight-indigo-300">v{{ extensionVersion }}</span>
                    </div>
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

        <Dialog v-model="extensionDialogOpen">
            <DialogContent class="sm:max-w-[520px] bg-prussian-blue-600 border-smart-blue-500/30">
                <DialogHeader>
                    <DialogTitle class="text-smart-blue-300">Install the Atlas Extension</DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Load the extension from this repo and connect it to your Atlas instance.
                    </DialogDescription>
                </DialogHeader>
                <div class="space-y-4 text-sm text-twilight-indigo-100">
                    <ol class="list-decimal pl-5 space-y-2">
                        <li>
                            Open <span class="font-semibold">chrome://extensions</span> (Chrome) or
                            <span class="font-semibold">brave://extensions</span> (Brave).
                        </li>
                        <li>Enable Developer Mode.</li>
                        <li>Click “Load unpacked” and select <span class="font-semibold">extension/atlas-downloader</span>.</li>
                        <li>Generate a token with <span class="font-semibold">php artisan atlas:extension-token --set</span>.</li>
                        <li>Open the extension options and set your Atlas base URL and <span class="font-semibold">ATLAS_EXTENSION_TOKEN</span>.</li>
                    </ol>
                    <div class="flex flex-wrap gap-2">
                        <Button as="a" :href="extensionDownloadUrl" variant="secondary" size="sm">
                            Download Zip (v{{ extensionVersion }})
                        </Button>
                        <Button variant="outline" size="sm" @click="copyToClipboard('chrome://extensions', 'Chrome extensions URL')">
                            Copy chrome://extensions
                        </Button>
                        <Button variant="outline" size="sm" @click="copyToClipboard('brave://extensions', 'Brave extensions URL')">
                            Copy brave://extensions
                        </Button>
                    </div>
                    <p v-if="extensionCopyStatus" class="text-xs text-smart-blue-200">
                        {{ extensionCopyStatus }}
                    </p>
                    <p class="text-xs text-twilight-indigo-300">
                        Browser pages like <span class="font-semibold">chrome://extensions</span> and
                        <span class="font-semibold">brave://extensions</span> must be opened manually in the address bar.
                    </p>
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" @click="extensionDialogOpen = false">
                            Close
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageLayout>
</template>
