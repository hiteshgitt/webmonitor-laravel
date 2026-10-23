<?php

return [
    // Shared secret the Web Monitor dashboard sends as "Authorization: Bearer <key>".
    // Generate one with: php artisan webmonitor:install
    'api_key' => env('WEBMONITOR_API_KEY', ''),

    // Public URL used for the SEO/security homepage checks (defaults to APP_URL)
    'site_url' => env('WEBMONITOR_SITE_URL', env('APP_URL')),
];
