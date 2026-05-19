import type { LocalPresetGroup } from '@/utils/tabFilter';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { SearchableDropdownGroup, SearchableDropdownOption } from '@/types/searchableDropdown';

type ServiceStatusBadge = Pick<SearchableDropdownOption, 'badge' | 'badgeVariant'>;

export function serviceDropdownOptions(services: readonly ServiceOption[]): SearchableDropdownOption[] {
    return services.map((service) => ({
        label: service.label,
        value: service.key,
        ...serviceStatusBadge(service),
    }));
}

export function localPresetDropdownGroups(groups: readonly LocalPresetGroup[]): SearchableDropdownGroup[] {
    return groups.map((group) => ({
        label: group.label,
        options: group.presets.map((preset) => ({
            label: preset.label,
            value: preset.value,
        })),
    }));
}

export function serviceStatusMessage(service: ServiceOption | null | undefined): string | null {
    if (!service?.status) {
        return null;
    }

    if (service.status.state === 'ready') {
        return null;
    }

    return service.status.message ?? service.status.label ?? 'Service unavailable.';
}

function serviceStatusBadge(service: ServiceOption): ServiceStatusBadge {
    if (!service.status || service.status.state === 'ready') {
        return {};
    }

    return {
        badge: service.status.label ?? (service.status.state === 'disconnected' ? 'Disconnected' : 'Error'),
        badgeVariant: service.status.state === 'disconnected' ? 'warning' : 'danger',
    };
}
