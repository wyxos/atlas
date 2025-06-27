<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
</script>

<template>
    <Head title="Welcome">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 p-4">
        <div class="w-full max-w-md space-y-8 rounded-lg bg-white p-8 shadow-md">
            <div class="text-center">
                <h1 class="text-3xl font-bold tracking-tight text-gray-900">Welcome to Atlas</h1>
                <p class="mt-2 text-sm text-gray-600">Your powerful application platform</p>
            </div>

            <div class="mt-8 space-y-4">
                <Button as="a" :href="route('login')" class="w-full">
                    Log in
                </Button>

                <Button as="a" :href="route('register')" variant="outline" class="w-full">
                    Create an account
                </Button>
            </div>
        </div>
    </div>
</template>
