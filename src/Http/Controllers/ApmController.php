<?php

namespace Fogeto\ServerOrchestrator\Http\Controllers;

use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * APM hata endpoint'i — /__apm/errors veya /apm/errors
 *
 * .NET'teki /__apm/errors endpoint'inin Laravel karşılığı.
 *
 * Özellikler:
 *   - En yeniden eskiye sıralı JSON array döndürür
 *   - Production'da IP whitelist koruması (varsayılan: sadece localhost)
 *   - DELETE method ile buffer temizleme desteği
 *   - Hem incoming (iç servis) hem outgoing (dış servis) hataları tek endpoint'te
 *
 * Response örneği:
 * [
 *   {
 *     "id": "a1b2c3d4-...",
 *     "timestamp": "2024-01-15T10:30:45+03:00",
 *     "source": "incoming",
 *     "path": "/api/users",
 *     "method": "POST",
 *     "statusCode": 400,
 *     "errorType": "Bad Request",
 *     "message": "{\"errors\":{\"email\":\"...\"}}",
 *     "requestBody": "{...}",
 *     "responseBody": "{...}",
 *     "requestHeaders": {"content-type": "application/json", "authorization": "[REDACTED]"},
 *     "responseHeaders": {"content-type": "application/json"},
 *     "durationMs": 45.23,
 *     "clientIp": "10.0.0.1",
 *     "userAgent": "Mozilla/5.0 ...",
 *     "queryString": ""
 *   }
 * ]
 */
class ApmController extends Controller
{
    public function __construct(private ApmErrorBuffer $buffer) {}

    /**
     * Tüm APM hata event'lerini getir.
     */
    public function index(Request $request): JsonResponse
    {
        // IP koruması
        if (! $this->isAllowed($request)) {
            return response()->json([], 403);
        }

        $errors = array_values(array_filter($this->buffer->getAll(), static function (array $event): bool {
            return ! isset($event['source']) || $event['source'] === 'incoming';
        }));

        return response()->json($errors);
    }

    /**
     * APM buffer'ı temizle.
     */
    public function clear(Request $request): JsonResponse
    {
        if (!$this->isAllowed($request)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'IP address not allowed.',
            ], 403);
        }

        $this->buffer->clear();

        return response()->json([
            'success' => true,
            'message' => 'APM error buffer cleared.',
        ]);
    }

    /**
     * IP erişim kontrolü.
     *
     * Development ortamında tüm IP'ler izinlidir.
     * Production'da sadece whitelist'teki IP'ler erişebilir.
     */
    private function isAllowed(Request $request): bool
    {
        // Development ortamında serbest
        if (app()->environment('local', 'development', 'testing')) {
            return true;
        }

        // IP koruması devre dışıysa herkese açık
        if (!config('server-orchestrator.apm.ip_protection', false)) {
            return true;
        }

        $remoteIp = $request->ip();
        if ($remoteIp === null) {
            return false;
        }

        // Loopback her zaman izinli
        $loopbackIps = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
        if (in_array($remoteIp, $loopbackIps, true)) {
            return true;
        }

        // Config'den ek izinli IP'ler
        $allowedIps = config('server-orchestrator.apm.allowed_ips', []);

        return in_array($remoteIp, $allowedIps, true);
    }
}
