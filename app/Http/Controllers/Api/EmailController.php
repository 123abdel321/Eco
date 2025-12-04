<?php

namespace App\Http\Controllers\Api;

use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
//JOBS
use App\Jobs\SendSingleEmail;
//MODELS
use App\Models\User;
use App\Models\CredencialEnvio;
use App\Models\Sistema\EnvioEmail;
use App\Models\Sistema\EnvioEmailDetalle;
use App\Models\Sistema\ConfiguracionEnvio;

class EmailController extends Controller
{
    public function send(Request $request)
    {
        $user = Auth::user();
        $envioEmail = null; // Inicializamos a null
        $usaCredencialesPropias = false;
        $credencialUsuario = null;

        // 1. Definición de la Validación
        $validator = Validator::make($request->all(), [
            'aplicacion' => 'required|string',
            'email' => 'required|email',
            'asunto' => 'required|string|max:255',
            'html' => 'required|string',
            'archivos' => 'nullable|array',
            'archivos.*.contenido' => 'required|string',
            'archivos.*.nombre' => 'required|string',
            'archivos.*.mime' => 'nullable|string',
            'metadata' => 'nullable|array', // Lo hacemos nullable aquí para usar el coalescing operator
            'filter_metadata' => 'nullable|array',
        ]);

        try {
            // --- 2. Pre-chequeo y Creación del Registro Principal (INTENTO) ---

            // 2.1. Verificar credenciales antes de crear el registro
            $credencialUsuario = CredencialEnvio::porUsuario($user->id)
                ->porTipo(CredencialEnvio::TIPO_EMAIL)
                ->activas()
                ->predeterminadas()
                ->first();

            $usaCredencialesPropias = !is_null($credencialUsuario);

            // 2.2. Crear el registro BASE en la tabla envios_email (Usamos valores seguros/placeholders)
            // Esto asegura que siempre tendremos un registro del intento.
            $envioEmail = EnvioEmail::create([
                'user_id' => $user->id,
                // Usamos el operador coalescente para evitar fallos de DB si los campos requeridos faltan
                'email' => $request->email ?? 'FALLO_VALIDACION',
                'contexto' => $request->metadata['contexto'] ?? $request->aplicacion ?? 'email.api',
                'status' => EnvioEmail::STATUS_EN_COLA,
                'filter_metadata' => $request->filter_metadata ?? [],
                'campos_adicionales' => [
                    'asunto' => $request->asunto ?? 'FALLO_VALIDACION',
                    'aplicacion' => $request->aplicacion ?? 'api',
                    'metadata' => $request->metadata ?? [],
                    'usa_credenciales_propias' => $usaCredencialesPropias,
                    'credencial_id' => $credencialUsuario?->id,
                    'html_preview' => substr($request->html ?? '', 0, 500) . '...', // Truncamos el HTML para el log
                    'archivos' => $request->archivos ?? [],
                    'raw_request' => $request->all(), // Guardamos el payload completo
                ]
            ]);

            // 2.3. Evaluación de la Validación
            if ($validator->fails()) {
                // Si la validación FALLA, registramos el fallo en el registro creado y terminamos.
                
                $errorMessages = $validator->errors()->all();
                $fullErrorMessage = implode('; ', $errorMessages);

                // Actualizar el estado del registro recién creado
                $envioEmail->update(['status' => EnvioEmail::STATUS_FALLIDO]);
                
                // Crear un detalle de fallo inmediato
                EnvioEmailDetalle::create([
                    'id_email' => $envioEmail->id, // Asumo que la columna de enlace es 'id_email'
                    'email' => $request->email ?? 'validation_error',
                    'event' => 'Error API (Validación)',
                    'response' => json_encode(['errors' => $errorMessages]),
                    'error_message' => 'Fallo de validación en la solicitud: ' . $fullErrorMessage,
                    'timestamp' => now(),
                    'campos_adicionales' => json_encode(['request_data' => $request->all()]),
                ]);
                
                // Devolver la respuesta de error de validación (422)
                return response()->json([
                    "success" => false,
                    "message" => $validator->errors()
                ], 422);
            }
            
            // --- 3. Despacho (Si la Validación es Exitosa) ---
            
            // 2. Enviar el email mediante un job (Solo si pasa la validación)
            SendSingleEmail::dispatch(
                $request->aplicacion,
                $request->email,
                $request->asunto,
                $request->html,
                $request->archivos ?? [],
                $request->metadata ?? [],
                $envioEmail->id,
                $user->id
            )->onQueue('email');
            
            // --- 4. Respuesta de Éxito ---

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
            
            // --- 5. Manejo de Fallos Imprevistos (Fallo de DB, u otro error fatal) ---

            $errorMessage = "Error al intentar crear el envío o despachar el Job: " . $e->getMessage();
            Log::error('EmailController@send: Error fatal', [
                'error' => $errorMessage,
                'request' => $request->all(),
            ]);

            // Si el registro se creó antes del fallo (protección)
            if ($envioEmail) {
                 // Actualizar el estado a FALLIDO
                $envioEmail->update(['status' => EnvioEmail::STATUS_FALLIDO]);
                
                // Crear un detalle de fallo
                EnvioEmailDetalle::create([
                    'id_email' => $envioEmail->id,
                    'email' => $request->email ?? 'fatal_error',
                    'event' => 'Error API (Fatal)',
                    'response' => json_encode(['error_interno' => $errorMessage]),
                    'error_message' => 'Fallo interno. El envío no pudo ser procesado.',
                    'timestamp' => now(),
                    'campos_adicionales' => json_encode([
                        'exception_class' => get_class($e),
                        'line' => $e->getLine(),
                        'request_data' => $request->all(),
                    ]),
                ]);
            }

            return response()->json([
                "success" => false,
                "message" => "Fallo interno del servidor. Contacte a soporte: " . $e->getMessage()
            ], 500);
        }
    }

