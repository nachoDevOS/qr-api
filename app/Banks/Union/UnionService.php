<?php

namespace App\Banks\Union;

use App\Banks\Contracts\QrBankServiceInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Servicio SOAP/XML para Banco Unión — UNIQR Service.
 *
 * Método SOAP único: BunApi
 * Servicios disponibles:
 *   QR_001 — Generar QR
 *   QR_002 — Verificar estado del QR
 *   QR_003 — Anular QR
 *   QR_004 — Recuperar QRs por fecha
 *
 * El XML de solicitud se firma digitalmente con X509 (Enveloping, RSA-SHA1)
 * antes de enviarse al banco.
 */
class UnionService implements QrBankServiceInterface
{
    private string $wsdlUrl;
    private string $usuario;
    private string $contrasena;
    private string $nroComercio;
    private string $nroSucursal;
    private string $nroAgencia;
    private string $nroPOS;
    private string $aesKey;
    private ?string $certPath;
    private ?string $keyPath;

    public function __construct()
    {
        $this->wsdlUrl     = config('union.wsdl_url');
        $this->usuario     = config('union.usuario');
        $this->contrasena  = config('union.contrasena');
        $this->nroComercio = config('union.nro_comercio');
        $this->nroSucursal = config('union.nro_sucursal');
        $this->nroAgencia  = config('union.nro_agencia');
        $this->nroPOS      = config('union.nro_pos');
        $this->aesKey      = config('union.aes_key');
        $this->certPath    = config('union.cert_path');
        $this->keyPath     = config('union.key_path');
    }

    // -------------------------------------------------------------------------
    // Implementación de QrBankServiceInterface
    // -------------------------------------------------------------------------

    /**
     * QR_001 — Genera un QR de cobro.
     *
     * $data esperado:
     *   currency, amount, gloss, single_use, expiration_date,
     *   additional_data (opcional), validez (U|D|C|S|M|A), nro_max_pagos
     */
    public function generateQR(array $data): array
    {
        $validez = $data['validez'] ?? ($data['single_use'] ? 'U' : 'D');

        $detalle = [
            'IdTransaccion'    => Str::uuid()->toString(),
            'MontoQr'          => number_format((float) $data['amount'], 2, '.', ''),
            'Validez'          => $validez,
            'NroMaxPagos'      => $data['nro_max_pagos'] ?? 1,
            'FechaVencimiento' => $data['expiration_date'],
            'Glosa'            => $data['gloss'],
            'FormatoQR'        => 1, // 1=PNG base64
        ];

        if (! empty($data['additional_data'])) {
            $detalle['DatosAdicionales'] = $data['additional_data'];
        }

        $response = $this->callBunApi('QR_001', $detalle);

        if (! $response['success']) {
            return $response;
        }

        return [
            'success' => true,
            'id'      => $response['detalle']['IdQR'] ?? null,
            'qr'      => $response['detalle']['ImagenQR'] ?? null,
            'message' => $response['cabecera']['MensajeRespuesta'] ?? 'QR generado',
        ];
    }

    /**
     * QR_002 — Verifica el estado de un QR.
     *
     * Estados del banco: H=Habilitado, P=Pagado, A=Anulado, V=Vencido
     */
    public function getQRStatus(int $qrId): array
    {
        $response = $this->callBunApi('QR_002', ['IdQR' => $qrId]);

        if (! $response['success']) {
            return $response;
        }

        $estadoBanco = $response['detalle']['Estado'] ?? '';

        return [
            'success'        => true,
            'id'             => $qrId,
            'statusId'       => $this->mapEstadoToStatusId($estadoBanco),
            'expirationDate' => $response['detalle']['FechaVencimiento'] ?? null,
            'voucherId'      => $response['detalle']['VoucherId'] ?? null,
            'estado'         => $estadoBanco,
            'message'        => $response['cabecera']['MensajeRespuesta'] ?? 'OK',
        ];
    }

    /**
     * QR_003 — Anula un QR.
     */
    public function cancelQR(int $qrId): array
    {
        $response = $this->callBunApi('QR_003', ['IdQR' => $qrId]);

        return [
            'success' => $response['success'],
            'message' => $response['cabecera']['MensajeRespuesta'] ?? ($response['message'] ?? 'Error al anular'),
        ];
    }

    /**
     * QR_004 — Recupera la lista de QRs generados en una fecha.
     */
    public function listQRsByDate(string $date): array
    {
        $response = $this->callBunApi('QR_004', ['FechaGeneracion' => $date]);

        if (! $response['success']) {
            return $response;
        }

        // El banco puede devolver un item o una lista bajo <Items>
        $items = $response['detalle']['Items'] ?? $response['detalle'] ?? [];

        return [
            'success'       => true,
            'dTOqrDetails'  => is_array($items) ? $items : [$items],
            'message'       => $response['cabecera']['MensajeRespuesta'] ?? 'OK',
        ];
    }

