# Teknik Notlar ve Bilinen Sorunlar
# Teknik Notlar

Bu doküman mevcut implementasyonun kararlarını ve Laravel'e özgü adaptasyonlarını toplar.

## 1. Metrics storage driver seçimi

Referans .NET implementasyonu process RAM kullanır. Laravel/FPM altında request'ler process belleğini paylaşmadığı için varsayılan driver `redis` olarak bırakıldı.

Desteklenen modlar:

- `redis`: varsayılan, FPM için güvenli
- `in_memory`: uzun ömürlü runtime kullanan projeler için

Redis driver seçildiğinde `PredisAdapter` metric family'leri hash'lerde saklar ve `metrics_ttl` uygular. Laravel Redis connection kullanıldığı için `predis` ve `phpredis` client'ları desteklenir.

## 2. Redis key yapısı

Redis driver kullanıldığında key formatı:

```
{laravel_prefix}prometheus:{prefix}:{type}:{metric_name}
```

Örnek:

```
laravel_database_prometheus:ikbackend:counters:http_requests_received_total
laravel_database_prometheus:ikbackend:histograms:http_request_duration_seconds
```

## 3. APM persistence

APM event'leri `ORCHESTRATOR_APM_STORE` ayarına göre MongoDB veya Redis'e yazılır.

### Mongo store

Kararlar:

- `timestamp` alanı TTL index için BSON date olarak saklanır.
- TTL süresi config'ten gelir, varsayılan 1 gündür.
- Event insert'i response sonrası `app()->terminating()` içinde flush edilir.
- Tek request içinde en fazla `channel_capacity` kadar event queue'lanır.
- Batch insert boyutu varsayılan `50`'dir.

Mongo config yoksa veya `ext-mongodb` yüklü değilse:

- request akışı kırılmaz
- capture sessizce devre dışı kalır
- `/apm/errors` boş array döndürür

### Redis store

Redis store seçildiğinde event index'i sorted set içinde, payload'lar ise TTL'li string key'lerinde tutulur:

```
apm:{prefix}:events
apm:{prefix}:event:{id}
```

Redis config yoksa veya bağlantı kurulamazsa:

- request akışı kırılmaz
- capture sessizce devre dışı kalır
- `/apm/errors` boş array döndürür

## 4. APM capture bypass kuralları

Memory baskısını önlemek için şu isteklerde body capture yapılmaz:

- `Content-Length > 5MB`
- `Content-Type: multipart/form-data`

Request yine normal akar; sadece APM body alanları atlanır.

## 5. SQL cardinality korumaları

Varsayılan korumalar:

- `max_unique_queries = 100`
- `query` label varsayılan olarak kapalı
- HangFire ve `information_schema` sorguları filtrelenir

Amaç, `/metrics` üzerinde kontrolsüz time-series patlamasını sınırlamaktır.

## 6. DB pool metriği yaklaşımı

Laravel tarafında .NET'teki `db.client.connections.*` kaynaklarının birebir karşılığı yoktur. Bu yüzden package şu yaklaşımı kullanır:

- `Threads_connected`
- `Threads_running`
- `max_connections`

Buradan `db_client_connections_usage`, `db_client_connections_max` ve `db_client_connections_pending_requests` türetilir.

`connections_pending_requests` şu anda her zaman `0` yayınlanır.

## 7. Bilinen limitasyonlar

| Limitasyon | Açıklama |
|-----------|----------|
| PHP-FPM altında gerçek process-RAM metrics yok | Bu nedenle varsayılan driver Redis |
| Mongo persistence için ext-mongodb gerekir | Package bunu suggest eder, zorunlu kılmaz |
| DB pool metricleri yaklaşık değerdir | PDO/MySQL tam pending queue metriği sunmaz |
| Outgoing APM varsayılan yüzeyin parçası değildir | `capture_outgoing` ile opsiyonel açılabilir |
Predis gönderir: KEYS laravel_database_prometheus:ikbackend:*
Redis döndürür: ["laravel_database_prometheus:ikbackend:gauges:meta", ...]
```

**Sorun:** `KEYS` komutu sonuçlarında prefix'i **döndürür** ama **kaldırmaz**. Sonra bu key'leri `DEL` ile silmeye çalıştığınızda:

```
Uygulama: DEL laravel_database_prometheus:ikbackend:gauges:meta
Predis gönderir: DEL laravel_database_laravel_database_prometheus:ikbackend:gauges:meta
                     ^^^^^^^^^^^^^^^^^^^^
                     DOUBLE PREFIX! Key bulunamaz.
