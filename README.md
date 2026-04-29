# Laravel Server Orchestrator

[![Laravel 9.x-12.x](https://img.shields.io/badge/Laravel-9.x--12.x-red.svg)](https://laravel.com)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)

Laravel uygulamaları için standart bir metrics ve APM paketi.

Paket üç yüzey üretir:

- `GET /metrics`
- `GET /__apm/errors`
- `GET /apm/errors`

HTTP, SQL ve DB client metric aileleri Prometheus formatında sunulur. APM hata event'leri ise MongoDB'ye yazılır ve limitli JSON feed olarak okunur.

## Özellikler

| Özellik | Açıklama |
|--------|----------|
| HTTP metrics | `http_request_duration_seconds`, `http_requests_received_total`, `http_requests_in_progress` |
| SQL metrics | `sql_query_duration_seconds`, `sql_query_errors_total` |
| DB client metrics | `db_client_connections_max`, `db_client_connections_usage`, `db_client_connections_pending_requests` |
| APM feed | 4xx/5xx response event'leri MongoDB `ApmErrors` collection'ında 1 gün tutulur |
| Metrics driver seçimi | `redis` veya `in_memory` |
| Laravel 9-12 desteği | Kernel ve Router akışlarıyla uyumlu |
| Migration komutu | Eski inline entegrasyonları temizlemek için `orchestrator:migrate` |

## Gereksinimler

| Bileşen | Gereksinim |
|--------|------------|
| PHP | `^8.0` |
| Laravel | `^9.0 | ^10.0 | ^11.0 | ^12.0` |
| Prometheus client | `promphp/prometheus_client_php ^2.2` |
| Redis | `metrics_storage=redis` için gerekli |
| ext-mongodb | Mongo tabanlı APM persistence için gerekli |

> `ext-mongodb` yüklü değilse package çalışmaya devam eder; sadece APM persistence devre dışı kalır ve `/apm/errors` boş array döner.

## Storage modeli

Referans .NET mimarisi metrics için process RAM kullanır. Laravel/FPM altında request'ler process belleğini paylaşmadığı için paket varsayılan olarak Redis-backed metrics driver ile gelir.

| Veri tipi | Varsayılan storage | Not |
|----------|--------------------|-----|
| HTTP/SQL/DB metrics | Redis | FPM için güvenli varsayılan |
| HTTP/SQL/DB metrics | InMemory | Uzun ömürlü runtime'larda seçilebilir |
| APM event'leri | MongoDB | TTL 1 gün |

## Kurulum

### 1. Repository tanımı

Packagist yerine doğrudan repo kullanıyorsanız uygulamanın `composer.json` dosyasına ekleyin:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/fogeto/laravel-server-orchestrator"
        }
    ]
}
```

Lokal geliştirmede `path` repository de kullanılabilir.

### 2. Paketi yükleme

```bash
composer require fogeto/laravel-server-orchestrator:dev-main
```

Laravel auto-discovery sayesinde provider otomatik kayıt edilir.

### 3. Prefix ayarı

```env
ORCHESTRATOR_PREFIX=myapp
```

Redis metrics driver kullanan her proje benzersiz prefix vermelidir.

### 4. Config publish (opsiyonel)

```bash
php artisan vendor:publish --tag=server-orchestrator-config
```

### 5. Route doğrulama

```bash
php artisan route:list | grep -E "metrics|apm"
```

Beklenen yüzey:

```text
GET|HEAD  metrics
GET|HEAD  __apm/errors
GET|HEAD  apm/errors
```

## Mevcut projeden geçiş

Eski inline Prometheus entegrasyonu olan projelerde:

```bash
php artisan orchestrator:migrate --dry-run
php artisan orchestrator:migrate --prefix=myapp
php artisan optimize:clear
```

Migration komutu eski middleware/provider/route kalıntılarını temizlemeye yardım eder.

## Kullanım

### Metrics

```bash
curl http://localhost:8000/metrics
```

### APM event feed

```bash
curl http://localhost:8000/apm/errors
curl http://localhost:8000/__apm/errors?limit=50
```

APM feed varsayılan olarak sadece incoming event'leri listeler.

### Test amaçlı hata üretme

```bash
curl -i http://localhost:8000/olmayan-endpoint
curl http://localhost:8000/apm/errors
```

## Toplanan yüzey

### HTTP metrics

| Metrik | Tip | Label'lar |
|--------|-----|-----------|
| `http_request_duration_seconds` | Histogram | `code`, `method`, `controller`, `action`, `endpoint` |
| `http_requests_received_total` | Counter | `code`, `method`, `controller`, `action`, `endpoint` |
| `http_requests_in_progress` | Gauge | `method`, `controller`, `action`, `endpoint` |

### SQL metrics

| Metrik | Tip | Varsayılan label'lar |
|--------|-----|----------------------|
| `sql_query_duration_seconds` | Histogram | `query_hash`, `operation`, `table` |
| `sql_query_errors_total` | Counter | `query_hash`, `operation`, `table` |

> `ORCHESTRATOR_SQL_QUERY_LABEL=true` yapılırsa `sql_query_duration_seconds` metric'ine `query` label'ı eklenir.

### DB client metrics

| Metrik | Tip | Not |
|--------|-----|-----|
| `db_client_connections_max` | Gauge | MySQL `max_connections` üzerinden |
| `db_client_connections_usage` | Gauge | `idle` ve `used` label'ları ile |
| `db_client_connections_pending_requests` | Gauge | Laravel tarafında gözlemlenmediği için varsayılan `0` |

### PHP / process metrics

| Metrik | Tip | Not |
|--------|-----|-----|
| `php_info` | Gauge | `version` label'ı ile PHP runtime bilgisi |
| `process_uptime_seconds` | Gauge | Request process uptime |
| `process_memory_usage_bytes` | Gauge | Anlık PHP bellek kullanımı |
| `process_memory_peak_bytes` | Gauge | Peak PHP bellek kullanımı |
| `php_opcache_enabled` | Gauge | OPcache aktifse `1`, değilse `0` |
| `php_opcache_hit_rate` | Gauge | OPcache hit rate yüzdesi |
| `php_opcache_memory_used_bytes` | Gauge | OPcache kullanılan bellek |

### Varsayılan yüzeyde olmayanlar

- `http_requests_total`
- `http_errors_total`
- `db_connections_active`
- `db_connections_max`
- `app_health_status`

## Konfigürasyon

### Temel env değişkenleri

| Değişken | Varsayılan | Açıklama |
|----------|-----------|----------|
| `ORCHESTRATOR_ENABLED` | `true` | Paketi aç/kapat |
| `ORCHESTRATOR_PREFIX` | `APP_NAME` | Redis prefix izolasyonu |
| `ORCHESTRATOR_METRICS_STORAGE` | `redis` | `redis` veya `in_memory` |
| `ORCHESTRATOR_REDIS_CONNECTION` | `default` | Redis bağlantısı |
| `ORCHESTRATOR_METRICS_TTL` | `86400` | Sadece Redis metrics driver için |
| `ORCHESTRATOR_SQL_ENABLED` | `true` | SQL metrics aç/kapat |
| `ORCHESTRATOR_SQL_QUERY_LABEL` | `false` | SQL `query` label'ını aç/kapat |
| `ORCHESTRATOR_SQL_MAX_UNIQUE_QUERIES` | `100` | Benzersiz query hash sınırı |
| `ORCHESTRATOR_APM_ENABLED` | `true` | APM capture aç/kapat |
| `ORCHESTRATOR_APM_TTL` | `86400` | Mongo TTL, 1 gün |
| `ORCHESTRATOR_APM_DEFAULT_LIMIT` | `200` | Endpoint varsayılan limit |
| `ORCHESTRATOR_APM_MAX_LIMIT` | `500` | Endpoint üst limit |
| `ORCHESTRATOR_APM_BYPASS_THRESHOLD_BYTES` | `5242880` | 5MB üstü capture bypass |

### Mongo APM ayarları

APM persistence için aşağıdaki env'ler kullanılır:

```env
Logging__MongoDB__ConnectionString=mongodb://user:pass@host:27017/?authSource=admin
Logging__MongoDB__DatabaseName=ecommerce
```

Collection adı sabit olarak `ApmErrors` kullanılır.

Database adı proje bazlı seçilmelidir. Örnek: `ecommerce`, `crm`, `hrportal`.

APM endpoint'leri paket içinde IP doğrulaması yapmaz. Production'da erişimi gerekiyorsa reverse proxy, firewall veya uygulama dışı auth katmanı ile kısıtlayın.

## Çoklu proje yapısı

Redis metrics driver kullanan birden fazla proje için prefix'leri ayırın:

```env
ORCHESTRATOR_PREFIX=ikbackend
ORCHESTRATOR_PREFIX=hrportal
ORCHESTRATOR_PREFIX=crm
```

Bu izolasyon sadece metrics driver için gereklidir; APM event'leri MongoDB collection'ında tutulur.

## Özel metric ekleme

Package `CollectorRegistry` singleton'ını container'a kaydeder:

```php
use Prometheus\CollectorRegistry;

