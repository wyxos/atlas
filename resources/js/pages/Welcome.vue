<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useGoogleAnalytics } from '@/composables/useGoogleAnalytics';
import { onMounted } from 'vue';

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
    <Head title="Welcome to Atlas">
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
                            Atlas
                        </span>
                    </h1>
                <p class="text-xl md:text-2xl text-muted-foreground max-w-3xl mx-auto">
                        Open-source, self-hosted media server for organizing and streaming your personal collection
                    </p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-4 justify-center items-center mt-8">
                    <Button as="a" href="https://github.com/username/atlas" target="_blank" size="lg" class="btn-atlas-primary px-8 py-3 text-lg" @click="handleGetStartedClick">
                        Get Started
                    </Button>
                    <Button as="a" :href="route('login')" variant="outline" size="lg" class="px-8 py-3 text-lg border-border hover:bg-accent" @click="handleSignInClick">
                        Sign In
                    </Button>
                </div>
            </div>

            <!-- Features Grid -->
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 mb-16">
                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
                        <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12 7-12 7z"></path>
                            </svg>
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
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                        </div>
                        <CardTitle>Smart Organization</CardTitle>
                        <CardDescription>
                            Automatic metadata extraction, intelligent tagging, and AI-powered organization keeps your library pristine
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
                        <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.367 2.684 3 3 0 00-5.367-2.684z"></path>
                            </svg>
                        </div>
                        <CardTitle>Easy Sharing</CardTitle>
                        <CardDescription>
                            Generate secure sharing links for specific tracks, albums, or playlists with your family and friends
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
                        <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-purple-500 to-pink-600 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <CardTitle>Powerful Search</CardTitle>
                        <CardDescription>
                            Find any track, artist, album, or video instantly with advanced search and filtering capabilities
                        </CardDescription>
                    </CardHeader>
                </Card>

                <Card class="border-border hover:shadow-lg transition-shadow">
                    <CardHeader>
                        <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-indigo-500 to-blue-600 flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
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
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                        </div>
                        <CardTitle>Lightning Fast</CardTitle>
                        <CardDescription>
                            Optimized streaming, infinite scroll, and smart caching deliver instant access to your content
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
                        Deploy Atlas on your own hardware and enjoy complete control over your digital media library
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-4xl mx-auto">
                    <div class="text-center space-y-4">
                        <div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12 7-12 7z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold">Audio Collection</h3>
                        <p class="text-muted-foreground">
                            MP3, FLAC, WAV, and more. Complete metadata support with album art, artist info, and smart playlists
                        </p>
                    </div>

                    <div class="text-center space-y-4">
                        <div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-red-500 to-pink-600 flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold">Video Library</h3>
                        <p class="text-muted-foreground">
                            Movies, TV shows, and personal videos. Multiple formats with progress tracking and watch status
                        </p>
                    </div>

                    <div class="text-center space-y-4">
                        <div class="w-16 h-16 mx-auto rounded-full bg-gradient-to-br from-green-500 to-teal-600 flex items-center justify-center">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold">Image Gallery</h3>
                        <p class="text-muted-foreground">
                            Photos and images with smart categorization, tagging, and beautiful gallery views
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
                    Atlas is completely free and open-source. Clone the repository, deploy on your own infrastructure, 
                    and enjoy unlimited access to your personal media server.
                </p>
                <div class="flex justify-center pt-4">
                    <Button as="a" href="https://github.com/username/atlas" target="_blank" size="lg" class="btn-atlas-primary px-8 py-3 text-lg" @click="handleGetStartedFooterClick">
                        Get Started
                    </Button>
                </div>
                
                <div class="mt-8 p-6 bg-accent/30 rounded-lg border border-border max-w-3xl mx-auto">
                    <div class="flex items-center justify-center space-x-2 mb-3">
                        <svg class="w-5 h-5 text-muted-foreground" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                        </svg>
                        <span class="text-sm font-medium text-foreground">Open Source & Free Forever</span>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Love Atlas? Consider supporting the project on GitHub Sponsors to help with development and maintenance.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
