<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sincronización de productos Miracle -> Belleza Áurea
    |--------------------------------------------------------------------------
    |
    | Config para el endpoint de solo-lectura que expone el catálogo de
    | productos (precios Centro de Experiencia y Distribuidor, stock,
    | imágenes y variantes) para que la tienda Áurea se conecte por URL.
    |
    | NO toca ningún flujo existente: es 100% aditivo y de solo lectura.
    |
    */

    // Token que la tienda debe enviar (por ?token= o header X-Sync-Token /
    // Authorization: Bearer). Si está vacío, el endpoint responde 503 para
    // no quedar expuesto sin autenticación por accidente.
    'sync_token' => env('AUREA_SYNC_TOKEN', ''),

    // Código (columna `codigo` de listas_precios) de cada lista de precios
    // que se expone. Se resuelve por `codigo` y, si no existe, por `nombre`.
    'lista_centro_experiencia' => env('AUREA_LISTA_CENTRO', 'PV C EXP'),
    'lista_distribuidor'       => env('AUREA_LISTA_DISTRIBUIDOR', 'PV DISTRIBUIDOR'),

    // Cuántos productos por página devuelve el endpoint (la tienda pagina con
    // ?page=). 0 = sin paginar (todo en una sola respuesta).
    'por_pagina' => (int) env('AUREA_SYNC_POR_PAGINA', 0),
];
