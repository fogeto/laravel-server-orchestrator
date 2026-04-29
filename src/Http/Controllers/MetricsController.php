<?php

namespace Fogeto\ServerOrchestrator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

class MetricsController extends Controller
{
    public function __construct(private CollectorRegistry $registry) {}

    /**
     * Prometheus metrikleri render et.
     */
    public function index(Request $request): Response
    {
        $this->collectSystemMetrics();

        $renderer = new RenderTextFormat();
        $result = $renderer->render($this->registry->getMetricFamilySamples());

        $headers = [
            'Content-Type' => RenderTextFormat::MIME_TYPE,
        ];

        // Gzip sıkıştırma — büyük response'larda ~90% boyut kazancı sağlar.
        // Prometheus Accept-Encoding: gzip destekler.
        if (str_contains($request->header('Accept-Encoding', ''), 'gzip') && function_exists('gzencode')) {
            $result = gzencode($result, 6);
            $headers['Content-Encoding'] = 'gzip';
            $headers['Vary'] = 'Accept-Encoding';
        }

        return response($result, 200, $headers);
    }

    /**
     * Tüm metrikleri temizle (reset).
     */
    public function wipe(): JsonResponse
    {
        $this->registry->wipeStorage();

        return response()->json([
            'success' => true,
            'message' => 'All metrics have been wiped.',
        ]);
    }

    /**
     * Sistem metriklerini topla.
     */
    private function collectSystemMetrics(): void
    {
        $config = config('server-orchestrator.system_metrics', []);

        if ($config['php_info'] ?? true) {
            $this->collectPhpInfo();
        }

        if ($config['uptime'] ?? true) {
            $this->collectUptime();
        }

        if ($config['memory'] ?? true) {
            $this->collectMemory();
        }

        // DB metrikleri — başarılıysa health=UP, değilse health=DOWN
        $dbAvailable = false;
        if (($config['database'] ?? true) && $this->isDbReachable()) {
            $dbAvailable = $this->collectDatabaseMetrics();
        }

        if ($config['opcache'] ?? true) {
            $this->collectOpcache();
        }

        if ($config['health'] ?? true) {
            $this->collectHealth($dbAvailable);
        }
    }

