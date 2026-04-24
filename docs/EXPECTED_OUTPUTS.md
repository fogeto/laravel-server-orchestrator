# Beklenen Çıktılar

Bu doküman varsayılan `/metrics` ve `/apm/errors` yüzeyinin örneklerini içerir.

## Örnek `/metrics` yanıtı

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

# HELP http_request_duration_seconds The duration of HTTP requests processed by the Laravel application.
# TYPE http_request_duration_seconds histogram
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.001"} 0
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.002"} 2
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="0.004"} 6
http_request_duration_seconds_bucket{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users",le="+Inf"} 12
http_request_duration_seconds_sum{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 0.041
http_request_duration_seconds_count{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12

# HELP http_requests_in_progress The number of HTTP requests currently in progress in the Laravel application.
# TYPE http_requests_in_progress gauge
http_requests_in_progress{method="GET",controller="UserController",action="index",endpoint="/api/users"} 1

# HELP http_requests_received_total The total number of HTTP requests processed by the Laravel application.
# TYPE http_requests_received_total counter
http_requests_received_total{code="200",method="GET",controller="UserController",action="index",endpoint="/api/users"} 12

# HELP sql_query_duration_seconds SQL query execution duration
# TYPE sql_query_duration_seconds histogram
sql_query_duration_seconds_bucket{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",le="0.005"} 3
sql_query_duration_seconds_bucket{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",le="0.01"} 4
sql_query_duration_seconds_bucket{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users",le="+Inf"} 4
sql_query_duration_seconds_sum{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users"} 0.019
sql_query_duration_seconds_count{query_hash="4db9851d7f6d6f3e",operation="SELECT",table="users"} 4

# HELP sql_query_errors_total SQL query error count
# TYPE sql_query_errors_total counter
sql_query_errors_total{query_hash="965741b42e0fe1e4",operation="INSERT",table="users"} 2
```

Notlar:

- `ORCHESTRATOR_SQL_QUERY_LABEL=true` yapılırsa SQL duration sample'larına `query` label'ı da eklenir.
- `db_client_*` metricleri DB bağlantısı okunabildiğinde görünür.
- Varsayılan çıktı eski `http_requests_total`, `http_errors_total` ve `system` ailelerini içermez.

## Örnek APM JSON yanıtı

`GET /apm/errors?limit=50`

```json
[
  {
    "id": "0f7b86af-7b7a-4c25-8b9a-b4f8337bd5d2",
    "timestamp": "2026-04-24T11:45:12.431Z",
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

Davranış notları:

- Event'ler MongoDB `ApmErrors` collection'ından okunur.
- Varsayılan sıralama en yeniden eskiyedir.
- Üretim ortamında whitelist dışı IP için yanıt `[]` gövdeli `403` olur.
- Body ve message alanları limitte düz kesilir.

## DB metricleri yoksa

MySQL dışı bir sürücü kullanılıyorsa veya bağlantı okunamıyorsa `db_client_*` metricleri görünmeyebilir. Bu durumda `/metrics` yine HTTP ve SQL metriclerini döndürür.
