<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useGoogleAnalytics } from '@/composables/useGoogleAnalytics';
import { onMounted } from 'vue';
import Icon from '@/components/Icon.vue';

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
let scrollDepthTracked = { 25: false, 50: false, 75: false, 100: false };

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

onMounted(() => {
    if (isEnabled()) {
        window.addEventListener('scroll', handleScroll);
    }
});
</script>

<template>
<Head title="ATLAS | Self-hosted media server">
        <meta name="description" content="ATLAS is a self-hosted media server for metadata extraction, file organization, and direct streaming." />
        <meta name="keywords" content="self-hosted media server, streaming, music, video, images, open source, Laravel, Vue.js, media management, metadata extraction, privacy, Docker" />
        <meta name="author" content="Wyxos" />
        <meta property="og:title" content="ATLAS | Self-hosted media server" />
        <meta property="og:description" content="Self-hosted media server for metadata extraction, file organization, and direct streaming." />
        <meta property="og:type" content="website" />
        <meta property="og:url" content="https://github.com/wyxos/atlas" />
        <meta property="og:image" content="/og-image.png" />
        <meta name="twitter:card" content="summary_large_image" />
        <meta name="twitter:title" content="ATLAS | Self-hosted media server" />
        <meta name="twitter:description" content="Self-hosted media server for metadata extraction, file organization, and direct streaming." />
        <meta name="twitter:image" content="/og-image.png" />
        <link rel="canonical" href="https://github.com/wyxos/atlas" />
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />
    </Head>

    <div class="min-h-screen bg-gradient-to-br from-background via-background to-accent/20">
        <!-- Hero Section -->
        <div class="container mx-auto px-4 py-16">
            <div class="text-center space-y-6 mb-16">
                <div class="space-y-4">
                    <h1 class="text-5xl md:text-7xl font-bold tracking-tight">
<span class="bg-gradient-to-r from-blue-600 via-purple-600 to-blue-800 bg-clip-text text-transparent dark:from-blue-400 dark:via-purple-400 dark:to-blue-600">
                            ATLAS
                        </span>
                    </h1>
<p class="text-xl md:text-2xl text-muted-foreground max-w-3xl mx-auto">
                        Self-hosted media server with fast browsing and frictionless workflows
                    </p>
                </div>

<div class="flex flex-col sm:flex-row gap-4 justify-center items-center mt-8">
                    <Button as="a" href="https://github.com/wyxos/atlas#quick-start-docker" target="_blank" size="lg" class="btn-atlas-primary px-8 py-3 text-lg" @click="handleGetStartedClick">
                        Get Started
                    </Button>
                    <Button as="a" :href="route('login')" variant="outline" size="lg" class="px-8 py-3 text-lg border-border hover:bg-accent" @click="handleSignInClick">
                        Sign In
                    </Button>
                </div>

                <div class="mt-3 flex justify-center">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground">
                        <Icon name="info" :size="16" />
                        <span>Work in progress. Features ship incrementally. Feedback welcome.</span>
                        <a href="https://github.com/wyxos/atlas/issues" target="_blank" class="underline">Issues</a>
                    </div>
                </div>
            </div>

            <!-- Features Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 mb-16">
                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
