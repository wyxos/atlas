# Google Analytics Integration

This document explains how Google Analytics is integrated into Atlas and how to configure it.

## Overview

Google Analytics is conditionally loaded **only on the Welcome page** and **only when the environment variable is set**. This ensures that:

1. Self-hosted users without the env variable won't have any GA tracking
2. Only the homepage visitors are tracked (not authenticated users)
3. The integration is completely optional and privacy-focused

## Configuration

### 1. Environment Variable

Add your Google Analytics Measurement ID to your `.env` file:

```env
# Google Analytics - only set this if you want GA tracking enabled
GOOGLE_ANALYTICS_ID=G-XEG7ZTBXZR
```

### 2. For Self-Hosted Users

Self-hosted users can simply leave this variable unset or commented out in their `.env` file:

```env
# Google Analytics - only set this if you want GA tracking enabled
# GOOGLE_ANALYTICS_ID=
```

## What Gets Tracked

The Google Analytics integration tracks the following events on the Welcome page:

### Page Views
- Automatic page view tracking when users visit the welcome page

### Button Clicks
- **"Get Started"** button (hero section) - tracked as `button_click` with `button_type: 'cta'`
- **"Sign In"** button - tracked as `button_click` with `button_type: 'navigation'`
- **"Get Started"** button (footer) - tracked as `button_click` with `button_type: 'cta'`

### Scroll Depth
- 25% scroll depth
- 50% scroll depth
- 75% scroll depth
- 100% scroll depth

## Technical Implementation

### Files Modified

1. **`.env`** - Added `GOOGLE_ANALYTICS_ID` variable
2. **`config/services.php`** - Added Google Analytics configuration
3. **`app/Http/Middleware/HandleInertiaRequests.php`** - Share GA config with frontend
4. **`resources/views/app.blade.php`** - Conditional GA script loading (Welcome page only)
5. **`resources/js/composables/useGoogleAnalytics.ts`** - Vue composable for GA tracking
6. **`resources/js/pages/Welcome.vue`** - Added tracking to buttons and scroll events

### Key Features

- **Conditional Loading**: GA script loads when `GOOGLE_ANALYTICS_ID` is set, but tracking only happens on Welcome page
- **Privacy First**: No tracking for authenticated users or other pages
- **Self-Hosted Friendly**: Easy to disable by not setting the environment variable
- **TypeScript Support**: Fully typed Google Analytics integration
- **Event Tracking**: Custom events for button clicks and scroll depth
- **Google-Compliant**: Script placed immediately after `<head>` tag as per Google's requirements
- **Inertia.js Compatible**: Works correctly with Inertia's SPA navigation

## Privacy Considerations

- Only tracks anonymous visitors to the welcome page
- No personal data collection
- No tracking of authenticated user behavior
- Completely disabled when environment variable is not set
- Self-hosted users maintain full control over their data

## Analytics Events

All custom events include a `page: 'welcome'` parameter to clearly identify the source page.

### Event Structure

```javascript
gtag('event', 'button_click', {
  button_name: 'Get Started',
  button_type: 'cta',
  page: 'welcome'
});
```

## Disabling Google Analytics

To disable Google Analytics completely:

1. Remove or comment out the `GOOGLE_ANALYTICS_ID` line in your `.env` file
2. Restart your application

The Google Analytics script will not be loaded, and no tracking will occur.
