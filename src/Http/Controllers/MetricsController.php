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

        if ($config['fpm'] ?? true) {
            $this->collectFpmMetrics();
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
     *
     * Prometheus formatında üretilen metrikler:
     *   - db_client_connections_usage{state="idle|used"} (gauge)
     *   - db_client_connections_max (gauge)
     *   - db_client_connections_pending_requests (gauge)
     */
    private function collectDatabaseMetrics(): void
    {
        try {
            $driver = config('database.default', 'mysql');

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $this->collectMysqlConnectionMetrics();
            } elseif ($driver === 'pgsql') {
                $this->collectPgsqlConnectionMetrics();
            }
        } catch (\Exception $e) {
            // DB dışı veritabanları veya bağlantı hatası — sessizce devam et
        }
    }

    /**
     * MySQL/MariaDB bağlantı metriklerini topla.
     */
    private function collectMysqlConnectionMetrics(): void
    {
        $threadsConnected = DB::select("SHOW STATUS LIKE 'Threads_connected'");
        $threadsRunning = DB::select("SHOW STATUS LIKE 'Threads_running'");
        $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'");

        $connected = ! empty($threadsConnected) ? (int) $threadsConnected[0]->Value : 0;
        $running = ! empty($threadsRunning) ? (int) $threadsRunning[0]->Value : 0;
        $max = ! empty($maxConnections) ? (int) $maxConnections[0]->Value : 0;

        // idle = toplam bağlı thread'ler - aktif çalışan thread'ler
        $idle = max(0, $connected - $running);

        // db_client_connections_usage{state="idle"} ve {state="used"}
        $usageGauge = $this->registry->getOrRegisterGauge(
            'db_client',
            'connections_usage',
            'Database connections by state',
            ['state']
        );
        $usageGauge->set($idle, ['idle']);
        $usageGauge->set($running, ['used']);

        // db_client_connections_max
        $maxGauge = $this->registry->getOrRegisterGauge(
            'db_client',
            'connections_max',
            'Maximum pool connections'
        );
        $maxGauge->set($max);

        // db_client_connections_pending_requests
        $pendingGauge = $this->registry->getOrRegisterGauge(
            'db_client',
            'connections_pending_requests',
            'Pending connection requests'
        );
        $pendingGauge->set(0);
    }

    /**
     * PostgreSQL bağlantı metriklerini topla.
     */
    private function collectPgsqlConnectionMetrics(): void
    {
        try {
            $dbName = config('database.connections.pgsql.database', 'forge');

            // Aktif bağlantılar
            $result = DB::select(
                "SELECT count(*) as cnt FROM pg_stat_activity WHERE datname = ? AND state = 'active'",
                [$dbName]
            );
            $active = ! empty($result) ? (int) $result[0]->cnt : 0;

            // Idle bağlantılar
            $resultIdle = DB::select(
                "SELECT count(*) as cnt FROM pg_stat_activity WHERE datname = ? AND state = 'idle'",
                [$dbName]
            );
            $idle = ! empty($resultIdle) ? (int) $resultIdle[0]->cnt : 0;

            // Max bağlantılar
            $maxResult = DB::select("SHOW max_connections");
            $max = ! empty($maxResult) ? (int) $maxResult[0]->max_connections : 100;

            $usageGauge = $this->registry->getOrRegisterGauge(
                'db_client',
                'connections_usage',
                'Database connections by state',
                ['state']
            );
            $usageGauge->set($idle, ['idle']);
            $usageGauge->set($active, ['used']);

            $maxGauge = $this->registry->getOrRegisterGauge(
                'db_client',
                'connections_max',
                'Maximum pool connections'
            );
            $maxGauge->set($max);

            $pendingGauge = $this->registry->getOrRegisterGauge(
                'db_client',
                'connections_pending_requests',
                'Pending connection requests'
            );
            $pendingGauge->set(0);
        } catch (\Exception $e) {
            // PostgreSQL sorgu hatası — sessizce devam et
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
     * PHP-FPM worker metrikleri.
     *
     * PHP-FPM'in pm.status_path üzerinden JSON formatında durum bilgisi alır.
     * Prometheus formatında üretilen metrikler:
     *   - php_fpm_active_processes (gauge)
     *   - php_fpm_idle_processes (gauge)
     *   - php_fpm_total_processes (gauge)
     *   - php_fpm_max_active_processes (gauge) — peak
     *   - php_fpm_accepted_connections (gauge) — toplam kabul edilen bağlantı
     *   - php_fpm_listen_queue (gauge) — kuyrukta bekleyen istek
     *   - php_fpm_max_listen_queue (gauge) — peak kuyruk
     *   - php_fpm_slow_requests (gauge) — yavaş istekler
     */
    private function collectFpmMetrics(): void
    {
        $fpmConfig = config('server-orchestrator.fpm', []);

        if (! ($fpmConfig['enabled'] ?? true)) {
            return;
        }

        try {
            $statusData = $this->fetchFpmStatus($fpmConfig);

            if ($statusData === null) {
                return;
            }

            // Active processes
            $activeGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'active_processes',
                'Number of active PHP-FPM worker processes'
            );
            $activeGauge->set((int) ($statusData['active processes'] ?? 0));

            // Idle processes
            $idleGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'idle_processes',
                'Number of idle PHP-FPM worker processes'
            );
            $idleGauge->set((int) ($statusData['idle processes'] ?? 0));

            // Total processes
            $totalGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'total_processes',
                'Total number of PHP-FPM worker processes'
            );
            $totalGauge->set((int) ($statusData['total processes'] ?? 0));

            // Max active processes (peak)
            $maxActiveGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'max_active_processes',
                'Maximum number of active processes since FPM started'
            );
            $maxActiveGauge->set((int) ($statusData['max active processes'] ?? 0));

            // Accepted connections (total handled)
            $acceptedGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'accepted_connections',
                'Total number of accepted connections'
            );
            $acceptedGauge->set((int) ($statusData['accepted conn'] ?? 0));

            // Listen queue (waiting requests)
            $listenQueueGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'listen_queue',
                'Number of requests in the listen queue'
            );
            $listenQueueGauge->set((int) ($statusData['listen queue'] ?? 0));

            // Max listen queue (peak)
            $maxListenQueueGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'max_listen_queue',
                'Maximum number of requests in the listen queue since FPM started'
            );
            $maxListenQueueGauge->set((int) ($statusData['max listen queue'] ?? 0));

            // Slow requests
            $slowGauge = $this->registry->getOrRegisterGauge(
                'php_fpm',
                'slow_requests',
                'Total number of slow requests'
            );
            $slowGauge->set((int) ($statusData['slow requests'] ?? 0));
        } catch (\Throwable $e) {
            // FPM status alınamadı — sessizce devam et
        }
    }

    /**
     * PHP-FPM status endpoint'ınden JSON veri al.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFpmStatus(array $fpmConfig): ?array
    {
        $url = ($fpmConfig['status_url'] ?? 'http://127.0.0.1/fpm-status') . '?json';
        $timeout = $fpmConfig['timeout'] ?? 2;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);

        if (! is_array($data)) {
            return null;
        }

        return $data;
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
