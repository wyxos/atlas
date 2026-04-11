import type { BrowseFormData } from '@/composables/useBrowseForm';
import type { BrowsePageToken } from '@/types/browse';
import { getLocalPresetLabel } from './localPresets';
import type { ServiceOption } from './browseCatalog';

type BuildBrowseTabLabelOptions = {
    formData: BrowseFormData;
    pageToken: BrowsePageToken;
    availableServices: ServiceOption[];
    localService?: ServiceOption | null;
};

function normalizeContainerValue(value: unknown): string | null {
    if (typeof value === 'string') {
        const trimmed = value.trim();

        return trimmed.length > 0 ? trimmed : null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : null;
    }

    return null;
}

function getBrowseContainerLabel(formData: BrowseFormData): string | null {
    if (formData.feed !== 'online') {
        return null;
    }

    if (formData.service === 'civit-ai-images') {
        const username = normalizeContainerValue(formData.serviceFilters?.username);
        if (username) {
            return `User ${username}`;
        }

        const postId = normalizeContainerValue(formData.serviceFilters?.postId);
        if (postId) {
            return `Post ${postId}`;
        }
    }

    return null;
}

export function formatTabLabel(serviceLabel: string, pageToken: BrowsePageToken, containerLabel?: string | null): string {
    const prefix = containerLabel ? `${serviceLabel}: ${containerLabel}` : serviceLabel;

    return `${prefix} - ${String(pageToken)}`;
}

export function buildBrowseTabLabel(options: BuildBrowseTabLabelOptions): string | null {
    if (options.formData.feed === 'online' && !options.formData.service) {
        return null;
    }

    const baseServiceLabel = options.formData.feed === 'local'
        ? (options.localService?.label ?? 'Local')
        : (
            options.availableServices.find((service) => service.key === options.formData.service)?.label
            ?? options.formData.service
        );

    const localPresetLabel = options.formData.feed === 'local'
        ? getLocalPresetLabel(options.formData.serviceFilters?.local_preset)
        : null;
    const serviceLabel = localPresetLabel ? `${baseServiceLabel} - ${localPresetLabel}` : baseServiceLabel;

    return formatTabLabel(serviceLabel, options.pageToken, getBrowseContainerLabel(options.formData));
}
