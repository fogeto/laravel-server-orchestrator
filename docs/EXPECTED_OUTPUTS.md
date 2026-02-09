# Beklenen Çıktılar

Bu doküman, `GET /metrics` endpoint'inin döndürdüğü Prometheus text format çıktısının tam örneklerini içerir.

---

## Tam Örnek Çıktı

Aşağıda bir Laravel projesi (prefix: `ikbackend`) için tipik bir `/api/metrics` yanıtı gösterilmektedir:

```
# HELP app_health_status Application health status (1=UP, 0=DOWN)
# TYPE app_health_status gauge
app_health_status 1

# HELP db_connections_active Active database connections
# TYPE db_connections_active gauge
db_connections_active 5

# HELP db_connections_max Maximum database connection limit
# TYPE db_connections_max gauge
db_connections_max 151

# HELP http_errors_total Total number of HTTP errors (4xx and 5xx).
# TYPE http_errors_total counter
http_errors_total{code="404",method="GET",controller="",action="",endpoint="/api/nonexistent"} 3
http_errors_total{code="500",method="POST",controller="PaymentController",action="charge",endpoint="/api/payments/charge"} 1

# HELP http_request_duration_seconds The duration of HTTP requests processed by a Laravel application.
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.005"} 0
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.01"} 2
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.025"} 8
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.05"} 15
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.1"} 20
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.25"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.5"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="1"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="2.5"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="5"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="10"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="30"} 22
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="+Inf"} 22
http_request_duration_seconds_sum{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 0.532
http_request_duration_seconds_count{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 22
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.005"} 0
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.01"} 0
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.025"} 1
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.05"} 3
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.1"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.25"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="0.5"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="1"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="2.5"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="5"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="10"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="30"} 5
http_request_duration_seconds_bucket{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users",le="+Inf"} 5
http_request_duration_seconds_sum{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users"} 0.187
http_request_duration_seconds_count{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users"} 5

# HELP http_requests_total Total number of HTTP requests.
# TYPE http_requests_total counter
http_requests_total{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 22
http_requests_total{code="201",method="POST",controller="UserController",action="store",endpoint="/api/users"} 5
http_requests_total{code="404",method="GET",controller="",action="",endpoint="/api/nonexistent"} 3
http_requests_total{code="500",method="POST",controller="PaymentController",action="charge",endpoint="/api/payments/charge"} 1

# HELP php_info PHP environment information
# TYPE php_info gauge
php_info{version="8.3.14"} 1

# HELP php_opcache_enabled OPcache is enabled (1=yes, 0=no)
# TYPE php_opcache_enabled gauge
php_opcache_enabled 1

# HELP php_opcache_hit_rate OPcache hit rate percentage
# TYPE php_opcache_hit_rate gauge
php_opcache_hit_rate 98.72

# HELP php_opcache_memory_used_bytes OPcache memory usage in bytes
# TYPE php_opcache_memory_used_bytes gauge
php_opcache_memory_used_bytes 52428800

# HELP process_memory_peak_bytes Peak memory usage in bytes
# TYPE process_memory_peak_bytes gauge
process_memory_peak_bytes 33554432

# HELP process_memory_usage_bytes Current memory usage in bytes
# TYPE process_memory_usage_bytes gauge
process_memory_usage_bytes 20971520

# HELP process_uptime_seconds Process uptime in seconds
# TYPE process_uptime_seconds gauge
process_uptime_seconds 0.245
```

---

## Response Detayları

| Alan | Değer |
|------|-------|
| HTTP Status | `200 OK` |
| Content-Type | `text/plain; version=0.0.4; charset=utf-8` |
| Encoding | UTF-8 |

---

## Metrik Çıktı Formatı Açıklaması

Her metrik grubu 3 satırla başlar:

```
# HELP metrik_adı Açıklama metni
# TYPE metrik_adı tip (gauge|counter|histogram|summary)
metrik_adı{label1="değer1",label2="değer2"} sayısal_değer
```

### Histogram Özel Formatı

Histogram'lar 3 alt metrik üretir:

```
# Bucket'lar (kümülatif — her bucket kendinden küçük tüm değerleri içerir)
metrik_bucket{...,le="0.005"} 0     ← 5ms altı istek sayısı
metrik_bucket{...,le="0.01"} 2      ← 10ms altı istek sayısı
metrik_bucket{...,le="0.025"} 8     ← 25ms altı istek sayısı
...
metrik_bucket{...,le="+Inf"} 22     ← Tüm istekler (= count)

# Toplam süre
metrik_sum{...} 0.532                ← Tüm isteklerin toplam süresi (saniye)

# Toplam sayı
metrik_count{...} 22                 ← Toplam istek sayısı
```

**Kümülatif bucket kuralı:** `le="0.025"` bucket'ı, `le="0.005"` ve `le="0.01"` bucket'larındaki istekleri de içerir. Yani bucket değerleri her zaman artmalıdır (veya eşit kalmalıdır).

---

## Wipe Endpoint Çıktısı

### İstek

```http
POST /api/wipe-metrics HTTP/1.1
Host: localhost:8000
```

### Yanıt

```json
{
    "success": true,
    "message": "All metrics have been wiped."
}
```

| Alan | Değer |
|------|-------|
| HTTP Status | `200 OK` |
| Content-Type | `application/json` |

**Wipe sonrası `/api/metrics` çıktısı:**

