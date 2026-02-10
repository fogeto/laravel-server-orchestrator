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
0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5
```

> Config'den değiştirilebilir: `server-orchestrator.http_histogram_buckets`

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

## SQL Sorgu Metrikleri (ServiceProvider — DB::listen)

Bu metrikler `DB::listen` ile otomatik olarak her SQL sorgusu çalıştığında kaydedilir.

### sql_query_duration_seconds

| Alan | Değer |
|------|-------|
| Tip | Histogram |
| Namespace | `sql` |
| Ad | `query_duration_seconds` |
| Açıklama | Duration of SQL queries in seconds. |
| Label'lar | `operation`, `table`, `query`, `query_hash` |
| Config | `sql_metrics.enabled` |

**Bucket sınırları (saniye):**
```
0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10
```

> Config'den değiştirilebilir: `server-orchestrator.sql_histogram_buckets`

**Label detayları:**

| Label | Açıklama | Örnek Değer |
|-------|----------|-------------|
| `operation` | SQL işlem türü (regex ile tespit) | `"SELECT"`, `"INSERT"`, `"UPDATE"`, `"DELETE"` |
| `table` | Etkilenen ana tablo adı | `"users"`, `"orders"`, `"unknown"` |
| `query` | Binding'leri yerleştirilmiş SQL sorgusu | `"SELECT * FROM users WHERE id = 1"` |
| `query_hash` | Sorgu metninin MD5 hash'i | `"a1b2c3d4e5f6..."` |

**SQL Operation tespiti:**
- `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `TRUNCATE`, `REPLACE` desteklenir
- Tanınmayan sorgular `OTHER` olarak etiketlenir

**Yok sayılan sorgular (varsayılan):**
- `SHOW ...` (MySQL sistem sorguları)
- `SET ...` (oturum ayarları)
- `DESCRIBE ...` / `EXPLAIN ...`
- `information_schema` içeren sorgular

> Config: `server-orchestrator.sql_metrics.ignore_patterns`

**Uyarı:** `query` label'ı yüksek kardinaliteye neden olabilir. Production'da `sql_metrics.include_query_label` ayarını `false` yaparak devre dışı bırakabilirsiniz.

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

### db_client_connections_usage

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `db_client` |
| Ad | `connections_usage` |
| Açıklama | Database connections by state |
| Label'lar | `state` |
| Config | `system_metrics.database` |
| Önkoşul | MySQL/MariaDB veya PostgreSQL, DB erişilebilir |

**State değerleri:**
- `state="idle"` → Bağlı ama aktif sorgu çalıştırmayan thread'ler (MySQL: `Threads_connected - Threads_running`)
- `state="used"` → Aktif olarak sorgu çalıştıran thread'ler (MySQL: `Threads_running`)

PostgreSQL'de `pg_stat_activity` tablosundan okunur.

---

### db_client_connections_max

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `db_client` |
| Ad | `connections_max` |
| Açıklama | Maximum pool connections |
| Label'lar | — |
| Config | `system_metrics.database` |
| Önkoşul | MySQL/MariaDB veya PostgreSQL, DB erişilebilir |

MySQL: `SHOW VARIABLES LIKE 'max_connections'`  
PostgreSQL: `SHOW max_connections`

---

### db_client_connections_pending_requests

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `db_client` |
| Ad | `connections_pending_requests` |
| Açıklama | Pending connection requests |
| Label'lar | — |
| Config | `system_metrics.database` |

Bekleyen bağlantı isteklerini gösterir. MySQL'de doğrudan bir karşılığı olmadığından varsayılan `0` değeri kullanılır.

**Kullanışlı PromQL:**
```promql
# Bağlantı kullanım oranı (%)
db_client_connections_usage{state="used"} / db_client_connections_max * 100
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

### php_fpm_active_processes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `active_processes` |
| Açıklama | Number of active PHP-FPM worker processes |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |
| Önkoşul | PHP-FPM status endpoint erişilebilir |

Aktif olarak istek işleyen PHP-FPM worker sayısı.

---

### php_fpm_idle_processes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `idle_processes` |
| Açıklama | Number of idle PHP-FPM worker processes |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

Boşta bekleyen PHP-FPM worker sayısı.

---

### php_fpm_total_processes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `total_processes` |
| Açıklama | Total number of PHP-FPM worker processes |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

Toplam PHP-FPM worker sayısı (active + idle).

---

### php_fpm_max_active_processes

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `max_active_processes` |
| Açıklama | Maximum number of active processes since FPM started |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

FPM başladığından beri görülen en yüksek aktif worker sayısı (peak).

---

### php_fpm_accepted_connections

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `accepted_connections` |
| Açıklama | Total number of accepted connections |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

FPM başladığından beri kabul edilen toplam bağlantı sayısı.

---

### php_fpm_listen_queue

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `listen_queue` |
| Açıklama | Number of requests in the listen queue |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

Kuyrukta bekleyen istek sayısı. Bu değer > 0 ise worker'lar yetersiz demektir.

---

### php_fpm_max_listen_queue

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `max_listen_queue` |
| Açıklama | Maximum number of requests in the listen queue since FPM started |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

FPM başladığından beri görülen en yüksek kuyruk uzunluğu (peak).

---

### php_fpm_slow_requests

| Alan | Değer |
|------|-------|
| Tip | Gauge |
| Namespace | `php_fpm` |
| Ad | `slow_requests` |
| Açıklama | Total number of slow requests |
| Label'lar | — |
| Config | `system_metrics.fpm` + `fpm.enabled` |

PHP-FPM'in `request_slowlog_timeout` ayarına göre yavaş kabul edilen istekler.

**Kullanışlı PromQL:**
```promql
# Worker kullanım oranı (%)
php_fpm_active_processes / php_fpm_total_processes * 100

# Kuyruk alarmı (>0 ise worker yetersiz)
php_fpm_listen_queue > 0
```

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
1. Middleware (terminate): HTTP metrikleri response sonrası Redis'e yazılır
   └─ Counter: HINCRBYFLOAT → atom, kayıp yok
   └─ Histogram: HINCRBY + HINCRBYFLOAT → atom, kayıp yok

2. ServiceProvider (DB::listen): SQL sorgu metrikleri her query'de Redis'e yazılır
   └─ Histogram: operation, table, query, query_hash label'ları ile
   └─ Milisaniye → saniye dönüşümü otomatik

3. GET /metrics: Sistem metrikleri anlık toplanır
   └─ Gauge'lar: Anı yansıtır, Redis'te saklanır
   └─ PHP-FPM: status endpoint'ınden HTTP ile alınır
   └─ Redis'ten collect() → RenderTextFormat

4. POST /wipe-metrics: Tüm veriler sıfırlanır
   └─ Lua script ile tek seferde silme
   └─ Sadece bu proje prefix'ine ait key'ler silinir
```

**Dikkat:** Sistem metrikleri (gauge) her scrape'de yeniden üretilir. HTTP metrikleri (counter/histogram) kümülatiftir ve ancak wipe ile sıfırlanır.
