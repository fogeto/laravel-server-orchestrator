<?php

namespace AysYazilim\ServerOrchestrator\Http\Controllers;

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

        return response($result, 200, [
            'Content-Type' => RenderTextFormat::MIME_TYPE,
        ]);
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

        // DB gerektiren metrikler — önce bağlantıyı test et
        $dbAvailable = $this->isDbReachable();

        if ($dbAvailable && ($config['database'] ?? true)) {
            $this->collectDatabaseMetrics();
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
        $memUsage->set(memory_get_usage(true));

        $memPeak = $this->registry->getOrRegisterGauge(
            'process',
            'memory_peak_bytes',
            'Peak memory usage in bytes'
        );
        $memPeak->set(memory_get_peak_usage(true));
    }

    /**
     * MySQL veritabanı bağlantı metrikleri.
     */
    private function collectDatabaseMetrics(): void
    {
        try {
            $dbConnections = DB::select("SHOW STATUS LIKE 'Threads_connected'");
            $dbMaxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");

            if (! empty($dbConnections)) {
                $gauge = $this->registry->getOrRegisterGauge(
                    'db',
                    'connections_active',
                    'Active database connections'
                );
                $gauge->set((int) $dbConnections[0]->Value);
            }

            if (! empty($dbMaxConnections)) {
                $gauge = $this->registry->getOrRegisterGauge(
                    'db',
                    'connections_max',
                    'Maximum database connection limit'
                );
                $gauge->set((int) $dbMaxConnections[0]->Value);
            }
        } catch (\Exception $e) {
            // MySQL dışı veritabanları veya bağlantı hatası — sessizce devam et
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
     * Uygulama sağlık durumu (DB bağlantısı üzerinden).
     */
    private function collectHealth(bool $dbAvailable = false): void
    {
        $gauge = $this->registry->getOrRegisterGauge(
            'app',
            'health_status',
            'Application health status (1=UP, 0=DOWN)'
        );

        if ($dbAvailable) {
            try {
                DB::connection()->getPdo();
                $gauge->set(1);

                return;
            } catch (\Exception $e) {
                // fall through
            }
        }

        $gauge->set(0);
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
