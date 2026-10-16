<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota opcional que escribe facturación al enviar una cotización a la DIAN.
 *
 * Va en las observaciones de la factura, debajo de las del vendedor. Se guarda
 * para que un reintento (desde el PDV o desde la cotización) la conserve.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('facturas_siigo', function (Blueprint $table) {
            $table->text('nota_facturacion')->nullable()->after('errores');
        });
    }

    public function down()
    {
        Schema::table('facturas_siigo', function (Blueprint $table) {
            $table->dropColumn('nota_facturacion');
        });
    }
};