    // -------------------------------------------------------------------------
    // Llamada SOAP al banco
    // -------------------------------------------------------------------------

    /**
     * Construye el XML de solicitud, lo firma y lo envía via SOAP.
     */
    private function callBunApi(string $servicio, array $detalle): array
    {
        $solicitudDoc = $this->buildSolicitud($servicio, $detalle);
        $signedXml    = $this->signXml($solicitudDoc);
        $soapResponse = $this->sendSoap($signedXml);

        return $this->parseResponse($soapResponse);
    }

    /**
     * Construye el DOMDocument de <Solicitud> con cabecera y detalle.
     */
    private function buildSolicitud(string $servicio, array $detalle): DOMDocument
    {
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('Solicitud');
        $doc->appendChild($root);

        // Cabecera
        $cabecera = $doc->createElement('Cabecera');
        $root->appendChild($cabecera);
        $this->appendChildren($doc, $cabecera, [
            'Servicio'    => $servicio,
            'Usuario'     => $this->usuario,
            'Contrasena'  => $this->encryptPassword(),
            'NroComercio' => $this->nroComercio,
            'NroSucursal' => $this->nroSucursal,
            'NroAgencia'  => $this->nroAgencia,
            'NroPOS'      => $this->nroPOS,
        ]);

        // Detalle
        $detalleEl = $doc->createElement('Detalle');
        $root->appendChild($detalleEl);
        $this->appendChildren($doc, $detalleEl, $detalle);

        return $doc;
    }

    /**
     * Firma el XML de la solicitud con X509 — Enveloping Signature (RSA-SHA1).
     *
     * Estructura resultante:
     *   <ds:Signature>
     *     <ds:SignedInfo> ... </ds:SignedInfo>
     *     <ds:SignatureValue> ... </ds:SignatureValue>
     *     <ds:KeyInfo> <ds:X509Data> ... </ds:X509Data> </ds:KeyInfo>
     *     <ds:Object Id="payload"> <Solicitud>...</Solicitud> </ds:Object>
     *   </ds:Signature>
     */
    private function signXml(DOMDocument $solicitud): string
    {
        $this->validateCertFiles();

        $dsig          = 'http://www.w3.org/2000/09/xmldsig#';
        $solicitudXml  = $solicitud->saveXML($solicitud->documentElement);

        // ── 1. Construir <ds:Object Id="payload"> con el contenido de la solicitud ──
        $objectDoc = new DOMDocument('1.0', 'UTF-8');
        $objectEl  = $objectDoc->createElementNS($dsig, 'ds:Object');
        $objectEl->setAttribute('Id', 'payload');
        $objectDoc->appendChild($objectEl);
        $objectEl->appendChild($objectDoc->importNode($solicitud->documentElement, true));

        // ── 2. Canonicalizar el Object para calcular el DigestValue ──
        $c14nObject  = $objectEl->C14N(false, false);
        $digestValue = base64_encode(sha1($c14nObject, true));

        // ── 3. Construir <ds:SignedInfo> ──
        $signedInfoXml =
            '<ds:SignedInfo xmlns:ds="' . $dsig . '">' .
            '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>' .
            '<ds:SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>' .
            '<ds:Reference URI="#payload">' .
            '<ds:DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>' .
            '<ds:DigestValue>' . $digestValue . '</ds:DigestValue>' .
            '</ds:Reference>' .
            '</ds:SignedInfo>';

        // ── 4. Canonicalizar el SignedInfo y firmar ──
        $siDoc = new DOMDocument();
        $siDoc->loadXML($signedInfoXml);
        $c14nSignedInfo = $siDoc->documentElement->C14N(false, false);

        $privateKey = openssl_pkey_get_private(file_get_contents($this->keyPath));
        if ($privateKey === false) {
            throw new RuntimeException('No se pudo leer la clave privada: ' . $this->keyPath);
        }

        openssl_sign($c14nSignedInfo, $rawSignature, $privateKey, OPENSSL_ALGO_SHA1);
        $signatureValue = base64_encode($rawSignature);

        // ── 5. Extraer el certificado en base64 (sin cabeceras PEM) ──
        $certContent = file_get_contents($this->certPath);
        $certBase64  = preg_replace('/-----[^-]+-----|\s/', '', $certContent);

        // ── 6. Ensamblar el XML firmado completo ──
        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<ds:Signature xmlns:ds="' . $dsig . '">' .
            $signedInfoXml .
            '<ds:SignatureValue>' . $signatureValue . '</ds:SignatureValue>' .
            '<ds:KeyInfo>' .
            '<ds:X509Data>' .
            '<ds:X509Certificate>' . $certBase64 . '</ds:X509Certificate>' .
            '</ds:X509Data>' .
            '</ds:KeyInfo>' .
            '<ds:Object Id="payload">' . $solicitudXml . '</ds:Object>' .
            '</ds:Signature>';
    }

