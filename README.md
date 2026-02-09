# Laravel Server Orchestrator

Laravel 9, 10, 11 ve 12 ile uyumlu, çoklu proje destekli Prometheus monitoring paketi.

Aynı Redis sunucusunu paylaşan birden fazla Laravel projesini güvenle izlemenizi sağlar. Her projeye özel Redis key prefix'i ile verilerin karışması önlenir.

## Özellikler

- **Redis Key İzolasyonu** — Her proje için benzersiz prefix (`prometheus:{prefix}:*`)
- **Otomatik HTTP Metrikleri** — Request duration histogram, toplam istek sayacı, hata sayacı
- **Sistem Metrikleri** — PHP info, memory, uptime, DB connections, OPcache, health
- **Laravel 9-12 Desteği** — Tek paket, tüm sürümlerle uyumlu
- **Sıfır Konfigürasyon** — Kurup çalıştırın, ihtiyaç olursa her şey özelleştirilebilir
- **Wildcard Path Ignore** — Metrics ve admin endpoint'lerini izlemeden hariç tutun

## Kurulum

### 1. Composer ile Yükleme

```bash
composer require aysyazilim/laravel-server-orchestrator
```

> Laravel'in paket auto-discovery özelliği sayesinde ServiceProvider otomatik olarak kaydedilir.

### 2. Config Dosyasını Yayınlama (Opsiyonel)

```bash
php artisan vendor:publish --tag=server-orchestrator-config
```

### 3. `.env` Dosyasını Düzenleme

```env
# ZORUNLU: Her projeye benzersiz bir prefix verin
ORCHESTRATOR_PREFIX=ikbackend

# Opsiyonel ayarlar
ORCHESTRATOR_ENABLED=true
ORCHESTRATOR_REDIS_CONNECTION=default
ORCHESTRATOR_ROUTE_PREFIX=api
```

> **ÖNEMLİ:** Aynı Redis sunucusunu paylaşan projeler **farklı prefix** kullanmalıdır (ör. `ikbackend`, `hrportal`, `crm`).

## Kullanım

### Metrikleri Görüntüleme

```bash
curl http://localhost:8000/api/metrics
```

### Metrikleri Temizleme

```bash
curl -X POST http://localhost:8000/api/wipe-metrics
```

### Örnek Çıktı

```
# HELP php_info PHP environment information
# TYPE php_info gauge
php_info{version="8.3.14"} 1

# HELP process_uptime_seconds Process uptime in seconds
# TYPE process_uptime_seconds gauge
process_uptime_seconds 42.156

# HELP http_request_duration_seconds The duration of HTTP requests
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.1"} 45
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="+Inf"} 50
http_request_duration_seconds_sum{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12.345
http_request_duration_seconds_count{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 50

# HELP http_requests_total Total number of HTTP requests
# TYPE http_requests_total counter
http_requests_total{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 50
```

## Konfigürasyon

`config/server-orchestrator.php` dosyasından tüm ayarlar özelleştirilebilir:

### Prefix (Redis Key İzolasyonu)

```php
'prefix' => env('ORCHESTRATOR_PREFIX', env('APP_NAME', 'laravel')),
```

### Middleware Ayarları

```php
'middleware' => [
    'enabled' => true,
    'groups' => ['api'],          // Hangi middleware gruplarına eklenecek
    'ignore_paths' => [           // Bu path'ler izlenmez
        'api/metrics',
        'metrics',
        'api/wipe-metrics',
        'wipe-metrics',
        'telescope/*',            // Wildcard desteği
    ],
],
```

### Route Ayarları

```php
'routes' => [
    'enabled' => true,
    'prefix' => env('ORCHESTRATOR_ROUTE_PREFIX', 'api'),
    'middleware' => [],           // ['auth:sanctum'] gibi koruma eklenebilir
],
```

### Histogram Bucket'ları

```php
'histogram_buckets' => [
    0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5,
    1.0, 2.5, 5.0, 10.0, 30.0,
],
```

### Sistem Metrikleri

```php
'system_metrics' => [
    'php_info'  => true,
    'memory'    => true,
    'uptime'    => true,
    'database'  => true,   // MySQL SHOW STATUS sorguları
    'opcache'   => true,
    'health'    => true,
],
```

## Çoklu Proje Yapılandırması

### Senaryo: 3 Laravel Projesi, 1 Redis Sunucusu

```
# Proje 1 (.env)
ORCHESTRATOR_PREFIX=ikbackend

# Proje 2 (.env)
ORCHESTRATOR_PREFIX=hrportal

# Proje 3 (.env)
ORCHESTRATOR_PREFIX=crm
```

Redis'teki key yapısı:

```
prometheus:ikbackend:gauges:php_info
prometheus:ikbackend:counters:http_requests_total
prometheus:hrportal:gauges:php_info
prometheus:hrportal:counters:http_requests_total
prometheus:crm:gauges:php_info
prometheus:crm:counters:http_requests_total
```

## Özel Metrik Ekleme

Paket, `CollectorRegistry` singleton'ını IoC container'a kaydeder. Kendi metriklerinizi ekleyebilirsiniz:

```php
use Prometheus\CollectorRegistry;

$registry = app(CollectorRegistry::class);

// Counter
$counter = $registry->getOrRegisterCounter(
    'app', 'orders_total', 'Total orders placed', ['status']
);
$counter->inc(['completed']);

// Gauge
$gauge = $registry->getOrRegisterGauge(
    'app', 'queue_size', 'Current queue size', ['queue']
);
$gauge->set(42, ['default']);

// Histogram
$histogram = $registry->getOrRegisterHistogram(
    'app', 'payment_duration', 'Payment processing time', ['gateway'], [0.1, 0.5, 1, 5]
);
$histogram->observe(0.35, ['stripe']);
```

## Route Koruma

Metrics endpoint'ini dışarıya kapatmak için middleware ekleyebilirsiniz:

```php
// config/server-orchestrator.php
'routes' => [
    'middleware' => ['auth:sanctum'],
],
```

Veya IP tabanlı kısıtlama:

```php
// app/Http/Middleware/RestrictToLocalNetwork.php
public function handle($request, Closure $next)
{
    $allowed = ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    // IP kontrolü...
}

// config/server-orchestrator.php
'routes' => [
    'middleware' => [RestrictToLocalNetwork::class],
],
```

## Gereksinimler

| Paket | Versiyon |
|-------|----------|
| PHP | ^8.0 |
| Laravel | ^9.0 \| ^10.0 \| ^11.0 \| ^12.0 |
| predis/predis | ^2.0 |
| promphp/prometheus_client_php | ^2.2 |

## Lisans

MIT
