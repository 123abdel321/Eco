<?php

namespace App\Http\Controllers;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use App\Http\Requests\ProfileUpdateRequest;
//MODELS
use App\Models\Sistema\ConfiguracionEnvio;

class ConfiguracionController extends Controller
{
    public function configuracion()
    {
        $configs = ConfiguracionEnvio::all();

        return response()->json([
            'success' => true,
            'data' => $configs
        ]);
    }

    public function actualizarConfiguracion(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'limite_por_minuto' => 'integer|min:1',
            'limite_por_hora' => 'integer|min:1',
            'limite_por_dia' => 'integer|min:1',
            'activo' => 'boolean',
            'configuracion' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => $validator->errors()
            ], 422);
        }

        $config = ConfiguracionEnvio::findOrFail($id);
        $config->update($request->only([
            'limite_por_minuto', 
            'limite_por_hora', 
            'limite_por_dia', 
            'activo', 
            'configuracion'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Configuración actualizada',
            'data' => $config
        ]);
    }

    public function estadisticas()
    {
        $claveMinuto = "email_rate:global:" . now()->format('Y-m-d-H-i');
        $claveHora = "email_rate:global:" . now()->format('Y-m-d-H');
        $claveDia = "email_rate:global:" . now()->format('Y-m-d');

        $configEmail = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_EMAIL)->first();
        $configWhatsapp = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_WHATSAPP)->first();

        return response()->json([
            'success' => true,
            'data' => [
                'email' => [
                    'enviados_minuto' => Redis::get($claveMinuto) ?? 0,
                    'enviados_hora' => Redis::get($claveHora) ?? 0,
                    'enviados_dia' => Redis::get($claveDia) ?? 0,
                    'limites' => $configEmail ? [
                        'minuto' => $configEmail->limite_por_minuto,
                        'hora' => $configEmail->limite_por_hora,
                        'dia' => $configEmail->limite_por_dia,
                    ] : null
                ],
                'whatsapp' => [
                    'limites' => $configWhatsapp ? [
                        'minuto' => $configWhatsapp->limite_por_minuto,
                        'hora' => $configWhatsapp->limite_por_hora,
                        'dia' => $configWhatsapp->limite_por_dia,
                    ] : null
                ]
            ]
        ]);
    }
}
