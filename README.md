# Laravel Server Orchestrator

[![Laravel 9.x-12.x](https://img.shields.io/badge/Laravel-9.x--12.x-red.svg)](https://laravel.com)
[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Laravel 9, 10, 11 ve 12 ile uyumlu, **çoklu proje destekli** Prometheus monitoring paketi.

Aynı Redis sunucusunu paylaşan birden fazla Laravel projesini güvenle izlemenizi sağlar. Her projeye özel Redis key prefix'i ile verilerin karışması önlenir.

---

## İçindekiler

- [Özellikler](#özellikler)
- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
  - [Yeni Proje (Sıfırdan Kurulum)](#yeni-proje-sıfırdan-kurulum)
  - [Mevcut Proje (Eski Entegrasyondan Geçiş)](#mevcut-proje-eski-entegrasyondan-geçiş)
- [Kullanım](#kullanım)
  - [Metrikleri Görüntüleme](#metrikleri-görüntüleme)
  - [Metrikleri Temizleme (Wipe)](#metrikleri-temizleme-wipe)
- [Toplanan Metrikler](#toplanan-metrikler)
  - [HTTP Metrikleri (Otomatik)](#http-metrikleri-otomatik)
  - [Sistem Metrikleri](#sistem-metrikleri)
- [Konfigürasyon](#konfigürasyon)
  - [Tüm .env Değişkenleri](#tüm-env-değişkenleri)
  - [Prefix (Redis Key İzolasyonu)](#prefix-redis-key-izolasyonu)
  - [Middleware Ayarları](#middleware-ayarları)
  - [Route Ayarları](#route-ayarları)
  - [Histogram Bucket'ları](#histogram-bucketları)
  - [Sistem Metrikleri Açma/Kapama](#sistem-metrikleri-açmakapama)
- [Çoklu Proje Yapılandırması](#çoklu-proje-yapılandırması)
- [Özel Metrik Ekleme](#özel-metrik-ekleme)
- [Route Koruma (Güvenlik)](#route-koruma-güvenlik)
- [Prometheus & Grafana Entegrasyonu](#prometheus--grafana-entegrasyonu)
- [Sorun Giderme (SSS)](#sorun-giderme-sss)
- [Lisans](#lisans)

---

## Özellikler

| Özellik | Açıklama |
|---------|----------|
| 🔑 **Redis Key İzolasyonu** | Her proje için benzersiz prefix (`prometheus:{prefix}:*`) |
| 📊 **Otomatik HTTP Metrikleri** | Request duration histogram, toplam istek sayacı, hata sayacı |
| 🖥️ **Sistem Metrikleri** | PHP info, memory, uptime, DB connections, OPcache, health |
| 🔄 **Laravel 9-12 Desteği** | Tek paket, tüm sürümlerle uyumlu |
| ⚡ **Sıfır Konfigürasyon** | Kurup çalıştırın, ihtiyaç olursa her şey özelleştirilebilir |
| 🚫 **Wildcard Path Ignore** | Telescope, Horizon, metrics gibi yolları izlemekten hariç tutun |
| 🧹 **Otomatik Migrasyon** | Eski inline entegrasyonu tek komutla temizleyin |

---

## Gereksinimler

| Paket | Versiyon |
|-------|----------|
| PHP | ^8.0 |
| Laravel | ^9.0 \| ^10.0 \| ^11.0 \| ^12.0 |
| predis/predis | ^2.0 \| ^3.0 |
| promphp/prometheus_client_php | ^2.2 |
| Redis sunucusu | Çalışır durumda olmalı |

> **Not:** `predis/predis` ve `promphp/prometheus_client_php` otomatik olarak yüklenir. Ekstra bir şey kurmanız gerekmez.

---

## Kurulum

### Yeni Proje (Sıfırdan Kurulum)

Projenizde daha önce Prometheus entegrasyonu yoksa bu adımları takip edin.

#### Adım 1 — Repository Tanımlayın

Paket henüz Packagist'te yayınlanmadığı için projenizin `composer.json` dosyasına repository eklemeniz gerekiyor.

**Yöntem A — Lokal Path (Geliştirme ortamı):**

Paket reposu bilgisayarınızda mevcutsa:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-server-orchestrator"
        }
    ]
}
```

> `url` değerini paket klasörünün **göreceli yoluna** göre düzenleyin.

**Yöntem B — GitHub VCS (Sunucu / production ortamı):**

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

> Private repo ise sunucuda GitHub token / SSH key yapılandırması gerekir.

#### Adım 2 — Paketi Yükleyin

```bash
# Path repository için:
composer require fogeto/laravel-server-orchestrator:@dev

# VCS repository için:
composer require fogeto/laravel-server-orchestrator:dev-main
```

> Laravel'in paket auto-discovery özelliği sayesinde ServiceProvider **otomatik** olarak kaydedilir. Ekstra bir kayıt yapmanıza gerek yoktur.

#### Adım 3 — `.env` Dosyasına Prefix Ekleyin

```env
ORCHESTRATOR_PREFIX=projenizin_adi
```

> ⚠️ **ZORUNLU:** Aynı Redis sunucusunu paylaşan projeler **farklı prefix** kullanmalıdır.
>
> Örnekler: `ikbackend`, `hrportal`, `crm`, `ecommerce`

#### Adım 4 — Config Dosyasını Yayınlayın (Opsiyonel)

Varsayılan ayarlar çoğu proje için yeterlidir. Özelleştirmek isterseniz:

```bash
php artisan vendor:publish --tag=server-orchestrator-config
```

Bu komut `config/server-orchestrator.php` dosyasını oluşturur.

#### Adım 5 — Doğrulama

```bash
# Route'ların kayıt olduğunu kontrol edin
php artisan route:list --path=metrics
```

Şu çıktıyı görmelisiniz:

```
GET|HEAD  api/metrics ........ Fogeto\ServerOrchestrator\Http\Controllers\MetricsController@index
POST      api/wipe-metrics ... Fogeto\ServerOrchestrator\Http\Controllers\MetricsController@wipe
```

**Bu kadar!** 🎉 Artık `/api/metrics` adresinden metriklere erişebilirsiniz.

---

### Mevcut Proje (Eski Entegrasyondan Geçiş)

Projenizde daha önce **inline** (elle yazılmış) Prometheus entegrasyonu varsa, `orchestrator:migrate` komutu eski dosyaları otomatik temizler.

#### Adım 1 — Repository Tanımlayın ve Paketi Yükleyin

Yukarıdaki [Yeni Proje — Adım 1](#adım-1--repository-tanımlayın) ve [Adım 2](#adım-2--paketi-yükleyin) bölümlerini uygulayın.

#### Adım 2 — Neler Değişeceğini Görün (Dry Run)

```bash
php artisan orchestrator:migrate --dry-run
```

Bu komut hiçbir değişiklik yapmaz, sadece **ne yapacağını** gösterir:

```
╔══════════════════════════════════════════════════════════════╗
║       Server Orchestrator — Inline Migration Tool          ║
╚══════════════════════════════════════════════════════════════╝

⚡ DRY-RUN modu — hiçbir değişiklik yapılmayacak.

🔍 Eski entegrasyon dosyaları taranıyor...

Bulunan eski entegrasyon bileşenleri:
+----------+------------------------------------------+-----------------------------+
| Tür      | Konum                                    | Açıklama                    |
+----------+------------------------------------------+-----------------------------+
| Dosya    | app/Core/PredisAdapter.php               | Eski PredisAdapter          |
| Dosya    | app/Http/Middleware/PrometheusMiddleware  | Eski PrometheusMiddleware   |
| Dosya    | app/Providers/PrometheusServiceProvider   | Eski ServiceProvider        |
| Referans | app/Http/Kernel.php                      | PrometheusMiddleware ref.   |
| Referans | config/app.php                           | Eski provider kaydı         |
| Referans | routes/api.php                           | Inline metrics route'ları   |
| Eksik    | .env                                     | ORCHESTRATOR_PREFIX yok     |
+----------+------------------------------------------+-----------------------------+
```

#### Adım 3 — Migrasyonu Çalıştırın

```bash
php artisan orchestrator:migrate --prefix=projenizin_adi
```

Komut otomatik olarak şunları yapar:

| İşlem | Detay |
|-------|-------|
| 🗑️ Eski dosyaları siler | `PredisAdapter.php`, `PrometheusMiddleware.php`, `PrometheusServiceProvider.php` |
| 🧹 Kernel.php temizler | Eski middleware referansını kaldırır |
| 🧹 config/app.php temizler | Eski provider kaydını kaldırır |
| 🧹 config/services.php temizler | Prometheus config bloğunu kaldırır |
| 🧹 routes/api.php temizler | Inline metrics route tanımlarını kaldırır |
| ➕ .env günceller | `ORCHESTRATOR_PREFIX=...` ekler |
| 📄 Config publish eder | `config/server-orchestrator.php` oluşturur |

#### Adım 4 — Temizlik Sonrası

```bash
composer dump-autoload
php artisan config:clear
php artisan route:list --path=metrics
```

#### Komut Seçenekleri

| Seçenek | Açıklama | Örnek |
|---------|----------|-------|
| `--prefix=` | Prometheus prefix'i belirle | `--prefix=ikbackend` |
| `--dry-run` | Değişiklik yapmadan ne yapacağını göster | `--dry-run` |
| `--force` | Onay sormadan çalıştır (CI/CD için) | `--force` |

```bash
# Tam otomatik (CI/CD ortamı)
php artisan orchestrator:migrate --prefix=myapp --force

# Önce bak, sonra çalıştır
php artisan orchestrator:migrate --dry-run
php artisan orchestrator:migrate --prefix=myapp
```

---

## Kullanım

### Metrikleri Görüntüleme

```bash
# curl ile
curl http://localhost:8000/api/metrics

# PowerShell ile
Invoke-RestMethod -Uri http://localhost:8000/api/metrics

# Tarayıcıdan
# http://localhost:8000/api/metrics
```

### Metrikleri Temizleme (Wipe)

Tüm Redis'teki metrik verilerini sıfırlar. Test/geliştirme ortamında kullanışlıdır.

```bash
curl -X POST http://localhost:8000/api/wipe-metrics
```

Yanıt:

```json
{
    "success": true,
    "message": "All metrics have been wiped."
}
```

---

## Toplanan Metrikler

### HTTP Metrikleri (Otomatik)

Middleware tarafından her API isteğinde otomatik olarak kaydedilir.

| Metrik | Tip | Açıklama |
|--------|-----|----------|
| `http_request_duration_seconds` | Histogram | İstek süresi (saniye) |
| `http_requests_total` | Counter | Toplam istek sayısı |
| `http_errors_total` | Counter | Toplam hata sayısı (4xx + 5xx) |

**Label'lar:**

| Label | Açıklama | Örnek |
|-------|----------|-------|
| `code` | HTTP durum kodu | `200`, `404`, `500` |
| `method` | HTTP metodu | `GET`, `POST`, `PUT` |
| `controller` | Controller adı | `UserController` |
| `action` | Method adı | `index`, `store` |
| `endpoint` | Normalize edilmiş URI | `/api/users/{id}` |

> **Not:** Endpoint'lerdeki UUID ve sayısal ID'ler otomatik olarak `{uuid}` ve `{id}` ile değiştirilir. Bu, kardinellik sorununu önler.

#### Örnek HTTP Metrikleri Çıktısı

```
# HELP http_request_duration_seconds The duration of HTTP requests processed by a Laravel application.
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.005"} 12
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.01"} 38
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.025"} 45
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="+Inf"} 50
http_request_duration_seconds_sum{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12.345
http_request_duration_seconds_count{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 50

# HELP http_requests_total Total number of HTTP requests.
# TYPE http_requests_total counter
http_requests_total{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 50
http_requests_total{code="404",method="GET",controller="UserController",action="show",endpoint="/api/users/{id}"} 3
```

### Sistem Metrikleri

`/api/metrics` endpoint'i her çağrıldığında anlık olarak hesaplanır.

| Metrik | Tip | Açıklama |
|--------|-----|----------|
| `php_info` | Gauge | PHP versiyonu (label: `version`) |
| `process_uptime_seconds` | Gauge | Proses çalışma süresi |
| `process_memory_usage_bytes` | Gauge | Anlık bellek kullanımı |
| `process_memory_peak_bytes` | Gauge | En yüksek bellek kullanımı |
| `db_connections_active` | Gauge | Aktif MySQL bağlantı sayısı |
| `db_connections_max` | Gauge | Maksimum bağlantı limiti |
| `php_opcache_enabled` | Gauge | OPcache durumu (1/0) |
| `php_opcache_hit_rate` | Gauge | OPcache hit oranı (%) |
| `php_opcache_memory_used_bytes` | Gauge | OPcache bellek kullanımı |
| `app_health_status` | Gauge | Uygulama sağlığı (1=UP, 0=DOWN) |

> **Not:** `app_health_status` veritabanı bağlantısını kontrol eder. MySQL çalışmıyorsa `0` döner. Bağlantı testi için `fsockopen()` ile 2 saniyelik TCP timeout kullanılır, bu sayede DB timeout'ları metrik endpoint'ini yavaşlatmaz.

---

## Konfigürasyon

Config dosyasını publish ettikten sonra `config/server-orchestrator.php` üzerinden tüm ayarları özelleştirebilirsiniz.

### Tüm .env Değişkenleri

| Değişken | Varsayılan | Açıklama |
|----------|-----------|----------|
| `ORCHESTRATOR_ENABLED` | `true` | Metrikleri tamamen açma/kapama |
| `ORCHESTRATOR_PREFIX` | `APP_NAME` | Redis key prefix'i (projeye özel) |
| `ORCHESTRATOR_REDIS_CONNECTION` | `default` | Kullanılacak Redis bağlantısı |
| `ORCHESTRATOR_ROUTE_PREFIX` | `api` | Metrik route'larının URL prefix'i |

### Prefix (Redis Key İzolasyonu)

Her projeye benzersiz bir prefix verin. Redis'teki key formatı:

```
{laravel_prefix}prometheus:{ORCHESTRATOR_PREFIX}:{type}:{metric_name}
```

Örnek (`ORCHESTRATOR_PREFIX=ikbackend`):

```
laravel_database_prometheus:ikbackend:gauges:php_info
laravel_database_prometheus:ikbackend:counters:http_requests_total
laravel_database_prometheus:ikbackend:histograms:http_request_duration_seconds
```

### Middleware Ayarları

```php
// config/server-orchestrator.php

'middleware' => [
    // Middleware'i tamamen devre dışı bırak (HTTP metrikleri toplanmaz)
    'enabled' => true,

    // Hangi middleware gruplarına eklenecek
    // Laravel 9-10: app/Http/Kernel.php'deki grup adları
    // Laravel 11-12: bootstrap/app.php'deki grup adları
    'groups' => ['api'],

    // Bu path'lerden gelen istekler izlenmez
    // Wildcard (*) desteği vardır
    'ignore_paths' => [
        'api/metrics',
        'metrics',
        'api/wipe-metrics',
        'wipe-metrics',
        'telescope/*',      // Telescope istekleri
        'horizon/*',        // Horizon istekleri
        // 'api/health',    // Custom health check
    ],
],
```

### Route Ayarları

```php
'routes' => [
    // Route'ları otomatik kayıt et (false = kendi route'larınızı tanımlayın)
    'enabled' => true,

    // URL prefix'i: 'api' → /api/metrics, '' → /metrics
    'prefix' => env('ORCHESTRATOR_ROUTE_PREFIX', 'api'),

    // Route'a uygulanacak middleware'ler (güvenlik için)
    'middleware' => [],
    // Örnekler:
    // 'middleware' => ['auth:sanctum'],
    // 'middleware' => ['throttle:10,1'],
    // 'middleware' => [App\Http\Middleware\IpWhitelist::class],
],
```

### Histogram Bucket'ları

İstek sürelerini gruplamak için kullanılan eşik değerleri (saniye cinsinden):

```php
'histogram_buckets' => [
    0.005,  // 5ms
    0.01,   // 10ms
    0.025,  // 25ms
    0.05,   // 50ms
    0.1,    // 100ms
    0.25,   // 250ms
    0.5,    // 500ms
    1.0,    // 1s
    2.5,    // 2.5s
    5.0,    // 5s
    10.0,   // 10s
    30.0,   // 30s
],
```

> **İpucu:** API response süreleriniz genellikle 100ms altındaysa, düşük bucket'lar (`0.001`, `0.0025`) ekleyerek daha hassas ölçüm yapabilirsiniz.

### Sistem Metrikleri Açma/Kapama

İhtiyacınız olmayan metrikleri devre dışı bırakabilirsiniz:

```php
'system_metrics' => [
    'php_info'  => true,   // PHP versiyon bilgisi
    'memory'    => true,   // Bellek kullanımı
    'uptime'    => true,   // Proses çalışma süresi
    'database'  => true,   // MySQL bağlantı metrikleri (SHOW STATUS)
    'opcache'   => true,   // OPcache istatistikleri
    'health'    => true,   // Uygulama sağlık durumu
],
```

---

## Çoklu Proje Yapılandırması

### Senaryo: 3 Laravel Projesi, 1 Redis Sunucusu

```env
# Proje 1 — IK Backend (.env)
ORCHESTRATOR_PREFIX=ikbackend

# Proje 2 — HR Portal (.env)
ORCHESTRATOR_PREFIX=hrportal

# Proje 3 — CRM (.env)
ORCHESTRATOR_PREFIX=crm
```

Redis'teki key yapısı:

```
prometheus:ikbackend:gauges:php_info
prometheus:ikbackend:counters:http_requests_total
prometheus:ikbackend:histograms:http_request_duration_seconds

prometheus:hrportal:gauges:php_info
prometheus:hrportal:counters:http_requests_total

prometheus:crm:gauges:php_info
prometheus:crm:counters:http_requests_total
```

### Prometheus Scrape Config (Tüm Projeler)

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'ikbackend'
    metrics_path: '/api/metrics'
    scrape_interval: 15s
    static_configs:
      - targets: ['ikbackend.example.com']

  - job_name: 'hrportal'
    metrics_path: '/api/metrics'
    scrape_interval: 15s
    static_configs:
      - targets: ['hrportal.example.com']

  - job_name: 'crm'
    metrics_path: '/api/metrics'
    scrape_interval: 15s
    static_configs:
      - targets: ['crm.example.com']
```

---

## Özel Metrik Ekleme

Paket, `CollectorRegistry` singleton'ını IoC container'a kaydeder. Kendi metriklerinizi istediğiniz yerde ekleyebilirsiniz:

### Counter (Sayaç)

```php
use Prometheus\CollectorRegistry;

$registry = app(CollectorRegistry::class);

$counter = $registry->getOrRegisterCounter(
    'app',                    // Namespace
    'orders_total',           // Metrik adı
    'Total orders placed',    // Açıklama
    ['status']                // Label'lar
);

$counter->inc(['completed']);     // +1
$counter->incBy(5, ['pending']); // +5
```

### Gauge (Anlık Değer)

```php
$gauge = $registry->getOrRegisterGauge(
    'app', 'queue_size', 'Current queue size', ['queue']
);

$gauge->set(42, ['default']);     // Değeri ata
$gauge->inc(['emails']);          // +1
$gauge->decBy(3, ['exports']);    // -3
```

### Histogram (Dağılım)

```php
$histogram = $registry->getOrRegisterHistogram(
    'app',
    'payment_duration_seconds',
    'Payment processing time',
    ['gateway'],                    // Label'lar
    [0.1, 0.25, 0.5, 1, 2.5, 5]   // Bucket'lar
);

$histogram->observe(0.35, ['stripe']);
$histogram->observe(1.2, ['paypal']);
```

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
    Route::post('/wipe-metrics', [\Fogeto\ServerOrchestrator\Http\Controllers\MetricsController::class, 'wipe']);
});
```

---

## Prometheus & Grafana Entegrasyonu

### Prometheus Scrape Config

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'laravel-app'
    metrics_path: '/api/metrics'
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
rate(http_requests_total[5m])

# p95 response süresi
histogram_quantile(0.95, rate(http_request_duration_seconds_bucket[5m]))

# p99 response süresi
histogram_quantile(0.99, rate(http_request_duration_seconds_bucket[5m]))

# Hata oranı (%)
rate(http_errors_total[5m]) / rate(http_requests_total[5m]) * 100

# En yavaş endpoint'ler (p95)
histogram_quantile(0.95, sum(rate(http_request_duration_seconds_bucket[5m])) by (endpoint, le))

# Bellek kullanımı (MB)
process_memory_usage_bytes / 1024 / 1024

# Uygulama sağlık durumu
app_health_status
```

---

## Sorun Giderme (SSS)

### 1. Metriklerde sadece sistem metrikleri var, HTTP metrikleri yok

**Sebep:** Wipe sonrası henüz bir HTTP isteği yapılmamıştır.

**Çözüm:** HTTP metrikleri middleware tarafından istek sırasında kaydedilir. Herhangi bir API endpoint'ine istek atın, ardından `/api/metrics`'i tekrar kontrol edin.

### 2. `/api/metrics` endpoint'i çok yavaş (10+ saniye)

**Sebep:** Veritabanı sunucusu erişilemez durumda ve bağlantı timeout'u bekleniyordur.

**Çözüm:** Paket otomatik olarak `fsockopen()` ile 2 saniyelik TCP check yapar. Eğer hâlâ yavaşsa, config'den DB metriklerini kapatın:

```php
'system_metrics' => [
    'database' => false,
    'health' => false,
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

### 5. Redis `wipe-metrics` çalışmıyor / Eski veriler silinmiyor

**Sebep:** Redis prefix uyumsuzluğu olabilir.

**Çözüm:** Paket, Laravel'in Redis prefix'ini (`laravel_database_`) otomatik algılar ve Lua script ile doğru key'leri siler. `config/database.php`'de Redis prefix'inizi kontrol edin.

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
