<?php

namespace Src\Auth\Application\Controllers;

use App\Http\Controllers\Controller;
use Src\Auth\Application\Actions\RegisterAction;
use Src\Auth\Application\Actions\LoginAction;
use Src\Auth\Application\Actions\LogoutAction;
use Src\Auth\Application\Actions\GetMeAction;
use Src\Auth\Infrastructure\Requests\RegisterRequest;
use Src\Auth\Infrastructure\Requests\LoginRequest;
use Src\Auth\Infrastructure\Resources\UserResource;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        protected RegisterAction $registerAction,
        protected LoginAction $loginAction,
        protected LogoutAction $logoutAction,
        protected GetMeAction $getMeAction
    ) {}

    public function register(RegisterRequest $request)
    {
        $result = $this->registerAction->execute($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Usuario registrado exitosamente',
            'data' => [
                'user' => new UserResource($result['user']),
                'access_token' => $result['token'],
                'token_type' => 'Bearer'
            ]
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $result = $this->loginAction->execute($request->validated());

        if (!$result) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas'
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'data' => [
                'user' => new UserResource($result['user']),
                'access_token' => $result['token'],
                'token_type' => 'Bearer'
            ]
        ], 200);
    }

    public function logout(Request $request)
    {
        // Delete the current access token
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada exitosamente'
        ], 200);
    }

    public function me(Request $request)
    {
        $user = $this->getMeAction->execute($request->user()->id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new UserResource($user)
        ], 200);
    }
}
