<script lang="ts" setup>
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
// import * as routes from '@/routes';
import { Separator } from '@/components/ui/separator';
import { useGoogleAnalytics } from '@/composables/useGoogleAnalytics';
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { useEchoPublic } from '@laravel/echo-vue';

const { trackButtonClick, trackScrollDepth, isEnabled } = useGoogleAnalytics();

// Track button clicks
const handleGetStartedClick = () => {
    trackButtonClick('Get Started', 'cta');
};

const handleSignInClick = () => {
    trackButtonClick('Sign In', 'navigation');
};

const handleGetStartedFooterClick = () => {
    trackButtonClick('Get Started Footer', 'cta');
};

// Track scroll depth
const scrollDepthTracked = { 25: false, 50: false, 75: false, 100: false };

const handleScroll = () => {
    if (!isEnabled()) return;

    const scrollTop = window.pageYOffset;
    const docHeight = document.documentElement.scrollHeight - window.innerHeight;
    const scrollPercent = Math.round((scrollTop / docHeight) * 100);

    // Track scroll milestones
    if (scrollPercent >= 25 && !scrollDepthTracked[25]) {
        scrollDepthTracked[25] = true;
        trackScrollDepth(25);
    } else if (scrollPercent >= 50 && !scrollDepthTracked[50]) {
        scrollDepthTracked[50] = true;
        trackScrollDepth(50);
    } else if (scrollPercent >= 75 && !scrollDepthTracked[75]) {
        scrollDepthTracked[75] = true;
        trackScrollDepth(75);
    } else if (scrollPercent >= 100 && !scrollDepthTracked[100]) {
        scrollDepthTracked[100] = true;
        trackScrollDepth(100);
    }
};

// Demo: listen for broadcast on ?demo=1 using new Echo hook API
// For fully-qualified event names, prefix with '.' to bypass Echo's default namespace
const demoMsg = ref<string | null>(null);

useEchoPublic('demo', '.App\\Events\\DemoPing', (e: any) => {
    demoMsg.value = e?.message ?? 'Demo ping received';
});

onMounted(() => {
    if (isEnabled()) {
        window.addEventListener('scroll', handleScroll);
    }
});
</script>

<template>
<!-- Testing marker: visible when DemoPing is received -->
    <div v-if="demoMsg" data-testid="demo-ping" class="fixed bottom-2 right-2 z-50 text-xs text-green-700 bg-green-50 dark:text-green-300 dark:bg-green-950 border border-green-200 dark:border-green-800 rounded px-2 py-1 shadow">
        Realtime: {{ demoMsg }}
    </div>
    <Head title="ATLAS | Self-hosted media server">
        <meta content="ATLAS is a self-hosted media server for metadata extraction, file organization, and direct streaming." name="description" />
        <meta
            content="self-hosted media server, streaming, music, video, images, open source, Laravel, Vue.js, media management, metadata extraction, privacy, Docker"
            name="keywords"
        />
        <meta content="Wyxos" name="author" />
        <meta content="ATLAS | Self-hosted media server" property="og:title" />
        <meta content="Self-hosted media server for metadata extraction, file organization, and direct streaming." property="og:description" />
        <meta content="website" property="og:type" />
        <meta content="https://github.com/wyxos/atlas" property="og:url" />
        <meta content="/og-image.png" property="og:image" />
        <meta content="summary_large_image" name="twitter:card" />
        <meta content="ATLAS | Self-hosted media server" name="twitter:title" />
        <meta content="Self-hosted media server for metadata extraction, file organization, and direct streaming." name="twitter:description" />
        <meta content="/og-image.png" name="twitter:image" />
        <link href="https://github.com/wyxos/atlas" rel="canonical" />
    </Head>

    <div class="min-h-screen bg-gradient-to-br from-background via-background to-accent/20">
        <!-- Hero Section -->
        <div class="container mx-auto px-4 py-16">
            <div class="mb-16 space-y-6 text-center">
                <div class="space-y-4">
                    <h1 class="text-5xl font-bold tracking-tight md:text-7xl">
                  <span
                      class="bg-gradient-to-r from-blue-600 via-purple-600 to-blue-800 bg-clip-text text-transparent dark:from-blue-400 dark:via-purple-400 dark:to-blue-600"
                  >
                            ATLAS
                        </span>
                    </h1>
                    <p class="mx-auto max-w-3xl text-xl text-muted-foreground md:text-2xl">
                        Self-hosted media server with fast browsing and frictionless workflows
                    </p>
                </div>

                <div class="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
                    <Link
                        as="a"
                        class="btn-atlas-primary px-8 py-3 text-lg"
                        href="https://github.com/wyxos/atlas#quick-start-docker"
                        size="lg"
                        target="_blank"
                        @click="handleGetStartedClick"
                    >
                        Get Started
                    </Link>
                    <Link
