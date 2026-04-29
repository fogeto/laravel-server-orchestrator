# Mimari Dokümanı

## Bileşen haritası

```
┌──────────────────────────────────────────────────────────────────┐
│                    Laravel Uygulaması                           │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │          ServerOrchestratorServiceProvider                  │ │
│  │  register()                                                 │ │
│  │   ├─ mergeConfig()                                          │ │
│  │   ├─ singleton(IApmErrorStore -> MongoApmErrorStore)        │ │
│  │   ├─ singleton(ApmErrorBuffer)                              │ │
│  │   └─ singleton(CollectorRegistry)                           │ │
│  │       ├─ metrics_storage=redis     -> PredisAdapter         │ │
│  │       └─ metrics_storage=in_memory -> InMemory              │ │
│  └─────────────────────────────────────────────────────────────┘ │
│                                                                  │
│  ┌────────────────────┐   ┌────────────────────┐                │
│  │ PrometheusMiddleware│   │ApmErrorCapture... │                │
│  │ HTTP metrics        │   │4xx/5xx event yakala│               │
│  └─────────┬──────────┘   └──────────┬─────────┘                │
│            │                         │                          │
│            ▼                         ▼                          │
│     CollectorRegistry         ApmErrorBuffer                   │
│            │                         │                          │
│            ▼                         ▼                          │
│   PredisAdapter / InMemory     MongoApmErrorStore              │
│            │                         │                          │
│            ▼                         ▼                          │
│         /metrics               MongoDB ApmErrors               │
│                                                       TTL 1 gün│
└──────────────────────────────────────────────────────────────────┘
```

## Sınıf sorumlulukları

### 1. ServerOrchestratorServiceProvider

Dosya: `src/Providers/ServerOrchestratorServiceProvider.php`

| Sorumluluk | Açıklama |
|------------|----------|
| Config merge | Paket config'ini uygulamaya taşır |
| APM binding | `IApmErrorStore` için Mongo store bind eder |
| Metrics binding | `CollectorRegistry` için storage driver seçer |
| Middleware kayıt | Laravel 9-12 uyumlu grup ekleme yapar |
| SQL hook | `DB::listen` ve `QueryException` raporlamasını bağlar |

### 2. MongoApmErrorStore

Dosya: `src/Services/MongoApmErrorStore.php`

| Sorumluluk | Açıklama |
|------------|----------|
| Mongo bağlantısı | `Logging__MongoDB__ConnectionString` ve `DatabaseName` ile bağlanır |
| Queue benzeri buffer | Event'leri request sonunda flush edilmek üzere bellekte tutar |
| Batch insert | Event'leri küçük partiler halinde Mongo'ya yazar |
| TTL index | `timestamp` alanında 1 günlük TTL index oluşturur |
| Read API | `/apm/errors?limit=` için descending sorgu yapar |

### 3. ApmErrorBuffer

Dosya: `src/Services/ApmErrorBuffer.php`

Uygulamanın geri kalanına tek bir capture API sunar.

| Metot | Amaç |
|-------|------|
| `shouldCapture()` | Yakalanacak HTTP status code setini belirler |
| `captureIncoming()` | Incoming error event'i normalize eder ve store'a bırakır |
| `captureOutgoing()` | Opsiyonel outgoing event'i normalize eder |
| `getAll($limit)` | Endpoint için en yeni event'leri döndürür |

### 4. PrometheusMiddleware

Dosya: `src/Http/Middleware/PrometheusMiddleware.php`

Üç HTTP metric ailesini üretir:

- `http_request_duration_seconds`
- `http_requests_received_total`
- `http_requests_in_progress`

Path ignore listesi ve endpoint normalizasyonu burada uygulanır.

### 5. MetricsController

Dosya: `src/Http/Controllers/MetricsController.php`

`GET /metrics` çağrısında:

1. `db_client_*` gauge'ları hesaplanır.
2. Registry içindeki metric family'ler toplanır.
3. Prometheus text format render edilir.

### 6. SqlQueryMetricsRecorder

Dosya: `src/Services/SqlQueryMetricsRecorder.php`

| Metrik | Davranış |
|--------|----------|
| `sql_query_duration_seconds` | Her query için duration observe eder |
| `sql_query_errors_total` | `QueryException` raporlandığında artar |

Varsayılan cardinality korumaları:

- `max_unique_queries = 100`
- HangFire ve `information_schema` filtreleri
- `query` label'i varsayılan olarak kapalı

## Config özeti

| Config | Default | Not |
|-------|---------|-----|
| `metrics_storage` | `redis` | FPM güvenli varsayılan |
| `metrics_ttl` | `86400` | Sadece redis driver için |
| `sql_metrics.include_query_label` | `false` | İstenirse açılabilir |
| `apm.ttl` | `86400` | Mongo TTL, 1 gün |
| `apm.default_limit` | `200` | `/apm/errors` varsayılan limiti |
| `apm.max_limit` | `500` | Üst sınır |
| `apm.bypass_threshold_bytes` | `5242880` | 5MB üstü body capture edilmez |

## Laravel uyumluluğu

| Özellik | Laravel 9 | Laravel 10 | Laravel 11 | Laravel 12 |
|---------|-----------|------------|------------|------------|
| Auto-discovery | Evet | Evet | Evet | Evet |
| Kernel append | Evet | Evet | Hayır | Hayır |
| Router push | Evet | Evet | Evet | Evet |

Paket middleware kayıtlarında hem Kernel hem Router yolunu denediği için 9-12 arası tek kod yüzeyiyle çalışır.
