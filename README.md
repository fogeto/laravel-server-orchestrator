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
GET|HEAD  metrics ............ Fogeto\ServerOrchestrator\Http\Controllers\MetricsController@index
```

**Bu kadar!** Artık `/metrics` adresinden metriklere erişebilirsiniz.

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
curl http://localhost:8000/metrics

# PowerShell ile
Invoke-RestMethod -Uri http://localhost:8000/metrics

# Tarayıcıdan
# http://localhost:8000/metrics
```

### Toplanan Metrikler

Paket varsayılan olarak rehberdeki yüzeyi üretir.

#### HTTP Metrikleri

| Metrik | Tip | Açıklama |
|--------|-----|----------|
| `http_request_duration_seconds` | Histogram | ASP.NET Core tarzı request latency histogramı |
| `http_requests_received_total` | Counter | İşlenen HTTP istek sayısı |
| `http_requests_in_progress` | Gauge | Pipeline içindeki anlık request sayısı |

**HTTP label'ları:** `code`, `method`, `controller`, `action`, `endpoint`

#### SQL Metrikleri

| Metrik | Tip | Açıklama |
|--------|-----|----------|
| `sql_query_duration_seconds` | Histogram | SQL query execution duration |
| `sql_query_errors_total` | Counter | SQL query error count |

**SQL label sırası:**

| Metrik | Label'lar |
|--------|-----------|
| `sql_query_duration_seconds` | `query_hash`, `operation`, `table`, `query` |
| `sql_query_errors_total` | `query_hash`, `operation`, `table` |

#### DB Client Metrikleri

| Metrik | Tip | Açıklama |
|--------|-----|----------|
| `db_client_connections_max` | Gauge | Maximum pool connections |
| `db_client_connections_usage` | Gauge | Database connections by state |
| `db_client_connections_pending_requests` | Gauge | Pending connection requests |

> Varsayılan yüzeyde artık `http_requests_total`, `http_errors_total`, `db_connections_active`, `db_connections_max`, `php_info`, `process_*`, `php_opcache_*` ve `app_health_status` üretilmez.

#### Örnek HTTP Çıktısı

```
# HELP http_request_duration_seconds The duration of HTTP requests processed by an ASP.NET Core application.
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.001"} 1
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.002"} 3
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.004"} 8
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="+Inf"} 12
http_request_duration_seconds_sum{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 0.018
http_request_duration_seconds_count{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12

# HELP http_requests_in_progress The number of requests currently in progress in the ASP.NET Core pipeline. One series without controller/action label values counts all in-progress requests, with separate series existing for each controller-action pair.
# TYPE http_requests_in_progress gauge
http_requests_in_progress{method="GET",controller="UserController",action="index",endpoint="/api/users"} 1

# HELP http_requests_received_total Provides the count of HTTP requests that have been processed by the ASP.NET Core pipeline.
# TYPE http_requests_received_total counter
http_requests_received_total{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12
```

### SQL ve DB Notları

- `query` label'ı rehber uyumu için varsayılan olarak açıktır.
- Yeni query hash'leri process başına `100` benzersiz sorguyla sınırlandırılır.
- `db_client_connections_pending_requests` metriği şu an `0` yayınlanır.

---

## Konfigürasyon

Config dosyasını publish ettikten sonra `config/server-orchestrator.php` üzerinden tüm ayarları özelleştirebilirsiniz.

### Tüm .env Değişkenleri

| Değişken | Varsayılan | Açıklama |
|----------|-----------|----------|
| `ORCHESTRATOR_ENABLED` | `true` | Metrikleri tamamen açma/kapama |
| `ORCHESTRATOR_PREFIX` | `APP_NAME` | Redis key prefix'i (projeye özel) |
| `ORCHESTRATOR_REDIS_CONNECTION` | `default` | Kullanılacak Redis bağlantısı |
| `ORCHESTRATOR_SQL_ENABLED` | `true` | SQL metriklerini açma/kapama |
| `ORCHESTRATOR_SQL_QUERY_LABEL` | `true` | Normalize edilmiş SQL query label'ını açma/kapama |
| `ORCHESTRATOR_SQL_MAX_UNIQUE_QUERIES` | `100` | Process başına tutulacak maksimum query hash sayısı |

> **Not:** Varsayılan route yüzeyi yalnızca `GET /metrics` endpoint'idir.

### Prefix (Redis Key İzolasyonu)

Her projeye benzersiz bir prefix verin. Redis'teki key formatı:

```
{laravel_prefix}prometheus:{ORCHESTRATOR_PREFIX}:{type}:{metric_name}
```

Örnek (`ORCHESTRATOR_PREFIX=ikbackend`):

```
laravel_database_prometheus:ikbackend:gauges:db_client_connections_max
laravel_database_prometheus:ikbackend:counters:http_requests_received_total
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
        'metrics',
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
    0.001, 0.002, 0.004, 0.008,
    0.016, 0.032, 0.064, 0.128,
    0.256, 0.512, 1.024, 2.048,
    4.096, 8.192, 16.384, 32.768,
],
```

> **Not:** Bu bucket seti ASP.NET Core referansındaki histogram çözünürlüğünü takip eder.

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
prometheus:ikbackend:gauges:db_client_connections_max
prometheus:ikbackend:counters:http_requests_received_total
prometheus:ikbackend:histograms:http_request_duration_seconds

prometheus:hrportal:gauges:db_client_connections_max
prometheus:hrportal:counters:http_requests_received_total

prometheus:crm:gauges:db_client_connections_max
prometheus:crm:counters:http_requests_received_total
```

### Prometheus Scrape Config (Tüm Projeler)

```yaml
# prometheus.yml
scrape_configs:
  - job_name: 'ikbackend'
        metrics_path: '/metrics'
    scrape_interval: 15s
    static_configs:
      - targets: ['ikbackend.example.com']

  - job_name: 'hrportal'
        metrics_path: '/metrics'
    scrape_interval: 15s
    static_configs:
      - targets: ['hrportal.example.com']

  - job_name: 'crm'
        metrics_path: '/metrics'
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

**Çözüm:** Rehber yüzeyinde yalnızca `db_client_*` metrikleri toplanır. Gerekirse config'den DB metriklerini kapatın:

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
