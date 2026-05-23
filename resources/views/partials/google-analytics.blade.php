@php
    $googleAnalyticsId = config('services.google_analytics.measurement_id');
    $googleAnalyticsEnabled = config('services.google_analytics.enabled') && filled($googleAnalyticsId);
@endphp

@if ($googleAnalyticsEnabled)
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ rawurlencode($googleAnalyticsId) }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){window.dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', @json($googleAnalyticsId));
    </script>
@endif
