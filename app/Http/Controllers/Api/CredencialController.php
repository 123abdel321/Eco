<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Services\CredencialService;
//MODELS
use App\Models\CredencialEnvio;

class CredencialController extends Controller
{
    /**
     * Listar credenciales del usuario autenticado
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        $query = CredencialEnvio::porUsuario($user->id);
        
        // Filtros opcionales
        if ($request->has('tipo')) {
            $query->porTipo($request->tipo);
        }
        
        if ($request->has('activo')) {
            $query->where('activo', $request->boolean('activo'));
        }
        
        $credenciales = $query->get()->map(function ($credencial) {
            return [
                'id' => $credencial->id,
                'tipo' => $credencial->tipo,
                'proveedor' => $credencial->proveedor,
                'activo' => $credencial->activo,
                'es_predeterminado' => $credencial->es_predeterminado,
                'estado_verificacion' => $credencial->estado_verificacion,
                'mensaje_verificacion' => $credencial->mensaje_verificacion,
                'ultima_verificacion' => $credencial->ultima_verificacion,
                'created_at' => $credencial->created_at,
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $credenciales
        ]);
    }

    /**
     * Crear nueva credencial
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tipo' => 'required|in:whatsapp,email,sms',
            'proveedor' => 'required|string|max:50',
            'credenciales' => 'required|array',
            'es_predeterminado' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            
            // Validar estructura de credenciales
            $errores = CredencialService::validarEstructuraCredenciales(
                $request->tipo,
                $request->proveedor,
                $request->credenciales
            );
            
            if (!empty($errores)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incompletas',
                    'errors' => $errores
                ], 422);
            }
            
            // Guardar credencial
            $credencial = CredencialService::guardarCredenciales(
                $user->id,
                $request->tipo,
                $request->proveedor,
                $request->credenciales,
                $request->boolean('es_predeterminado', true)
            );
            
            // Verificar credenciales
            $credencial->verificarCredenciales();
            
            return response()->json([
                'success' => true,
                'message' => 'Credencial guardada correctamente',
                'data' => [
                    'id' => $credencial->id,
                    'tipo' => $credencial->tipo,
                    'proveedor' => $credencial->proveedor,
                    'estado_verificacion' => $credencial->estado_verificacion,
                    'mensaje_verificacion' => $credencial->mensaje_verificacion,
                ]
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar credencial
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'credenciales' => 'required|array',
            'activo' => 'boolean',
            'es_predeterminado' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = Auth::user();
            
            $credencial = CredencialEnvio::porUsuario($user->id)->findOrFail($id);
            
            // Validar estructura de credenciales
            $errores = CredencialService::validarEstructuraCredenciales(
                $credencial->tipo,
                $credencial->proveedor,
                $request->credenciales
            );
            
            if (!empty($errores)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credenciales incompletas',
                    'errors' => $errores
                ], 422);
            }
            
            // Actualizar
            $credencial->update([
                'credenciales' => $request->credenciales,
                'activo' => $request->boolean('activo', $credencial->activo),
            ]);
            
            // Si se marca como predeterminada
            if ($request->boolean('es_predeterminado')) {
                $credencial->marcarComoPredeterminada();
            }
            
            // Verificar credenciales
            $credencial->verificarCredenciales();
            
            return response()->json([
                'success' => true,
                'message' => 'Credencial actualizada correctamente',
                'data' => [
                    'id' => $credencial->id,
                    'estado_verificacion' => $credencial->estado_verificacion,
                    'mensaje_verificacion' => $credencial->mensaje_verificacion,
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar credencial
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            
            $credencial = CredencialEnvio::porUsuario($user->id)->findOrFail($id);
            $credencial->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Credencial eliminada correctamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marcar credencial como predeterminada
     */
    public function setPredeterminada($id)
    {
        try {
            $user = Auth::user();
            
            $credencial = CredencialEnvio::porUsuario($user->id)->findOrFail($id);
            $credencial->marcarComoPredeterminada();
            
            return response()->json([
                'success' => true,
                'message' => 'Credencial marcada como predeterminada'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar credencial manualmente
     */
    public function verificar($id)
    {
        try {
            $user = Auth::user();
            
            $credencial = CredencialEnvio::porUsuario($user->id)->findOrFail($id);
            $resultado = $credencial->verificarCredenciales();
            
            return response()->json([
                'success' => true,
                'verificado' => $resultado,
                'estado' => $credencial->estado_verificacion,
                'mensaje' => $credencial->mensaje_verificacion,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}