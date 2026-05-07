import { computed, ref } from 'vue';
import type { DashboardMetrics } from '@/types/dashboard';
import {
    createDashboardContainerGroups,
    createDashboardContainerTotals,
    createDashboardCoverage,
    createDashboardMetricPanels,
    createDashboardPositiveOutcomes,
} from '@/utils/dashboard';

export function useDashboardMetrics() {
    const metrics = ref<DashboardMetrics | null>(null);
    const isLoading = ref(true);
    const loadError = ref<string | null>(null);

    const coverage = computed(() => createDashboardCoverage(metrics.value));
    const metricPanels = computed(() => createDashboardMetricPanels(metrics.value));
    const positiveOutcomes = computed(() => createDashboardPositiveOutcomes(metrics.value));
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
            coverage,
            metricPanels,
            positiveOutcomes,
            containerGroups,
            containerTotals,
        },
        actions: {
            fetchMetrics,
        },
    };
}
