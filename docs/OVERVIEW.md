# Proje Genel Bakış

## Amaç

Laravel Server Orchestrator, Laravel 9-12 uygulamalarına standart bir observability yüzeyi ekleyen Composer paketidir.

Paket üç ana alanı kapsar:

| Alan | Yüzey | Saklama modeli |
|------|-------|----------------|
| HTTP metrikleri | `/metrics` | `redis` veya `in_memory` driver |
| SQL metrikleri | `/metrics` | `redis` veya `in_memory` driver |
| APM hata event'leri | `/__apm/errors`, `/apm/errors` | MongoDB veya Redis, TTL 1 gün |

## Önemli mimari not

Referans .NET rehberi process RAM kullanan bir metrik modeli anlatır. Laravel/FPM altında request'ler process belleğini paylaşmadığı için paket varsayılan olarak Redis tabanlı metrik driver'ı ile gelir.

Uzun ömürlü PHP runtime kullanan projelerde `ORCHESTRATOR_METRICS_STORAGE=in_memory` seçilerek .NET'e daha yakın davranış elde edilebilir.

## Paket kimliği

| Alan | Değer |
|------|-------|
| Paket adı | `fogeto/laravel-server-orchestrator` |
| Namespace | `Fogeto\ServerOrchestrator` |
| PHP | `^8.0` |
| Laravel | `^9.0 | ^10.0 | ^11.0 | ^12.0` |
| Varsayılan metrics driver | `redis` |
| APM persistence | `ORCHESTRATOR_APM_STORE=mongo|redis` |

## Dosya yapısı

```
laravel-server-orchestrator/
├── config/server-orchestrator.php
├── routes/metrics.php
├── src/
│   ├── Adapters/PredisAdapter.php
│   ├── Contracts/IApmErrorStore.php
│   ├── Http/
│   │   ├── Controllers/ApmController.php
│   │   ├── Controllers/MetricsController.php
│   │   ├── Middleware/ApmErrorCaptureMiddleware.php
│   │   └── Middleware/PrometheusMiddleware.php
│   ├── Providers/ServerOrchestratorServiceProvider.php
│   ├── Services/ApmErrorBuffer.php
│   ├── Services/MongoApmErrorStore.php
│   └── Services/SqlQueryMetricsRecorder.php
└── docs/
```

## Akış özeti

```
HTTP request
   │
   ├─ PrometheusMiddleware
   │    ├─ endpoint normalize edilir
   │    ├─ in-progress gauge güncellenir
   │    └─ duration + received counter kaydedilir
   │
   ├─ ApmErrorCaptureMiddleware
   │    ├─ 4xx/5xx response kontrolü
   │    ├─ büyük upload bypass kontrolü
   │    └─ event Mongo store kuyruğuna bırakılır
   │
   ├─ DB::listen + QueryException hook
   │    ├─ sql_query_duration_seconds
   │    └─ sql_query_errors_total
   │
   ├─ GET /metrics
   │    ├─ db_client_* gauge'ları hesaplanır
   │    └─ registry text format olarak render edilir
   │
   └─ GET /apm/errors
        └─ MongoDB'den en yeni event'ler limit ile okunur
```

---

## Hızlı Başlangıç (Geliştirici)

### Lokal Geliştirme

```bash
# 1. Repo'yu klonla
git clone https://github.com/fogeto/laravel-server-orchestrator.git
cd laravel-server-orchestrator

# 2. Bağımlılıkları yükle
composer install

# 3. Bir Laravel projesine symlink olarak bağla
# Ayrı projenin composer.json'ına ekle:
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-server-orchestrator"
        }
    ],
    "require": {
        "fogeto/laravel-server-orchestrator": "@dev"
    }
}

# 4. composer update
cd ../laravel-projesi
composer update fogeto/laravel-server-orchestrator
```

### Test Etme

```bash
# Sunucuyu başlat
php artisan serve

# Birkaç istek at
curl http://localhost:8000/api/users
curl http://localhost:8000/api/users
curl -X POST http://localhost:8000/api/admin/login

# Metrikleri kontrol et
curl http://localhost:8000/metrics
```

### Değişiklik Yaptıktan Sonra

```bash
# Paketin bağlı olduğu projede autoload'u güncelle
cd ../laravel-projesi
composer dump-autoload

# Config cache'ini temizle
php artisan config:clear
```