href="/login"
                        as="a"
                        class="border-border px-8 py-3 text-lg hover:bg-accent"
                        size="lg"
                        variant="outline"
                        @click="handleSignInClick"
                    >
                        Sign In
                    </Link>
                </div>

                <div class="mt-3 flex justify-center">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                        <Icon :size="16" name="info" />
                        <span>Work in progress. Features ship incrementally. Feedback welcome.</span>
                        You may report issues <a class="underline" href="https://github.com/wyxos/atlas/issues" target="_blank">here</a>
                    </div>
                </div>
            </div>

            <!-- Features Grid -->
            <div class="mb-16 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <Card class="border-border transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-purple-600">
                            <Icon :size="24" class="text-white" name="play" />
                        </div>
                        <CardTitle>Stream Anywhere</CardTitle>
                        <CardDescription> Stream your personal media collection to any device, anywhere you have internet access </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-green-500 to-teal-600">
                            <Icon :size="24" class="text-white" name="folderTree" />
                        </div>
                        <CardTitle>Smart Organization</CardTitle>
                        <CardDescription> Metadata extraction and file organization by artist/album </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-orange-500 to-red-600">
                            <Icon :size="24" class="text-white" name="images" />
                        </div>
                        <CardTitle>Curated Browsing</CardTitle>
                        <CardDescription> Blacklist and curation. Keyboard shortcuts for quick actions. </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-purple-500 to-pink-600">
                            <Icon :size="24" class="text-white" name="search" />
                        </div>
                        <CardTitle>Powerful Search</CardTitle>
                        <CardDescription> Typesense search when configured (SCOUT_DRIVER=typesense) </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600">
                            <Icon :size="24" class="text-white" name="lock" />
                        </div>
                        <CardTitle>Complete Privacy</CardTitle>
                        <CardDescription> Self-hosted on your own infrastructure. Your data never leaves your control </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border transition-shadow hover:shadow-lg">
                    <CardHeader>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-gradient-to-br from-yellow-500 to-orange-600">
                            <Icon :size="24" class="text-white" name="zap" />
                        </div>
                        <CardTitle>Lightning Fast</CardTitle>
                        <CardDescription> Fast browsing and playback. Infinite scroll in media feeds. </CardDescription>
                    </CardHeader>
                </Card>
            </div>

            <!-- Media Types Section -->
            <div class="space-y-8 text-center">
                <div class="space-y-4">
                    <h2 class="text-3xl font-bold text-foreground md:text-4xl">Your Media, Your Server</h2>
                    <p class="mx-auto max-w-2xl text-lg text-muted-foreground">
                        Deploy ATLAS on your own hardware and enjoy complete control over your digital media library
                    </p>
                </div>

                <div class="mx-auto grid max-w-4xl grid-cols-1 gap-8 md:grid-cols-3">
                    <div class="space-y-4 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-purple-600">
                            <Icon :size="32" class="text-white" name="music" />
                        </div>
                        <h3 class="text-xl font-semibold">Audio Collection</h3>
                        <p class="text-muted-foreground">
                            MP3, FLAC, WAV, and more. Complete metadata support with album art, artist info, and playlists
                        </p>
                    </div>

                    <div class="space-y-4 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-red-500 to-pink-600">
                            <Icon :size="32" class="text-white" name="film" />
                        </div>
                        <h3 class="text-xl font-semibold">Video Library</h3>
                        <p class="text-muted-foreground">Movies, TV shows, and personal videos. Basic playback across common formats.</p>
                    </div>

                    <div class="space-y-4 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-green-500 to-teal-600">
                            <Icon :size="32" class="text-white" name="image" />
                        </div>
                        <h3 class="text-xl font-semibold">Image Gallery</h3>
                        <p class="text-muted-foreground">Photos and images with blacklist, unrated views, and curated browsing</p>
                    </div>
                </div>
            </div>

            <Separator class="my-16" />

            <!-- CTA Section -->
            <div class="space-y-6 text-center">
                <h2 class="text-3xl font-bold text-foreground">Ready to take control of your media?</h2>
                <p class="mx-auto max-w-2xl text-lg text-muted-foreground">
                    ATLAS is completely free and open-source. Clone the repository, deploy on your own infrastructure, and enjoy unlimited access to
                    your personal media server.
                </p>
                <div class="flex justify-center pt-4">
                    <Button
                        as="a"
                        class="btn-atlas-primary px-8 py-3 text-lg"
                        href="https://github.com/wyxos/atlas#quick-start-docker"
                        size="lg"
                        target="_blank"
                        @click="handleGetStartedFooterClick"
                    >
                        Get Started
                    </Button>
                </div>

                <div class="mx-auto mt-8 max-w-3xl rounded-lg border border-border bg-accent/30 p-6">
                    <div class="mb-3 flex items-center justify-center space-x-2">
                        <Icon :size="20" class="text-muted-foreground" name="badgeCheck" />
                        <span class="text-sm font-medium text-foreground">Open Source & Free Forever</span>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Love ATLAS? Consider supporting the project on GitHub Sponsors to help with development and maintenance.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
