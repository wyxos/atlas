<?php

$config = require base_path('vendor/sentry/sentry-laravel/config/sentry.php');

$config['spotlight'] = (bool) env('SENTRY_SPOTLIGHT', false);

return $config;

