<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Balanza física
    |--------------------------------------------------------------------------
    |
    | La computadora de la balanza publica el peso pesado en la columna
    | `peso` de la tabla `interfaces_pesosbalanzas`. El valor centinela `-1`
    | significa "no hay lectura pendiente".
    |
    | El módulo que registra el peso libera la balanza (vuelve a dejar `-1`)
    | para que pueda publicar la siguiente lectura.
    |
    */

    /** Código de balanza (`interfaces_pesosbalanzas.cod_balanza`). */
    'cod_balanza' => (int) env('BALANZA_COD', 1),

    /** Frecuencia de lectura del peso desde el frontend (milisegundos). */
    'intervalo_ms' => (int) env('BALANZA_INTERVALO_MS', 100),

];
