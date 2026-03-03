<?php

namespace Fogeto\ServerOrchestrator\Listeners;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Prometheus\CollectorRegistry;
use Prometheus\Histogram;

/**
 * Laravel HTTP Client (Http::) ile yapılan outgoing istekleri izler.
 *
 * Üretilen metrikler:
 *   - http_client_request_duration_seconds (Histogram)
 *   - http_client_requests_total (Counter)
 *   - http_client_errors_total (Counter) — sadece 4xx/5xx
 *
 * Event akışı:
 *   1. RequestSending  → start time kaydet
 *   2. ResponseReceived → duration hesapla, metrik yaz
 *   3. ConnectionFailed → connection error metriği yaz
 *
 * Label'lar (.NET uyumlu):
 *   error_type, http_request_method, http_response_status_code,
 *   server_address, url_scheme
 */
class HttpClientListener
{
    /**
     * Request başlangıç zamanlarını tutan map.
     * Key: spl_object_id(Request), Value: microtime(true)
     *
     * @var array<int, float>
     */
    private static array $startTimes = [];

    /**
     * Overflow koruması — bellek sızıntısını önler.
     * Eğer eşleşmeyen request'ler birikirse (edge case), 1000 girişte temizle.
     */
    private const MAX_PENDING = 1000;

    /**
     * Lazy-loaded histogram instance.
     */
    private ?Histogram $histogram = null;

    /**
     * İlk hata loglandı mı? Flood önleme.
     */
    private static bool $errorReported = false;

    /**
     * RequestSending event — istek gönderilmeden önce zamanı kaydet.
     */
    public function handleRequestSending(RequestSending $event): void
    {
        // Overflow koruması
        if (count(self::$startTimes) > self::MAX_PENDING) {
            self::$startTimes = array_slice(self::$startTimes, -100, null, true);
        }

        $requestId = spl_object_id($event->request);
        self::$startTimes[$requestId] = microtime(true);
    }

    /**
     * ResponseReceived event — response alındığında metrikleri kaydet.
     */
    public function handleResponseReceived(ResponseReceived $event): void
    {
        $requestId = spl_object_id($event->request);
        $start = self::$startTimes[$requestId] ?? null;
        unset(self::$startTimes[$requestId]);

        if ($start === null) {
            return;
        }

        $duration = microtime(true) - $start;
        $statusCode = (string) $event->response->status();
        $method = strtoupper($event->request->method());

        $parsed = parse_url($event->request->url());
        $serverAddress = $parsed['host'] ?? 'unknown';
        $urlScheme = $parsed['scheme'] ?? 'https';

        // Ignore hosts kontrolü
        if ($this->shouldIgnoreHost($serverAddress)) {
            return;
        }

        // Error type — sadece 4xx/5xx için status code, başarılıysa boş
        $errorType = '';
        if ($event->response->status() >= 400) {
            $errorType = $statusCode;
        }

        $this->recordMetrics($duration, $method, $statusCode, $serverAddress, $urlScheme, $errorType);
    }

    /**
     * ConnectionFailed event — bağlantı hatası olduğunda metrik kaydet.
     */
    public function handleConnectionFailed(ConnectionFailed $event): void
    {
        $requestId = spl_object_id($event->request);
        $start = self::$startTimes[$requestId] ?? null;
        unset(self::$startTimes[$requestId]);

        if ($start === null) {
            return;
        }

        $duration = microtime(true) - $start;
        $method = strtoupper($event->request->method());

        $parsed = parse_url($event->request->url());
        $serverAddress = $parsed['host'] ?? 'unknown';
        $urlScheme = $parsed['scheme'] ?? 'https';

        if ($this->shouldIgnoreHost($serverAddress)) {
            return;
        }

        $this->recordMetrics($duration, $method, '0', $serverAddress, $urlScheme, 'connection_error');
    }

    /**
     * Host'un ignore listesinde olup olmadığını kontrol et.
     */
    private function shouldIgnoreHost(string $host): bool
    {
        $ignoreHosts = config('server-orchestrator.http_client_metrics.ignore_hosts', []);

        foreach ($ignoreHosts as $ignoreHost) {
            if ($host === $ignoreHost) {
                return true;
            }

            // Wildcard desteği: *.example.com
            if (str_contains($ignoreHost, '*') && fnmatch($ignoreHost, $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tüm HTTP client metriklerini kaydet.
     *
     * - Histogram: request duration (saniye)
     * - Counter: toplam istek sayısı
     * - Counter: hata sayısı (error_type boş değilse)
     */
    private function recordMetrics(
        float $duration,
        string $method,
        string $statusCode,
        string $serverAddress,
        string $urlScheme,
        string $errorType
    ): void {
        try {
            $registry = app(CollectorRegistry::class);

            $labelNames = ['http_request_method', 'http_response_status_code', 'server_address', 'url_scheme', 'error_type'];
            $labelValues = [$method, $statusCode, $serverAddress, $urlScheme, $errorType];

            $buckets = config('server-orchestrator.http_client_metrics.histogram_buckets', [
                0.01, 0.025, 0.05, 0.1, 0.25, 0.5,
                1.0, 2.5, 5.0, 10.0, 30.0, 60.0,
            ]);

            // Histogram — request duration
            if ($this->histogram === null) {
                $this->histogram = $registry->getOrRegisterHistogram(
                    'http_client',
                    'request_duration_seconds',
                    'The duration of outbound HTTP requests.',
                    $labelNames,
                    $buckets
                );
            }
            $this->histogram->observe($duration, $labelValues);

            // Counter — toplam outgoing istekler
            $counter = $registry->getOrRegisterCounter(
                'http_client',
                'requests_total',
                'Total number of outbound HTTP requests.',
                $labelNames
            );
            $counter->inc($labelValues);

            // Counter — sadece hatalar (4xx/5xx veya connection error)
            if ($errorType !== '') {
                $errorCounter = $registry->getOrRegisterCounter(
                    'http_client',
                    'errors_total',
                    'Total number of outbound HTTP errors (4xx, 5xx, connection errors).',
                    $labelNames
                );
                $errorCounter->inc($labelValues);
            }
        } catch (\Throwable $e) {
            // İlk hatayı logla — flood önlemek için sadece bir kez
            if (! self::$errorReported) {
                report($e);
                self::$errorReported = true;
            }
        }
    }
}
