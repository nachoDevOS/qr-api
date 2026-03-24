<?php

namespace App\Banks\Union;

use App\Models\QrCode;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;

class UnionController extends Controller
{
    public function __construct(private UnionService $union) {}

    /**
     * Genera un QR de cobro y lo guarda en la base de datos.
     *
     * POST /api/union/qr/generate
     * Body: {
     *   currency, amount, gloss, single_use, expiration_date,
     *   additional_data (opcional),
     *   validez: U|D|C|S|M|A  (opcional, default: U si single_use=true, D si false)
     *   nro_max_pagos (opcional, default: 1)
     * }
     *
     * Validez:
     *   U = único pago          D = día (N pagos)   C = día (1 pago)
     *   S = semana              M = mes              A = año
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency'        => 'required|in:BOB,USD',
            'gloss'           => 'required|string|max:255',
            'amount'          => 'required|numeric|min:0.01',
            'single_use'      => 'boolean',
            'expiration_date' => 'required|date|after:today',
            'additional_data' => 'nullable|string|max:500',
            'validez'         => 'nullable|in:U,D,C,S,M,A',
            'nro_max_pagos'   => 'nullable|integer|min:1',
        ]);

        try {
            $result = $this->union->generateQR([
                'currency'        => $data['currency'],
                'gloss'           => $data['gloss'],
                'amount'          => $data['amount'],
                'single_use'      => $data['single_use'] ?? true,
                'expiration_date' => $data['expiration_date'],
                'additional_data' => $data['additional_data'] ?? '',
                'validez'         => $data['validez'] ?? null,
                'nro_max_pagos'   => $data['nro_max_pagos'] ?? 1,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al generar QR en Banco Unión',
            ], 422);
        }

        $qr = QrCode::create([
            'bank'            => QrCode::BANK_UNION,
            'bank_qr_id'      => $result['id'],
            'currency'        => $data['currency'],
            'amount'          => $data['amount'],
            'gloss'           => $data['gloss'],
            'single_use'      => $data['single_use'] ?? true,
            'expiration_date' => $data['expiration_date'],
            'additional_data' => $data['additional_data'] ?? null,
            'status_id'       => QrCode::STATUS_NO_USADO,
            'qr_image'        => $result['qr'],
        ]);

        return response()->json([
            'success'         => true,
            'id'              => $qr->id,
            'bank_qr_id'      => $result['id'],
            'qr_image'        => $result['qr'],
            'expiration_date' => $data['expiration_date'],
        ], 201);
    }

    /**
     * Consulta el estado de un QR en el banco y actualiza la base de datos.
     *
     * POST /api/union/qr/status
     * Body: { qr_id }
     */
    public function status(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_id' => 'required|integer',
        ]);

        try {
            $result = $this->union->getQRStatus($data['qr_id']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al consultar estado del QR',
            ], 422);
        }

        QrCode::where('bank', QrCode::BANK_UNION)
            ->where('bank_qr_id', $data['qr_id'])
            ->update([
                'status_id'  => $result['statusId'],
                'voucher_id' => $result['voucherId'] ?? null,
            ]);

        return response()->json([
            'success'         => true,
            'id'              => $result['id'],
            'status_id'       => $result['statusId'],
            'status_label'    => $this->statusLabel($result['statusId']),
            'estado_banco'    => $result['estado'],
            'expiration_date' => $result['expirationDate'],
            'voucher_id'      => $result['voucherId'],
        ]);
    }

    /**
     * Anula un QR en Banco Unión.
     *
     * POST /api/union/qr/cancel
     * Body: { qr_id }
     */
    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_id' => 'required|integer',
        ]);

        try {
            $result = $this->union->cancelQR($data['qr_id']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Error al anular el QR',
            ], 422);
        }

        QrCode::where('bank', QrCode::BANK_UNION)
            ->where('bank_qr_id', $data['qr_id'])
            ->update(['status_id' => QrCode::STATUS_CANCELADO]);

        return response()->json(['success' => true, 'message' => 'QR anulado correctamente']);
    }

    /**
     * Lista los QRs generados en una fecha determinada (QR_004).
     *
     * POST /api/union/qr/list
     * Body: { generation_date } — formato: YYYY-MM-DD
     */
    public function list(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generation_date' => 'required|date',
        ]);

        try {
            $result = $this->union->listQRsByDate($data['generation_date']);
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
     * Recibe la notificación de pago enviada por Banco Unión.
     * El banco llama a este endpoint automáticamente cuando alguien paga el QR.
     * El body es un XML firmado con la información del pago.
     *
     * POST /api/union/qr/notification
     */
    public function notification(Request $request): \Illuminate\Http\Response
    {
        $rawXml = $request->getContent();

        if (empty($rawXml)) {
            return response('Bad Request', 400);
        }

        $doc = new DOMDocument();
        if (! @$doc->loadXML($rawXml)) {
            return response('Invalid XML', 400);
        }

        // Extraer IdQR de la notificación
        $idQRNodes = $doc->getElementsByTagName('IdQR');
        if ($idQRNodes->length === 0) {
            return response('Missing IdQR', 400);
        }

        $idQR = $idQRNodes->item(0)->nodeValue;

        // Extraer voucher si lo incluye
        $voucherNodes = $doc->getElementsByTagName('VoucherId');
        $voucherId    = $voucherNodes->length > 0 ? $voucherNodes->item(0)->nodeValue : null;

        // Extraer banco origen si lo incluye
        $sourceBankNodes = $doc->getElementsByTagName('SourceBankId');
        $sourceBank      = $sourceBankNodes->length > 0 ? $sourceBankNodes->item(0)->nodeValue : null;

        QrCode::where('bank', QrCode::BANK_UNION)
            ->where('bank_qr_id', $idQR)
            ->update([
                'status_id'                => QrCode::STATUS_USADO,
                'voucher_id'               => $voucherId,
                'source_bank'              => $sourceBank,
                'transaction_date'         => now(),
                'notification_received_at' => now(),
            ]);

        // El banco de Unión espera XML como respuesta
        return response(
            '<?xml version="1.0" encoding="UTF-8"?><Respuesta><Codigo>000</Codigo><Mensaje>OK</Mensaje></Respuesta>',
            200,
            ['Content-Type' => 'application/xml']
        );
    }

    /**
     * Endpoint de conciliación para Banco Unión.
     * El banco llama a este endpoint para conciliar los QRs de un día.
     * Se autentica con Bearer token definido en UNION_CONCILIATION_TOKEN.
     *
     * GET /api/union/reporte-qrs/conciliacion?fecha_conciliacion=YYYY-MM-DD
     */
    public function conciliation(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        if ($token !== config('union.conciliation_token') || empty($token)) {
            return response()->json(['error' => 'No autorizado'], 401);
        }

        $fecha = $request->query('fecha_conciliacion');
        if (! $fecha || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return response()->json(['error' => 'Parámetro fecha_conciliacion requerido (YYYY-MM-DD)'], 422);
        }

        $qrs = QrCode::where('bank', QrCode::BANK_UNION)
            ->whereDate('created_at', $fecha)
            ->get(['bank_qr_id', 'amount', 'currency', 'status_id', 'voucher_id', 'transaction_date', 'gloss']);

        return response()->json([
            'fecha_conciliacion' => $fecha,
            'total'              => $qrs->count(),
            'qrs'                => $qrs,
        ]);
    }

    private function statusLabel(int $statusId): string
    {
        return match ($statusId) {
            1 => 'No Usado',
            2 => 'Usado',
            3 => 'Expirado',
            4 => 'Con Error',
            5 => 'Cancelado',
            default => 'Desconocido',
        };
    }
}