$registry = app(CollectorRegistry::class);

$counter = $registry->getOrRegisterCounter(
        'app',
        'orders_total',
        'Total orders placed',
        ['status']
);

$counter->inc(['completed']);
```

## Prometheus scrape örneği

```yaml
scrape_configs:
    - job_name: 'my-api'
        metrics_path: '/metrics'
        scrape_interval: 15s
        static_configs:
            - targets: ['my-api.example.com']
```

## Sorun giderme

### `/apm/errors` boş dönüyor

- Mongo env'leri dolu mu?
- `ext-mongodb` yüklü mü?
- Gerçekten 4xx/5xx event oluştu mu?

### `/metrics` sayaçları FPM altında sıfırlanıyor

- `ORCHESTRATOR_METRICS_STORAGE=in_memory` kullanıyorsan FPM altında beklenen davranış budur.
- FPM için `redis` driver kullan.

### Publish edilen config eski kaldı

`vendor:publish` mevcut dosyayı ezmez. `config/server-orchestrator.php` içindeki yeni anahtarları elle merge et veya kontrollü şekilde `--force` kullan.

## Lisans

MIT

### Job/Queue Metriği Örneği

```php
// app/Jobs/ProcessPayment.php
class ProcessPayment implements ShouldQueue
{
    public function handle(): void
    {
        $start = microtime(true);

        // ... iş mantığı ...

        $duration = microtime(true) - $start;

        $histogram = app(CollectorRegistry::class)->getOrRegisterHistogram(
            'app', 'job_duration_seconds', 'Job processing time',
            ['job'], [0.1, 0.5, 1, 5, 30, 60]
        );
        $histogram->observe($duration, ['process_payment']);
    }
}
```

---

## Route Koruma (Güvenlik)

Metrics endpoint'ini production'da dışarıya açık bırakmayın!

### Yöntem 1: Auth Middleware

```php
// config/server-orchestrator.php
'routes' => [
    'middleware' => ['auth:sanctum'],
],
```

### Yöntem 2: IP Kısıtlama

```php
// app/Http/Middleware/MetricsIpWhitelist.php
class MetricsIpWhitelist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

        foreach ($allowed as $ip) {
            if ($request->ip() === $ip) {
                return $next($request);
            }
        }

        abort(403, 'Forbidden');
    }
}

