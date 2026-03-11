<script setup lang="ts">
import { Play } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { Switch } from '@/components/ui/switch';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/lib/browseCatalog';

interface Props {
    form: BrowseFormInstance;
    availableServices: ServiceOption[];
    availableSources: string[];
    isLoading: boolean;
    setLocalMode: (value: boolean) => void;
    updateService: (service: string) => void;
    updateSource: (source: string) => void;
    applyService: () => void | Promise<void>;
}

defineProps<Props>();
</script>

<template>
    <div class="flex items-center justify-center h-full" data-test="new-tab-form">
        <div
            class="flex flex-col items-center gap-4 p-8 bg-prussian-blue-700/50 rounded-lg border border-twilight-indigo-500/30 max-w-md w-full">
            <h2 class="text-xl font-semibold text-twilight-indigo-100 mb-2">Start Browsing</h2>
            <p class="text-sm text-twilight-indigo-300 mb-6 text-center">Select a service and click play to begin</p>

            <div class="w-full flex items-center justify-between">
                <label class="block text-sm font-medium text-twilight-indigo-200">Source</label>
                <div class="flex items-center gap-3">
                    <span class="text-sm text-twilight-indigo-300"
                        :class="{ 'text-twilight-indigo-100 font-medium': !form.isLocalMode.value }">Online</span>
                    <Switch :model-value="form.isLocalMode.value"
                        @update:model-value="setLocalMode"
                        data-test="source-type-switch" />
                    <span class="text-sm text-twilight-indigo-300"
                        :class="{ 'text-twilight-indigo-100 font-medium': form.isLocalMode.value }">Local</span>
                </div>
            </div>

            <div v-if="form.data.feed === 'online'" class="w-full">
                <label class="block text-sm font-medium text-twilight-indigo-200 mb-2">Service</label>
                <Select :model-value="form.data.service" @update:model-value="(value) => updateService(value as string)" :disabled="isLoading">
                    <SelectTrigger class="w-full" data-test="service-select-trigger">
                        <SelectValue placeholder="Select a service..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="service in availableServices.filter((entry) => entry.key !== 'local')"
                            :key="service.key" :value="service.key" data-test="service-select-item">
                            {{ service.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="form.data.feed === 'local'" class="w-full">
                <label class="block text-sm font-medium text-twilight-indigo-200 mb-2">Source</label>
                <Select :model-value="form.data.source" @update:model-value="(value) => updateSource(value as string)"
                    :disabled="isLoading">
                    <SelectTrigger class="w-full" data-test="source-select-trigger">
                        <SelectValue placeholder="Select a source..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="source in availableSources" :key="source" :value="source"
                            data-test="source-select-item">
                            {{ source }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="flex gap-3 w-full mt-2 items-center">
                <Button @click="applyService" size="sm" class="flex-1" data-test="play-button"
                    :disabled="form.data.feed === 'online' && !form.data.service">
                    <Play :size="16" />
                </Button>
            </div>
        </div>
    </div>
</template>
