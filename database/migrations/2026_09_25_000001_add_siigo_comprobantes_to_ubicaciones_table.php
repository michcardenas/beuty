<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeración propia por sede: cada tienda puede facturar con su propio
 * comprobante de SIIGO (su prefijo y su resolución DIAN). Vacío = usa el
 * comprobante general de la configuración de SIIGO, como hasta ahora.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('ubicaciones', function (Blueprint $table) {
            $table->unsignedInteger('siigo_document_type_id')->nullable()->after('activo')
                ->comment('Comprobante FV de SIIGO de esta sede; null = el general');
            $table->unsignedInteger('siigo_credit_note_type_id')->nullable()->after('siigo_document_type_id')
                ->comment('Comprobante NC de SIIGO de esta sede; null = el general');
        });
    }

    public function down()
    {
        Schema::table('ubicaciones', function (Blueprint $table) {
            $table->dropColumn(['siigo_document_type_id', 'siigo_credit_note_type_id']);
        });
    }
};
