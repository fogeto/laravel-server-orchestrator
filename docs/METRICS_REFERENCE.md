# Metrik Referansı

Bu doküman paketin topladığı tüm metriklerin detaylı referansıdır.

---

## HTTP Metrikleri (PrometheusMiddleware)

Bu metrikler her HTTP isteğinde otomatik olarak kaydedilir.

### http_request_duration_seconds

| Alan | Değer |
|------|-------|
| Tip | Histogram |
| Namespace | `http` |
| Ad | `request_duration_seconds` |
| Açıklama | The duration of HTTP requests processed by a Laravel application. |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |

**Bucket sınırları (saniye):**
```
0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0
```

> Config'den değiştirilebilir: `server-orchestrator.histogram_buckets`

**Label detayları:**

| Label | Açıklama | Örnek Değer |
|-------|----------|-------------|
| `code` | HTTP status kodu (string) | `"200"`, `"404"`, `"500"` |
| `method` | HTTP metodu | `"GET"`, `"POST"`, `"PUT"`, `"DELETE"` |
| `controller` | Controller sınıf adı (class_basename) | `"UserController"`, `""` (closure) |
| `action` | Controller metot adı | `"index"`, `"store"`, `""` (closure) |
| `endpoint` | Normalize edilmiş URI path | `"/api/users/{user}"`, `"/api/admin/login"` |

**Endpoint normalizasyonu:**
- Route tanımlıysa → `$route->uri()` kullanılır (ör. `api/users/{user}`)
- Route yoksa:
  - UUID'ler → `{uuid}` (ör. `550e8400-e29b-41d4-a716-446655440000` → `{uuid}`)
  - Sayısal ID'ler → `{id}` (ör. `/users/42` → `/users/{id}`)
- Path her zaman `/` ile başlar

---

### http_requests_total

| Alan | Değer |
|------|-------|
| Tip | Counter |
| Namespace | `http` |
| Ad | `requests_total` |
| Açıklama | Total number of HTTP requests. |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |

Tüm HTTP isteklerini sayar. Label'lar `http_request_duration_seconds` ile aynıdır.

---

### http_errors_total

| Alan | Değer |
|------|-------|
| Tip | Counter |
| Namespace | `http` |
| Ad | `errors_total` |
| Açıklama | Total number of HTTP errors (4xx and 5xx). |
| Label'lar | `code`, `method`, `controller`, `action`, `endpoint` |

Sadece status kodu **≥ 400** olan isteklerde kaydedilir. 1xx, 2xx, 3xx istekleri bu metrikte görünmez.

---

## Sistem Metrikleri (MetricsController)

Bu metrikler `GET /metrics` çağrıldığında anlık olarak toplanır (gauge). Her scrape'de güncel değerleri yansıtır.

### php_info

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php` |
| Ad | `info` |
| Açıklama | PHP environment information |
| Label'lar | `version` |
| Config | `system_metrics.php_info` |

PHP sürümünü label olarak taşır. Değeri her zaman `1`'dir.  
Prometheus'ta `php_info{version="8.3.14"} 1` şeklinde görünür.

---

### process_uptime_seconds

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `process` |
| Ad | `uptime_seconds` |
| Açıklama | Process uptime in seconds |
| Label'lar | — |
| Config | `system_metrics.uptime` |

`microtime(true) - LARAVEL_START` formülü ile hesaplanır.  
PHP-FPM altında her istek yeni process olduğu için düşük değerler normaldir.  
Octane/Swoole altında gerçek process uptime'ını gösterir.

---

### process_memory_usage_bytes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `process` |
| Ad | `memory_usage_bytes` |
| Açıklama | Current memory usage in bytes |
| Label'lar | — |
| Config | `system_metrics.memory` |

`memory_get_usage(true)` değeri. İşletim sisteminden ayrılmış toplam bellek.

---

### process_memory_peak_bytes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `process` |
| Ad | `memory_peak_bytes` |
| Açıklama | Peak memory usage in bytes |
| Label'lar | — |
| Config | `system_metrics.memory` |

`memory_get_peak_usage(true)` değeri. Process süresince ulaşılan en yüksek bellek kullanımı.

---

### db_connections_active

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `db` |
| Ad | `connections_active` |
| Açıklama | Active database connections |
| Label'lar | — |
| Config | `system_metrics.database` |
| Önkoşul | MySQL/MariaDB, DB erişilebilir |

MySQL `SHOW STATUS LIKE 'Threads_connected'` sorgusundan alınır.  
PostgreSQL veya SQLite kullanan projelerde bu metrik üretilmez (sessizce atlanır).

---

### db_connections_max

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `db` |
| Ad | `connections_max` |
| Açıklama | Maximum database connection limit |
| Label'lar | — |
| Config | `system_metrics.database` |
| Önkoşul | MySQL/MariaDB, DB erişilebilir |

MySQL `SHOW VARIABLES LIKE 'max_connections'` sorgusundan alınır.

**Kullanışlı PromQL:**
```promql
# Bağlantı kullanım oranı (%)
db_connections_active / db_connections_max * 100
```

---

### php_opcache_enabled

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php` |
| Ad | `opcache_enabled` |
| Açıklama | OPcache is enabled (1=yes, 0=no) |
| Label'lar | — |
| Config | `system_metrics.opcache` |

