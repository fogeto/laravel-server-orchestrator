<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics Aktif/Pasif
    |--------------------------------------------------------------------------
    |
    | Metrik toplamayı tamamen devre dışı bırakmak için false yapın.
    | Devre dışı bırakıldığında middleware ve route'lar kayıt edilmez.
    |
    */
    'enabled' => env('ORCHESTRATOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Proje Prefix'i (Redis Key İzolasyonu)
    |--------------------------------------------------------------------------
    |
    | Her projeye benzersiz bir prefix verin. Aynı Redis sunucusunda birden
    | fazla proje çalışırken verilerin karışmamasını sağlar.
    |
    | Örnek: 'ikbackend', 'crm', 'hrportal'
    |
    | Redis key formatı: prometheus:{prefix}:gauges:metric_name
    |
    */
    'prefix' => env('ORCHESTRATOR_PREFIX', env('APP_NAME', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Redis Bağlantısı
    |--------------------------------------------------------------------------
    |
    | config/database.php içindeki redis connections'tan hangisi kullanılacak.
    | Genellikle 'default' yeterlidir. Metrikleri ayrı bir Redis DB'ye
    | yazmak isterseniz özel bir connection tanımlayabilirsiniz.
    |
    */
    'redis_connection' => env('ORCHESTRATOR_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Route Ayarları
    |--------------------------------------------------------------------------
    |
    | Metrics endpoint'lerinin yapılandırması.
    | - enabled: Route'ları otomatik kayıt et
    | - prefix: URL prefix'i (örn: 'api' → /api/metrics)
    | - middleware: Route'a uygulanacak ek middleware'ler
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => env('ORCHESTRATOR_ROUTE_PREFIX', 'api'),
        'middleware' => [], // Örn: ['auth:sanctum', 'throttle:60,1']
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Ayarları
    |--------------------------------------------------------------------------
    |
    | HTTP istek metriklerini toplayan middleware'in yapılandırması.
    | - enabled: Middleware'i otomatik kayıt et
    | - groups: Hangi middleware gruplarına eklenecek
    | - ignore_paths: Bu path'lerden gelen istekler izlenmez
    |
    */
    'middleware' => [
        'enabled' => true,
        'groups' => ['api'],
        'ignore_paths' => [
            'api/metrics',
            'metrics',
            'api/wipe-metrics',
            'wipe-metrics',
            'telescope/*',
            'horizon/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Histogram Bucket'ları
    |--------------------------------------------------------------------------
    |
    | http_request_duration_seconds histogram için bucket sınırları (saniye).
    | Prometheus standartlarına uygun değerler kullanılır.
    |
    */
    'http_histogram_buckets' => [
        0.001,
        0.005,
        0.01,
        0.05,
        0.1,
        0.5,
        1,
        5,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Histogram Bucket'ları
    |--------------------------------------------------------------------------
    |
    | sql_query_duration_seconds histogram için bucket sınırları (saniye).
    | Veritabanı sorgu sürelerini ölçmek için kullanılır.
    |
    */
    'sql_histogram_buckets' => [
        0.005,
        0.01,
        0.025,
        0.05,
        0.1,
        0.25,
        0.5,
        1,
        2.5,
        5,
        10,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Metrikleri Ayarları
    |--------------------------------------------------------------------------
    |
    | DB::listen ile yakalanan SQL sorgu metriklerinin yapılandırması.
    | - enabled: SQL metrik toplamayı aktifleştir/devre dışı bırak
    | - include_query_label: Sorgu metnini label olarak ekle (yüksek
    |   kardinaliteye neden olabilir, dikkatli kullanın)
    | - query_max_length: Label'daki sorgu metninin max uzunluğu
    | - ignore_patterns: Bu regex pattern'lara uyan sorgular izlenmez
    |
    */
    'sql_metrics' => [
        'enabled' => env('ORCHESTRATOR_SQL_METRICS', true),
        'include_query_label' => env('ORCHESTRATOR_SQL_QUERY_LABEL', true),
        'query_max_length' => 200,
        'ignore_patterns' => [
            '/^SHOW\s/i',
            '/^SET\s/i',
            '/information_schema/i',
            '/^DESCRIBE\s/i',
            '/^EXPLAIN\s/i',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PHP-FPM Metrikleri
    |--------------------------------------------------------------------------
    |
    | PHP-FPM worker metriklerini toplamak için status endpoint yapılandırması.
    | PHP-FPM'in pm.status_path ayarının aktif olması gerekir.
    |
    | Gerekli PHP-FPM pool ayarı:
    |   pm.status_path = /fpm-status
    |
    | Nginx/Apache config'inde bu path'i dışarıya kapatıp,
    | sadece localhost'tan erişilebilir yapmanız önerilir.
    |
    */
    'fpm' => [
        'enabled' => env('ORCHESTRATOR_FPM_ENABLED', true),
        'status_url' => env('ORCHESTRATOR_FPM_STATUS_URL', 'http://127.0.0.1/fpm-status'),
        'timeout' => 2, // HTTP isteği timeout (saniye)
    ],

    /*
    |--------------------------------------------------------------------------
    | Sistem Metrikleri
    |--------------------------------------------------------------------------
    |
    | /metrics endpoint'inde hangi sistem metriklerinin toplanacağı.
    | İhtiyacınız olmayanları false yaparak devre dışı bırakabilirsiniz.
    |
    */
    'system_metrics' => [
        'php_info' => true,
        'memory' => true,
        'uptime' => true,
        'database' => true,
        'opcache' => true,
        'fpm' => true,
        'health' => true,
    ],

];
