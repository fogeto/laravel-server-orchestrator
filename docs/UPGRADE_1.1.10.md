# Laravel Server Orchestrator v1.1.10 Guncelleme Rehberi

Bu rehber, `fogeto/laravel-server-orchestrator` kullanan Laravel projelerini `v1.1.10` surumune gecirmek icin hazirlandi.

`v1.1.10` ile gelen ana konular:

- APM error store secimi: `mongo` veya `redis`.
- Redis tarafinda `predis` / `phpredis` secimi.
- PHP, process ve OPcache metricleri.
- Bellek metriclerinin ayrilmasi:
  - `process_memory_usage_bytes`
  - `process_memory_allocated_bytes`
  - `process_memory_peak_bytes`
  - `process_memory_peak_allocated_bytes`
  - `process_memory_limit_bytes`
- APM endpointleri:
  - `GET /apm/errors`
  - `GET /_apm/errors`
  - `GET /__apm/errors`
- Metrics endpointi:
  - `GET /metrics`

## 1. Kimler Guncellemeli

Bu rehberi uygulayin:

- Paket `1.1.9` veya daha eskiyse.
- `/metrics` endpointinde eski Redis metric verisi veya label uyumsuzlugu yasaniyorsa.
- APM eventlerini Mongo yerine Redis'e almak veya Redis'ten tekrar Mongo'ya donmek istiyorsaniz.
- Dashboard'da PHP memory yuzdesi yanlis gorunuyorsa.
- Published `config/server-orchestrator.php` eski kaldiysa ve `apm.store`, `apm.service` gibi alanlar `null` donuyorsa.
- Sentry/loglarda `Index with name: ix_apm_ttl already exists with different options` hatasi goruyorsaniz.
- Sentry/loglarda `Detected invalid UTF-8 for field path "requestBody"` hatasi goruyorsaniz.

## 2. On Kosullar

Kontrol edin:

```bash
php -v
php -m | grep -Ei "mongodb|redis"
composer show fogeto/laravel-server-orchestrator
```

Gereksinimler:

- PHP `^8.0`
- Laravel 9, 10, 11 veya 12
- Metrics Redis driver kullaniliyorsa calisan Redis baglantisi
- Mongo APM kullanilacaksa `ext-mongodb`
- PhpRedis kullanilacaksa `ext-redis`
- Predis kullanilacaksa `predis/predis`

## 3. Guncelleme Oncesi Yedek

Published config varsa yedek alin:

```bash
cp config/server-orchestrator.php config/server-orchestrator.php.bak
cp .env .env.bak
```

Canli ortamda container env degerlerini de not alin:

```bash
printenv | grep -E "ORCHESTRATOR|Logging__MongoDB|REDIS"
```

Host tarafindan container env kontrolu:

```bash
docker inspect your-container-name --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -E "ORCHESTRATOR|Logging__MongoDB|REDIS"
```

## 4. Paketi 1.1.10'a Guncelle

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
```

Alternatif:

```bash
composer update fogeto/laravel-server-orchestrator --with-all-dependencies
```

Surumu dogrulayin:

```bash
composer show fogeto/laravel-server-orchestrator | grep versions
```

Beklenen:

```text
versions : * v1.1.10
```

## 5. Config Publish / Merge

Yeni config anahtarlarini host projede gormek icin publish edin:

```bash
php artisan vendor:publish --tag=server-orchestrator-config --force
```

Sonra cache temizleyin:

```bash
php artisan optimize:clear
```

Dikkat: `--force` mevcut `config/server-orchestrator.php` dosyasini ezer. Projeye ozel ayarlariniz varsa yedekten manuel merge yapin.

Yeni config icinde su alanlar olmali:

```bash
grep -n "'store'\|'service'\|'scope_by_service'\|'redis'" config/server-orchestrator.php
```

Beklenen:

```text
'store' => env('ORCHESTRATOR_APM_STORE', 'mongo')
'service' => env('ORCHESTRATOR_APM_SERVICE', env('ORCHESTRATOR_PREFIX', env('APP_NAME', 'laravel')))
'scope_by_service' => env('ORCHESTRATOR_APM_SCOPE_BY_SERVICE', true)
'redis' => [...]
```

## 6. Ortak Env Ayarlari

Her projede benzersiz prefix kullanin:

```env
ORCHESTRATOR_PREFIX=ticaret_backend
ORCHESTRATOR_METRICS_STORAGE=redis
ORCHESTRATOR_REDIS_CONNECTION=default
ORCHESTRATOR_METRICS_TTL=86400
```

APM service degerini acik vermeniz onerilir:

```env
ORCHESTRATOR_APM_SERVICE=ticaret_backend
```

Onemli: `ORCHESTRATOR_APM_SERVICE`, `ORCHESTRATOR_PREFIX` degerinden onceliklidir. Prefix dogru ama service yanlis gorunuyorsa container icinde eski `ORCHESTRATOR_APM_SERVICE` kalmistir.

## 7. Mongo APM Kurulumu

Mongo kullanmak icin:

```env
ORCHESTRATOR_PREFIX=ticaret_backend
ORCHESTRATOR_APM_STORE=mongo
ORCHESTRATOR_APM_SERVICE=ticaret_backend
ORCHESTRATOR_APM_SCOPE_BY_SERVICE=true
ORCHESTRATOR_APM_TTL=86400

