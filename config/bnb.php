<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Banco Nacional de Bolivia - API Market
    |--------------------------------------------------------------------------
    | Credenciales y URLs para conectarse a la API del BNB.
    | Documentación: https://www.bnb.com.bo/PortalBNB/Api/OpenBanking
    */

    'account_id'       => env('BNB_ACCOUNT_ID'),
    'authorization_id' => env('BNB_AUTHORIZATION_ID'),

    // URLs base
    'auth_url' => env('BNB_AUTH_URL', 'http://test.bnb.com.bo/ClientAuthentication.API/api/v1/auth'),
    'qr_url'   => env('BNB_QR_URL',   'http://test.bnb.com.bo/QRSimple.API/api/v1/main'),

    // Tiempo de vida del token en caché (segundos). El token del BNB expira,
    // se cachea por 50 minutos para evitar peticiones innecesarias.
    'token_ttl' => env('BNB_TOKEN_TTL', 3000),
];
