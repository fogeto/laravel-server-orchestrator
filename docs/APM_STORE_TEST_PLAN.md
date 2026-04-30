# APM Store Test Plani

Bu dokuman Mongo, Redis, Predis ve PhpRedis tarafinin guvenli sekilde nasil test edilecegini anlatir.

Test iki seviyedir:

1. Unit testler: Redis/Mongo servisi gerektirmez. CI veya lokal makinede hizli calisir.
2. Integration testler: Gercek Mongo ve Redis baglantisi ister. Production yerine test database/Redis DB ile calistirilmalidir.

## 1. Unit Testleri Calistir

Repo kok dizininde:

```bash
vendor/bin/phpunit
```

Windows:

```bash
vendor\bin\phpunit.bat
```

Bu testler sunlari dogrular:

- Redis APM store event yazip okuyabiliyor.
- Redis APM store TTL ve prefix yaziyor.
- Redis APM store expired index kayitlarini temizliyor.
- Redis APM store capacity limitini uyguluyor.
- APM buffer `service`, `timestamp`, header redaction ve body truncate islemlerini dogru yapiyor.
- Mongo config eksikken store request'i bozmadan disabled kaliyor.
- Package config icinde `apm.store`, `apm.service`, `apm.mongo`, `apm.redis` anahtarlari var.
- Eski publish edilmis config yeni nested default'larla merge edilebiliyor.
- `ORCHESTRATOR_REDIS_CLIENT=predis|phpredis` Laravel `database.redis.client` ayarini override ediyor.

## 2. Gercek Mongo Integration Testi

Test icin production database yerine ayri bir database kullanin.

Ornek:

```bash
APM_STORE_INTEGRATION=1 \
APM_TEST_MONGO_URI='mongodb://USER:PASS@HOST:27017/?authSource=admin' \
APM_TEST_MONGO_DATABASE='orchestrator_apm_test' \
vendor/bin/phpunit --filter mongo_store_round_trips
```

Windows PowerShell:

```powershell
$env:APM_STORE_INTEGRATION="1"
$env:APM_TEST_MONGO_URI="mongodb://USER:PASS@HOST:27017/?authSource=admin"
$env:APM_TEST_MONGO_DATABASE="orchestrator_apm_test"
vendor\bin\phpunit.bat --filter mongo_store_round_trips
```

Bu test:

- `MongoApmErrorStore` olusturur.
- `ApmErrors` collection'ina test event'i yazar.
- `/apm/errors` mantigiyla son event'leri okur.
- Test service scope'u altindaki kayitlari temizler.

## 3. Gercek Redis + Predis Integration Testi

```bash
APM_STORE_INTEGRATION=1 \
APM_TEST_REDIS_HOST=127.0.0.1 \
APM_TEST_REDIS_PORT=6379 \
APM_TEST_REDIS_DATABASE=15 \
vendor/bin/phpunit --filter redis_store_round_trips_against_real_redis_with_predis
```

Windows PowerShell:

```powershell
$env:APM_STORE_INTEGRATION="1"
$env:APM_TEST_REDIS_HOST="127.0.0.1"
$env:APM_TEST_REDIS_PORT="6379"
$env:APM_TEST_REDIS_DATABASE="15"
vendor\bin\phpunit.bat --filter redis_store_round_trips_against_real_redis_with_predis
```

Bu test:

- Laravel Redis client'i `predis` yapar.
- `RedisApmErrorStore` ile event yazar.
- Event'i geri okur.
- Test prefix'li key'leri temizler.

## 4. Gercek Redis + PhpRedis Integration Testi

On kosul:

```bash
php -m | grep -i redis
```

Calistirma:

```bash
APM_STORE_INTEGRATION=1 \
APM_TEST_REDIS_HOST=127.0.0.1 \
APM_TEST_REDIS_PORT=6379 \
APM_TEST_REDIS_DATABASE=15 \
vendor/bin/phpunit --filter redis_store_round_trips_against_real_redis_with_phpredis
```

Windows PowerShell:

```powershell
$env:APM_STORE_INTEGRATION="1"
$env:APM_TEST_REDIS_HOST="127.0.0.1"
$env:APM_TEST_REDIS_PORT="6379"
$env:APM_TEST_REDIS_DATABASE="15"
vendor\bin\phpunit.bat --filter redis_store_round_trips_against_real_redis_with_phpredis
```

Bu test `ext-redis` yoksa skip olur.

## 5. Canli Uygulama Smoke Testi

Package testleri gectikten sonra uygulama container'inda runtime config'i dogrulayin.

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.enabled")); dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service")); dump(config("server-orchestrator.apm.mongo")); dump(config("server-orchestrator.apm.redis")); dump(config("database.redis.client"));'
```

Beklenen Mongo:

```text
true
"mongo"
"ikbackend"
```

Beklenen Redis:

```text
true
"redis"
"ikbackend"
```

Yeni hata event'i uretin:

```bash
curl -i https://your-domain.example.com/codex-apm-smoke-404
curl -s https://your-domain.example.com/apm/errors?limit=5
```

Beklenen:

- JSON array bos olmamali.
- Son event'te `path` alaninda `/codex-apm-smoke-404` olmali.
- `statusCode` 404 olmali.
- `service` beklenen proje adi olmali.

## 6. Redis Key Kontrolu

Redis APM store icin:

```bash
docker exec -it ik-redis redis-cli -n 15 keys "apm:ikbackend:*"
```

Beklenen:

```text
apm:ikbackend:events
apm:ikbackend:event:{uuid}
```

TTL:

```bash
docker exec -it ik-redis redis-cli -n 15 ttl "apm:ikbackend:event:{uuid}"
```

## 7. Mongo Document Kontrolu

```bash
mongosh "mongodb://USER:PASS@HOST:27017/orchestrator_ik?authSource=admin" --eval 'db.ApmErrors.countDocuments(); db.ApmErrors.find().sort({timestamp:-1}).limit(3).pretty()'
```

Service dagilimi:

```bash
mongosh "mongodb://USER:PASS@HOST:27017/orchestrator_ik?authSource=admin" --eval 'db.ApmErrors.aggregate([{$group:{_id:"$service", count:{$sum:1}}}]).toArray()'
```

## 8. Test Sonucu Karar Tablosu

| Test | Gecerse | Kalirsa |
|------|---------|---------|
| Unit Redis store | Paket icindeki Redis store mantigi saglam | Store kodunda veya fake/testte hata var |
| Unit provider redis client | `predis/phpredis` env override mantigi saglam | Provider config override incelenmeli |
| Mongo integration | Mongo write/read ve service scope saglam | URI, database, auth, network veya ext-mongodb incelenmeli |
| Redis predis integration | Predis ile Redis write/read saglam | Redis host/port/password veya predis baglantisi incelenmeli |
| Redis phpredis integration | PhpRedis ile Redis write/read saglam | ext-redis veya phpredis client config incelenmeli |
| Canli smoke | App runtime capture + store saglam | Middleware, config cache, Docker env veya service filter incelenmeli |

