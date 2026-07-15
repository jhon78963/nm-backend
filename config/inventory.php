<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inicio del inventario físico (cuadre)
    |--------------------------------------------------------------------------
    |
    | Ventas POS registradas desde esta fecha se muestran en la pantalla de
    | reconciliación para ajustar el conteo en anaquel.
    |
    */
    'physical_count_started_at' => env('INVENTORY_PHYSICAL_COUNT_STARTED_AT', '2026-07-10 00:00:00'),

];