Logging__MongoDB__ConnectionString=mongodb://USER:PASS@HOST:27017/?authSource=admin
Logging__MongoDB__DatabaseName=orchestrator_ecommerce
```

Notlar:

- Collection adi varsayilan olarak `ApmErrors`.
- Mongo store varsayilan olarak sadece kendi `service` degerindeki kayitlari okur.
- Eski kayitlarda `service` alani yoksa veya farkli service varsa endpoint bos donebilir.

Runtime kontrol:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.prefix")); dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service")); dump(config("server-orchestrator.apm.mongo"));'
```

Beklenen:

```text
"ticaret_backend"
"mongo"
"ticaret_backend"
array:3 [
  "connection_string" => "mongodb://..."
  "database" => "orchestrator_ecommerce"
  "collection" => "ApmErrors"
]
```

## 8. Redis APM Kurulumu

Redis kullanmak icin:

```env
ORCHESTRATOR_PREFIX=ticaret_backend
ORCHESTRATOR_APM_STORE=redis
ORCHESTRATOR_APM_SERVICE=ticaret_backend
ORCHESTRATOR_APM_REDIS_CONNECTION=default
ORCHESTRATOR_APM_REDIS_PREFIX=ticaret_backend
ORCHESTRATOR_APM_TTL=86400
```

Redis key mantigi:

```text
apm:{prefix}:events
apm:{prefix}:event:{uuid}
```

Laravel Redis prefix'i varsa raw Redis'te key onune `laravel_database_` gibi ek gelebilir.

## 9. Predis / PhpRedis Secimi

Predis:

```env
REDIS_CLIENT=predis
ORCHESTRATOR_REDIS_CLIENT=predis
```

PhpRedis:

```env
REDIS_CLIENT=phpredis
ORCHESTRATOR_REDIS_CLIENT=phpredis
```

PhpRedis icin extension kontrolu:

```bash
php -m | grep -i redis
```

Runtime kontrol:

```bash
php artisan tinker --execute='dump(config("database.redis.client")); dump(config("server-orchestrator.redis_client")); dump(get_class(\Illuminate\Support\Facades\Redis::connection("default")));'
```

## 10. Docker Restart Degil Recreate

Env degistiyse sadece `docker compose restart` yeterli olmayabilir. Recreate kullanin:

```bash
docker compose up -d --no-deps --force-recreate your-service-name
```

Gerekirse tum stack:

```bash
docker compose down
docker compose up -d
```

Container icinde cache temizleyin:

```bash
php artisan optimize:clear
```

Queue/Horizon/Octane varsa:

```bash
php artisan queue:restart
php artisan horizon:terminate
php artisan octane:reload
```

## 11. Route Kontrolu

```bash
php artisan route:list | grep -E "metrics|apm"
```

Beklenen:

```text
GET|HEAD  metrics
GET|HEAD  apm/errors
GET|HEAD  _apm/errors
GET|HEAD  __apm/errors
```

Route yoksa:

```bash
php artisan optimize:clear
php artisan route:clear
```

## 12. Metrics Dogrulama

Container icinde:

```bash
curl -i http://127.0.0.1/metrics
```

Beklenen:

```text
HTTP/1.1 200 OK
Content-Type: text/plain; version=0.0.4; charset=utf-8
```

Body icinde ornek metricler:

```text
# HELP php_info Information about the PHP environment.
# TYPE php_info gauge
php_info{version="8.3.30"} 1

process_memory_usage_bytes 123456
process_memory_allocated_bytes 2097152
process_memory_peak_bytes 234567
process_memory_peak_allocated_bytes 4194304
process_memory_limit_bytes 1073741824
process_uptime_seconds 1.23
php_opcache_enabled 1
```