    /**
     * PHP versiyon bilgisi (app type detection için zorunlu).
     */
    private function collectPhpInfo(): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            'php',
            'info',
            'PHP environment information',
            ['version']
        );
        $gauge->set(1, [PHP_VERSION]);
    }

    /**
     * Process uptime (saniye cinsinden).
     */
    private function collectUptime(): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            'process',
            'uptime_seconds',
            'Process uptime in seconds'
        );
        $gauge->set(microtime(true) - LARAVEL_START);
    }

    /**
     * Bellek kullanımı (memory_get_usage, peak).
     */
    private function collectMemory(): void
    {
        $memUsage = $this->registry->getOrRegisterGauge(
            'process',
            'memory_usage_bytes',
            'Current memory usage in bytes'
        );
        $memUsage->set(memory_get_usage(false));

        $memAllocated = $this->registry->getOrRegisterGauge(
            'process',
            'memory_allocated_bytes',
            'Current PHP allocated memory in bytes'
        );
        $memAllocated->set(memory_get_usage(true));

        $memPeak = $this->registry->getOrRegisterGauge(
            'process',
            'memory_peak_bytes',
            'Peak memory usage in bytes'
        );
        $memPeak->set(memory_get_peak_usage(false));

        $memPeakAllocated = $this->registry->getOrRegisterGauge(
            'process',
            'memory_peak_allocated_bytes',
            'Peak PHP allocated memory in bytes'
        );
        $memPeakAllocated->set(memory_get_peak_usage(true));

        $memLimit = $this->registry->getOrRegisterGauge(
            'process',
            'memory_limit_bytes',
            'Configured PHP memory limit in bytes'
        );
        $memLimit->set($this->parsePhpIniBytes((string) ini_get('memory_limit')));
    }

    private function parsePhpIniBytes(string $value): float
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * MySQL veritabanı bağlantı metrikleri.
     *
     * @return bool DB'den başarıyla veri alındıysa true
     */
    private function collectDatabaseMetrics(): bool
    {
        try {
            $dbConnections = DB::select("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running')");
            $dbMaxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");

            $statusValues = [];
            foreach ($dbConnections as $status) {
                $statusValues[$status->Variable_name] = (int) $status->Value;
            }

            $threadsConnected = $statusValues['Threads_connected'] ?? null;
            $threadsRunning = $statusValues['Threads_running'] ?? null;
            $maxConnections = ! empty($dbMaxConnections) ? (int) $dbMaxConnections[0]->Value : null;

            if ($maxConnections !== null) {
                $poolMaxGauge = $this->registry->getOrRegisterGauge(
                    'db_client',
                    'connections_max',
                    'Maximum pool connections'
                );
                $poolMaxGauge->set($maxConnections);
            }

            if ($threadsConnected !== null) {
                $usedConnections = $threadsRunning ?? $threadsConnected;
                $idleConnections = max($threadsConnected - $usedConnections, 0);

                $usageGauge = $this->registry->getOrRegisterGauge(
                    'db_client',
                    'connections_usage',
                    'Database connections by state',
                    ['state']
                );
                $usageGauge->set($idleConnections, ['idle']);
                $usageGauge->set($usedConnections, ['used']);

                $pendingGauge = $this->registry->getOrRegisterGauge(
                    'db_client',
                    'connections_pending_requests',
                    'Pending connection requests'
                );
                $pendingGauge->set(0);
            }

            return true;
        } catch (\Throwable $e) {
            // MySQL dışı veritabanları veya bağlantı hatası — sessizce devam et
            return false;
        }
    }

    /**
     * OPcache metrikleri (varsa).
     */
    private function collectOpcache(): void
    {
        if (! function_exists('opcache_get_status')) {
            return;
        }

        $status = opcache_get_status(false);

        if ($status === false) {
            return;
        }

        $enabledGauge = $this->registry->getOrRegisterGauge(
            'php',
            'opcache_enabled',
            'OPcache is enabled (1=yes, 0=no)'
        );
        $enabledGauge->set($status['opcache_enabled'] ? 1 : 0);

        if ($status['opcache_enabled']) {
            $hitRate = $this->registry->getOrRegisterGauge(
                'php',
                'opcache_hit_rate',
                'OPcache hit rate percentage'
            );
            $hitRate->set($status['opcache_statistics']['opcache_hit_rate'] ?? 0);

            $memUsed = $this->registry->getOrRegisterGauge(
                'php',
                'opcache_memory_used_bytes',
                'OPcache memory usage in bytes'
            );
            $memUsed->set($status['memory_usage']['used_memory'] ?? 0);
        }
    }

    /**
     * Uygulama sağlık durumu.
     *
     * collectDatabaseMetrics() zaten DB bağlantısını doğruladığı için
     * burada tekrar getPdo() çağrısı yapılmaz (redundant round-trip eliminasyonu).
     */
    private function collectHealth(bool $dbAvailable): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            'app',
            'health_status',
            'Application health status (1=UP, 0=DOWN)'
        );
        $gauge->set($dbAvailable ? 1 : 0);
    }

    /**
     * DB sunucusuna hızlı TCP bağlantı testi (2 saniye timeout).
     * getPdo() hangup'ını önlemek için socket-level check yapar.
     */
    private function isDbReachable(): bool
    {
        try {
            $host = config('database.connections.mysql.host', '127.0.0.1');
            $port = (int) config('database.connections.mysql.port', 3306);

            $fp = @fsockopen($host, $port, $errno, $errstr, 2);

            if ($fp) {
                fclose($fp);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
