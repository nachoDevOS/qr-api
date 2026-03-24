<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BnbService
{
    private string $authUrl;
    private string $qrUrl;
    private string $accountId;
    private string $authorizationId;
    private int $tokenTtl;

    public function __construct()
    {
        $this->authUrl         = config('bnb.auth_url');
        $this->qrUrl           = config('bnb.qr_url');
        $this->accountId       = config('bnb.account_id');
        $this->authorizationId = config('bnb.authorization_id');
        $this->tokenTtl        = config('bnb.token_ttl');
    }

    /**
     * Obtiene el token Bearer del BNB.
     * Se cachea para no pedirlo en cada solicitud.
     */
    public function getToken(): string
    {
        return Cache::remember('bnb_token', $this->tokenTtl, function () {
            $response = Http::asJson()
                ->post("{$this->authUrl}/token", [
                    'accountId'       => $this->accountId,
                    'authorizationId' => $this->authorizationId,
                ]);

            if (! $response->successful() || ! $response->json('success')) {
                throw new RuntimeException('Error al obtener token BNB: ' . $response->json('message', 'Sin respuesta del banco'));
            }

            // El banco devuelve el token JWT en el campo "message"
            return $response->json('message');
        });
    }

    /**
     * Fuerza la renovación del token eliminando el caché.
     */
    public function refreshToken(): string
    {
        Cache::forget('bnb_token');
        return $this->getToken();
    }

    /**
     * Actualiza las credenciales del banco.
     * OBLIGATORIO la primera vez antes de usar cualquier servicio.
     */
    public function updateCredentials(string $newAuthorizationId): array
    {
        $response = Http::asJson()
            ->post("{$this->authUrl}/UpdateCredentials", [
                'AccountId'            => $this->accountId,
                'actualAuthorizationId' => $this->authorizationId,
                'newAuthorizationId'   => $newAuthorizationId,
            ]);

        return $response->json();
    }

    /**
     * Genera un QR de cobro simple.
     * Devuelve el id del QR y la imagen en base64.
     */
    public function generateQR(array $data): array
    {
        return $this->request('post', "{$this->qrUrl}/getQRWithImageAsync", $data);
    }

    /**
     * Obtiene el estado de un QR por su ID.
     * statusId: 1=No Usado, 2=Usado, 3=Expirado, 4=Con Error
     */
    public function getQRStatus(int $qrId): array
    {
        return $this->request('post', "{$this->qrUrl}/getQRStatusAsync", ['qrId' => $qrId]);
    }

    /**
     * Cancela un QR (solo QRs de uso único no utilizados).
     */
    public function cancelQR(int $qrId): array
    {
        return $this->request('post', "{$this->qrUrl}/CancelQRByIdAsync", ['qrId' => $qrId]);
    }

    /**
     * Lista todos los QRs generados en una fecha determinada.
     */
    public function listQRsByDate(string $date): array
    {
        return $this->request('post', "{$this->qrUrl}/getQRbyGenerationDateAsync", ['generationDate' => $date]);
    }

    /**
     * Ejecuta una petición autenticada al banco.
     * Si el token expiró (401), lo renueva y reintenta una vez.
     */
    private function request(string $method, string $url, array $data): array
    {
        $response = $this->authenticatedHttp()->{$method}($url, $data);

        // Si el token expiró, lo renovamos y reintentamos
        if ($response->status() === 401) {
            $this->refreshToken();
            $response = $this->authenticatedHttp()->{$method}($url, $data);
        }

        return $response->json() ?? ['success' => false, 'message' => 'Sin respuesta del banco'];
    }

    private function authenticatedHttp()
    {
        return Http::withToken($this->getToken())
            ->withHeaders(['cache-control' => 'no-cache'])
            ->asJson();
    }
}
