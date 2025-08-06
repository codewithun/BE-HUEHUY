<?php

declare(strict_types=1);

return [
    /*
     * ------------------------------------------------------------------------
     * Default Firebase project
     * ------------------------------------------------------------------------
     */

    'default' => env('FIREBASE_PROJECT', 'app'),

    /*
     * ------------------------------------------------------------------------
     * Firebase project configurations
     * ------------------------------------------------------------------------
     */

    'projects' => [
        'app' => [

            /*
             * ------------------------------------------------------------------------
             * Credentials / Service Account
             * ------------------------------------------------------------------------
             *
             * In order to access a Firebase project and its related services using a
             * server SDK, requests must be authenticated. For server-to-server
             * communication this is done with a Service Account.
             *
             * If you don't already have generated a Service Account, you can do so by
             * following the instructions from the official documentation pages at
             *
             * https://firebase.google.com/docs/admin/setup#initialize_the_sdk
             *
             * Once you have downloaded the Service Account JSON file, you can use it
             * to configure the package.
             *
             * If you don't provide credentials, the Firebase Admin SDK will try to
             * auto-discover them
             *
             * - by checking the environment variable FIREBASE_CREDENTIALS
             * - by checking the environment variable GOOGLE_APPLICATION_CREDENTIALS
             * - by trying to find Google's well known file
             * - by checking if the application is running on GCE/GCP
             *
             * If no credentials file can be found, an exception will be thrown the
             * first time you try to access a component of the Firebase Admin SDK.
             *
             */

            'credentials' => [
                'type' => 'service_account',
                'project_id' => 'huehuy-c43c3',
                'private_key_id' => 'ad9ff9b64ccee8cbbdd7feae6823e1e1bdbd111c',
                'private_key' => '-----BEGIN PRIVATE KEY-----\nMIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDDY3m8HGEC9PCO\nLQxZZEwTYiu0Ypygf5fzC0EIUG9eF+Lc5Gvkw+lm4y7Wy+yWWvg0jNQyYKQVwEa5\nXNllAoXq0o30fPnCk+VY8fJ+X5/iRyGRfzVHEI/nHP/k/ZJlZ2suvYS8TId5EDAs\n5v34Ax9wHoVqCgb639xvSqjIoaLkx0q7B2je68Jx77sXntnXtIu7MOOUeHmCZI/3\nmhRAvrj5eq6h6sfb3Wr0IivrK0BzpGarN3yUNjClkZ9dm/rOrxZ5ebCTpQ1oYFUC\n2uRp8XIfKI0h0yCTIbK0kShHnVxEe965T3eSUzlE3OCVlMZjqG5ZJqyv48AgJ1ge\n6bezjMU9AgMBAAECggEAAlwaNA1hiYnIwIpSR8iRHWq2mWOch3nCNB9hG+wB5iuP\nu8lvymkHhiBbhPLoEPhL719cxE6vXQ0EBxaSk7tlh9WojWYPXaWmIx/4UtWig6aD\nMv5fd92259OIeh1zHwFT2iQ50hhe7n2bsF5SViRMvoRY4BkO9MdtXXtzEiXDdA7m\nKtC7t+6dvjUrR1R1FTaJreBnroXxLv1UHiNWtq4ejSfNZVMgguhdM0C4lH8/tYsj\n5qc4sgwSEFS2qezjj66kQHg9KoZ8Cajo02maEdkmz+gH21SoJNb3IaYeqyWFTd5I\nOMcTJ6SKtHK/1incYv4MOLKbV78Kxaw4p60nYAtVdQKBgQD8ZmycUR9SzW2idkAv\nrbnrhig0vc3lc/RdWCCjE7DaQqTp8yJFc4N37olCDD6FIfloSFxaL5Zwb2xlzDCh\nqY22EYDne9ltPI+MyPzqtsorxV72JPqK/fERyDnW6CpaGFFrTQTAfzl7SIZDsIeG\nwEl8U55dunuMVZckwQs7Uh1HYwKBgQDGLONS4tgPcQL0b3bc+YMqTXzpqw70FnuZ\nZ/ZCiajI9rXTz0wblBRrDCyifOGAo0Vme1KRFeYIP5BS5VlrMEbsm0v1dFOqimYi\nHEWKlYqx08e5z0ZwXDsP9+MDG4ioMNxgEowi7TdaKi081sT0KQgKATNwtIesP+vT\nIiM6NDDy3wKBgCaHLAUgjPuCyD2Id3vPtRWywOhsIMXp0V9+WF0MYG6wxaPArXaU\nj3j7PJCMde60pPG6Of66TOiU2aMgbDwBOdSVD2xGh4YZPIBtHc5mYK4Vzs0cD/Kv\nmODyA4I+plhiZetPMm5//TJIe9ZRWB7Fs3H7Aa2lDb76Qbwmi6RegIGpAoGAf/Ir\nMjBS3mVQSxBL5Y8SKBWvOA3AscZyNjDwxTSrTFQ8QGvt70BDjnllt+J4lNzUyb2F\nKTbCNUEUpPB+Mr4QjGIXQHnCKrEAD7XBECBMU1Mv977i81gYqc6ZOkBkknI5Va2j\n3EjbG9NvMYBX2GtFTXBJDdMAZS0/zCiWJdXcZHECgYBogimN9NiUITUEkvXS+ZHC\nB3vjFSzmNftMpV8VjFjVOlaDB7oegdF/+vHlZVPVisMfRKPXcBRvIE6n+4QU1nr6\n1QxeXulZm2xMQ8NPkN1EEhlktosj4wSTfxQVIxVnx7rI2Fzkpk/2gChxMCaocV+x\n1+Gvj+XHypZau+TglCN9Tg==\n-----END PRIVATE KEY-----\n',
                'client_email' => 'firebase-adminsdk-n9gqp@huehuy-c43c3.iam.gserviceaccount.com"',
                'client_id' => '116908476211153313008',
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-n9gqp%40huehuy-c43c3.iam.gserviceaccount.com',
                'universe_domain' => 'googleapis.com',
            ],

            /*
             * ------------------------------------------------------------------------
             * Firebase Auth Component
             * ------------------------------------------------------------------------
             */

            'auth' => [
                'tenant_id' => env('FIREBASE_AUTH_TENANT_ID'),
            ],

            /*
             * ------------------------------------------------------------------------
             * Firestore Component
             * ------------------------------------------------------------------------
             */

            'firestore' => [

                /*
                 * If you want to access a Firestore database other than the default database,
                 * enter its name here.
                 *
                 * By default, the Firestore client will connect to the `(default)` database.
                 *
                 * https://firebase.google.com/docs/firestore/manage-databases
                 */

                // 'database' => env('FIREBASE_FIRESTORE_DATABASE'),
            ],

            /*
             * ------------------------------------------------------------------------
             * Firebase Realtime Database
             * ------------------------------------------------------------------------
             */

            'database' => [

                /*
                 * In most of the cases the project ID defined in the credentials file
                 * determines the URL of your project's Realtime Database. If the
                 * connection to the Realtime Database fails, you can override
                 * its URL with the value you see at
                 *
                 * https://console.firebase.google.com/u/1/project/_/database
                 *
                 * Please make sure that you use a full URL like, for example,
                 * https://my-project-id.firebaseio.com
                 */

                'url' => env('FIREBASE_DATABASE_URL'),

                /*
                 * As a best practice, a service should have access to only the resources it needs.
                 * To get more fine-grained control over the resources a Firebase app instance can access,
                 * use a unique identifier in your Security Rules to represent your service.
                 *
                 * https://firebase.google.com/docs/database/admin/start#authenticate-with-limited-privileges
                 */

                // 'auth_variable_override' => [
                //     'uid' => 'my-service-worker'
                // ],

            ],

            'dynamic_links' => [

                /*
                 * Dynamic links can be built with any URL prefix registered on
                 *
                 * https://console.firebase.google.com/u/1/project/_/durablelinks/links/
                 *
                 * You can define one of those domains as the default for new Dynamic
                 * Links created within your project.
                 *
                 * The value must be a valid domain, for example,
                 * https://example.page.link
                 */

                'default_domain' => env('FIREBASE_DYNAMIC_LINKS_DEFAULT_DOMAIN'),
            ],

            /*
             * ------------------------------------------------------------------------
             * Firebase Cloud Storage
             * ------------------------------------------------------------------------
             */

            'storage' => [

                /*
                 * Your project's default storage bucket usually uses the project ID
                 * as its name. If you have multiple storage buckets and want to
                 * use another one as the default for your application, you can
                 * override it here.
                 */

                'default_bucket' => env('FIREBASE_STORAGE_DEFAULT_BUCKET'),

            ],

            /*
             * ------------------------------------------------------------------------
             * Caching
             * ------------------------------------------------------------------------
             *
             * The Firebase Admin SDK can cache some data returned from the Firebase
             * API, for example Google's public keys used to verify ID tokens.
             *
             */

            'cache_store' => env('FIREBASE_CACHE_STORE', 'file'),

            /*
             * ------------------------------------------------------------------------
             * Logging
             * ------------------------------------------------------------------------
             *
             * Enable logging of HTTP interaction for insights and/or debugging.
             *
             * Log channels are defined in config/logging.php
             *
             * Successful HTTP messages are logged with the log level 'info'.
             * Failed HTTP messages are logged with the log level 'notice'.
             *
             * Note: Using the same channel for simple and debug logs will result in
             * two entries per request and response.
             */

            'logging' => [
                'http_log_channel' => env('FIREBASE_HTTP_LOG_CHANNEL'),
                'http_debug_log_channel' => env('FIREBASE_HTTP_DEBUG_LOG_CHANNEL'),
            ],

            /*
             * ------------------------------------------------------------------------
             * HTTP Client Options
             * ------------------------------------------------------------------------
             *
             * Behavior of the HTTP Client performing the API requests
             */

            'http_client_options' => [

                /*
                 * Use a proxy that all API requests should be passed through.
                 * (default: none)
                 */

                'proxy' => env('FIREBASE_HTTP_CLIENT_PROXY'),

                /*
                 * Set the maximum amount of seconds (float) that can pass before
                 * a request is considered timed out
                 *
                 * The default time out can be reviewed at
                 * https://github.com/kreait/firebase-php/blob/6.x/src/Firebase/Http/HttpClientOptions.php
                 */

                'timeout' => env('FIREBASE_HTTP_CLIENT_TIMEOUT'),

                'guzzle_middlewares' => [],
            ],
        ],
    ],
];
