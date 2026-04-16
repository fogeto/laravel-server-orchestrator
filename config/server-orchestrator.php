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
    | Varsayılan: 86400 (24 saat). Her yazma işleminde TTL yenilenir,
    | böylece aktif metrikler canlı kalır, kullanılmayan metrikler
    | otomatik olarak temizlenir.
    |
    | null yaparsanız TTL uygulanmaz (sonsuz saklama).
    |
    */
    'metrics_ttl' => env('ORCHESTRATOR_METRICS_TTL', 86400),

    /*
    |--------------------------------------------------------------------------
    | Route Ayarları
    |--------------------------------------------------------------------------
    |
    | Dokümandaki standart yüzey doğrudan /metrics endpoint'idir.
    | - enabled: Route'ları otomatik kayıt et
    | - middleware: Route'a uygulanacak ek middleware'ler
    |
    */
    'routes' => [
        'enabled' => true,
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
            'metrics',
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
        0.001, 0.002, 0.004, 0.008, 0.016, 0.032, 0.064, 0.128,
        0.256, 0.512, 1.024, 2.048, 4.096, 8.192, 16.384, 32.768,
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Query Metrikleri
    |--------------------------------------------------------------------------
    |
    | DB::listen() ile otomatik olarak SQL sorgu metriklerini toplar.
    | - enabled: SQL metrik toplama aktif/pasif
    | - include_query_label: Normalize edilmiş SQL sorgusunu label olarak ekle
    |   (rehber uyumu için varsayılan açıktır; public metrics'te dikkatli olun)
    | - query_max_length: Query label'ı için maksimum karakter uzunluğu
    | - max_unique_queries: Aynı process içinde tutulacak maksimum query hash sayısı
    | - ignore_patterns: Bu regex pattern'lara uyan sorgular izlenmez
    | - histogram_buckets: SQL sorgu süreleri için bucket sınırları (saniye)
    |
    */
    'sql_metrics' => [
        'enabled' => env('ORCHESTRATOR_SQL_ENABLED', true),
        'include_query_label' => env('ORCHESTRATOR_SQL_QUERY_LABEL', true),
        'query_max_length' => 200,
        'max_unique_queries' => env('ORCHESTRATOR_SQL_MAX_UNIQUE_QUERIES', 100),
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
            0.005, 0.01, 0.025, 0.05, 0.1, 0.25,
            0.5, 1.0, 2.5, 5.0, 10.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client (Outgoing) Metrikleri
    |--------------------------------------------------------------------------
    |
    | Laravel Http:: client ile yapılan dışarıya giden HTTP isteklerini izler.
    | Prometheus histogram + counter metrikleri üretir.
    |
    | Üretilen metrikler:
    |   - http_client_request_duration_seconds (Histogram)
    |   - http_client_requests_total (Counter)
    |   - http_client_errors_total (Counter — sadece 4xx/5xx/connection error)
    |
    | Label'lar:
    |   http_request_method, http_response_status_code,
    |   server_address, url_scheme, error_type
    |
    | - enabled: HTTP client metrik toplama aktif/pasif
    | - ignore_hosts: Bu host'lara yapılan istekler izlenmez
    | - histogram_buckets: İstek süreleri için bucket sınırları (saniye)
    |
    */
    'http_client_metrics' => [
        'enabled' => env('ORCHESTRATOR_HTTP_CLIENT_ENABLED', false),
        'ignore_hosts' => [
            // 'internal-service.local',
            // '*.internal.example.com',
        ],
        'histogram_buckets' => [
            0.01, 0.025, 0.05, 0.1, 0.25, 0.5,
            1.0, 2.5, 5.0, 10.0, 30.0, 60.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | APM Error Capture (Hata Yakalama)
    |--------------------------------------------------------------------------
    |
    | HTTP hata response'larını (request/response body dahil) yakalayıp
    | Redis circular buffer'da saklar. Hem incoming (iç servis) hem outgoing
    | (dış servis - Http:: client) hataları yakalanır.
    |
    | .NET'teki ApmErrorCaptureMiddleware'in Laravel karşılığı.
    |
    | Endpoint: /__apm/errors veya /apm/errors
    |   - GET: Hata listesi (en yeniden eskiye, JSON array)
    |
    | Yakalanan status code'lar: 400, 401, 403, 404, 429, 500, 502, 503
    |
    | - enabled: APM hata yakalama aktif/pasif
    | - max_buffer_size: Circular buffer boyutu (en eskiler otomatik silinir)
    | - max_body_size: Request/response body max capture boyutu (byte)
    | - max_message_length: Kısa mesaj alanı max uzunluğu
    | - ttl: Redis'te event'lerin saklanma süresi (saniye, null = sonsuz)
    | - ip_protection: Production'da IP whitelist koruması aktif/pasif
    | - allowed_ips: Ek izinli IP'ler (localhost her zaman izinli)
    | - ignore_paths: Bu path'lerden gelen incoming istekler yakalanmaz
    | - capture_outgoing: Outgoing (Http:: client) hatalarını da yakala
    |
    */
    'apm' => [
        'enabled' => env('ORCHESTRATOR_APM_ENABLED', true),
        'max_buffer_size' => 200,
        'max_body_size' => 32768, // 32KB
        'max_message_length' => 200,
        'ttl' => 86400, // 24 saat
        'ip_protection' => env('ORCHESTRATOR_APM_IP_PROTECTION', false),
        'allowed_ips' => array_filter([
            env('ApmSettings__AllowedIps__0'),
            env('ApmSettings__AllowedIps__1'),
            env('ApmSettings__AllowedIps__2'),
            env('ApmSettings__AllowedIps__3'),
            env('ApmSettings__AllowedIps__4'),
            env('APM_ALLOWED_IP_0'),
            env('APM_ALLOWED_IP_1'),
            env('APM_ALLOWED_IP_2'),
            env('APM_ALLOWED_IP_3'),
            env('APM_ALLOWED_IP_4'),
        ]),
        'ignore_paths' => [
            'metrics',
            '__apm/*',
            'apm/*',
            'telescope/*',
            'horizon/*',
        ],
        'capture_outgoing' => false,
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
        'php_info' => false,
        'memory' => false,
        'uptime' => false,
        'database' => true,
        'opcache' => false,
        'health' => false,
    ],

];
