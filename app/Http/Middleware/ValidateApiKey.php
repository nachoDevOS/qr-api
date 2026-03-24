<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = config('app.api_key');

        if (empty($apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'API Key no configurada en el servidor.',
            ], 500);
        }

        if ($request->header('X-API-Key') !== $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'API Key inválida o ausente.',
            ], 401);
        }

        return $next($request);
    }
}
