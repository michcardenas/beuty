<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Factura electrónica (SIIGO) para cotizaciones.
 *
 * Hasta ahora facturas_siigo solo colgaba de una venta del PDV. Una cotización
 * también puede facturarse, así que la venta pasa a ser opcional y se añade
 * el enlace a la cotización. El permiso limita quién puede enviar a la DIAN.
 */
return new class extends Migration
{
    public function up()
    {
        // Laravel 9 sin doctrine/dbal: change() no está disponible.
        DB::statement('ALTER TABLE facturas_siigo MODIFY venta_pdv_id BIGINT UNSIGNED NULL');

        Schema::table('facturas_siigo', function (Blueprint $table) {
            $table->foreignId('solicitud_cotizacion_id')->nullable()->after('venta_pdv_id')
                ->constrained('solicitudes_cotizacion')->nullOnDelete();
        });

        Schema::table('solicitudes_cotizacion', function (Blueprint $table) {
            $table->foreignId('factura_siigo_id')->nullable()->after('archivo_factura')
                ->constrained('facturas_siigo')->nullOnDelete();
        });

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'cotizaciones.facturar_siigo', 'guard_name' => 'web']);
    }

    public function down()
    {
        Schema::table('solicitudes_cotizacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_siigo_id');
        });

        Schema::table('facturas_siigo', function (Blueprint $table) {
            $table->dropConstrainedForeignId('solicitud_cotizacion_id');
        });

        DB::table('facturas_siigo')->whereNull('venta_pdv_id')->delete();
        DB::statement('ALTER TABLE facturas_siigo MODIFY venta_pdv_id BIGINT UNSIGNED NOT NULL');

        Permission::where('name', 'cotizaciones.facturar_siigo')->delete();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
