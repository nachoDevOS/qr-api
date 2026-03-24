<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bnb_qr_id')->nullable()->comment('ID del QR retornado por el BNB');
            $table->string('currency', 3)->default('BOB')->comment('BOB o USD');
            $table->decimal('amount', 10, 2);
            $table->string('gloss')->comment('Descripción del pago');
            $table->boolean('single_use')->default(true);
            $table->date('expiration_date');
            $table->string('additional_data')->nullable();
            $table->tinyInteger('destination_account_id')->default(1)->comment('1=moneda nacional, 2=moneda extranjera');
            $table->tinyInteger('status_id')->default(1)->comment('1=No Usado, 2=Usado, 3=Expirado, 4=Con Error');
            $table->text('qr_image')->nullable()->comment('Imagen en base64 retornada por el BNB');
            $table->string('voucher_id')->nullable()->comment('Código de bancarización del pago');
            $table->unsignedInteger('source_bank')->nullable()->comment('Banco desde el que se realizó el pago');
            $table->timestamp('transaction_date')->nullable();
            $table->timestamp('notification_received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
