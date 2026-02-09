<?php

namespace Fogeto\ServerOrchestrator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MigrateFromInlineCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orchestrator:migrate
                            {--prefix= : Prometheus prefix for this project (e.g. ikbackend)}
                            {--dry-run : Show what would be changed without making changes}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate from inline Prometheus integration to the fogeto/laravel-server-orchestrator package';

    /** @var array<string, string> */
    private array $log = [];

    /** @var bool */
    private bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║       Server Orchestrator — Inline Migration Tool          ║');
        $this->info('╠══════════════════════════════════════════════════════════════╣');
        $this->info('║  Bu komut eski inline Prometheus entegrasyonunu temizler.   ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->info('');

        if ($this->dryRun) {
            $this->warn('⚡ DRY-RUN modu — hiçbir değişiklik yapılmayacak.');
            $this->info('');
        }

        // 1. Scan
        $this->info('🔍 Eski entegrasyon dosyaları taranıyor...');
        $findings = $this->scan();

        if (empty($findings)) {
            $this->info('');
            $this->info('✅ Eski inline entegrasyon bulunamadı. Proje zaten temiz!');

            return self::SUCCESS;
        }

        // 2. Show findings
        $this->info('');
        $this->warn('Bulunan eski entegrasyon bileşenleri:');
        $this->table(['Tür', 'Konum', 'Açıklama'], $findings);

        if (! $this->dryRun) {
            if (! $this->option('force') && ! $this->confirm('Devam edilsin mi? Eski dosyalar silinecek ve referanslar temizlenecek.')) {
                $this->info('İptal edildi.');

                return self::SUCCESS;
            }
        }

        // 3. Execute cleanup
        $this->info('');
        $this->info('🧹 Temizlik başlıyor...');
        $this->info('');

        $this->removeOldFiles();
        $this->cleanKernel();
        $this->cleanConfigApp();
        $this->cleanConfigServices();
        $this->cleanRoutes();
        $this->setupEnvPrefix();
        $this->publishConfig();

        // 4. Summary
        $this->info('');
        $this->info('═══════════════════════════════════════════════════════');

        if ($this->dryRun) {
            $this->warn('DRY-RUN tamamlandı — yukarıdaki değişiklikler uygulanmadı.');
        } else {
            $this->info('✅ Migrasyon tamamlandı!');
            $this->info('');
            $this->warn('⚠️  Lütfen şunları kontrol edin:');
            $this->line('  1. composer dump-autoload çalıştırın');
            $this->line('  2. php artisan config:clear çalıştırın');
            $this->line('  3. php artisan route:list --path=metrics ile route\'ları doğrulayın');
            $this->line('  4. .env dosyasında ORCHESTRATOR_PREFIX değerini kontrol edin');
        }

        $this->info('');

        return self::SUCCESS;
    }

    /**
     * Eski entegrasyon dosya ve referanslarını tara.
     *
     * @return array<int, array<int, string>>
     */
    private function scan(): array
    {
        $findings = [];

        // Olası PredisAdapter konumları
        $adapterPaths = [
            'app/Core/PredisAdapter.php',
            'app/Adapters/PredisAdapter.php',
            'app/Services/PredisAdapter.php',
            'app/Prometheus/PredisAdapter.php',
        ];

        foreach ($adapterPaths as $path) {
            if (file_exists(base_path($path))) {
                $findings[] = ['Dosya', $path, 'Eski PredisAdapter (Redis adapter)'];
            }
        }

        // Olası PrometheusMiddleware konumları
        $middlewarePaths = [
            'app/Http/Middleware/PrometheusMiddleware.php',
            'app/Middleware/PrometheusMiddleware.php',
        ];

        foreach ($middlewarePaths as $path) {
            if (file_exists(base_path($path))) {
                $findings[] = ['Dosya', $path, 'Eski PrometheusMiddleware'];
            }
        }

        // Olası PrometheusServiceProvider konumları
        $providerPaths = [
            'app/Providers/PrometheusServiceProvider.php',
            'app/Providers/PrometheusMonitoringServiceProvider.php',
        ];

        foreach ($providerPaths as $path) {
            if (file_exists(base_path($path))) {
                $findings[] = ['Dosya', $path, 'Eski ServiceProvider'];
            }
        }

        // Kernel.php'de middleware referansı
        $kernelPath = base_path('app/Http/Kernel.php');
        if (file_exists($kernelPath)) {
            $kernelContent = file_get_contents($kernelPath);
            if (str_contains($kernelContent, 'PrometheusMiddleware')) {
                $findings[] = ['Referans', 'app/Http/Kernel.php', 'PrometheusMiddleware referansı'];
            }
        }

        // config/app.php'de provider referansı
        $configAppPath = base_path('config/app.php');
        if (file_exists($configAppPath)) {
            $configContent = file_get_contents($configAppPath);
            if (str_contains($configContent, 'PrometheusServiceProvider') ||
                str_contains($configContent, 'PrometheusMonitoringServiceProvider')) {
                $findings[] = ['Referans', 'config/app.php', 'Eski provider kaydı'];
            }
        }

        // config/services.php'de prometheus config
        $configServicesPath = base_path('config/services.php');
        if (file_exists($configServicesPath)) {
            $servicesContent = file_get_contents($configServicesPath);
            if (str_contains($servicesContent, "'prometheus'") || str_contains($servicesContent, '"prometheus"')) {
                $findings[] = ['Referans', 'config/services.php', 'Prometheus config bloğu'];
            }
        }

        // Route dosyalarında inline metrics endpoint'leri
        $routeFiles = ['routes/api.php', 'routes/web.php'];
        foreach ($routeFiles as $routeFile) {
            $routePath = base_path($routeFile);
            if (file_exists($routePath)) {
                $routeContent = file_get_contents($routePath);
                if (str_contains($routeContent, 'metrics') &&
                    (str_contains($routeContent, 'CollectorRegistry') ||
                     str_contains($routeContent, 'RenderTextFormat') ||
                     str_contains($routeContent, 'wipe-metrics'))) {
                    $findings[] = ['Referans', $routeFile, 'Inline metrics route tanımları'];
                }
            }
        }

        // .env'de ORCHESTRATOR_PREFIX kontrolü
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            if (! str_contains($envContent, 'ORCHESTRATOR_PREFIX')) {
                $findings[] = ['Eksik', '.env', 'ORCHESTRATOR_PREFIX tanımlı değil'];
            }
        }

        return $findings;
    }

    /**
     * Eski dosyaları sil.
     */
    private function removeOldFiles(): void
    {
        $filesToRemove = [
            'app/Core/PredisAdapter.php',
            'app/Adapters/PredisAdapter.php',
            'app/Services/PredisAdapter.php',
            'app/Prometheus/PredisAdapter.php',
            'app/Http/Middleware/PrometheusMiddleware.php',
            'app/Middleware/PrometheusMiddleware.php',
            'app/Providers/PrometheusServiceProvider.php',
            'app/Providers/PrometheusMonitoringServiceProvider.php',
        ];

        foreach ($filesToRemove as $file) {
            $fullPath = base_path($file);
            if (file_exists($fullPath)) {
                if ($this->dryRun) {
                    $this->line("  [DRY-RUN] Silinecek: <comment>{$file}</comment>");
                } else {
                    unlink($fullPath);
                    $this->line("  ✅ Silindi: <comment>{$file}</comment>");
                }
            }
        }
    }

    /**
     * Kernel.php'den eski middleware referanslarını temizle.
     */
    private function cleanKernel(): void
    {
        $kernelPath = base_path('app/Http/Kernel.php');

        if (! file_exists($kernelPath)) {
            return;
        }

        $content = file_get_contents($kernelPath);
        $original = $content;

        // Middleware satırını kaldır (çeşitli formatlar)
        $patterns = [
            '/\s*\\\\?App\\\\Http\\\\Middleware\\\\PrometheusMiddleware::class,?\s*\n/m',
            '/\s*PrometheusMiddleware::class,?\s*\n/m',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, "\n", $content);
        }

        // use statement'ı kaldır
        $content = preg_replace(
            '/^use\s+App\\\\Http\\\\Middleware\\\\PrometheusMiddleware;\s*\n/m',
            '',
            $content
        );

        if ($content !== $original) {
            // Ardışık boş satırları temizle
            $content = preg_replace('/\n{3,}/', "\n\n", $content);

            if ($this->dryRun) {
                $this->line('  [DRY-RUN] Temizlenecek: <comment>app/Http/Kernel.php</comment> (PrometheusMiddleware referansları)');
            } else {
                file_put_contents($kernelPath, $content);
                $this->line('  ✅ Temizlendi: <comment>app/Http/Kernel.php</comment> (PrometheusMiddleware kaldırıldı)');
            }
        }
    }

    /**
     * config/app.php'den eski provider referansını kaldır.
     */
    private function cleanConfigApp(): void
    {
        $configPath = base_path('config/app.php');

        if (! file_exists($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);
        $original = $content;

        $patterns = [
            '/\s*App\\\\Providers\\\\PrometheusServiceProvider::class,?\s*\n/m',
            '/\s*App\\\\Providers\\\\PrometheusMonitoringServiceProvider::class,?\s*\n/m',
        ];

        foreach ($patterns as $pattern) {
            $content = preg_replace($pattern, "\n", $content);
        }

        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        if ($content !== $original) {
            if ($this->dryRun) {
                $this->line('  [DRY-RUN] Temizlenecek: <comment>config/app.php</comment> (Eski provider kaydı)');
            } else {
                file_put_contents($configPath, $content);
                $this->line('  ✅ Temizlendi: <comment>config/app.php</comment> (Eski provider kaldırıldı)');
            }
        }
    }

    /**
     * config/services.php'den prometheus bloğunu kaldır.
     */
    private function cleanConfigServices(): void
    {
        $configPath = base_path('config/services.php');

        if (! file_exists($configPath)) {
            return;
        }

        $content = file_get_contents($configPath);
        $original = $content;

        // 'prometheus' => [...], bloğunu kaldır
        $pattern = "/\s*['\"]prometheus['\"]\s*=>\s*\[.*?\],?\s*\n/s";
        $content = preg_replace($pattern, "\n", $content);

        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        if ($content !== $original) {
            if ($this->dryRun) {
                $this->line('  [DRY-RUN] Temizlenecek: <comment>config/services.php</comment> (prometheus bloğu)');
            } else {
                file_put_contents($configPath, $content);
                $this->line('  ✅ Temizlendi: <comment>config/services.php</comment> (prometheus bloğu kaldırıldı)');
            }
        }
    }

    /**
     * Route dosyalarından inline metrics/wipe-metrics tanımlarını kaldır.
     */
    private function cleanRoutes(): void
    {
        $routeFiles = ['routes/api.php', 'routes/web.php'];

        foreach ($routeFiles as $routeFile) {
            $routePath = base_path($routeFile);

            if (! file_exists($routePath)) {
                continue;
            }

            $content = file_get_contents($routePath);
            $original = $content;

            // Route::get('metrics', ...) veya Route::get('/metrics', ...) bloklarını kaldır
            // Çok satırlı closure tanımlarını kapsar
            $routePatterns = [
                // Route::get('metrics', function() { ... });
                "/Route::(get|post)\s*\(\s*['\"]\/?(wipe-)?metrics['\"]\s*,\s*function\s*\([^)]*\)\s*\{.*?\}\s*\)\s*;/s",
                // Route::get('/metrics', [Controller::class, 'method']);
                "/Route::(get|post)\s*\(\s*['\"]\/?(wipe-)?metrics['\"]\s*,\s*\[.*?\]\s*\)\s*;/s",
            ];

            foreach ($routePatterns as $pattern) {
                $content = preg_replace($pattern, '', $content);
            }

            // Artık kullanılmayan use statement'larını kaldır
            $unusedUses = [
                'Prometheus\CollectorRegistry',
                'Prometheus\RenderTextFormat',
                'Illuminate\Support\Facades\DB',
            ];

            foreach ($unusedUses as $use) {
                // Sadece başka yerde kullanılmıyorsa kaldır
                $className = class_basename($use);
                $withoutUse = preg_replace("/^use\s+" . preg_quote($use, '/') . ";\s*\n/m", '', $content);

                // use satırı kaldırıldıktan sonra class hâlâ referans ediliyorsa geri al
                if (! str_contains($withoutUse, $className)) {
                    $content = $withoutUse;
                }
            }

            $content = preg_replace('/\n{3,}/', "\n\n", $content);

            if ($content !== $original) {
                if ($this->dryRun) {
                    $this->line("  [DRY-RUN] Temizlenecek: <comment>{$routeFile}</comment> (inline metrics route'ları)");
                } else {
                    file_put_contents($routePath, $content);
                    $this->line("  ✅ Temizlendi: <comment>{$routeFile}</comment> (inline metrics route'ları kaldırıldı)");
                }
            }
        }
    }

    /**
     * .env dosyasına ORCHESTRATOR_PREFIX ekle.
     */
    private function setupEnvPrefix(): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);

        if (str_contains($envContent, 'ORCHESTRATOR_PREFIX')) {
            $this->line('  ℹ️  .env: <comment>ORCHESTRATOR_PREFIX</comment> zaten tanımlı.');

            return;
        }

        // Prefix değerini belirle
        $prefix = $this->option('prefix');

        if (! $prefix && ! $this->dryRun) {
            // Eski config'den prefix'i bulmaya çalış
            $oldPrefix = $this->findOldPrefix($envContent);
            $defaultPrefix = $oldPrefix ?: Str::slug(config('app.name', 'laravel'), '_');

            if ($this->option('force')) {
                $prefix = $defaultPrefix;
            } else {
                $prefix = $this->ask('Prometheus prefix nedir? (Diğer projelerle çakışmaması için benzersiz olmalı)', $defaultPrefix);
            }
        }

        $prefix = $prefix ?: 'laravel';

        if ($this->dryRun) {
            $this->line("  [DRY-RUN] Eklenecek: <comment>.env</comment> → ORCHESTRATOR_PREFIX={$prefix}");
        } else {
            // PROMETHEUS_PREFIX varsa yorum satırına al
            if (str_contains($envContent, 'PROMETHEUS_PREFIX')) {
                $envContent = preg_replace(
                    '/^(PROMETHEUS_PREFIX=.*)$/m',
                    "# $1 # Replaced by ORCHESTRATOR_PREFIX",
                    $envContent
                );
            }

            $envContent .= "\n# Server Orchestrator\nORCHESTRATOR_PREFIX={$prefix}\n";
            file_put_contents($envPath, $envContent);
            $this->line("  ✅ Eklendi: <comment>.env</comment> → ORCHESTRATOR_PREFIX={$prefix}");
        }
    }

    /**
     * Eski config'den prefix değerini bul.
     */
    private function findOldPrefix(string $envContent): ?string
    {
        // PROMETHEUS_PREFIX=xxx
        if (preg_match('/^PROMETHEUS_PREFIX=(.+)$/m', $envContent, $matches)) {
            return trim($matches[1]);
        }

        // config/services.php'den
        try {
            $prefix = config('services.prometheus.prefix');
            if ($prefix) {
                return $prefix;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * Config dosyasını publish et.
     */
    private function publishConfig(): void
    {
        $configPath = config_path('server-orchestrator.php');

        if (file_exists($configPath)) {
            $this->line('  ℹ️  Config: <comment>server-orchestrator.php</comment> zaten mevcut.');

            return;
        }

        if ($this->dryRun) {
            $this->line('  [DRY-RUN] Publish edilecek: <comment>config/server-orchestrator.php</comment>');
        } else {
            $this->call('vendor:publish', [
                '--tag' => 'server-orchestrator-config',
                '--no-interaction' => true,
            ]);
            $this->line('  ✅ Config publish edildi: <comment>config/server-orchestrator.php</comment>');
        }
    }
}
