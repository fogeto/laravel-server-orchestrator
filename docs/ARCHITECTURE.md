# Mimari Dokümanı

## Bileşen Haritası

```
┌──────────────────────────────────────────────────────────────────┐
│                    LARAVEL UYGULAMASI                            │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │             ServerOrchestratorServiceProvider               │ │
│  │                                                             │ │
│  │  register()                                                 │ │
│  │  ├─ mergeConfig()  → config/server-orchestrator.php         │ │
│  │  └─ singleton(CollectorRegistry)                            │ │
│  │       ├─ Redis::connection(...)                              │ │
│  │       ├─ PredisAdapter(connection, prefix)                  │ │
│  │       └─ fallback → InMemory adapter                        │ │
│  │                                                             │ │
│  │  boot()                                                     │ │
│  │  ├─ publishes(config)                                       │ │
│  │  ├─ commands(MigrateFromInlineCommand)                      │ │
│  │  ├─ loadRoutesFrom(metrics.php)                             │ │
│  │  └─ registerMiddleware()                                    │ │
│  │       ├─ Kernel::appendMiddlewareToGroup()                  │ │
│  │       └─ Router::pushMiddlewareToGroup()                    │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌────────────────┐     ┌─────────────────┐    ┌──────────────┐ │
│  │ Prometheus      │     │ Metrics         │    │ Migrate      │ │
│  │ Middleware      │     │ Controller      │    │ Command      │ │
│  │                │     │                 │    │              │ │
│  │ HTTP istekleri  │     │ GET /metrics    │    │ artisan      │ │
│  │ izle, kaydet   │     │ POST /wipe      │    │ orchestrator │ │
│  └───────┬────────┘     └────────┬────────┘    │ :migrate     │ │
│          │                       │              └──────────────┘ │
│          ▼                       ▼                               │
│  ┌──────────────────────────────────────────┐                   │
│  │           CollectorRegistry              │                   │
│  │           (Singleton)                    │                   │
│  │                                          │                   │
│  │  getOrRegisterHistogram()                │                   │
│  │  getOrRegisterCounter()                  │                   │
│  │  getOrRegisterGauge()                    │                   │
│  │  collect()                               │                   │
│  │  wipeStorage()                           │                   │
│  └───────────────┬──────────────────────────┘                   │
│                  │                                               │
│                  ▼                                               │
│  ┌──────────────────────────────────────────┐                   │
│  │           PredisAdapter                  │                   │
│  │           (implements Adapter)           │                   │
│  │                                          │                   │
│  │  updateGauge()    → Redis HSET           │                   │
│  │  updateCounter()  → Redis HINCRBYFLOAT   │                   │
│  │  updateHistogram()→ Redis HINCRBY/FLOAT  │                   │
│  │  collect()        → HGETALL + parse      │                   │
│  │  wipeStorage()    → Lua KEYS + DEL       │                   │
│  └───────────────┬──────────────────────────┘                   │
│                  │                                               │
└──────────────────┼───────────────────────────────────────────────┘
                   │
                   ▼
           ┌───────────────┐
           │  Redis Server  │
           │                │
           │  prefix:       │
           │  laravel_db_   │
           │  prometheus:   │
           │  {app}:        │
           │  gauges/       │
           │  counters/     │
           │  histograms/   │
           └───────────────┘
```

---

## Sınıf Sorumluları

### 1. ServerOrchestratorServiceProvider

**Dosya:** `src/Providers/ServerOrchestratorServiceProvider.php`

| Sorumluluk | Açıklama |
|------------|----------|
| Config merge | Paketin default config'ini uygulamanın config'i ile birleştirir |
| Singleton kayıt | `CollectorRegistry`'yi IoC container'a singleton olarak kaydeder |
| Prefix sanitize | `ORCHESTRATOR_PREFIX` → lowercase, alfanumerik olmayan → `_` |
| Redis fallback | Redis bağlantı hatası → InMemory adapter (metrik kaybı ama hata yok) |
| Middleware kayıt | Dual yöntem: Kernel + Router (Laravel 9-12 uyumluluk) |
| Route kayıt | `routes/metrics.php` dosyasını yükler |
| Command kayıt | `orchestrator:migrate` artisan komutunu kaydeder |

**Singleton yaşam döngüsü:**

```
register() çağrılır
    └─ $this->app->singleton(CollectorRegistry::class, ...) tanımlanır
        └─ İlk kez resolve edildiğinde (lazy):
            ├─ Redis::connection() → Predis client
            ├─ config prefix → sanitize → 'prometheus:{prefix}:'
            ├─ new PredisAdapter($conn, $prefix)
            └─ new CollectorRegistry($adapter)
```

### 2. PredisAdapter

**Dosya:** `src/Adapters/PredisAdapter.php`  
**Interface:** `Prometheus\Storage\Adapter`

Prometheus PHP client'ının storage backend'i. Tüm metrik verilerini Redis hash'lerinde saklar.

| Metot | Redis Komutu | Açıklama |
|-------|-------------|----------|
| `updateGauge()` | `HSET` | Gauge değerini set eder |
| `updateCounter()` | `HINCRBYFLOAT` | Counter'ı artırır |
| `updateHistogram()` | `HINCRBY` + `HINCRBYFLOAT` | count, sum ve bucket günceller |
| `collectGauges()` | `HGETALL` × 2 | Meta + data hash'lerini okur |
| `collectCounters()` | `HGETALL` × 2 | Meta + data hash'lerini okur |
| `collectHistograms()` | `HGETALL` × 2 | Meta + data → parse → sample'lar |
| `wipeStorage()` | Lua `KEYS` + `DEL` | Lua script ile prefix'li tüm key'leri siler |

