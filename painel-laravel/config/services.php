<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // Publicação no Facebook e Instagram (API Graph da Meta). Sem token, nada é publicado.
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        'page_id' => env('META_PAGE_ID'),
        'instagram_user_id' => env('META_IG_USER_ID'),
        'page_token' => env('META_PAGE_TOKEN'),
    ],

    // Browser sem janela que gera as imagens dos posts. Vazio = procura o chrome-headless-shell (/opt) ou o Edge.
    'render' => [
        'browser' => env('RENDER_BROWSER'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
