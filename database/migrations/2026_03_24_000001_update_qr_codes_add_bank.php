<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            // Indica qué banco generó el QR (bnb, union, etc.)
            $table->string('bank', 20)->default('bnb')->after('id');

            // Renombramos bnb_qr_id a bank_qr_id para que sea genérico
            $table->renameColumn('bnb_qr_id', 'bank_qr_id');
        });
    }

    public function down(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropColumn('bank');
            $table->renameColumn('bank_qr_id', 'bnb_qr_id');
        });
    }
};
