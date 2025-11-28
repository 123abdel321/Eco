<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
//JOBS
use App\Jobs\SendSingleEmail;
//MODELS
use App\Models\User;
use App\Models\CredencialEnvio;
use App\Models\ConfiguracionEnvio;
use App\Models\Sistema\EnvioEmail;
use App\Models\Sistema\EnvioEmailDetalle;

class EmailController extends Controller
{
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'aplicacion' => 'required|string',
            'email' => 'required|email',
            'asunto' => 'required|string|max:255',
            'html' => 'required|string',
            'archivos' => 'array',
            'archivos.*.contenido' => 'required|string',
            'archivos.*.nombre' => 'required|string',
            'archivos.*.mime' => 'string',
            'metadata' => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "message" => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();

            // Verificar si el usuario tiene credenciales propias activas
            $credencialUsuario = CredencialEnvio::porUsuario($user->id)
                ->porTipo(CredencialEnvio::TIPO_EMAIL)
                ->activas()
                ->predeterminadas()
                ->first();

            $usaCredencialesPropias = !is_null($credencialUsuario);

            // 1. Crear registro en la base de datos
            $envioEmail = EnvioEmail::create([
                'user_id' => $user->id,
                'email' => $request->email,
                'contexto' => $request->metadata['contexto'] ?? $request->aplicacion,
                'status' => EnvioEmail::STATUS_EN_COLA,
                'campos_adicionales' => [
                    'asunto' => $request->asunto,
                    'aplicacion' => $request->aplicacion,
                    'metadata' => $request->metadata ?? [],
                    'usa_credenciales_propias' => $usaCredencialesPropias,
                    'credencial_id' => $credencialUsuario?->id,
                ]
            ]);

            // 2. Enviar el email mediante un job
            SendSingleEmail::dispatch(
                $request->aplicacion,
                $request->email,
                $request->asunto,
                $request->html,
                $request->archivos ?? [],
                $request->metadata ?? [],
                $envioEmail->id,
                $user->id
            );

            // 3. Obtener configuración para mostrar límites
            $configEmail = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_EMAIL)->first();

            return response()->json([
                'success' => true,
                'message' => 'Email encolado para envío',
                'envio_id' => $envioEmail->id,
                'status' => EnvioEmail::STATUS_EN_COLA,
                'credenciales_usadas' => $usaCredencialesPropias ? 'propias' : 'sistema',
                'limites' => $configEmail ? [
                    'por_minuto' => $configEmail->limite_por_minuto,
                    'por_hora' => $configEmail->limite_por_hora,
                    'por_dia' => $configEmail->limite_por_dia
                ] : null
            ], 202);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], 500);
        }
    }
}