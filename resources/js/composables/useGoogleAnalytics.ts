import { usePage } from '@inertiajs/vue3'

interface GoogleAnalyticsConfig {
    id: string | null
}

interface GtagFunction {
    (command: 'config', targetId: string, config?: any): void
    (command: 'event', eventName: string, eventParams?: any): void
    (command: 'js', date: Date): void
    (command: string, ...args: any[]): void
}

declare global {
    interface Window {
        gtag?: GtagFunction
        dataLayer?: any[]
    }
}

export function useGoogleAnalytics() {
    const page = usePage()
    
    // Check if Google Analytics is configured AND we're on the Welcome page
    const isEnabled = (): boolean => {
        const hasGAId = !!(page.props.google_analytics as GoogleAnalyticsConfig)?.id
        const isWelcomePage = page.component === 'Welcome'
        return hasGAId && isWelcomePage
    }
    
    // Get the GA tracking ID
    const getTrackingId = (): string | null => {
        return (page.props.google_analytics as GoogleAnalyticsConfig)?.id || null
    }
    
    // Track a custom event
    const trackEvent = (eventName: string, eventParams?: Record<string, any>): void => {
        if (!isEnabled() || !window.gtag) {
            return
        }
        
        window.gtag('event', eventName, eventParams)
    }
    
    // Track welcome page interactions
    const trackButtonClick = (buttonName: string, buttonType: 'cta' | 'navigation' | 'feature'): void => {
        trackEvent('button_click', {
            button_name: buttonName,
            button_type: buttonType,
            page: 'welcome'
        })
    }
    
    const trackFeatureView = (featureName: string): void => {
        trackEvent('feature_view', {
            feature_name: featureName,
            page: 'welcome'
        })
    }
    
    const trackScrollDepth = (depth: number): void => {
        trackEvent('scroll_depth', {
            depth_percentage: depth,
            page: 'welcome'
        })
    }
    
    return {
        isEnabled,
        getTrackingId,
        trackEvent,
        trackButtonClick,
        trackFeatureView,
        trackScrollDepth
    }
}

