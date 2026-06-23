<script setup lang="ts">
defineProps<{
    atlasDomain: string;
    apiToken: string;
    showApiToken: boolean;
}>();

const emit = defineEmits<{
    'update:atlasDomain': [value: string];
    'update:apiToken': [value: string];
    'update:showApiToken': [value: boolean];
    save: [];
}>();

function inputValue(event: Event): string {
    return (event.target as HTMLInputElement).value;
}
</script>

<template>
    <section class="space-y-4">
        <div class="border-b border-smart-blue-500/20 pb-4">
            <h2 class="text-lg font-semibold text-regal-navy-100">Connection</h2>
            <p class="mt-1 text-sm text-blue-slate-300">
                Atlas domain and API key used by extension requests, auth checks, and Reverb discovery.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <label class="block space-y-2">
                <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Atlas Domain</span>
                <input
                    :value="atlasDomain"
                    type="url"
                    placeholder="https://atlas.test"
                    class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                    @input="emit('update:atlasDomain', inputValue($event))"
                />
            </label>

            <label class="block space-y-2">
                <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">API Key</span>
                <div class="flex items-center gap-2">
                    <input
                        :value="apiToken"
                        :type="showApiToken ? 'text' : 'password'"
                        autocomplete="off"
                        class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                        @input="emit('update:apiToken', inputValue($event))"
                    />
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                        @click="emit('update:showApiToken', !showApiToken)"
                    >
                        {{ showApiToken ? 'Hide' : 'Show' }}
                    </button>
                </div>
            </label>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-smart-blue-500/20 pt-4">
            <button
                type="button"
                class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                data-test-save-connection
                @click="emit('save')"
            >
                Save Connection
            </button>
        </div>
    </section>
</template>
