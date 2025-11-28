<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
//JOBS
use App\Jobs\SendSingleWhatsapp;
//MODELS
use App\Models\User;
use App\Models\CredencialEnvio;
use App\Models\ConfiguracionEnvio;
use App\Models\Sistema\EnvioWhatsapp;
use App\Models\Sistema\EnvioWhatsappDetalle;

class WhatsappController extends Controller
{
    public function send(Request $request)
    {
        // 1. Validación
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|regex:/^57\d{10}$/', 
            'plantilla_id' => 'required|string|max:255', 
            'parameters' => 'required|array',
            'contexto' => 'nullable|string|max:255',
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
                ->porTipo(CredencialEnvio::TIPO_WHATSAPP)
                ->activas()
                ->predeterminadas()
                ->first();
            
            $usaCredencialesPropias = !is_null($credencialUsuario);
            
            // 2. Crear el registro en la tabla envios_whatsapp
            $envioWhatsapp = EnvioWhatsapp::create([
                'user_id' => $user->id,
                'plantilla_id' => $request->plantilla_id,
                'phone' => $request->phone,
                'contexto' => $request->contexto ?? 'whatsapp.api_template',
                'status' => EnvioWhatsapp::STATUS_EN_COLA,
                'campos_adicionales' => [
                    'parameters' => $request->parameters,
                    'aplicacion' => $request->aplicacion ?? 'api',
                    'usa_credenciales_propias' => $usaCredencialesPropias,
                    'credencial_id' => $credencialUsuario?->id,
                ]
            ]);
            
            // 3. Despachar el Job a la cola con el user_id para obtener credenciales
            SendSingleWhatsapp::dispatch(
                $request->phone,
                $request->plantilla_id,
                $request->parameters,
                $envioWhatsapp->id,
                $user->id
            );
            
            // 4. Obtener configuración para mostrar límites (solo informativo)
            $configWhatsapp = ConfiguracionEnvio::porTipo(ConfiguracionEnvio::TIPO_WHATSAPP)->first();
            
            // 5. Respuesta de la API
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
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
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
        ], 202);
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
        $query = EnvioWhatsapp::with(['detalles', 'user']);

        // 3. Total de registros (antes de filtrar)
        $totalRecords = $query->count();

        // 4. Búsqueda (Filtros)
        // Importante: Usamos un grupo (closure) para que los 'OR' no rompan otras condiciones si las hubiera.
        if (!empty($searchValue)) {
            $query->where(function($q) use ($searchValue) {
                $q->where('phone', 'like', '%' . $searchValue . '%')
                ->orWhere('status', 'like', '%' . $searchValue . '%')
                ->orWhere('message_id', 'like', '%' . $searchValue . '%')
                // Opcional: Buscar por nombre del usuario relacionado
                ->orWhereHas('user', function($userQuery) use ($searchValue) {
                    $userQuery->where('name', 'like', '%' . $searchValue . '%')
                                ->orWhere('email', 'like', '%' . $searchValue . '%');
                });
            });
        }

        // 5. Total de registros filtrados (para la paginación correcta)
        $totalRecordwithFilter = $query->count();

        // 6. Ordenamiento Dinámico
        // Verificamos si la columna es válida para ordenar directamente en SQL
        $validSortColumns = ['id', 'phone', 'status', 'created_at', 'updated_at'];
        
        if (in_array($columnName, $validSortColumns)) {
            $query->orderBy($columnName, $columnSortOrder);
        } else {
            // Por defecto si la columna no es ordenable (ej: una columna calculada o relación compleja)
            $query->orderBy('id', 'DESC'); 
        }

        // 7. Paginación y Obtención de datos
        $records = $query->skip($start)
            ->take($length)
            ->get();

        // 8. Mapeo de datos (Opcional pero recomendado para DataTables)
        // Esto es útil si quieres dar formato a las fechas o mostrar el nombre del usuario en lugar del objeto
        $data_arr = [];
        
        foreach($records as $record){
            $data_arr[] = [
                "id" => $record->id,
                "user_name" => $record->user ? $record->user->name : 'N/A', // Acceso seguro a la relación
                "phone" => $record->phone,
                "status" => $record->status,
                "message_id" => $record->message_id,
                "created_at" => $record->created_at->format('Y-m-d H:i:s'), // Formato de fecha
                "acciones" => '', // Aquí puedes poner botones HTML si los necesitas
            ];
        }

        // 9. Respuesta JSON
        return response()->json([
            'success' => true,
            'draw' => intval($draw),
            'iTotalRecords' => $totalRecords,         // Total real en base de datos
            'iTotalDisplayRecords' => $totalRecordwithFilter, // Total después del filtro de búsqueda
            'data' => $data_arr, // O usa $records si no quieres el mapeo manual del paso 8
            'message' => 'Envíos de WhatsApp cargados con éxito!'
        ]);
    }
}