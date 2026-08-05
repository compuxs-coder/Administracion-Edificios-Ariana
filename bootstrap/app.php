<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use Src\Factura\Domain\Exceptions\FacturaNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (FacturaNotFoundException $exception, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 404);
        });

        $exceptions->render(function (ValidationException $e, $request) {
            // Convert validation errors from snake_case to camelCase
            $errors = $e->errors();
            $camelCaseErrors = [];

            foreach ($errors as $field => $messages) {
                // Convert field name from snake_case to camelCase
                $camelCaseField = lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '.'], [' ', '.'], $field))));
                // Handle nested fields like detalles.0.precio_unitario -> detalles.0.precioUnitario
                $camelCaseField = preg_replace_callback('/\.([a-z_]+)/', function ($matches) {
                    $part = $matches[1];
                    // If it's a number, keep it as is
                    if (is_numeric($part)) {
                        return '.'.$part;
                    }

                    // Otherwise convert to camelCase
                    return '.'.lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $part))));
                }, $camelCaseField);

                $camelCaseErrors[$camelCaseField] = $messages;
            }

            // Use reflection to modify the validator's messages directly
            $validator = $e->validator;
            $validatorReflection = new \ReflectionClass($validator);
            $messagesProperty = $validatorReflection->getProperty('messages');
            $messagesProperty->setAccessible(true);
            $messagesProperty->setValue($validator, new MessageBag($camelCaseErrors));

            // Return null to let Laravel handle the exception normally with modified errors
            return null;
        });
    })->create();