// config/server-orchestrator.php
'routes' => [
    'middleware' => [\App\Http\Middleware\MetricsIpWhitelist::class],
],
```

### Yöntem 3: Route'ları Devre Dışı Bırakıp Kendiniz Tanımlama

```php
// config/server-orchestrator.php
'routes' => [
    'enabled' => false, // Paketin route'larını kapat
],

// routes/api.php — Kendi tanımınız
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/metrics', [\Fogeto\ServerOrchestrator\Http\Controllers\MetricsController::class, 'index']);
});
```

---

## Prometheus & Grafana Entegrasyonu

### Prometheus Scrape Config

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'laravel-app'
        metrics_path: '/metrics'
    scrape_interval: 15s
    static_configs:
      - targets: ['your-app.example.com']
    # Auth gerekiyorsa:
    # bearer_token: 'your-token'
```

### Faydalı Grafana Sorguları (PromQL)

```promql
# Son 5 dakikadaki ortalama response süresi
rate(http_request_duration_seconds_sum[5m]) / rate(http_request_duration_seconds_count[5m])

# Saniyedeki istek sayısı (RPS)
rate(http_requests_received_total[5m])

# p95 response süresi
histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))

# p99 response süresi
histogram_quantile(0.99, rate(http_request_duration_seconds_bucket[5m]))

# DB connection kullanım oranı (%)
db_client_connections_usage{state="used"} / db_client_connections_max * 100

# En yavaş endpoint'ler (p95)
histogram_quantile(0.95, sum(rate(http_request_duration_seconds_bucket[5m])) by (endpoint, le))

# SQL hata oranı
rate(sql_query_errors_total[5m])
```

