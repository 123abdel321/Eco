<?php

namespace App\Http\Controllers\Api;

use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
//JOBS
use App\Jobs\SendSingleWhatsapp;
//MODELS
use App\Models\User;
use App\Models\CredencialEnvio;
use App\Models\Sistema\EnvioWhatsapp;
use App\Models\Sistema\ConfiguracionEnvio;
use App\Models\Sistema\EnvioWhatsappDetalle;

class WhatsappController extends Controller
{
    public function send(Request $request)
    {
        $user = Auth::user();
        $envioWhatsapp = null; // Inicializamos a null
        $usaCredencialesPropias = false;
        $credencialUsuario = null;

        // 1. Definición de la Validación (Mantenemos la definición de reglas)
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^57\d{10}$/', 
            'plantilla_id' => 'required|string|max:255', 
            'parameters' => 'required|array',
            'contexto' => 'nullable|string|max:255',
            'filter_metadata' => 'nullable|array', 
            'aplicacion' => 'nullable|string|max:255',
        ]);
        
        // El bloque try ahora incluye la lógica de validación para asegurar el registro
        try {
            // --- 2. Pre-chequeo y Creación del Registro Principal (INTENTO) ---

            // 2.1. Verificar credenciales antes de crear el registro
            $credencialUsuario = CredencialEnvio::porUsuario($user->id)
                ->porTipo(CredencialEnvio::TIPO_WHATSAPP)
                ->activas()
                ->predeterminadas()
                ->first();
            
            $usaCredencialesPropias = !is_null($credencialUsuario);

            // 2.2. Crear el registro BASE en la tabla envios_whatsapp (Usamos valores seguros/placeholders)
            $envioWhatsapp = EnvioWhatsapp::create([
                'user_id' => $user->id,
                // Usamos el operador coalescente para evitar fallos de DB si los campos requeridos faltan
                'plantilla_id' => $request->plantilla_id ?? 'FALLO_VALIDACION', 
                'phone' => $request->phone ?? 'FALLO_VALIDACION',
                'contexto' => $request->contexto ?? 'whatsapp.api_template',
                // Inicialmente en cola (se actualizará inmediatamente si falla la validación)
                'status' => EnvioWhatsapp::STATUS_EN_COLA, 
                // Guardamos la metadata para el filtro (ya validada como array)
                'filter_metadata' => $request->filter_metadata ?? [], 
                'campos_adicionales' => [
                    'parameters' => $request->parameters,
                    'aplicacion' => $request->aplicacion ?? 'api',
                    'usa_credenciales_propias' => $usaCredencialesPropias,
                    'credencial_id' => $credencialUsuario?->id,
                    'raw_request' => $request->all(), // Guardamos el payload completo en campos_adicionales
                ]
            ]);

            // 2.3. Evaluación de la Validación
            if ($validator->fails()) {
                // Si la validación FALLA, registramos el fallo y terminamos el proceso.
                
                $errorMessages = $validator->errors()->all();
                $fullErrorMessage = implode('; ', $errorMessages);

                // Actualizar el estado del registro recién creado
                $envioWhatsapp->update(['status' => EnvioWhatsapp::STATUS_FALLIDO]);
                
                // Crear un detalle de fallo inmediato (para ver qué falló)
                EnvioWhatsappDetalle::create([
                    'id_whatsapp' => $envioWhatsapp->id,
                    'phone' => $request->phone ?? 'validation_error',
                    'event' => 'Error API (Validación)',
                    'response' => ['errors' => $errorMessages],
                    'error_message' => 'Fallo de validación en la solicitud: ' . $fullErrorMessage,
                    'timestamp' => now(),
                    'campos_adicionales' => ['request_data' => $request->all()],
                ]);
                
                // Devolver la respuesta de error de validación (422)
                return response()->json([
                    "success" => false,
                    "message" => $validator->errors()
                ], 422);
            }
            
            // --- 3. Despacho (Si la Validación es Exitosa) ---
            
            SendSingleWhatsapp::dispatch(
                $request->phone,
                $request->plantilla_id,
                $request->parameters,
                $envioWhatsapp->id,
                $user->id 
            );
            
            // --- 4. Respuesta de Éxito ---

            $configWhatsapp = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_WHATSAPP)->first();
            
            return response()->json([
                'success' => true,
                'message' => 'Mensaje de WhatsApp encolado correctamente.',
                'envio_id' => $envioWhatsapp->id,
                'status' => EnvioWhatsapp::STATUS_EN_COLA,
                'credenciales_usadas' => $usaCredencialesPropias ? 'propias' : 'sistema',
                'limites' => $configWhatsapp ? [
                    'por_minuto' => $configWhatsapp->limite_por_minuto,
                    'por_hora' => $configWhatsapp->limite_por_hora,
                    'por_dia' => $configWhatsapp->limite_por_dia
                ] : null
            ], 202);

        } catch (\Exception $e) {
            
            // --- 5. Manejo de Fallos Imprevistos (Fallo de DB, u otro error fatal) ---

            $errorMessage = "Error al intentar crear el envío o despachar el Job: " . $e->getMessage();
            Log::error('WhatsappController@send: Error fatal', [
                'error' => $errorMessage,
                'request' => $request->all(),
            ]);

            // Si el registro se creó antes del fallo (protección)
            if ($envioWhatsapp) {
                 // Actualizar el estado a FALLIDO
                $envioWhatsapp->update(['status' => EnvioWhatsapp::STATUS_FALLIDO]);
                
                // Crear un detalle de fallo
                EnvioWhatsappDetalle::create([
                    'id_whatsapp' => $envioWhatsapp->id,
                    'phone' => $request->phone ?? 'validation_error',
                    'event' => 'Error API (Fatal)',
                    'response' => ['error_interno' => $errorMessage],
                    'error_message' => 'Fallo interno. El envío no pudo ser procesado.',
                    'timestamp' => now(),
                    'campos_adicionales' => [
                        'exception_class' => get_class($e),
                        'line' => $e->getLine(),
                        'request_data' => $request->all(),
                    ],
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
        try {
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

            // 2. Consulta Base con relaciones y selección de campos formateados
            $query = EnvioWhatsapp::with(['detalles', 'user'])->select(
                '*',
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %T') AS fecha_creacion"),
                DB::raw("DATE_FORMAT(updated_at, '%Y-%m-%d %T') AS fecha_edicion")
            );

            // 3. Total de registros (antes de filtrar)
            $totalRecords = $query->count();

            // -------------------------------------------------------------------
            // 4. Aplicación de Filtros Dinámicos (incluyendo JSON Metadata)
            // -------------------------------------------------------------------
            
            // Excluir parámetros que ya son de DataTables o de uso interno
            $ignoredParams = ['draw', 'start', 'length', 'search', 'order', 'columns', '_'];

            foreach ($request->query() as $key => $value) {
                // Aplicar el filtro si el parámetro NO es uno ignorado y tiene valor
                if (!in_array($key, $ignoredParams) && !is_null($value) && $value !== '') {
                    
                    // Filtrar por campos específicos de la tabla (ej: 'status=enviado')
                    if (Schema::hasColumn('envios_whatsapp', $key)) {
                        $query->where($key, $value);
                    }
                    
                    // Filtrar para la metadata JSON (donde van 'id_nit', 'cliente_id', etc.)
                    $query->whereJsonContains("filter_metadata->{$key}", $value);
                }
            }
            
            // -------------------------------------------------------------------

            // 5. Búsqueda general de DataTables
            if (!empty($searchValue)) {
                $query->where(function($q) use ($searchValue) {
                    $q->where('phone', 'like', '%' . $searchValue . '%')
                    ->orWhere('status', 'like', '%' . $searchValue . '%')
                    ->orWhere('message_id', 'like', '%' . $searchValue . '%');
                });
            }

            // 6. Total de registros filtrados (para la paginación correcta)
            $totalRecordwithFilter = $query->count();

            // 7. Ordenamiento Dinámico
            $validSortColumns = ['id', 'phone', 'status', 'created_at', 'updated_at'];
            
            if (in_array($columnName, $validSortColumns)) {
                $query->orderBy($columnName, $columnSortOrder);
            } else {
                $query->orderBy('id', 'DESC'); 
            }

            // 8. Paginación y Obtención de datos
            $records = $query->skip($start)
                ->take($length)
                ->get();

            // 9. Respuesta JSON
            return response()->json([
                'success' => true,
                'draw' => intval($draw),
                'iTotalRecords' => $totalRecords, 
                'iTotalDisplayRecords' => $totalRecordwithFilter, 
                'data' => $records, 
                'message' => 'Envíos de WhatsApp cargados con éxito!'
            ]);
        } catch (Exception $e) {
            // Manejo de errores profesional
            return response()->json([
                "success" => false,
                'data' => [],
                "message" => "Ocurrió un error al procesar la solicitud: " . $e->getMessage()
            ], 500); // Usamos 500 para errores internos inesperados
        }
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
            $whatSappId = $request->get('id'); 
            
            // Parámetros de ordenamiento
            $columnIndex_arr = $request->get('order');
            $columnName_arr = $request->get('columns');
            $order_arr = $request->get('order');
            
            // Determinación de la columna y el orden para la consulta
            $columnIndex = $columnIndex_arr[0]['column'] ?? 0; 
            $columnName = $columnName_arr[$columnIndex]['data'] ?? 'id'; 
            $columnSortOrder = $order_arr[0]['dir'] ?? 'desc'; 

            if (!$whatSappId) {
                 return response()->json([
                    'success' => false,
                    'message' => 'El parámetro "id" del envío es obligatorio.',
                ], 400);
            }

            // 2. Consulta Base y Filtro de Seguridad
            $query = EnvioWhatsappDetalle::with('whatsapp')
                // 2.1. Filtro por el ID del envío principal
                ->where('id_whatsapp', $whatSappId);

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
                'message' => 'Whatsapp detalle generado con éxito!'
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
        // 1. Obtener el ID del mensaje del proveedor
        $messageSid = $request->input('MessageSid');
        $statusRaw = $request->input('SmsStatus') ?? $request->input('MessageStatus');

        // 2. Buscar el envío padre
        $envio = EnvioWhatsapp::where('message_id', $messageSid)->first();

        if (!$envio) {
            Log::error("Webhook WhatsApp: No se encontró envío relacionado con SID: $messageSid");
            return response()->json([
                'success' => false,
                'message' => 'Envio no encontrado'
            ], 404);
        }

        // 3. Mapeo de estados
        $status = 'pendiente';

        switch ($statusRaw) {
            case 'sent':
                $status = 'enviado';
                break;
            case 'delivered':
                $status = 'entregado';
                break;
            case 'read':
                $status = 'abierto';
                break;
            case 'failed':
            case 'undelivered':
                $status = 'rechazado';
                break;
            default:
                $status = $statusRaw;
                break;
        }
        
        // 4. Actualizar el estado del padre
        $envio->status = $status;
        $envio->save();

        // 5. Crear el registro en la tabla DETALLE
        try {
            EnvioWhatsappDetalle::create([
                'id_whatsapp'        => $envio->id,
                'phone'              => $request->input('To'), // El número destino
                'event'              => $status,               // El estado traducido
                'message_id'         => $messageSid,
                'response'           => json_encode($request->all()), // Guardamos todo el payload por seguridad
                'timestamp'          => now(),
                'error_code'         => $request->input('ErrorCode', null),    // Si falla, Twilio manda esto
                'error_message'      => $request->input('ErrorMessage', null), // Si falla, Twilio manda esto
                'campos_adicionales' => [
                    'account_sid' => $request->input('AccountSid'),
                    'from'        => $request->input('From'),
                    'raw_status'  => $statusRaw
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("Error guardando detalle Whatsapp: " . $e->getMessage());
            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado, pero fallo al guardar detalle'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Estado y detalle guardados correctamente'
        ], 200);
    }
}