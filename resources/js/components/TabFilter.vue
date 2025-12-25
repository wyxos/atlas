<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger, SheetFooter } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { SlidersHorizontal } from 'lucide-vue-next';
import type { TabData } from '@/composables/useTabs';
import { useBrowseForm } from '@/composables/useBrowseForm';
import { Masonry } from '@wyxos/vibe';

interface Props {
    open: boolean;
    availableServices: Array<{ key: string; label: string }>;
    tab?: TabData;
    masonry?: InstanceType<typeof Masonry> | null;
    isMasonryLoading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    open: false,
    masonry: null,
    isMasonryLoading: false,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    'apply': [];
    'reset': [];
}>();

// Initialize form with tab
const form = useBrowseForm({
    tab: props.tab,
});

// Sync form when sheet opens
function syncFormFromTab(): void {
    form.syncFromTab(props.tab);
}

// Handle apply button
function handleApply(): void {
    emit('apply');
    emit('update:open', false);
}

// Handle reset button
function handleReset(): void {
    form.reset();
    emit('reset');
}
</script>

<template>
    <Sheet :open="props.open"
        @update:open="(value: boolean) => { emit('update:open', value); if (value) syncFormFromTab(); }">
        <SheetTrigger as-child>
            <Button size="sm" variant="ghost" class="h-10 w-10" data-test="filter-button" :disabled="isMasonryLoading">
                <SlidersHorizontal :size="14" />
            </Button>
        </SheetTrigger>
        <SheetContent side="right" class="w-full sm:max-w-lg">
            <SheetHeader>
                <SheetTitle>Advanced Filters</SheetTitle>
            </SheetHeader>
            <div class="flex-1 p-6 overflow-y-auto space-y-6">
                <!-- Service Filter -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Service</label>
                    <Select v-model="form.data.service">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a service..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in availableServices" :key="service.key" :value="service.key">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- NSFW Toggle -->
                <div class="flex items-center justify-between">
                    <label class="text-sm font-medium text-regal-navy-100">NSFW</label>
                    <Switch v-model="form.data.nsfw" />
                </div>

                <!-- Type Radio Group -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100 mb-4">Type</label>
                    <RadioGroup v-model="form.data.type" class="flex gap-4">
                        <div class="flex items-center gap-2">
                            <RadioGroupItem value="all" id="type-all" />
                            <label for="type-all" class="text-sm text-twilight-indigo-200 cursor-pointer">All</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <RadioGroupItem value="image" id="type-image" />
                            <label for="type-image"
                                class="text-sm text-twilight-indigo-200 cursor-pointer">Image</label>
                        </div>
                        <div class="flex items-center gap-2">
                            <RadioGroupItem value="video" id="type-video" />
                            <label for="type-video"
                                class="text-sm text-twilight-indigo-200 cursor-pointer">Video</label>
                        </div>
                    </RadioGroup>
                </div>

                <!-- Limit Dropdown -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Limit</label>
                    <Select v-model="form.data.limit">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="20">20</SelectItem>
                            <SelectItem value="40">40</SelectItem>
                            <SelectItem value="60">60</SelectItem>
                            <SelectItem value="80">80</SelectItem>
                            <SelectItem value="100">100</SelectItem>
                            <SelectItem value="200">200</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Sort Dropdown -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Sort</label>
                    <Select v-model="form.data.sort">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="Newest">Newest</SelectItem>
                            <SelectItem value="Most Reactions">Most Reactions</SelectItem>
                            <SelectItem value="Most Comments">Most Comments</SelectItem>
                            <SelectItem value="Most Collected">Most Collected</SelectItem>
                            <SelectItem value="Top Rated">Top Rated</SelectItem>
                            <SelectItem value="Oldest">Oldest</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <SheetFooter>
                <Button variant="destructive" @click="handleReset">
                    Reset
                </Button>
                <Button variant="default" @click="handleApply" :disabled="form.processing">
                    Apply
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
