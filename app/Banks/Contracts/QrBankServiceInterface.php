<?php

namespace App\Banks\Contracts;

/**
 * Contrato que todos los bancos deben implementar.
 * Al agregar un nuevo banco, su Service debe implementar esta interfaz.
 */
interface QrBankServiceInterface
{
    /**
     * Genera un QR de cobro.
     * Devuelve: { success, id (bank_qr_id), qr (base64), message }
     */
    public function generateQR(array $data): array;

    /**
     * Consulta el estado de un QR por su ID en el banco.
     * Devuelve: { success, id, statusId, expirationDate, voucherId, message }
     */
    public function getQRStatus(int $qrId): array;

    /**
     * Cancela un QR (solo QRs de uso único no utilizados).
     * Devuelve: { success, message }
     */
    public function cancelQR(int $qrId): array;

    /**
     * Lista los QRs generados en una fecha determinada.
     * Devuelve: { success, dTOqrDetails[], message }
     */
    public function listQRsByDate(string $date): array;
}