---

## Sorun Giderme (SSS)

### 1. Metriklerde sadece DB/SQL metrikleri var, HTTP metrikleri yok

**Sebep:** Henüz uygulama trafiği oluşmamıştır.

**Çözüm:** HTTP metrikleri middleware tarafından istek sırasında kaydedilir. Herhangi bir API endpoint'ine istek atın, ardından `/metrics`'i tekrar kontrol edin.

### 2. `/metrics` endpoint'i çok yavaş (10+ saniye)

**Sebep:** DB durum sorguları yavaşlıyor veya MySQL dışı sürücüde sessiz fallback yaşanıyordur.

**Çözüm:** `/metrics` render edilirken PHP/process/opcache metrikleriyle birlikte `db_client_*` metrikleri de toplanır. Gerekirse config'den DB metriklerini kapatın:

```php
'system_metrics' => [
    'database' => false,
],
```

### 3. `composer require` sırasında hata alıyorum

**Sebep:** `predis/predis` versiyon uyumsuzluğu.

**Çözüm:** Paket `predis/predis ^2.0|^3.0` kabul eder. composer.json'ınızdaki predis versiyonunu kontrol edin:

```bash
composer show predis/predis
```

### 4. Route'lar görünmüyor (`route:list`'te yok)

**Kontrol edin:**

```bash
# Config cache'ini temizleyin
php artisan config:clear

# Autoload'u yenileyin
composer dump-autoload

# Paketin keşfedildiğini doğrulayın
php artisan package:discover
```

### 5. Redis'te eski metrikler silinmiyor

**Sebep:** Redis prefix uyumsuzluğu olabilir.

**Çözüm:** Paket, Laravel'in Redis prefix'ini (`laravel_database_`) otomatik algılar. `config/database.php` içindeki Redis prefix'i ile `ORCHESTRATOR_PREFIX` değerinizin beklediğiniz key setini ürettiğini kontrol edin.

### 6. Aynı Redis'te iki projenin verileri karışıyor

**Sebep:** İki proje aynı `ORCHESTRATOR_PREFIX` kullanıyordur.

**Çözüm:** Her projenin `.env` dosyasında **benzersiz** bir `ORCHESTRATOR_PREFIX` değeri olmalıdır.

### 7. `ORCHESTRATOR_ENABLED=false` yaptım ama route'lar hâlâ var

**Çözüm:**

```bash
php artisan config:clear
php artisan route:clear
```

---

## Lisans

MIT License — [LICENSE](LICENSE) dosyasına bakın.

---

**Fogeto** tarafından ❤️ ile geliştirilmiştir.
