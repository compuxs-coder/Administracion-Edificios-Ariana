<?php

namespace Src\Auth\Application\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Src\Auth\Application\Actions\LoginAction;
use Src\Auth\Application\Actions\RegisterAction;
use Src\Auth\Infrastructure\Requests\LoginRequest;
use Src\Auth\Infrastructure\Requests\RegisterRequest;
use Src\Auth\Infrastructure\Models\UserEloquentModel;
use Exception;
use Throwable;

class WebAuthController extends Controller
{
    public function __construct(
        private RegisterAction $registerAction,
        private LoginAction $loginAction
    ) {}

    /**
     * Mostrar formulario de login
     */
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Mostrar formulario de registro
     */
    public function showRegisterForm(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Procesar login mediante sesión web.
     */
    public function login(LoginRequest $request): RedirectResponse
    {
        try {
            $result = $this->loginAction->execute([
                'email' => $request->input('email'),
                'password' => $request->input('password')
            ], false, $request->boolean('remember'));

            if (!$result) {
                return back()->withErrors([
                    'email' => 'Las credenciales proporcionadas no coinciden con nuestros registros.',
                ])->onlyInput('email');
            }

            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'))
                ->with('success', '¡Bienvenido de vuelta!');

        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            report($exception);

            return back()->withErrors([
                'email' => 'No fue posible iniciar sesión. Inténtalo nuevamente.',
            ])->onlyInput('email');
        }
    }

    /**
     * Procesar registro
     */
    public function register(RegisterRequest $request): RedirectResponse
    {
        try {
            $result = $this->registerAction->execute([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => $request->input('password'),
            ], false);

            // Obtener el modelo Eloquent del usuario
            $eloquentUser = UserEloquentModel::find($result['user']->getId());

            if (!$eloquentUser) {
                throw new Exception('User not found after creation');
            }

            // Iniciar sesión automáticamente después del registro
            Auth::login($eloquentUser);

            // Regenerar sesión
            $request->session()->regenerate();

            return redirect()->route('dashboard')
                ->with('success', '¡Registro exitoso! Bienvenido.');

        } catch (Exception $e) {
            return back()->withErrors([
                'email' => 'Error al registrar el usuario. El email puede estar en uso.',
            ])->withInput($request->only('name', 'email'));
        }
    }

    /**
     * Cerrar sesión
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->with('success', 'Sesión cerrada exitosamente.');
    }
}
