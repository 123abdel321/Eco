<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;
use Illuminate\Validation\ValidationException;
//SERVICES
use App\Services\CredencialService;
//MODELS
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Registro de usuario
     * @param Request $request
     * @param CredencialService $credencialService Inyección del servicio
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request, CredencialService $credencialService)
    {
        try {
            // Validar los datos de entrada
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:5|confirmed'
            ]);

            // Crear el usuario
            $user = User::create([
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => Hash::make($validatedData['password']),
            ]);

            //Registrar credenciales
            $credencialService->createDefaultCredentials($user); 

            // Crear token de acceso
            $plainTextToken = $user->createToken('auth_token')->plainTextToken;

            $parts = explode('|', $plainTextToken, 2);
            $tokenHash = end($parts);

            $user->update([
                'remember_token' => $plainTextToken, 
            ]);

            // Devolver respuesta con el token
            return response()->json([
                'access_token' => $plainTextToken,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error en el servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login de usuario
     */
    public function login(Request $request)
    {
        try {
            // Validar los datos de entrada
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Buscar usuario por email
            $user = User::where('email', $request->email)->first();

            // Verificar credenciales
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Credenciales incorrectas'
                ], 401);
            }

            // Eliminar tokens existentes (opcional, para mayor seguridad)
            $user->tokens()->delete();

            // Crear nuevo token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error en el servidor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout de usuario
     */
    public function logout(Request $request)
    {
        try {
            // Eliminar el token actual del usuario
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'message' => 'Sesión cerrada exitosamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cerrar sesión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuario actual (opcional)
     */
    public function user(Request $request)
    {
        try {
            return response()->json([
                'user' => $request->user()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
