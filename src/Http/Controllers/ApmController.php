<?php

namespace Fogeto\ServerOrchestrator\Http\Controllers;

use Fogeto\ServerOrchestrator\Services\ApmErrorBuffer;
use Fogeto\ServerOrchestrator\Services\MonitoringErrorReporter;
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
    public function __construct(private ?ApmErrorBuffer $buffer = null) {}

    /**
     * Tüm APM hata event'lerini getir.
     */
    public function index(Request $request): JsonResponse
    {
        $defaultLimit = (int) config('server-orchestrator.apm.default_limit', 200);
        $maxLimit = (int) config('server-orchestrator.apm.max_limit', 500);
        $requestedLimit = (int) $request->query('limit', $defaultLimit);
        $limit = min(max(1, $requestedLimit), max(1, $maxLimit));

        try {
            $this->buffer ??= app(ApmErrorBuffer::class);
            $errors = array_values(array_filter($this->buffer->getAll($limit), static function (array $event): bool {
                return ! isset($event['source']) || $event['source'] === 'incoming';
            }));
            if (! $this->buffer->isAvailable()) {
                return response()->json(['message' => 'APM storage temporarily unavailable.'], 503, ['Cache-Control' => 'no-store']);
            }
        } catch (\Throwable $e) {
            app(MonitoringErrorReporter::class)->report($e);
            return response()->json(['message' => 'APM storage temporarily unavailable.'], 503, ['Cache-Control' => 'no-store']);
        }

        return response()->json($errors, 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * APM buffer'ı temizle.
     */
    public function clear(): JsonResponse
    {
        $this->buffer ??= app(ApmErrorBuffer::class);
        $this->buffer->clear();

        return response()->json([
            'success' => true,
            'message' => 'APM error buffer cleared.',
        ]);
    }
}
