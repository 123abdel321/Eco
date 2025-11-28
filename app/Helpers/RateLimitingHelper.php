<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

class RateLimitingHelper
{
    // Las claves deben ser ÚNICAS para cada período (MINUTO, HORA, DÍA)
    public static function puedeEnviar($tipo, $limitePorMinuto, $limitePorHora, $limitePorDia)
    {
        // AJUSTE: Agregar el período ('min', 'hr', 'day') a la clave
        $claveMinuto = "{$tipo}_rate:global:min:" . now()->format('Y-m-d-H-i');
        $claveHora   = "{$tipo}_rate:global:hr:"  . now()->format('Y-m-d-H');
        $claveDia    = "{$tipo}_rate:global:day:" . now()->format('Y-m-d');

        $enviosMinuto = Cache::get($claveMinuto) ?? 0;
        $enviosHora   = Cache::get($claveHora) ?? 0;
        $enviosDia    = Cache::get($claveDia) ?? 0;
        
        return $enviosMinuto < $limitePorMinuto && 
               $enviosHora < $limitePorHora &&
               $enviosDia < $limitePorDia;
    }

    public static function registrarEnvio($tipo)
    {
        // AJUSTE: Usar las claves ÚNICAS para cada período
        $claveMinuto = "{$tipo}_rate:global:min:" . now()->format('Y-m-d-H-i');
        $claveHora   = "{$tipo}_rate:global:hr:"  . now()->format('Y-m-d-H');
        $claveDia    = "{$tipo}_rate:global:day:" . now()->format('Y-m-d');

        // Incrementa y establece la expiración (TTL) en segundos.
        // TTL debe ser *al menos* la duración del período para asegurar que la cuenta se mantenga.
        // Minuto: Expira en 60 segundos (el TTL correcto para un minuto es 60, o 61 para margen)
        Cache::put($claveMinuto, (Cache::get($claveMinuto) ?? 0) + 1, 60);
        // Hora: Expira en 3600 segundos (o 3601 para margen)
        Cache::put($claveHora, (Cache::get($claveHora) ?? 0) + 1, 3600);
        // Día: Expira en 86400 segundos (o 86401 para margen)
        Cache::put($claveDia, (Cache::get($claveDia) ?? 0) + 1, 86400);
    }
}