### 3. PrometheusMiddleware

**Dosya:** `src/Http/Middleware/PrometheusMiddleware.php`

Her HTTP isteğinde 3 metrik kaydeder:

| Metrik | Tip | Açıklama |
|--------|-----|----------|
| `http_request_duration_seconds` | Histogram | İstek süresi (saniye) |
| `http_requests_total` | Counter | Toplam istek sayısı |
| `http_errors_total` | Counter | 4xx + 5xx hataları |

**Label'lar:** `code`, `method`, `controller`, `action`, `endpoint`

**Middleware akışı:**
1. `shouldIgnore()` → ignore listesinde mi? (evet → bypass)
2. `$start = microtime(true)` → kronometre başlat
3. `$next($request)` → isteği işle
4. `$duration` hesapla
5. `resolveEndpoint()` → URI normalize et
6. `resolveControllerAction()` → Controller@method çıkar
7. 3 metriği kaydet → Redis'e yaz

### 4. MetricsController

**Dosya:** `src/Http/Controllers/MetricsController.php`

İki endpoint:

#### `GET /metrics` → `index()`
1. `collectSystemMetrics()` çağrılır
   - `isDbReachable()` → TCP socket check (2s timeout)
   - Sırasıyla: php_info, uptime, memory, database, opcache, health
2. `$registry->collect()` → Redis'ten HTTP metriklerini oku
3. `RenderTextFormat::render()` → Prometheus text format'a çevir
4. Response döndür (`Content-Type: text/plain; version=0.0.4`)

#### `POST /wipe-metrics` → `wipe()`
1. `$registry->wipeStorage()` → PredisAdapter Lua script çalıştır
2. JSON success response döndür

### 5. MigrateFromInlineCommand

**Dosya:** `src/Console/Commands/MigrateFromInlineCommand.php`

Eski inline Prometheus entegrasyonundan pakete geçiş yapan artisan komutu.

```bash
php artisan orchestrator:migrate --prefix=myapp --dry-run
```

| İşlem | Açıklama |
|-------|----------|
| scan() | Eski dosyaları + referansları bul |
| removeOldFiles() | PredisAdapter, Middleware, Provider dosyalarını sil |
| cleanKernel() | Kernel.php'den eski middleware referansını temizle |
| cleanConfigApp() | config/app.php'den eski provider referansını temizle |
| cleanConfigServices() | config/services.php'den prometheus bloğunu temizle |
| cleanRoutes() | routes/api.php'den eski metrics route'larını temizle |
| setupEnvPrefix() | .env'e ORCHESTRATOR_PREFIX ekle |
| publishConfig() | Paket config dosyasını publish et |

---

## Dependency Injection Akışı

```
Request gelir
    │
    ├─ PrometheusMiddleware
    │      └─ __construct(CollectorRegistry $registry)  ← IoC auto-inject
    │              └─ CollectorRegistry
    │                      └─ PredisAdapter
    │                              └─ Redis Connection
    │
    └─ MetricsController
           └─ __construct(CollectorRegistry $registry)  ← IoC auto-inject
                   └─ Aynı singleton instance
```

**Önemli:** `CollectorRegistry` singleton olduğu için middleware ve controller **aynı instance**'ı kullanır. Bu, middleware'in yazdığı metriklerin controller'dan okunabilmesini garanti eder.

---

## Laravel Sürüm Uyumluluğu

| Özellik | Laravel 9 | Laravel 10 | Laravel 11 | Laravel 12 |
|---------|-----------|------------|------------|------------|
| Auto-discovery | ✅ | ✅ | ✅ | ✅ |
| Kernel middleware | ✅ `appendMiddlewareToGroup` | ✅ | ❌ (Kernel yok) | ❌ |
| Router middleware | ✅ `pushMiddlewareToGroup` | ✅ | ✅ | ✅ |
| Config merge | ✅ | ✅ | ✅ | ✅ |
| Route loading | ✅ | ✅ | ✅ | ✅ |

**Not:** Laravel 11+ Kernel dosyası kaldırıldı. Bu yüzden middleware kaydı iki yöntemle yapılır:
- `Kernel::appendMiddlewareToGroup()` → Laravel 9/10 için
- `Router::pushMiddlewareToGroup()` → Laravel 11/12 için (ve tüm sürümlerde yedek)

---

## Config Parametreleri

Detaylı config açıklamaları `config/server-orchestrator.php` içinde bulunur.

| Config Key | Env Variable | Default | Açıklama |
|-----------|-------------|---------|----------|
| `enabled` | `ORCHESTRATOR_ENABLED` | `true` | Paketi aktif/pasif yap |
| `prefix` | `ORCHESTRATOR_PREFIX` | `APP_NAME` | Redis key prefix'i |
| `redis_connection` | `ORCHESTRATOR_REDIS_CONNECTION` | `default` | Redis bağlantı adı |
| `routes.enabled` | — | `true` | Metrics route'larını kaydet |
| `routes.prefix` | `ORCHESTRATOR_ROUTE_PREFIX` | `api` | Route URL prefix'i |
| `routes.middleware` | — | `[]` | Ek route middleware'leri |
| `middleware.enabled` | — | `true` | HTTP middleware'i kaydet |
| `middleware.groups` | — | `['api']` | Hangi gruplara eklenecek |
| `middleware.ignore_paths` | — | `[...]` | İzlenmeyen path'ler |
| `histogram_buckets` | — | `[0.005...30.0]` | Histogram sınırları |
| `system_metrics.*` | — | `true` | Sistem metrikleri aç/kapat |
