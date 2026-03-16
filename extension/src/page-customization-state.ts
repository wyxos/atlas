import type { SiteCustomization } from './site-customizations';

let activePageSiteCustomization: SiteCustomization | null = null;

export function getActivePageSiteCustomization(): SiteCustomization | null {
    return activePageSiteCustomization;
}

export function setActivePageSiteCustomization(siteCustomization: SiteCustomization | null): void {
    activePageSiteCustomization = siteCustomization;
}