`opcache_get_status()` fonksiyonu yoksa veya `false` dönerse bu metrik üretilmez.

---

### php_opcache_hit_rate

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php` |
| Ad | `opcache_hit_rate` |
| Açıklama | OPcache hit rate percentage |
| Label'lar | — |
| Config | `system_metrics.opcache` |

OPcache aktifse `opcache_statistics.opcache_hit_rate` değeri. 0-100 arası yüzde.  
%95+ normal kabul edilir. Düşük değeri production'da cache problemi olduğunu gösterir.

---

### php_opcache_memory_used_bytes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php` |
| Ad | `opcache_memory_used_bytes` |
| Açıklama | OPcache memory usage in bytes |
| Label'lar | — |
| Config | `system_metrics.opcache` |

OPcache aktifse `memory_usage.used_memory` değeri.

---

### app_health_status

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `app` |
| Ad | `health_status` |
| Açıklama | Application health status (1=UP, 0=DOWN) |
| Label'lar | — |
| Config | `system_metrics.health` |

Uygulama sağlık durumunu gösterir:
- `1` = DB bağlantısı başarılı (UP)
- `0` = DB bağlantısı başarısız (DOWN)

Kontrol akışı:
1. `isDbReachable()` → `fsockopen()` ile TCP port kontrolü (2s timeout)
2. TCP başarılıysa → `DB::connection()->getPdo()` ile gerçek bağlantı testi
3. Herhangi biri başarısızsa → `0`

---

## Ignore Edilen Path'ler

Config'deki `middleware.ignore_paths` listesindeki path'ler HTTP metriklerine dahil edilmez:

| Default Ignore Path | Açıklama |
|---------------------|----------|
| `api/metrics` | Metrics endpoint kendisi |
| `metrics` | Prefix'siz metrics |
| `api/wipe-metrics` | Wipe endpoint |
| `wipe-metrics` | Prefix'siz wipe |
| `telescope/*` | Laravel Telescope (wildcard) |
| `horizon/*` | Laravel Horizon (wildcard) |

Wildcard (`*`) desteği `fnmatch()` fonksiyonu ile sağlanır.

---

## Metrik Yaşam Döngüsü

```
1. Middleware: HTTP metrikleri Redis'e yazılır
   └─ Counter: HINCRBYFLOAT → atom, kayıp yok
   └─ Histogram: HINCRBY + HINCRBYFLOAT → atom, kayıp yok

2. GET /metrics: Sistem metrikleri anlık toplanır
   └─ Gauge'lar: Anı yansıtır, Redis'te saklanır
   └─ Redis'ten collect() → RenderTextFormat

3. POST /wipe-metrics: Tüm veriler sıfırlanır
   └─ Lua script ile tek seferde silme
   └─ Sadece bu proje prefix'ine ait key'ler silinir
```

**Dikkat:** Sistem metrikleri (gauge) her scrape'de yeniden üretilir. HTTP metrikleri (counter/histogram) kümülatiftir ve ancak wipe ile sıfırlanır.
