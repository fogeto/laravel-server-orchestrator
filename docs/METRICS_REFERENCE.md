# Metrik Referansı

Bu doküman paketin varsayılan metric ve APM yüzeyini listeler.

## HTTP metrikleri

### http_request_duration_seconds

| Alan | Değer |
|------|-------|
| Tip | Histogram |
| Namespace | `http` |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |
| Açıklama | The duration of HTTP requests processed by the Laravel application. |

Bucket sınırları:

```
0.001, 0.002, 0.004, 0.008, 0.016, 0.032, 0.064, 0.128,
0.256, 0.512, 1.024, 2.048, 4.096, 8.192, 16.384, 32.768
```

### http_requests_received_total

| Alan | Değer |
|------|-------|
| Tip | Counter |
| Namespace | `http` |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |
| Açıklama | The total number of HTTP requests processed by the Laravel application. |

### http_requests_in_progress

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `http` |
| Label'lar | `method`, `controller`, `action`, `endpoint` |
| Açıklama | The number of HTTP requests currently in progress in the Laravel application. |

Endpoint normalizasyonu:

- Route tanımlıysa `route()->uri()` kullanılır.
- Sayısal segmentler `{id}` olur.
- UUID segmentleri `{uuid}` olur.
- Sonuç her zaman `/` ile başlar.

## SQL metrikleri

### sql_query_duration_seconds

| Alan | Değer |
|------|-------|
| Tip | Histogram |
| Namespace | `sql` |
| Varsayılan label'lar | `query_hash`, `operation`, `table` |
| Opsiyonel label | `query` (`ORCHESTRATOR_SQL_QUERY_LABEL=true`) |
| Açıklama | SQL query execution duration |

Bucket sınırları:

```
0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0
```

Normalizasyon kuralları:

- Tek tırnaklı string literal'ler `?` ile değiştirilir.
- Sayısal literal'ler `?` ile değiştirilir.
- Fazla boşluklar tek boşluğa indirilir.
- `query_hash`, normalize edilmiş SQL'in SHA-256 hash'inin ilk 16 karakteridir.
- Varsayılan ignore pattern'ları HangFire ve `information_schema` sorgularını atlar.
- `max_unique_queries` varsayılanı `100`'dür.

### sql_query_errors_total

| Alan | Değer |
|------|-------|
| Tip | Counter |
| Namespace | `sql` |
| Label'lar | `query_hash`, `operation`, `table` |
| Açıklama | SQL query error count |

Bu metric `QueryException` exception handler üzerinden raporlandığında artar.

## DB client metrikleri

Bu gauge'lar `GET /metrics` sırasında anlık hesaplanır.

### db_client_connections_max

Maximum pool connections

### db_client_connections_usage

Database connections by state

Label: `state` (`idle`, `used`)

### db_client_connections_pending_requests

Pending connection requests

Notlar:

- Laravel tarafında gerçek pool pending metriği gözlemlenemediği için varsayılan çıktı `0` yayınlar.
- MySQL `Threads_connected`, `Threads_running` ve `max_connections` değerleri kullanılır.

## APM hata endpoint'leri

Kayıt edilen endpoint'ler:

- `GET /__apm/errors`
- `GET /apm/errors`

Davranış:

- JSON array döner.
- `?limit=N` destekler. Varsayılan `200`, üst sınır `500`.
- Production ortamında IP whitelist uygulanır.
- Localhost IP'leri her zaman izinlidir.
- Varsayılan listede sadece incoming event'ler görünür.
- Event'ler MongoDB `ApmErrors` collection'ında tutulur.
- TTL index süresi varsayılan `604800` saniyedir, yani 7 gün.
- `ext-mongodb` veya Mongo config yoksa persistence sessizce devre dışı kalır ve endpoint boş array döner.
- `Content-Length > 5MB` veya `multipart/form-data` isteklerde body capture yapılmaz.

## Storage notu

Metrics storage driver:

- `redis`: FPM için güvenli varsayılan
- `in_memory`: uzun ömürlü runtime'lar için .NET'e daha yakın davranış

## Varsayılan yüzeyde olmayanlar

- `http_requests_total`
- `http_errors_total`
- `db_connections_active`
- `db_connections_max`
- `php_info`
- `process_*`
- `php_opcache_*`
- `app_health_status`
