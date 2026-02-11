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
    | Redis Key TTL (Saniye)
    |--------------------------------------------------------------------------
    |
    | Metrik key'lerinin Redis'te ne kadar süre tutulacağı (saniye).
    | Varsayılan: 604800 (7 gün). Her yazma işleminde TTL yenilenir,
    | böylece aktif metrikler canlı kalır, kullanılmayan metrikler
    | otomatik olarak temizlenir.
    |
    | null yaparsanız TTL uygulanmaz (sonsuz saklama).
    |
    */
    'metrics_ttl' => env('ORCHESTRATOR_METRICS_TTL', 604800),

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
    | Histogram Bucket'ları
    |--------------------------------------------------------------------------
    |
    | HTTP request duration histogram için bucket sınırları (saniye cinsinden).
    | Prometheus standart bucket'ları kullanılır. Daha hassas ölçüm için
    | düşük değerli bucket'lar ekleyebilirsiniz.
    |
    */
    'histogram_buckets' => [
        0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5,
        1.0, 2.5, 5.0, 10.0, 30.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Query Metrikleri
    |--------------------------------------------------------------------------
    |
    | DB::listen() ile otomatik olarak SQL sorgu metriklerini toplar.
    | - enabled: SQL metrik toplama aktif/pasif
    | - include_query_label: SQL sorgusunun kendisini label olarak ekle
    |   (dikkat: yüksek cardinality — sadece debug için önerilir)
    | - query_max_length: Query label'ı için maksimum karakter uzunluğu
    | - ignore_patterns: Bu regex pattern'lara uyan sorgular izlenmez
    | - histogram_buckets: SQL sorgu süreleri için bucket sınırları (saniye)
    |
    */
    'sql_metrics' => [
        'enabled' => env('ORCHESTRATOR_SQL_ENABLED', true),
        'include_query_label' => env('ORCHESTRATOR_SQL_QUERY_LABEL', false),
        'query_max_length' => 200,
        'ignore_patterns' => [
            '/^SHOW\b/i',
            '/^SET\b/i',
            '/^DESCRIBE\b/i',
            '/^EXPLAIN\b/i',
            '/\bSAVEPOINT\b/i',
            '/\bRELEASE SAVEPOINT\b/i',
            '/\bmigrations\b/i',
        ],
        'histogram_buckets' => [
            0.001, 0.005, 0.01, 0.025, 0.05, 0.1,
            0.25, 0.5, 1.0, 2.5, 5.0, 10.0,
        ],
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
        'health' => true,
    ],

];
