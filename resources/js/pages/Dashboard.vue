<script setup lang="ts">
import PageLayout from '../components/PageLayout.vue';
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="text-center mb-8">
                <h4 class="text-2xl font-semibold mb-2 text-regal-navy-900">
                    Dashboard
                </h4>
                <p class="text-blue-slate-700">
                    Welcome to your dashboard
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <router-link
                    to="/users"
                    class="block rounded-lg p-6 transition-colors cursor-pointer bg-smart-blue-300 border-2 border-smart-blue-500 hover:bg-smart-blue-400"
                >
                    <h2 class="text-xl font-semibold mb-3 text-smart-blue-900">
                        Users
                    </h2>
                    <p class="text-twilight-indigo-900">
                        Manage users and permissions
                    </p>
                </router-link>

                <div class="rounded-lg p-6 bg-sapphire-300 border-2 border-sapphire-500">
                    <h2 class="text-xl font-semibold mb-3 text-sapphire-900">
                        Coming Soon
                    </h2>
                    <p class="text-twilight-indigo-900">
                        More features coming soon
                    </p>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
