import { computed, ref } from 'vue';
import type { DashboardMetrics } from '@/types/dashboard';
import {
    createDashboardChartSections,
    createDashboardContainerGroups,
    createDashboardContainerTotals,
} from '@/utils/dashboard';

export function useDashboardMetrics() {
    const metrics = ref<DashboardMetrics | null>(null);
    const isLoading = ref(true);
    const loadError = ref<string | null>(null);

    const chartSections = computed(() => createDashboardChartSections(metrics.value));
    const containerGroups = computed(() => createDashboardContainerGroups(metrics.value));
    const containerTotals = computed(() => createDashboardContainerTotals(metrics.value));

    async function fetchMetrics(): Promise<void> {
        isLoading.value = true;
        loadError.value = null;

        try {
            const { data } = await window.axios.get<DashboardMetrics>('/api/dashboard/metrics');
            metrics.value = data;
        } catch (error) {
            loadError.value = error instanceof Error ? error.message : 'Unable to load dashboard metrics.';
        } finally {
            isLoading.value = false;
        }
    }

    return {
        state: {
            metrics,
            isLoading,
            loadError,
        },
        derived: {
            chartSections,
            containerGroups,
            containerTotals,
        },
        actions: {
            fetchMetrics,
        },
    };
}