HTTP/SQL metrikleri trafik olustuktan sonra gorunur:

```bash
curl -i http://127.0.0.1/api/health
curl -s http://127.0.0.1/metrics | grep -E "http_request_duration_seconds|http_requests_received_total|sql_query_duration_seconds"
```

## 13. APM Event Dogrulama

APM sadece su status kodlarini yakalar:

```text
400, 401, 403, 404, 429, 500, 502, 503
```

Test 404 uretin:

```bash
curl -i http://127.0.0.1/codex-apm-test-404
curl -s http://127.0.0.1/apm/errors?limit=5
```

Beklenen JSON:

```json
[
  {
    "id": "uuid",
    "timestamp": "2026-04-30T10:39:15.000Z",
    "service": "ticaret_backend",
    "path": "/codex-apm-test-404",
    "method": "GET",
    "statusCode": 404,
    "errorType": "Not Found",
    "message": "...",
    "requestBody": "",
    "responseBody": "...",
    "durationMs": 12.34,
    "clientIp": "127.0.0.1",
    "userAgent": "curl/8.x",
    "queryString": ""
  }
]
```

## 14. APM Bos Gelirse

`/apm/errors` `200 []` donuyorsa sira ile kontrol edin.

Runtime config:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.enabled")); dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service")); dump(config("server-orchestrator.apm.mongo")); dump(config("server-orchestrator.apm.redis"));'
```

Manuel store testi:

```bash
php artisan tinker --execute='
$buffer = app(\Fogeto\ServerOrchestrator\Services\ApmErrorBuffer::class);
$buffer->captureIncoming([
    "path" => "/manual-apm-test",
    "method" => "GET",
    "statusCode" => 500,
    "requestBody" => "",
    "responseBody" => "manual apm test",
    "durationMs" => 1,
    "clientIp" => "127.0.0.1",
    "userAgent" => "artisan",
    "queryString" => "",
]);
dump($buffer->getAll(5));
'
```

Sonuc:

- Manuel event gorunuyorsa store calisiyor, middleware/capture tarafini kontrol edin.
- Manuel event de `[]` ise Mongo/Redis baglantisi, extension veya service filtresi sorunludur.

## 15. Mongo Veri Temizleme

Testten sonra Mongo APM verisini tamamen temizlemek icin:

```bash
mongosh "mongodb://USER:PASS@HOST:27017/?authSource=admin"
use orchestrator_ecommerce
db.ApmErrors.drop()
```

`drop()` dokumanlari ve eski indexleri temizler.

Canli veriyi komple silmek istemiyorsaniz sadece ilgili service icin silin:

```javascript
db.ApmErrors.deleteMany({ service: "ticaret_backend" })
```

Service dagilimini kontrol:

```javascript
db.ApmErrors.aggregate([{ $group: { _id: "$service", count: { $sum: 1 } } }])
```

Son kayitlari kontrol:

```javascript
db.ApmErrors.find({}, {service: 1, timestamp: 1, path: 1, statusCode: 1}).sort({timestamp: -1}).limit(5)
```

TTL index hatasi gorurseniz:

```text
MongoDB\Driver\Exception\CommandException:
Index with name: ix_apm_ttl already exists with different options
```

Sebep: Eski kurulumdan kalan `ix_apm_ttl` index'i yeni paketin bekledigi `timestamp` TTL index'i ile ayni isimde ama farkli key/options ile duruyordur.

`v1.1.10` uyumsuz eski TTL index'i otomatik dusurup yeniden olusturacak sekilde hazirlanmistir. Acil manuel duzeltme:

```javascript
db.ApmErrors.dropIndex("ix_apm_ttl")
```

Veri temizligi de isteniyorsa:

```javascript
db.ApmErrors.drop()
```

Invalid UTF-8 body hatasi gorurseniz:

```text
MongoDB\Driver\Exception\UnexpectedValueException:
Detected invalid UTF-8 for field path "requestBody"
```

Sebep genellikle bot/scanner isteklerindeki binary body'dir. Ornek: `Content-Type: application/dns-message`. Patch sonrasi APM buffer invalid UTF-8 body'leri raw olarak Mongo'ya yazmaz; guvenli placeholder yazar:

```text
[non-utf8 string omitted; bytes=41; base64_prefix=...]
```

## 16. Redis Veri Temizleme

Paket uzerinden secili prefix'i temizlemek:

```bash
php artisan tinker --execute='app(\Fogeto\ServerOrchestrator\Contracts\IApmErrorStore::class)->clear(); dump("APM temizlendi");'
```

Metrics Redis storage temizlemek:

```bash
php artisan tinker --execute='app(\Prometheus\CollectorRegistry::class)->wipeStorage(); dump("Metrics temizlendi");'
```

Raw Redis key kontrolu:

```bash
redis-cli keys "*apm:ticaret_backend:*"
redis-cli keys "*prometheus:ticaret_backend:*"
```

## 17. Public Domain, Proxy ve CORS

Container icinde endpoint calisiyor ama public domain calismiyorsa uc noktayi ayri test edin:

```bash
# Container icinde
curl -i http://127.0.0.1/apm/errors?limit=5

