<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrCode extends Model
{
    protected $fillable = [
        'bnb_qr_id',
        'currency',
        'amount',
        'gloss',
        'single_use',
        'expiration_date',
        'additional_data',
        'destination_account_id',
        'status_id',
        'qr_image',
        'voucher_id',
        'source_bank',
        'transaction_date',
        'notification_received_at',
    ];

    protected $casts = [
        'single_use'               => 'boolean',
        'expiration_date'          => 'date',
        'transaction_date'         => 'datetime',
        'notification_received_at' => 'datetime',
        'amount'                   => 'decimal:2',
    ];

    // Estados del QR
    const STATUS_NO_USADO  = 1;
    const STATUS_USADO     = 2;
    const STATUS_EXPIRADO  = 3;
    const STATUS_CON_ERROR = 4;

    public function statusLabel(): string
    {
        return match ($this->status_id) {
            self::STATUS_NO_USADO  => 'No Usado',
            self::STATUS_USADO     => 'Usado',
            self::STATUS_EXPIRADO  => 'Expirado',
            self::STATUS_CON_ERROR => 'Con Error',
            default                => 'Desconocido',
        };
    }
}
