<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
        'android_package_name' => env('GOOGLE_ANDROID_PACKAGE_NAME'),
    ],

    'apple' => [
        'iap_shared_secret' => env('APPLE_IAP_SHARED_SECRET'),
        'issuer_id' => env('APPLE_APP_STORE_ISSUER_ID'),
        'key_id' => env('APPLE_APP_STORE_KEY_ID'),
    ],

    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'list_id' => env('BREVO_LIST_ID',3),
    ],


];
