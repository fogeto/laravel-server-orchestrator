# Beklenen Ciktilar

Bu dokuman, rehberle hizali varsayilan `/metrics` ve APM JSON yuzeyinin orneklerini icerir.

---

## Ornek `/metrics` Yaniti

```text
# HELP db_client_connections_usage Database connections by state
# TYPE db_client_connections_usage gauge
db_client_connections_usage{state="idle"} 3
db_client_connections_usage{state="used"} 2

# HELP db_client_connections_max Maximum pool connections
# TYPE db_client_connections_max gauge
db_client_connections_max 151

# HELP db_client_connections_pending_requests Pending connection requests
# TYPE db_client_connections_pending_requests gauge
db_client_connections_pending_requests 0

# HELP http_request_duration_seconds The duration of HTTP requests processed by an ASP.NET Core application.
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.001"} 0
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.002"} 2
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.004"} 6
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.008"} 11
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="+Inf"} 12
http_request_duration_seconds_sum{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 0.041
http_request_duration_seconds_count{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12

# HELP http_requests_in_progress The number of requests currently in progress in the ASP.NET Core pipeline. One series without controller/action label values counts all in-progress requests, with separate series existing for each controller-action pair.
# TYPE http_requests_in_progress gauge
http_requests_in_progress{method="GET",controller="UserController",action="index",endpoint="/api/users"} 1

# HELP http_requests_received_total Provides the count of HTTP requests that have been processed by the ASP.NET Core pipeline.
# TYPE http_requests_received_total counter
http_requests_received_total{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12
http_requests_received_total{code="404",method="GET",controller="",action="",endpoint="/api/nonexistent"} 3

# HELP sql_query_duration_seconds SQL query execution duration
# TYPE sql_query_duration_seconds histogram
sql_query_duration_seconds_bucket{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",query="SELECT * FROM users WHERE id = ?",le="0.005"} 3
sql_query_duration_seconds_bucket{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",query="SELECT * FROM users WHERE id = ?",le="0.01"} 4
sql_query_duration_seconds_bucket{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",query="SELECT * FROM users WHERE id = ?",le="+Inf"} 4
sql_query_duration_seconds_sum{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",query="SELECT * FROM users WHERE id = ?"} 0.019
sql_query_duration_seconds_count{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",query="SELECT * FROM users WHERE id = ?"} 4

# HELP sql_query_errors_total SQL query error count
# TYPE sql_query_errors_total counter
sql_query_errors_total{query_hash="965741b42e0fe1e4",operation="INSERT",table="users"} 2
```

Notlar:

- `db_client_*` metricleri MySQL baglanti metrikleri okunabildiginde gorunur.
- `http_requests_total`, `http_errors_total`, `db_connections_*`, `php_info`, `process_*`, `php_opcache_*` ve `app_health_status` varsayilan ciktida yer almaz.
- Histogram bucket dizileri config uzerinden degistirilebilir; yukaridaki ornek varsayilanlari gosterir.

---

## Ornek APM JSON Yaniti

`GET /__apm/errors` veya `GET /apm/errors`

```json
[
  {
    "id": "0f7b86af-7b7a-4c25-8b9a-b4f8337bd5d2",
    "timestamp": "2026-04-16T11:45:12.431Z",
    "path": "/api/orders",
    "method": "POST",
    "statusCode": 400,
    "errorType": "Bad Request",
    "message": "{\"errors\":{\"email\":[\"The email field is required.\"]}}",
    "requestBody": "{\"customerId\":42}",
    "responseBody": "{\"errors\":{\"email\":[\"The email field is required.\"]}}",
    "requestHeaders": {
      "content-type": "application/json",
      "authorization": "***REDACTED***"
    },
    "responseHeaders": {
      "content-type": "application/json"
    },
    "durationMs": 18.27,
    "clientIp": "127.0.0.1",
    "userAgent": "Mozilla/5.0",
    "queryString": ""
  }
]
```

Davranis notlari:

- Production'da izin verilmeyen IP icin yanit `[]` govdeli `403` olur.
- Endpoint varsayilan olarak sadece incoming event'leri listeler.
- Header maskeleme degeri `***REDACTED***` seklindedir.
- Uzun body ve message alanlari limitte duz kesilir.

---

## DB Metricleri Yoksa

MySQL disi bir surucu kullaniliyorsa veya DB baglantisi okunamiyorsa `db_client_*` metricleri cikmayabilir. Bu durumda `/metrics` yaniti yine HTTP ve SQL metriclerini icermeye devam eder.
