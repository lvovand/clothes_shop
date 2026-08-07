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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cdek' => [
        'client_id' => env('CDEK_CLIENT_ID'),
        'client_secret' => env('CDEK_CLIENT_SECRET'),
        'sender_city_code' => env('CDEK_SENDER_CITY_CODE', 44), // Moscow
        'sender_address' => env('CDEK_SENDER_ADDRESS', 'г. Москва, ул. Мясницкая 10с1'),
        'pvz_code' => env('CDEK_PVZ_CODE', 'MSK16'),
        'tariff_pvz' => (int) env('CDEK_TARIFF_PVZ', 136),
        'tariff_door' => (int) env('CDEK_TARIFF_DOOR', 137),
        'free_from_amount' => (float) env('CDEK_FREE_FROM_AMOUNT', 10000),
        'yandex_map_api_key' => env('CDEK_YANDEX_MAP_API_KEY'),
    ],

    'yandex_delivery' => [
        // Боевые значения задаются в админке («Настройки → Доставка и оплата»),
        // env — только фолбэк для локальной разработки.
        'token' => env('YANDEX_DELIVERY_TOKEN'),
        'dropoff_id' => env('YANDEX_DELIVERY_DROPOFF_ID'),
    ],

    'tbank' => [
        'terminal_key' => env('TBANK_TERMINAL_KEY'),
        'secret_key' => env('TBANK_SECRET_KEY'),
    ],

    'yandex_pay' => [
        'merchant_id' => env('YANDEX_PAY_MERCHANT_ID'),
        'api_key' => env('YANDEX_PAY_API_KEY'),
        // sandbox | production — в песочнице ключом служит сам Merchant ID
        'env' => env('YANDEX_PAY_ENV', 'production'),
    ],

];