<div class="w-12 h-12 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mb-4">
                            <Icon name="play" :size="24" class="text-white" />
                        </div>
                        <CardTitle>Stream Anywhere</CardTitle>
                        <CardDescription>
                            Stream your personal media collection to any device, anywhere you have internet access
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
<div class="w-12 h-12 rounded-lg bg-gradient-to-br from-green-500 to-teal-600 flex items-center justify-center mb-4">
                            <Icon name="folderTree" :size="24" class="text-white" />
                        </div>
                        <CardTitle>Smart Organization</CardTitle>
                        <CardDescription>
                            Metadata extraction and file organization by artist/album
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
<div class="w-12 h-12 rounded-lg bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center mb-4">
                            <Icon name="images" :size="24" class="text-white" />
                        </div>
                        <CardTitle>Curated Browsing</CardTitle>
                        <CardDescription>
                            Blacklist and curation. Keyboard shortcuts for quick actions.
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
<div class="w-12 h-12 rounded-lg bg-gradient-to-br from-purple-500 to-pink-600 flex items-center justify-center mb-4">
                            <Icon name="search" :size="24" class="text-white" />
                        </div>
                        <CardTitle>Powerful Search</CardTitle>
                        <CardDescription>
                            Typesense search when configured (SCOUT_DRIVER=typesense)
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
<div class="w-12 h-12 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center mb-4">
                            <Icon name="lock" :size="24" class="text-white" />
                        </div>
                        <CardTitle>Complete Privacy</CardTitle>
                        <CardDescription>
                            Self-hosted on your own infrastructure. Your data never leaves your control
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
<div class="w-12 h-12 rounded-lg bg-gradient-to-br from-yellow-500 to-orange-600 flex items-center justify-center mb-4">
                            <Icon name="zap" :size="24" class="text-white" />
                        </div>
                        <CardTitle>Lightning Fast</CardTitle>
                        <CardDescription>
                            Fast browsing and playback. Infinite scroll in media feeds.
                        </CardDescription>
                    </CardHeader>
                </Card>
            </div>

            <!-- Media Types Section -->
            <div class="text-center space-y-8">
                <div class="space-y-4">
                    <h2 class="text-3xl md:text-4xl font-bold text-foreground">
                        Your Media, Your Server
                    </h2>
<p class="text-lg text-muted-foreground max-w-2xl mx-auto">
                        Deploy ATLAS on your own hardware and enjoy complete control over your digital media library
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mx-auto">
                    <div class="text-center space-y-4">
<div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                            <Icon name="music" :size="32" class="text-white" />
                        </div>
                        <h3 class="text-xl font-semibold">Audio Collection</h3>
                        <p class="text-muted-foreground">
                            MP3, FLAC, WAV, and more. Complete metadata support with album art, artist info, and playlists
                        </p>
                    </div>

                    <div class="text-center space-y-4">
<div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-red-500 to-pink-600 flex items-center justify-center">
                            <Icon name="film" :size="32" class="text-white" />
                        </div>
                        <h3 class="text-xl font-semibold">Video Library</h3>
                        <p class="text-muted-foreground">
                            Movies, TV shows, and personal videos. Basic playback across common formats.
                        </p>
                    </div>

                    <div class="text-center space-y-4">
<div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-green-500 to-teal-600 flex items-center justify-center">
                            <Icon name="image" :size="32" class="text-white" />
                        </div>
                        <h3 class="text-xl font-semibold">Image Gallery</h3>
                        <p class="text-muted-foreground">
                            Photos and images with blacklist, unrated views, and curated browsing
                        </p>
                    </div>
                </div>
            </div>

            <Separator class="my-16" />

            <!-- CTA Section -->
            <div class="text-center space-y-6">
<h2 class="text-3xl font-bold text-foreground">
                    Ready to take control of your media?
                </h2>
                <p class="text-lg text-muted-foreground max-w-2xl mx-auto">
                    ATLAS is completely free and open-source. Clone the repository, deploy on your own infrastructure,
                    and enjoy unlimited access to your personal media server.
                </p>
                <div class="flex justify-center pt-4">
<Button as="a" href="https://github.com/wyxos/atlas#quick-start-docker" target="_blank" size="lg" class="btn-atlas-primary px-8 py-3 text-lg" @click="handleGetStartedFooterClick">
                        Get Started
                    </Button>
                </div>

<div class="mt-8 p-6 bg-accent/30 rounded-lg border border-border max-w-3xl mx-auto">
                    <div class="flex items-center justify-center space-x-2 mb-3">
                        <Icon name="badgeCheck" :size="20" class="text-muted-foreground" />
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