# Container icinde Host header ile
curl -i -H 'Host: ticaretapi.webdekolay.com' http://127.0.0.1/apm/errors?limit=5

# Public domain
curl -i https://ticaretapi.webdekolay.com/apm/errors?limit=5
```

Yorum:

- Container icinde `200`, public domain'de `403`: paket/store degil, nginx/proxy/security katmani.
- `curl` ile `403`: CORS degil, server istegi engelliyor.
- Browser CORS hatasi var ama `curl` `200`: Laravel veya proxy CORS header eksik.

Nginx/proxy kontrolu:

```bash
grep -R "ticaretapi.webdekolay.com\|apm/errors\|deny\|allow\|return 403" -n /etc/nginx /etc/nginx/sites-enabled
```

Laravel `config/cors.php` icin root endpointleri ekleyin:

```php
'paths' => [
    'api/*',
    'metrics',
    'apm/errors',
    '_apm/errors',
    '__apm/errors',
],

'allowed_methods' => ['GET', 'OPTIONS'],

'allowed_origins' => [
    'https://server.aysbulut.com',
    'https://server-orchestrator.aysbulut.com',
],

'allowed_headers' => ['*'],

'supports_credentials' => false,
```

Cache temizleyin:

```bash
php artisan optimize:clear
```

Preflight test:

```bash
curl -i -X OPTIONS \
  -H "Origin: https://server-orchestrator.aysbulut.com" \
  -H "Access-Control-Request-Method: GET" \
  https://ticaretapi.webdekolay.com/apm/errors
```

Beklenen header:

```text
Access-Control-Allow-Origin: https://server-orchestrator.aysbulut.com
Access-Control-Allow-Methods: GET, OPTIONS
```

OPTIONS veya GET hala `403` ise sorun `config/cors.php` degil, proxy/security katmanidir.

## 18. Geri Donus Plani

Sorun halinde onceki calisan surume donun:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.9 --with-all-dependencies
php artisan optimize:clear
docker compose up -d --no-deps --force-recreate your-service-name
```

Config publish ile dosya ezildiyse yedegi geri alin:

```bash
cp config/server-orchestrator.php.bak config/server-orchestrator.php
php artisan optimize:clear
```

## 19. Minimum Canli Guncelleme Akisi

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
php artisan vendor:publish --tag=server-orchestrator-config --force
php artisan optimize:clear
docker compose up -d --no-deps --force-recreate your-service-name
docker exec -ti your-container-name bash
php artisan optimize:clear
php artisan route:list | grep -E "metrics|apm"
php artisan tinker --execute='dump(config("server-orchestrator.prefix")); dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service"));'
curl -i http://127.0.0.1/metrics
curl -i http://127.0.0.1/codex-apm-test-404
curl -s http://127.0.0.1/apm/errors?limit=5
```

Bu akistan sonra:

- `/metrics` `200 OK` ve Prometheus text format donmeli.
- `/apm/errors` yeni 4xx/5xx eventlerini JSON array olarak donmeli.
- `apm.store` env'deki secime gore `mongo` veya `redis` olmali.
- `apm.service` ilgili proje adiyla ayni olmali.
- Public domain farkli sonuc donuyorsa proxy/CORS katmani ayrica incelenmeli.

## 20. Guvenlik Notu

Mongo connection string, Redis sifresi, API token veya benzeri secret'lari dokumanlara, loglara veya chat'e acik yazmayin.

Orneklerde her zaman su format kullanilmalidir:

```env
Logging__MongoDB__ConnectionString=mongodb://USER:PASS@HOST:27017/?authSource=admin
```

Secret yanlislikla aciga ciktiysa:

1. Sifreyi rotate edin.
2. Eski kullaniciyi devre disi birakin veya sifresini degistirin.
3. Env degerlerini guncelleyin.
4. Container recreate ve `php artisan optimize:clear` yapin.
