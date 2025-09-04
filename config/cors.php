<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*','sanctum/csrf-cookie','login','logout'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['https://fe-huehuy-ig6k.vercel.app',
  'https://app-159-223-48-146.nip.io',
  'http://localhost:3000',
  'http://localhost:5173',],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type','X-Requested-With','X-XSRF-TOKEN','Authorization','Accept','Origin'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];