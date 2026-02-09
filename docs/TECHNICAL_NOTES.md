# Teknik Notlar ve Bilinen Sorunlar

Bu doküman, paket geliştirilirken karşılaşılan kritik sorunları, alınan kararları ve edge case'leri detaylandırır. **Gelecekte bu pakete dokunacak herkes bu dokümanı okumalıdır.**

---

## 1. Redis Key Yapısı

### Key Format

```
{laravel_prefix}{prometheus_prefix}{type}:{metric_name}
```

Katmanlar:

| Katman | Kaynak | Örnek |
|--------|--------|-------|
| `laravel_prefix` | `config/database.php → redis.options.prefix` | `laravel_database_` |
| `prometheus_prefix` | `config/server-orchestrator.php → prefix` + sanitize | `prometheus:ikbackend:` |
| `type` | Metrik türü | `gauges:`, `counters:`, `histograms:` |
| `metric_name` | Prometheus metric adı | `http_request_duration_seconds` |

**Gerçek Redis key örnekleri:**
```
laravel_database_prometheus:ikbackend:gauges:php_info
laravel_database_prometheus:ikbackend:gauges:meta
laravel_database_prometheus:ikbackend:counters:http_requests_total
laravel_database_prometheus:ikbackend:counters:meta
laravel_database_prometheus:ikbackend:histograms:http_request_duration_seconds
laravel_database_prometheus:ikbackend:histograms:meta
```

### Hash Yapısı

Her metrik bir Redis Hash'tir. Key yapısı:

**Gauge ve Counter:**
```redis
HGETALL prometheus:ikbackend:counters:http_requests_total
# Field                                     → Value
# MjAw:R0VU:VXNlckNvbnRyb2xsZXI=:aW5kZXg=:/YXBpL3VzZXJz → 42
# Base64 encode edilmiş label değerleri      → sayaç değeri
```

**Histogram:**
```redis
HGETALL prometheus:ikbackend:histograms:http_request_duration_seconds
# Base64Key:count    → 42
# Base64Key:sum      → 3.14159
# Base64Key:bucket:0.005  → 5
# Base64Key:bucket:0.01   → 12
# Base64Key:bucket:0.025  → 25
# ...
```

**Meta hash'ler:**
```redis
HGETALL prometheus:ikbackend:counters:meta
# Field: http_requests_total
# Value: {"name":"http_requests_total","help":"Total...","labelNames":["code","method","controller","action","endpoint"]}
```

---

## 2. Label Encoding (Base64 + Colon)

### Neden Base64?

Label değerleri rastgele string olabilir (`/api/users/{id}`, `UserController`, `200`). Redis hash field'ında güvenli saklamak için base64 encode edilir.

### Encoding Formatı

```php
// Encoding
$labelValues = ['200', 'GET', 'UserController', 'index', '/api/users'];
$encoded = implode(':', array_map(fn($v) => base64_encode((string)$v), $labelValues));
// Sonuç: "MjAw:R0VU:VXNlckNvbnRyb2xsZXI=:aW5kZXg=:L2FwaS91c2Vycw=="

// Decoding
$decoded = array_map(fn($v) => base64_decode($v), explode(':', $encoded));
// Sonuç: ['200', 'GET', 'UserController', 'index', '/api/users']
```

### ⚠️ KRİTİK: Base64 ve `:` Çakışması

Base64 çıktısı `:` içerebilir (yalnızca padding'de değil, encoded value genelinde de). Label değerlerinin kendisi `:`separator olarak kullanılsa da **base64 encode edilmiş** değerler de `:` içerebilir.

**Problem:** Histogram field'ları `{labelKey}:sum`, `{labelKey}:count`, `{labelKey}:bucket:{le}` formatındadır. `labelKey` kendisi `:` içerdiği için basit `explode(':')` ile parse etmek **MÜMKÜN DEĞİLDİR**.

**Çözüm:** Suffix-based parsing:

```php
// ❌ YANLIŞ — base64'teki ':' nedeniyle bozulur
$parts = explode(':', $field);
$suffix = end($parts);

// ✅ DOĞRU — sondan suffix kontrolü
if (str_ends_with($field, ':sum')) {
    $labelKey = substr($field, 0, -4);  // Son 4 karakter ':sum'
} elseif (str_ends_with($field, ':count')) {
    $labelKey = substr($field, 0, -6);  // Son 6 karakter ':count'
} elseif (preg_match('/^(.+):bucket:([^:]+)$/', $field, $matches)) {
    $labelKey = $matches[1];
    $le = $matches[2];  // Bucket sınırı (ör. "0.005", "10")
}
```

**Bu regex neden güvenli?** Bucket `le` değerleri sayısal olduğu için `:` içermez. `[^:]+` pattern'i en sondaki `:` ayrımından sonraki sayıyı yakalar.

---

## 3. Redis Double-Prefix Sorunu

### Problem

Laravel'in Predis client'ı `KeyPrefixProcessor` kullanır. Bu processor her Redis komutuna otomatik prefix ekler.

```
Uygulama: KEYS prometheus:ikbackend:*
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
