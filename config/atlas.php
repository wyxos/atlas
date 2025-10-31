<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Composer Binary Path
    |--------------------------------------------------------------------------
    |
    | Path to the composer binary. Defaults to 'composer' which assumes
    | composer is available in your PATH. You can override this in .env
    | using COMPOSER_BIN if you need a specific path or 'php composer.phar'.
    |
    */

    'composer_bin' => env('COMPOSER_BIN', 'composer'),
];
