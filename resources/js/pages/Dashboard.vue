<template>
    <div class="w-full">
        <div class="rounded-lg shadow-xl md:p-8 md:p-12 bg-prussian-blue-400">
            <div class="text-center mb-8">
                <h1 class="text-3xl md:text-4xl font-bold mb-2 text-smart-blue-900">
                    Dashboard
                </h1>
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
    </div>
</template>