```

### Çözüm: Lua Script

Lua script Redis server tarafında çalışır. Predis prefix processor Lua script'in iç komutlarına dokunmaz.

```lua
local keys = redis.call('KEYS', ARGV[1])  -- Prefix'siz, gerçek key
local deleted = 0
for i = 1, #keys, 100 do                  -- 100'lük chunk'lar
    local chunk = {}
    for j = i, math.min(i + 99, #keys) do
        table.insert(chunk, keys[j])
    end
    deleted = deleted + redis.call('DEL', unpack(chunk))  -- Doğrudan silme
end
return deleted
```

Lua'ya pattern aktarırken **tam prefix** gerekir:

```php
$connectionPrefix = $this->getConnectionPrefix();  // 'laravel_database_'
$fullPattern = $connectionPrefix . $this->prefix . '*';
// Sonuç: 'laravel_database_prometheus:ikbackend:*'

$this->redis->eval($luaScript, 0, $fullPattern);
```

`eval()` komutuna `0` numKeys parametresi verilir — tüm argümanlar `ARGV`'ye gider, `KEYS`'e değil. Bu, Predis'in key argümanlarına prefix eklemesini engeller.

---

## 4. Prometheus `array_combine ValueError`

### Problem

`promphp/prometheus_client_php` kütüphanesinin `RenderTextFormat` sınıfı metrik render ederken:

```php
// vendor/promphp/prometheus_client_php/src/Prometheus/RenderTextFormat.php:84
$labelNames = array_merge($metric->getLabelNames(), $sample->getLabelNames());
$labelValues = $sample->getLabelValues();
$combined = array_combine($labelNames, $labelValues);
// ^^^ labelNames ve labelValues uzunlukları eşleşmezse ValueError!
```

Bu formül şunu yapar:
- `$metric->getLabelNames()` → metrik seviyesindeki label adları (ör. `['code', 'method', 'controller', 'action', 'endpoint']`)
- `$sample->getLabelNames()` → sample seviyesindeki **ek** label adları

İki diziyi `array_merge` ile birleştirir, sonra `array_combine` ile `labelValues`'la eşleştirir.

### Sample LabelNames Kuralı

| Metrik Tipi | Sample | `$sample->getLabelNames()` | Açıklama |
|-------------|--------|--------------------------|----------|
| Gauge | — | `[]` (boş dizi) | Label yok, metrik seviyesindekiler yeterli |
| Counter | — | `[]` (boş dizi) | Label yok, metrik seviyesindekiler yeterli |
| Histogram | `_bucket` | `['le']` | Bucket sınır değeri label'ı |
| Histogram | `_sum` | `[]` (boş dizi) | Ek label yok |
| Histogram | `_count` | `[]` (boş dizi) | Ek label yok |

**Eğer sample `labelNames`'e gereksiz label eklerseniz:**
```
metric labelNames: ['code', 'method'] → 2 eleman
sample labelNames: ['code', 'method'] → 2 eleman (YANLIŞ!)
array_merge: ['code', 'method', 'code', 'method'] → 4 eleman
labelValues: ['200', 'GET'] → 2 eleman
array_combine(4, 2) → ValueError!
```

---

## 5. DB Timeout Koruması

### Problem

`DB::connection()->getPdo()` çağrısı veritabanına TCP bağlantı açar. Eğer DB sunucusu erişilemez durumdaysa (ağ sorunu, sunucu kapalı), bu çağrı **30 saniye** (varsayılan MySQL connect_timeout) boyunca bloklar.

`GET /metrics` endpoint'i her Prometheus scrape'inde çağrılır (tipik: 15-30 saniye aralıkla). DB erişilemezse her scrape 30 saniye bekler ve ardışık scrape'ler birikirir.

### Çözüm: Socket-Level Pre-Check

```php
private function isDbReachable(): bool
{
    $host = config('database.connections.mysql.host', '127.0.0.1');
    $port = (int) config('database.connections.mysql.port', 3306);

    $fp = @fsockopen($host, $port, $errno, $errstr, 2);  // 2 saniye timeout

    if ($fp) {
        fclose($fp);
        return true;
    }

    return false;
}
```

**Neden `fsockopen`?**
- TCP SYN/ACK kontrolü yapar (L4 seviyesinde)
- 2 saniye timeout ile hızlı fail
- MySQL handshake yapmaz (daha hızlı)
- Socket açılabiliyorsa → MySQL muhtemelen yanıt verir

**Akış:**
```
GET /metrics
  └─ collectSystemMetrics()
       └─ isDbReachable() → fsockopen() [max 2s]
            ├─ true → collectDatabaseMetrics() + collectHealth(true)
            └─ false → database metrikleri atlanır + collectHealth(false) → 0
```

---

## 6. Middleware Dual Registration

### Problem

Laravel 9-10'da middleware Kernel üzerinden kayıt edilir. Laravel 11-12'de `Kernel.php` dosyası kaldırıldı ve middleware `bootstrap/app.php` içinde Router üzerinden tanımlanır.

Tek bir yöntemle tüm sürümleri desteklemek mümkün değil.

### Çözüm

```php
private function registerMiddleware(): void
{
    $groups = config('server-orchestrator.middleware.groups', ['api']);

    // Yöntem 1: Kernel (Laravel 9-10)
    if ($this->app->bound(Kernel::class)) {
        $kernel = $this->app->make(Kernel::class);
        foreach ($groups as $group) {
            if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                $kernel->appendMiddlewareToGroup($group, PrometheusMiddleware::class);
            }
        }
    }

    // Yöntem 2: Router (Laravel 11-12 + tüm sürümlerde yedek)
    $router = $this->app->make(\Illuminate\Routing\Router::class);
    foreach ($groups as $group) {
        $router->pushMiddlewareToGroup($group, PrometheusMiddleware::class);
    }
}
```

**Not:** Her iki yöntem de çağrılır. Laravel `$this->middleware` dizisinde duplikasyon kontrolü yapar, aynı middleware iki kez çalışmaz.

---

## 7. Prefix Sanitization

Config'den gelen prefix güvenli hale getirilir:

```php
$rawPrefix = config('server-orchestrator.prefix', 'laravel');
$sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawPrefix));
$prefix = 'prometheus:' . $sanitized . ':';
```

| Girdi | Çıktı |
|-------|-------|
| `IK Backend` | `prometheus:ik_backend:` |
| `my-app.v2` | `prometheus:my-app_v2:` |
| `CRM_PRO` | `prometheus:crm_pro:` |
| `test@app!` | `prometheus:test_app_:` |

**Kurallar:**
1. Lowercase'e çevrilir
2. `[a-z0-9_-]` dışındaki karakterler `_`'ye dönüştürülür
3. `prometheus:` prefix'i ve `:` suffix'i eklenir

---

## 8. InMemory Fallback

Redis bağlantısı başarısız olursa `Prometheus\Storage\InMemory` adapter'ı kullanılır:

```php
$this->app->singleton(CollectorRegistry::class, function () {
    try {
        // Redis bağlantısı + PredisAdapter
        $adapter = new PredisAdapter($redisConnection, $prefix);
    } catch (\Throwable $e) {
        report($e);  // Hata loglanır
        $adapter = new InMemory();  // Fallback
    }

    return new CollectorRegistry($adapter);
});
```

**Sonuçlar:**
- Metrikler sadece mevcut PHP process'inde yaşar
- PHP-FPM altında her istek ayrı process → her istek sıfırdan başlar
- Counter'lar her zaman 1 gösterir, histogram'lar tek gözlem içerir
- Uygulama çökmez ama metrikler anlamsız olur
- Log'da hata görünür

**Önerilen aksiyon:** Redis bağlantı hatası alıyorsanız `.env`'deki Redis ayarlarını kontrol edin.

---

## 9. Chunk Silme (wipeStorage)

Lua script'te `DEL` komutu tek seferde çok fazla key silerse Redis'i bloklar. Bu yüzden key'ler **100'lük chunk'lara** bölünür:

```lua
for i = 1, #keys, 100 do
    local chunk = {}
    for j = i, math.min(i + 99, #keys) do
        table.insert(chunk, keys[j])
    end
    deleted = deleted + redis.call('DEL', unpack(chunk))
end
```

**Neden 100?** Redis `unpack()` limiti Lua'da genellikle 7999 (LuaJIT) veya daha az. 100 güvenli bir değerdir ve performansı etkilemez.

---

## 10. Bilinen Limitasyonlar

| Limitasyon | Açıklama | Workaround |
|------------|----------|------------|
| Sadece MySQL veritabanı metrikleri | `SHOW STATUS`/`SHOW VARIABLES` MySQL'e özgü | PostgreSQL için connection_count sorgusu eklenebilir |
| Summary metrik tipi desteklenmiyor | `updateSummary()` boş method | Prometheus histogram ile quantile hesaplanabilir |
| KEYS komutu production'da yavaş | Lua script KEYS kullanıyor (wipe'da) | Wipe sadece bakım zamanında çağrılmalı |
| Label cardinality kontrolü yok | Sınırsız unique label kombinasyonu → Redis şişmesi | Endpoint normalizasyonu ile kısmen çözülmüş |
| Closure route'lar label'sız | controller/action boş string olur | Named route kullanılması önerilir |
| OPcache CLI'da kapalı | `opcache_get_status()` CLI'da false döner | FPM/Nginx ortamında test edin |

---

## 11. Geliştirme Kontrol Listesi

Yeni özellik eklerken:

- [ ] Label sayısı tutarlı mı? (metric labelNames.length + sample labelNames.length = labelValues.length)
- [ ] Base64 encoding'de `:` çakışması var mı?
- [ ] Redis key prefix'leri doğru mu? (`getConnectionPrefix()` + `$this->prefix`)
- [ ] `wipeStorage()` yeni key pattern'ini de temizliyor mu?
- [ ] `collect*()` yeni metrik tipini de okuyor mu?
- [ ] Config'e yeni opsiyon eklendiyse default değer var mı?
- [ ] Ignore paths yeni endpoint'i içeriyor mu?
- [ ] Laravel 9-12 uyumlu mu? (Kernel vs Router)
