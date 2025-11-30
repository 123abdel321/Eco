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

    public function list(Request $request)
    {
        // 1. Parámetros de DataTables
        $draw = $request->get('draw');
        $start = $request->get("start");
        $length = $request->get("length", 20); // Usamos 'length' si viene, sino 20 por defecto
        $searchValue = $request->get('search')['value'] ?? null;
        
        // Ordenamiento
        $columnIndex_arr = $request->get('order');
        $columnName_arr = $request->get('columns');
        $order_arr = $request->get('order');
        
        $columnIndex = $columnIndex_arr[0]['column'] ?? 0; 
        $columnName = $columnName_arr[$columnIndex]['data'] ?? 'id'; 
        $columnSortOrder = $order_arr[0]['dir'] ?? 'desc'; 

        // 2. Consulta Base
        $query = EnvioEmail::with(['detalles', 'user']); // Usamos el modelo EnvioEmail

        // 3. Total de registros (antes de filtrar)
        $totalRecords = $query->count();

        // 4. Búsqueda (Filtros)
        // Buscamos en email, status, message_id, sg_message_id y nombre/email del usuario.
        if (!empty($searchValue)) {
            $query->where(function($q) use ($searchValue) {
                $q->where('email', 'like', '%' . $searchValue . '%')
                ->orWhere('status', 'like', '%' . $searchValue . '%')
                ->orWhere('message_id', 'like', '%' . $searchValue . '%')
                ->orWhere('sg_message_id', 'like', '%' . $searchValue . '%')
                ->orWhereHas('user', function($userQuery) use ($searchValue) {
                    $userQuery->where('name', 'like', '%' . $searchValue . '%')
                        ->orWhere('email', 'like', '%' . $searchValue . '%');
                });
            });
        }

        // 5. Total de registros filtrados (para la paginación correcta)
        $totalRecordwithFilter = $query->count();

        // 6. Ordenamiento Dinámico
        // Columnas válidas para ordenar directamente en SQL
        $validSortColumns = ['id', 'email', 'status', 'message_id', 'sg_message_id', 'created_at', 'updated_at'];
        
        if (in_array($columnName, $validSortColumns)) {
            $query->orderBy($columnName, $columnSortOrder);
        } else {
            // Por defecto si la columna no es ordenable
            $query->orderBy('id', 'DESC'); 
        }

        // 7. Paginación y Obtención de datos
        $records = $query->skip($start)
            ->take($length)
            ->get();

        // 8. Respuesta JSON
        return response()->json([
            'success' => true,
            'draw' => intval($draw),
            'iTotalRecords' => $totalRecords, 
            'iTotalDisplayRecords' => $totalRecordwithFilter, 
            'data' => $records, 
            'message' => 'Envíos de Email cargados con éxito!'
        ]);
    }

    public function webHook(Request $request)
    {
        $events = json_decode($request->getContent(), true) ?? [];

        foreach ($events as $event) {
            try {
                // 1. Extracción y limpieza de datos del evento
                $sgMessageId = $event['message_id'] ?? null;
                $sgEventId = $event['sg_event_id'] ?? null;
                $smtpId = $event['smtp-id'] ?? null;
                // El smtp-id puede venir con <>
                $smtpId = $smtpId ? trim($smtpId, '<>') : null; 
                $eventType = $event['event'] ?? null;
                $email = $event['email'] ?? null;
                $timestamp = $event['timestamp'] ?? null;

                $trackingId = null;

                // 2. Determinar el ID de rastreo (usando la lógica para separar el ID corto/largo)
                if ($sgMessageId && str_contains($sgMessageId, '.')) {
                    // Si el message_id es un formato largo, tomamos la primera parte (el ID corto)
                    $trackingId = explode('.', $sgMessageId)[0];
                } elseif ($sgMessageId) {
                    // Si es un ID corto o simple
                    $trackingId = $sgMessageId;
                }
                // Si no se encuentra message_id, usar smtp-id (sin el @dominio) como fallback.
                elseif ($smtpId && !str_contains($smtpId, '@')) {
                    $trackingId = $smtpId;
                }

                // 3. Buscar el registro de EnvioEmail
                $envio = null;

                if ($trackingId) {
                    $envio = EnvioEmail::where('message_id', 'LIKE', $trackingId . '%')
                        ->orWhere('message_id', $trackingId)
                        ->first();
                }
                
                // 4. Si se encuentra el registro, actualizar estado y registrar detalle
                if ($envio) {
                    $newStatus = null;

                    // Mapeo de eventos de SendGrid a constantes del modelo EnvioEmail
                    if ($eventType === "delivered") {
                        // El correo fue entregado al servidor del destinatario
                        $newStatus = EnvioEmail::STATUS_ENTREGADO;
                    } elseif ($eventType === "open") {
                        // El destinatario abrió el correo
                        $newStatus = EnvioEmail::STATUS_LEIDO;
                    } elseif (in_array($eventType, ["bounce", "dropped", "spamreport", "blocked"])) {
                        // El correo rebotó, fue eliminado o reportado como spam (falla final)
                        $newStatus = EnvioEmail::STATUS_FALLIDO;
                    }

                    if ($newStatus) {
                        $envio->status = $newStatus;
                        $envio->message_id = $sgMessageId; 
                        $envio->save();
                    }
                    
                    // Registrar el detalle del evento
                    EnvioEmailDetalle::create([
                        'id_email' => $envio->id,
                        'email' => $email,
                        'event' => $eventType,
                        'sg_event_id' => $sgEventId,
                        'message_id' => $sgMessageId,
                        'smtp_id' => $smtpId,
                        'timestamp' => $timestamp,
                        'campos_adicionales' => $event, // Guardar el evento completo para referencia
                    ]);

                } else {
                    Log::warning('Webhook: No se encontro correo relacionado en EnvioEmail.', [
                        'message_id_buscado' => $trackingId,
                        'message_id_full' => $sgMessageId,
                        'event' => $event,
                    ]);
                }

            } catch (\Throwable $e) {
                Log::error('Error al procesar un evento del Webhook', [
                    'error' => $e->getMessage(),
                    'event_data' => $event ?? 'N/A'
                ]);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}