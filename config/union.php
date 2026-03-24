<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Banco Unión — UNIQR Service
    |--------------------------------------------------------------------------
    | Conexión SOAP/XML con firma digital X509.
    | Documentación: UNIQR Service — BunApi (método único).
    */

    // URL del WSDL del servicio SOAP (ambiente de pruebas por defecto)
    'wsdl_url' => env('UNION_WSDL_URL', 'https://test-unbunqr.bancounion.com.bo/WS_QR/BunQR.svc?wsdl'),

    // Credenciales de acceso
    'usuario'     => env('UNION_USUARIO'),
    'contrasena'  => env('UNION_CONTRASENA'),

    // Identificadores del comercio
    'nro_comercio' => env('UNION_NRO_COMERCIO'),
    'nro_sucursal' => env('UNION_NRO_SUCURSAL', '1'),
    'nro_agencia'  => env('UNION_NRO_AGENCIA', '1'),
    'nro_pos'      => env('UNION_NRO_POS', '1'),

    // Clave AES-ECB: el password se encripta con AES-256-ECB usando SHA256 de este string
    // En pruebas la clave es "12345678"
    'aes_key' => env('UNION_AES_KEY', '12345678'),

    // Certificado X509 para firma digital XML (rutas absolutas a archivos .pem)
    'cert_path' => env('UNION_CERT_PATH'),  // Certificado público
    'key_path'  => env('UNION_KEY_PATH'),   // Clave privada

    // Token Bearer que Banco Unión usa para llamar al endpoint de conciliación
    'conciliation_token' => env('UNION_CONCILIATION_TOKEN'),
];
