<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mini App build
    |--------------------------------------------------------------------------
    | The interface lives in its own repository and is deployed separately.
    | Laravel only needs to know where Vite wrote its manifest, so it can print
    | the hashed filenames into the page. nginx serves the files themselves.
    */

    'manifest' => env('MINIAPP_MANIFEST', base_path('../frontend/dist/.vite/manifest.json')),

    'asset_base' => env('MINIAPP_ASSET_BASE', '/app-assets'),

    'entry' => 'src/main.js',

];