    public function list(Request $request)
    {
        // 1. Parámetros de DataTables
        $draw = $request->get('draw');
        $start = $request->get("start");
        $length = $request->get("length", 20);
        $searchValue = $request->get('search')['value'] ?? null;
        
        // Ordenamiento
        $columnIndex_arr = $request->get('order');
        $columnName_arr = $request->get('columns');
        $order_arr = $request->get('order');
        
        $columnIndex = $columnIndex_arr[0]['column'] ?? 0; 
        $columnName = $columnName_arr[$columnIndex]['data'] ?? 'id'; 
        $columnSortOrder = $order_arr[0]['dir'] ?? 'desc'; 

        $userId = $request->user()->id;
        
        // 2. Consulta Base
        $query = EnvioEmail::with(['detalles', 'user'])
            ->select(
                '*',
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %T') AS fecha_creacion"),
                DB::raw("DATE_FORMAT(updated_at, '%Y-%m-%d %T') AS fecha_edicion")
            )
            ->where('user_id', $userId);

        // 3. Total de registros (antes de filtrar)
        $totalRecords = $query->count();
        
        // --- 4. Filtro Dinámico en JSON (filter_metadata) ---
        // Excluir parámetros que ya son de DataTables o de uso interno
        $ignoredParams = ['draw', 'start', 'length', 'search', 'order', 'columns', '_', 'fecha_desde', 'fecha_hasta'];
            
        $fechaDesde = $request->query('fecha_desde');
        $fechaHasta = $request->query('fecha_hasta');

        if (!empty($fechaDesde)) {
            $query->where('created_at', '>=', $fechaDesde . ' 00:00:00');
        }

        if (!empty($fechaHasta)) {
            $query->where('created_at', '<=', $fechaHasta . ' 23:59:59');
        }

        foreach ($request->query() as $key => $value) {
            // Aplicar el filtro si el parámetro NO es uno ignorado y tiene valor
            if (!in_array($key, $ignoredParams) && !is_null($value)) {
                // Filtro para campos específicos de la tabla (ej: 'status=enviado')
                if (Schema::hasColumn('envios_email', $key)) {
                    $query->where($key, $value);
                }
                
                // Filtro para la metadata JSON
                $query->whereJsonContains("filter_metadata->{$key}", $value);
            }
        }
        
        // 5. Búsqueda general de DataTables (se mantiene)
        if (!empty($searchValue)) {
            $query->where(function($q) use ($searchValue) {
                $q->where('email', 'like', '%' . $searchValue . '%')
                ->orWhere('status', 'like', '%' . $searchValue . '%')
                ->orWhere('message_id', 'like', '%' . $searchValue . '%')
                ->orWhere('sg_message_id', 'like', '%' . $searchValue . '%');
            });
        }

        // 6. Total de registros filtrados (para la paginación correcta)
        $totalRecordwithFilter = $query->count();

        // 7. Ordenamiento Dinámico (se mantiene)
        $validSortColumns = ['id', 'email', 'status', 'message_id', 'sg_message_id', 'created_at', 'updated_at'];
        
        if (in_array($columnName, $validSortColumns)) {
            $query->orderBy($columnName, $columnSortOrder);
        } else {
            $query->orderBy('id', 'DESC'); 
        }

        // 8. Paginación y Obtención de datos (se mantiene)
        $records = $query->skip($start)
            ->take($length)
            ->get();

        // 9. Respuesta JSON (se mantiene)
        return response()->json([
            'success' => true,
            'draw' => intval($draw),
            'iTotalRecords' => $totalRecords, 
            'iTotalDisplayRecords' => $totalRecordwithFilter, 
            'data' => $records, 
            'message' => 'Envíos de Email cargados con éxito!'
        ]);
    }

