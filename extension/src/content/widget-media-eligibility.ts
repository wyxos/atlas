import {
    resolveMediaResolution,
    type MediaElement,
} from './media-utils';

export function mediaHasEligibleWidgetWidth(element: MediaElement, minImageWidth: number): boolean {
    if (element instanceof HTMLVideoElement) {
        return true;
    }

    const resolution = resolveMediaResolution(element);
    if (resolution === null) {
        return true;
    }

    return resolution.width > minImageWidth;
}
