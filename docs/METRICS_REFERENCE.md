# Metrik Referansi

Bu dokuman, paketin rehberle hizali varsayilan metric yuzeyini listeler.

---

## HTTP Metrikleri

### http_request_duration_seconds

| Alan | Deger |
|------|-------|
| Tip | Histogram |
| Namespace | `http` |
| Ad | `request_duration_seconds` |
| Aciklama | The duration of HTTP requests processed by an ASP.NET Core application. |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |

Bucket sinirlari:

```
0.001, 0.002, 0.004, 0.008, 0.016, 0.032, 0.064, 0.128,
0.256, 0.512, 1.024, 2.048, 4.096, 8.192, 16.384, 32.768
```

### http_requests_received_total

| Alan | Deger |
|------|-------|
| Tip | Counter |
| Namespace | `http` |
| Ad | `requests_received_total` |
| Aciklama | Provides the count of HTTP requests that have been processed by the ASP.NET Core pipeline. |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |

### http_requests_in_progress

| Alan | Deger |
|------|-------|
| Tip | Gauge |
| Namespace | `http` |
| Ad | `requests_in_progress` |
| Aciklama | The number of requests currently in progress in the ASP.NET Core pipeline. One series without controller/action label values counts all in-progress requests, with separate series existing for each controller-action pair. |
| Label'lar | `method`, `controller`, `action`, `endpoint` |

HTTP endpoint normalizasyonu:

- Route tanimliysa `route()->uri()` kullanilir.
- Sayisal id segmentleri `{id}` olarak normalize edilir.
- UUID segmentleri `{uuid}` olarak normalize edilir.
- Sonuc her zaman `/` ile baslar.

---

## SQL Metrikleri

### sql_query_duration_seconds

| Alan | Deger |
|------|-------|
| Tip | Histogram |
| Namespace | `sql` |
| Ad | `query_duration_seconds` |
| Aciklama | SQL query execution duration |
| Label'lar | `query_hash`, `operation`, `table`, `query` |

Bucket sinirlari:

```
0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0
```

Normalizasyon kurallari:

- Tek tirnakli string literal'ler `?` ile degistirilir.
- Sayisal literal'ler `?` ile degistirilir.
- Bosluklar tek bosluga dusurulur.
- `query_hash`, normalize edilmis SQL'in SHA-256 hash'inin ilk 16 karakteridir.
- `query` label'i `query_max_length` sinirinda duz kesilir, suffix eklenmez.

### sql_query_errors_total

| Alan | Deger |
|------|-------|
| Tip | Counter |
| Namespace | `sql` |
| Ad | `query_errors_total` |
| Aciklama | SQL query error count |
| Label'lar | `query_hash`, `operation`, `table` |

Bu metric, `QueryException` uygulamanin exception handler'ina raporlandiginda artar.

---

## DB Client Metrikleri

Bu gauge'lar `GET /metrics` sirasinda anlik olarak hesaplanir.

### db_client_connections_max

| Alan | Deger |
|------|-------|
| Tip | Gauge |
| Namespace | `db_client` |
| Ad | `connections_max` |
| Aciklama | Maximum pool connections |

### db_client_connections_usage

| Alan | Deger |
|------|-------|
| Tip | Gauge |
| Namespace | `db_client` |
| Ad | `connections_usage` |
| Aciklama | Database connections by state |
| Label'lar | `state` (`idle`, `used`) |

### db_client_connections_pending_requests

| Alan | Deger |
|------|-------|
| Tip | Gauge |
| Namespace | `db_client` |
| Ad | `connections_pending_requests` |
| Aciklama | Pending connection requests |

Notlar:

- Bu metric seti MySQL `Threads_connected`, `Threads_running` ve `max_connections` degerlerinden turetilir.
- Laravel tarafinda bekleyen pool request sayisi gozlemlenmedigi icin `connections_pending_requests` su anda `0` yayinlanir.
- MySQL disi suruculerde veya baglanti hatasinda `db_client_*` metricleri uretilmeyebilir.

---

## APM Hata Endpoint'leri

Paket iki GET endpoint'i kaydeder:

- `/__apm/errors`
- `/apm/errors`

Davranis:

- Yanit JSON array'dir.
- Varsayilan olarak IP korumasi kapalidir; `ORCHESTRATOR_APM_IP_PROTECTION=true` ile acilabilir.
- IP korumasi acikken izin verilmeyen IP icin bos array ile `403` doner.
- Varsayilan yuzeyde sadece incoming hata event'leri listelenir.
- Timestamp UTC ISO-8601 formatindadir.
- Hassas request header'lari `***REDACTED***` olarak maskelenir.
- Request ve response body alanlari limitte duz kesilir, suffix eklenmez.

---

## Varsayilan Yuzeyde Olmayanlar

Asagidaki metric aileleri artik varsayilan ciktida uretilmez:

- `http_requests_total`
- `http_errors_total`
- `db_connections_active`
- `db_connections_max`
- `php_info`
- `process_*`
- `php_opcache_*`
- `app_health_status`