    public function detail(Request $request)
    {
        try {
            // 1. Extracción de Parámetros DataTables
            $draw = $request->get('draw');
            $start = $request->get("start");
            // Se usa $length para la paginación, si no está definido, usamos un valor sensato.
            $length = $request->get("length", 20); 
            
            // ID del envío principal (parámetro esencial para este método)
            $emailId = $request->get('id'); 
            
            // Parámetros de ordenamiento
            $columnIndex_arr = $request->get('order');
            $columnName_arr = $request->get('columns');
            $order_arr = $request->get('order');
            
            // Determinación de la columna y el orden para la consulta
            $columnIndex = $columnIndex_arr[0]['column'] ?? 0; 
            $columnName = $columnName_arr[$columnIndex]['data'] ?? 'id'; 
            $columnSortOrder = $order_arr[0]['dir'] ?? 'desc'; 

            if (!$emailId) {
                 return response()->json([
                    'success' => false,
                    'message' => 'El parámetro "id" del envío es obligatorio.',
                ], 400);
            }

            // 2. Consulta Base y Filtro de Seguridad
            $query = EnvioEmailDetalle::with('email')
                // 2.1. Filtro por el ID del envío principal
                ->where('id_email', $emailId);

            // 3. Total de registros filtrados (antes de la paginación)
            $totalRecordwithFilter = $query->count();
            // Para el detalle, el total de registros es igual al total filtrado.
            $totalRecords = $totalRecordwithFilter; 
            
            // 4. Ordenamiento Dinámico
            // Define las columnas válidas para ordenar en la tabla EnvioEmailDetalle
            $validSortColumns = ['id', 'created_at', 'updated_at', 'status_evento']; 
            
            if (in_array($columnName, $validSortColumns)) {
                $query->orderBy($columnName, $columnSortOrder);
            } else {
                // Por defecto, ordena por la columna de creación de forma descendente.
                $query->orderBy('created_at', 'DESC'); 
            }

            // 5. Selección de Columnas (incluyendo formato de fechas)
            $query->select(
                '*',
                // Usamos la función de DB para formatear fechas
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %T') AS fecha_creacion"),
                DB::raw("DATE_FORMAT(updated_at, '%Y-%m-%d %T') AS fecha_edicion")
            );

            // 6. Paginación y Obtención de datos
            $records = $query->skip($start)
                ->take($length)
                ->get();
            
            // 7. Respuesta JSON
            return response()->json([
                'success' => true,
                'draw' => intval($draw),
                'iTotalRecords' => $totalRecords, 
                'iTotalDisplayRecords' => $totalRecordwithFilter, 
                'data' => $records, 
                'message' => 'Emails detalle generado con éxito!'
            ]);

        } catch (Exception $e) {

            return response()->json([
                "success" => false,
                'data' => [],
                "message" => "Ocurrió un error al procesar la solicitud: " . $e->getMessage()
            ], 500);
        }
    }

    public function webHook(Request $request)
    {
        $events = json_decode($request->getContent(), true) ?? [];
        Log::error($events);
        foreach ($events as $event) {
            try {
                // 1. Extracción y limpieza de datos del evento
                $sgMessageId = $event['sg_message_id'] ?? null;
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
                Log::error([
                    'trackingId' => $trackingId,
                    'smtpId' => $smtpId
                ]);

                if ($trackingId) {
                    $envio = EnvioEmail::where('message_id', 'LIKE', $trackingId . '%')
                        ->orWhere('message_id', $trackingId)
                        ->first();
                }

                if (!$envio) {
                    $envio = EnvioEmail::where('message_id', $smtpId)->first();
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

                    Log::info([
                        "newStatus" => $newStatus,
                        "eventType" => $eventType
                    ]);

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
                        'message_id' => $trackingId,
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