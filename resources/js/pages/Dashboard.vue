<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { Skeleton } from '@/components/ui/skeleton';
import { store as tabsStore, setActive as tabsSetActive } from '@/actions/App/Http/Controllers/TabController';
import DashboardContainerList from '@/components/dashboard/DashboardContainerList.vue';
import DashboardMetricChartCard from '@/components/dashboard/DashboardMetricChartCard.vue';
import { useDashboardMetrics } from '@/composables/useDashboardMetrics';
import type { ContainerMetricItem } from '@/types/dashboard';
import { formatDashboardCount } from '@/utils/dashboard';
import PageLayout from '../components/PageLayout.vue';

const router = useRouter();
const dashboard = useDashboardMetrics();
const loadError = dashboard.state.loadError;
const isLoading = dashboard.state.isLoading;
const chartSections = dashboard.derived.chartSections;
const containerGroups = dashboard.derived.containerGroups;
const containerTotals = dashboard.derived.containerTotals;

async function openContainerInApp(item: ContainerMetricItem): Promise<void> {
    const payload = item.browse_tab;
    if (!payload) {
        return;
    }

    const { data } = await window.axios.post(tabsStore.url(), {
        label: payload.label,
        params: payload.params,
    });

    if (data?.id) {
        await window.axios.patch(tabsSetActive.url(data.id));
    }

    await router.push('/browse');
}

onMounted(dashboard.actions.fetchMetrics);
</script>

<template>
    <PageLayout>
        <div class="w-full space-y-8">
            <div class="text-center">
                <h4 class="text-xl font-semibold text-regal-navy-100">
                    Dashboard
                </h4>
                <p class="text-sm text-blue-slate-300">
                    File volume and moderation impact at a glance.
                </p>
            </div>

            <div
                v-if="loadError"
                class="rounded-lg border border-danger-600/60 bg-danger-700/20 p-4 text-sm text-danger-100"
            >
                {{ loadError }}
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <DashboardMetricChartCard
                    v-for="section in chartSections"
                    :key="section.key"
                    :section="section"
                    :is-loading="isLoading"
                />
            </div>

            <div class="space-y-6 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6">
                <div class="flex flex-col gap-2">
                    <h2 class="text-lg font-semibold text-regal-navy-100">Containers</h2>
                    <p class="text-sm text-twilight-indigo-200">
                        Top containers by downloads, favorites, and blacklisted files.
                    </p>
                    <div class="flex gap-6 text-sm text-twilight-indigo-200">
                        <div>
                            Total containers:
                            <span class="font-semibold text-regal-navy-100">
                                {{ formatDashboardCount(containerTotals.total) }}
                            </span>
                        </div>
                        <div>
                            Blacklisted containers:
                            <span class="font-semibold text-regal-navy-100">
                                {{ formatDashboardCount(containerTotals.blacklisted) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div v-if="isLoading" class="space-y-4">
                    <Skeleton class="h-40 w-full" />
                </div>

                <div v-else class="grid gap-6 lg:grid-cols-3">
                    <DashboardContainerList
                        v-for="group in containerGroups"
                        :key="group.key"
                        :title="group.title"
                        :items="group.items"
                        @open-in-app="openContainerInApp"
                    />
                </div>
            </div>
        </div>
    </PageLayout>
</template>
