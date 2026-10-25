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

    // Verificación de CFDI en el SAT: RFC del receptor (el Ayuntamiento) para cuando no está el XML guardado
    // de una factura (p. ej. una base de SIEG sin sus archivos). Sin él, el SAT responde "expresión no válida".
    // Régimen fiscal del receptor y usos de CFDI aceptados en las facturas (CFDI 4.0); vacío no se revisa.
    'sat' => [
        'rfc_receptor' => env('SAT_RFC_RECEPTOR', 'MGU6501015R2'),
        // 603: Personas Morales con Fines no Lucrativos (el Municipio).
        'regimen_receptor' => env('SAT_REGIMEN_RECEPTOR', '603'),
        'usos_cfdi' => array_filter(explode(',', (string) env('SAT_USOS_CFDI', 'G01,G03,I01,I02,I03,I04,I05,I06,I07,I08,S01'))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
