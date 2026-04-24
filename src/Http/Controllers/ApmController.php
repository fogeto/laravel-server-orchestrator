<?php

namespace Fogeto\ServerOrchestrator\Http\Controllers;

use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * APM hata endpoint'i — /__apm/errors veya /apm/errors.
 *
 * Event'ler MongoDB'den en yeniden eskiye okunur ve `?limit=` ile sınırlandırılabilir.
 * Varsayılan public yüzey sadece incoming event'leri döndürür.
 */
class ApmController extends Controller
{
    public function __construct(private ApmErrorBuffer $buffer) {}

    /**
     * Tüm APM hata event'lerini getir.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $this->isAllowed($request)) {
            return response()->json([], 403, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $defaultLimit = (int) config('server-orchestrator.apm.default_limit', 200);
        $maxLimit = (int) config('server-orchestrator.apm.max_limit', 500);
        $requestedLimit = (int) $request->query('limit', $defaultLimit);
        $limit = min(max(1, $requestedLimit), max(1, $maxLimit));

        $errors = array_values(array_filter($this->buffer->getAll($limit), static function (array $event): bool {
            return ! isset($event['source']) || $event['source'] === 'incoming';
        }));

        return response()->json($errors, 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