    /**
     * Envía el XML firmado al banco via HTTP/SOAP.
     */
    private function sendSoap(string $signedXml): string
    {
        // El XML firmado va como parámetro del método BunApi dentro del envelope SOAP
        $soapEnvelope =
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" ' .
            '               xmlns:tns="http://tempuri.org/">' .
            '<soap:Body>' .
            '<tns:BunApi>' .
            '<tns:xml><![CDATA[' . $signedXml . ']]></tns:xml>' .
            '</tns:BunApi>' .
            '</soap:Body>' .
            '</soap:Envelope>';

        $response = Http::withHeaders([
            'Content-Type' => 'text/xml; charset=utf-8',
            'SOAPAction'   => '"http://tempuri.org/IBunQR/BunApi"',
        ])->withBody($soapEnvelope, 'text/xml')
          ->timeout(30)
          ->post($this->wsdlUrl);

        if ($response->failed()) {
            throw new RuntimeException(
                'Error de conexión con Banco Unión (HTTP ' . $response->status() . '): ' . $response->body()
            );
        }

        return $response->body();
    }

    // -------------------------------------------------------------------------
    // Parseo de respuesta
    // -------------------------------------------------------------------------

    /**
     * Parsea la respuesta SOAP/XML del banco y la normaliza.
     *
     * Respuesta exitosa del banco: CodigoRespuesta = "000"
     */
    private function parseResponse(string $soapBody): array
    {
        $doc = new DOMDocument();
        $doc->loadXML($soapBody);

        // Extraer el XML de respuesta del cuerpo SOAP (BunApiResult o similar)
        $results = $doc->getElementsByTagName('BunApiResult');
        if ($results->length === 0) {
            $results = $doc->getElementsByTagName('BunApiResponse');
        }

        $responseXml = $results->length > 0
            ? $results->item(0)->nodeValue
            : $soapBody;

        $respDoc = new DOMDocument();
        if (! @$respDoc->loadXML($responseXml)) {
            throw new RuntimeException('Respuesta inválida del banco Unión: ' . $soapBody);
        }

        $cabecera = [];
        foreach ($respDoc->getElementsByTagName('Cabecera')->item(0)?->childNodes ?? [] as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $cabecera[$node->nodeName] = $node->nodeValue;
            }
        }

        $detalle = [];
        $detalleNode = $respDoc->getElementsByTagName('Detalle')->item(0);
        if ($detalleNode) {
            foreach ($detalleNode->childNodes as $node) {
                if ($node->nodeType === XML_ELEMENT_NODE) {
                    $detalle[$node->nodeName] = $node->nodeValue;
                }
            }
        }

        $codigoRespuesta = $cabecera['CodigoRespuesta'] ?? '';
        $success         = ($codigoRespuesta === '000');

        return [
            'success'  => $success,
            'cabecera' => $cabecera,
            'detalle'  => $detalle,
            'message'  => $cabecera['MensajeRespuesta'] ?? 'Error desconocido del banco',
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Encripta la contraseña con AES-256-ECB, PKCS7 padding.
     * Clave = SHA256 binario del string configurado en UNION_AES_KEY.
     */
    private function encryptPassword(): string
    {
        $key       = hash('sha256', $this->aesKey, true); // 32 bytes = AES-256
        $encrypted = openssl_encrypt(
            $this->contrasena,
            'AES-256-ECB',
            $key,
            OPENSSL_RAW_DATA  // PKCS7 padding por defecto en OpenSSL
        );

        if ($encrypted === false) {
            throw new RuntimeException('Error al encriptar la contraseña para Banco Unión');
        }

        return base64_encode($encrypted);
    }

    /**
     * Convierte el estado del banco al STATUS_ID interno.
     *
     * H = Habilitado  → 1 (No Usado)
     * P = Pagado      → 2 (Usado)
     * V = Vencido     → 3 (Expirado)
     * A = Anulado     → 5 (Cancelado)
     */
    private function mapEstadoToStatusId(string $estado): int
    {
        return match ($estado) {
            'H' => 1,
            'P' => 2,
            'V' => 3,
            'A' => 5,
            default => 4, // Con Error
        };
    }

    private function appendChildren(DOMDocument $doc, DOMElement $parent, array $children): void
    {
        foreach ($children as $name => $value) {
            $el = $doc->createElement((string) $name, htmlspecialchars((string) $value, ENT_XML1));
            $parent->appendChild($el);
        }
    }

    private function validateCertFiles(): void
    {
        if (empty($this->certPath) || ! file_exists($this->certPath)) {
            throw new RuntimeException(
                'Certificado X509 no encontrado. Configure UNION_CERT_PATH en .env apuntando al archivo .pem del certificado.'
            );
        }

        if (empty($this->keyPath) || ! file_exists($this->keyPath)) {
            throw new RuntimeException(
                'Clave privada no encontrada. Configure UNION_KEY_PATH en .env apuntando al archivo .pem de la clave privada.'
            );
        }
    }
}