Sadece anlık gauge sistem metrikleri görünür (çünkü her scrape'de yeniden toplanır). Counter ve histogram verileri sıfırdan başlar — trafik geldikçe tekrar birikir.

```
# HELP app_health_status Application health status (1=UP, 0=DOWN)
# TYPE app_health_status gauge
app_health_status 1

# HELP php_info PHP environment information
# TYPE php_info gauge
php_info{version="8.3.14"} 1

# HELP process_memory_peak_bytes Peak memory usage in bytes
# TYPE process_memory_peak_bytes gauge
process_memory_peak_bytes 16777216

# HELP process_memory_usage_bytes Current memory usage in bytes
# TYPE process_memory_usage_bytes gauge
process_memory_usage_bytes 12582912

# HELP process_uptime_seconds Process uptime in seconds
# TYPE process_uptime_seconds gauge
process_uptime_seconds 0.089
```

---

## DB Erişilemez Durumunda Çıktı

Veritabanı erişilemezse (ör. MySQL kapalı):

```
# HELP app_health_status Application health status (1=UP, 0=DOWN)
# TYPE app_health_status gauge
app_health_status 0

# HELP php_info PHP environment information
# TYPE php_info gauge
php_info{version="8.3.14"} 1

# db_connections_active → GÖRÜNMEZ (toplanmaz)
# db_connections_max → GÖRÜNMEZ (toplanmaz)

# HELP process_memory_usage_bytes Current memory usage in bytes
# TYPE process_memory_usage_bytes gauge
process_memory_usage_bytes 14680064

# ... diğer gauge'lar normal
```

**Dikkat:** `db_connections_active` ve `db_connections_max` metrikleri tamamen kaybolur — `0` değil, hiç üretilmez. Bu, Prometheus'ta `absent()` fonksiyonu ile alert kurmanızı sağlar:

```promql
# DB metrikleri kaybolursa uyar
absent(db_connections_active) == 1
```

---

## Prometheus Scrape Konfigürasyonu

`prometheus.yml` dosyası:

```yaml
scrape_configs:
  # Tek proje
  - job_name: 'ikbackend'
    scrape_interval: 15s
    metrics_path: '/api/metrics'
    static_configs:
      - targets: ['192.168.1.100:8000']
        labels:
          project: 'ikbackend'
          environment: 'production'

  # Birden fazla proje — aynı Redis, farklı prefix
  - job_name: 'crm'
    scrape_interval: 15s
    metrics_path: '/api/metrics'
    static_configs:
      - targets: ['192.168.1.101:8000']
        labels:
          project: 'crm'
          environment: 'production'
```

---

## Grafana PromQL Örnekleri

### İstek Oranı (req/s)

```promql
rate(http_requests_total{job="ikbackend"}[5m])
```

### Ortalama Yanıt Süresi

```promql
rate(http_request_duration_seconds_sum{job="ikbackend"}[5m])
/
rate(http_request_duration_seconds_count{job="ikbackend"}[5m])
```

### P95 Latency

```promql
histogram_quantile(0.95, rate(http_request_duration_seconds_bucket{job="ikbackend"}[5m]))
```

### P99 Latency

```promql
histogram_quantile(0.99, rate(http_request_duration_seconds_bucket{job="ikbackend"}[5m]))
```

### Hata Oranı (%)

```promql
rate(http_errors_total{job="ikbackend"}[5m])
/
rate(http_requests_total{job="ikbackend"}[5m])
* 100
```

### En Yavaş Endpoint'ler

```promql
topk(5,
  rate(http_request_duration_seconds_sum{job="ikbackend"}[5m])
  /
  rate(http_request_duration_seconds_count{job="ikbackend"}[5m])
)
```

### DB Bağlantı Kullanım Oranı

```promql
db_connections_active{job="ikbackend"}
/
db_connections_max{job="ikbackend"}
* 100
```

### Sağlık Durumu Alert

```promql
# 5 dakikadır DOWN ise uyar
app_health_status{job="ikbackend"} == 0
```

### Bellek Kullanım Trendi

```promql
process_memory_usage_bytes{job="ikbackend"} / 1024 / 1024
```

---

## Redis Key Kontrolü

Metriklerin Redis'e doğru yazılıp yazılmadığını kontrol etmek için:

```bash
# Redis CLI ile bağlan
redis-cli

# Tüm prometheus key'lerini listele
KEYS *prometheus:ikbackend:*

# Beklenen çıktı:
# 1) "laravel_database_prometheus:ikbackend:gauges:meta"
# 2) "laravel_database_prometheus:ikbackend:gauges:php_info"
# 3) "laravel_database_prometheus:ikbackend:gauges:process_uptime_seconds"
# 4) "laravel_database_prometheus:ikbackend:gauges:process_memory_usage_bytes"
# 5) "laravel_database_prometheus:ikbackend:gauges:process_memory_peak_bytes"
# 6) "laravel_database_prometheus:ikbackend:gauges:app_health_status"
# 7) "laravel_database_prometheus:ikbackend:counters:meta"
# 8) "laravel_database_prometheus:ikbackend:counters:http_requests_total"
# 9) "laravel_database_prometheus:ikbackend:counters:http_errors_total"
# 10) "laravel_database_prometheus:ikbackend:histograms:meta"
# 11) "laravel_database_prometheus:ikbackend:histograms:http_request_duration_seconds"

# Bir counter hash'inin içeriğine bak
HGETALL laravel_database_prometheus:ikbackend:counters:http_requests_total
# Field'lar base64 encode edilmiş label değerleri
# Value'lar sayısal değerlerdir

# Meta bilgisini kontrol et
HGET laravel_database_prometheus:ikbackend:counters:meta http_requests_total
# {"name":"http_requests_total","help":"Total number of HTTP requests.","labelNames":["code","method","controller","action","endpoint"]}
```
