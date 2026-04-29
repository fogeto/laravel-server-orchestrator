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
    public function clear(): JsonResponse
    {
        $this->buffer->clear();

        return response()->json([
            'success' => true,
            'message' => 'APM error buffer cleared.',
        ]);
    }
}
