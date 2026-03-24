<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use App\Services\BnbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class QrController extends Controller
{
    public function __construct(private BnbService $bnb) {}

    /**
     * Genera un QR de cobro y lo guarda en la base de datos.
     *
     * POST /api/qr/generate
     * Body: { currency, gloss, amount, single_use, expiration_date, additional_data, destination_account_id }
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency'              => 'required|in:BOB,USD',
            'gloss'                 => 'required|string|max:255',
            'amount'                => 'required|numeric|min:0.01',
            'single_use'            => 'boolean',
            'expiration_date'       => 'required|date|after:today',
            'additional_data'       => 'nullable|string|max:500',
            'destination_account_id' => 'required|in:1,2',
        ]);

        try {
            $result = $this->bnb->generateQR([
                'currency'             => $data['currency'],
                'gloss'                => $data['gloss'],
                'amount'               => $data['amount'],
                'singleUse'            => $data['single_use'] ?? true,
                'expirationDate'       => $data['expiration_date'],
                'additionalData'       => $data['additional_data'] ?? '',
                'destinationAccountId' => $data['destination_account_id'],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al generar QR en el banco',
            ], 422);
        }

        $qr = QrCode::create([
            'bnb_qr_id'              => $result['id'],
            'currency'               => $data['currency'],
            'amount'                 => $data['amount'],
            'gloss'                  => $data['gloss'],
            'single_use'             => $data['single_use'] ?? true,
            'expiration_date'        => $data['expiration_date'],
            'additional_data'        => $data['additional_data'] ?? null,
            'destination_account_id' => $data['destination_account_id'],
            'status_id'              => QrCode::STATUS_NO_USADO,
            'qr_image'               => $result['qr'],
        ]);

        return response()->json([
            'success'    => true,
            'id'         => $qr->id,
            'bnb_qr_id'  => $result['id'],
            'qr_image'   => $result['qr'],   // base64 — usarlo como <img src="data:image/png;base64,{qr_image}">
            'expiration_date' => $data['expiration_date'],
        ], 201);
    }

    /**
     * Consulta el estado de un QR en el banco y actualiza la base de datos.
     *
     * POST /api/qr/status
     * Body: { qr_id }
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_id' => 'required|integer',
        ]);

        try {
            $result = $this->bnb->getQRStatus($data['qr_id']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al consultar estado del QR',
            ], 422);
        }

        QrCode::where('bnb_qr_id', $data['qr_id'])->update([
            'status_id'  => $result['statusId'],
            'voucher_id' => $result['voucherId'] ?? null,
        ]);

        return response()->json([
            'success'         => true,
            'id'              => $result['id'],
            'status_id'       => $result['statusId'],
            'status_label'    => $this->statusLabel($result['statusId']),
            'expiration_date' => $result['expirationDate'],
            'voucher_id'      => $result['voucherId'],
        ]);
    }

    /**
     * Cancela un QR (solo QRs de uso único no utilizados).
     *
     * POST /api/qr/cancel
     * Body: { qr_id }
     */
    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_id' => 'required|integer',
        ]);

        try {
            $result = $this->bnb->cancelQR($data['qr_id']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al cancelar el QR',
            ], 422);
        }

        QrCode::where('bnb_qr_id', $data['qr_id'])->update([
            'status_id' => QrCode::STATUS_CON_ERROR,
        ]);

        return response()->json(['success' => true, 'message' => 'QR cancelado correctamente']);
    }

    /**
     * Lista todos los QRs generados en una fecha determinada.
     *
     * POST /api/qr/list
     * Body: { generation_date } — formato: YYYY-MM-DD
     */
    public function list(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generation_date' => 'required|date',
        ]);

        try {
            $result = $this->bnb->listQRsByDate($data['generation_date']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al listar QRs',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => $result['dTOqrDetails'] ?? [],
        ]);
    }

    /**
     * Recibe la notificación de pago enviada por el banco BNB.
     * El banco llama a este endpoint automáticamente cuando alguien paga el QR.
     *
     * POST /api/qr/notification
     */
    public function notification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'QRId'                => 'required',
            'Gloss'               => 'nullable|string',
            'sourceBankId'        => 'nullable|integer',
            'originName'          => 'nullable|string',
            'VoucherId'           => 'nullable|string',
            'TransactionDateTime' => 'nullable|string',
            'additionalData'      => 'nullable|string',
            'amount'              => 'nullable|numeric',
            'currencyId'          => 'nullable|integer',
        ]);

        QrCode::where('bnb_qr_id', $data['QRId'])->update([
            'status_id'               => QrCode::STATUS_USADO,
            'voucher_id'              => $data['VoucherId'] ?? null,
            'source_bank'             => $data['sourceBankId'] ?? null,
            'transaction_date'        => now(),
            'notification_received_at' => now(),
        ]);

        // El banco espera exactamente esta respuesta
        return response()->json(['success' => true, 'message' => 'OK']);
    }

    private function statusLabel(int $statusId): string
    {
        return match ($statusId) {
            1 => 'No Usado',
            2 => 'Usado',
            3 => 'Expirado',
            4 => 'Con Error',
            default => 'Desconocido',
        };
    }
}
