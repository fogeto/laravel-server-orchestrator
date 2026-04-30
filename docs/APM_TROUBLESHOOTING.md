# APM Sorun Giderme Rehberi

Bu dokuman, `/apm/errors` veya `/__apm/errors` endpoint'i bos dondugunde, Mongo/Redis APM store calismadiginda veya Docker/Laravel config degisiklikleri runtime'a yansimadiginda izlenecek adimlari toplar.

Gercek olay akisi su semptomlardan olusmustu:

- `/apm/errors` endpoint'i `200 OK` donuyor ama body `[]`.
- MongoDB'de `ApmErrors` collection'i olusuyor ama endpoint data donmuyor.
- `.env` icine Mongo bilgileri eklenmesine ragmen Laravel config once bos gorunuyor.
- `docker compose restart` sonrasi yeni env degerleri runtime'a gecmiyor.
- `vendor:publish --force` calistirilmasina ragmen `apm.store` ve `apm.service` `null` kaliyor.

Bu durumlarda panik yapmadan asagidaki sira ile ilerleyin.

## 1. APM Akisi Nasil Calisir

Paket uc yuzey saglar:

```text
GET /metrics
GET /__apm/errors
GET /apm/errors
```

APM error capture akisi:

1. `ApmErrorCaptureMiddleware` response'u gorur.
2. Sadece belirli status code'lari yakalar:

```text
400, 401, 403, 404, 429, 500, 502, 503
```

3. Event'i `ApmErrorBuffer` uzerinden secili store'a yazar.
4. Store secimi `ORCHESTRATOR_APM_STORE` ile yapilir:

```env
ORCHESTRATOR_APM_STORE=mongo
# veya
ORCHESTRATOR_APM_STORE=redis
```

5. `/apm/errors` endpoint'i secili store'dan son event'leri okur.

Onemli ayrim:

- `/apm/errors` `404` ise route/package/version problemi vardir.
- `/apm/errors` `200 []` ise route calisiyor ama okunabilir event yoktur.
- Mongo'da `ApmErrors` collection'i olusmasi event yazildigi anlamina gelmez. Store acilirken index/collection olusturulabilir.

## 2. Ilk Canli Kontrol

Endpoint status ve body kontrolu:

```bash
curl -i https://your-domain.example.com/apm/errors?limit=5
```

Sonuclari soyle yorumlayin:

| Sonuc | Anlam |
|------|-------|
| `404 Not Found` | APM route kayitli degil, paket eski olabilir veya route cache eski olabilir |
| `200 []` | Route calisiyor, storage/capture tarafinda sorun var |
| `500` | Controller/store tarafinda exception var, Laravel log'a bakin |
| CORS hatasi | Browser problemi olabilir; once `curl` ile server response'u ayirin |

Yeni event uretmek icin:

```bash
curl -i https://your-domain.example.com/codex-apm-test-404
curl -s https://your-domain.example.com/apm/errors?limit=5
```

Eger bu testten sonra hala `[]` donuyorsa capture veya persistence tarafi calismiyor demektir.

## 3. Runtime Config'i Kontrol Et

Container icinde:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.enabled")); dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service")); dump(config("server-orchestrator.apm.mongo")); dump(config("server-orchestrator.apm.redis")); dump(config("database.redis.client"));'
php -m | grep -Ei "mongodb|redis"
```

Beklenen Mongo ornegi:

```text
true
"mongo"
"ikbackend"
array:3 [
  "connection_string" => "mongodb://..."
  "database" => "orchestrator_ik"
  "collection" => "ApmErrors"
]
```

Beklenen Redis ornegi:

```text
true
"redis"
"ikbackend"
array:2 [
  "connection" => "default"
  "prefix" => "ikbackend"
]
```

### Config Sonuclarini Yorumlama

#### `connection_string` ve `database` bos

Ornek:

```text
"store" => "mongo"
"connection_string" => ""
"database" => ""
```

Sebep:

- Mongo env degerleri container'a gecmemistir.
- Laravel config cache eski kalmistir.
- `.env` yanlis dosyaya eklenmistir.

Cozum:

```env
ORCHESTRATOR_APM_STORE=mongo
ORCHESTRATOR_APM_SERVICE=ikbackend
Logging__MongoDB__ConnectionString=mongodb://USER:PASS@HOST:27017/?authSource=admin
Logging__MongoDB__DatabaseName=orchestrator_ik
```

Sonra:

```bash
php artisan optimize:clear
php artisan config:clear
rm -f bootstrap/cache/config.php
```

#### `apm.store` ve `apm.service` null, ama `apm.mongo` dolu

Ornek:

```text
config("server-orchestrator.apm.store") => null
config("server-orchestrator.apm.service") => null
config("server-orchestrator.apm.mongo") => dolu
```

Sebep:

- Host projedeki publish edilmis `config/server-orchestrator.php` eski kalmistir.
- Veya container icindeki vendor package eski surumdedir.
- Yeni config anahtarlari (`store`, `service`, `scope_by_service`, `ttl`, `redis`) yoktur.

Kontrol:

```bash
composer show fogeto/laravel-server-orchestrator | grep versions
grep -n "'store'" vendor/fogeto/laravel-server-orchestrator/config/server-orchestrator.php
grep -n "'service'" vendor/fogeto/laravel-server-orchestrator/config/server-orchestrator.php
grep -n "mergeConfigFromRecursive" vendor/fogeto/laravel-server-orchestrator/src/Providers/ServerOrchestratorServiceProvider.php
grep -n "'store'" config/server-orchestrator.php
```

Eger vendor config icinde bile `store` ve `service` yoksa `vendor:publish` sorunu cozmez. Once paketi guncelleyin:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
php artisan vendor:publish --tag=server-orchestrator-config --force
php artisan optimize:clear
rm -f bootstrap/cache/config.php
```

Sonra tekrar kontrol edin:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service")); dump(config("server-orchestrator.apm.mongo"));'
```

#### `class_exists("MongoDB\\Driver\\Manager")` false

Sebep:

- PHP MongoDB extension yoktur.

Kontrol:

```bash
php -m | grep -i mongodb
```

Cozum:

- Image icine `ext-mongodb` eklenmeli.
- Extension yuklendikten sonra app container yeniden olusturulmali veya PHP runtime restart edilmeli.

## 4. Docker Env Degisikligi Neden Restart ile Gelmez

Compose tarafinda `.env` degistirmek ile container icindeki PHP process'in yeni env'i gormesi ayni sey degildir.

`docker compose restart` ne yapar:

- Var olan container'i durdurup tekrar baslatir.
- Container'in olusturulmus environment config'ini degistirmez.
- Compose `.env` dosyasina yeni eklenen degerleri mevcut container'a islemez.

Yeni env degerlerini container'a gecirmek icin container recreate gerekir.

Kontrollu recreate:

```bash
docker compose up -d --no-deps --force-recreate ik-backend
```

Bu komut:

- Sadece `ik-backend` container'ini yeniden olusturur.
- `--no-deps` nedeniyle Redis gibi dependency container'lari recreate etmez.
- Kisa sureli API kesintisi yaratabilir.

Alternatif olarak tum stack yeniden olusturulabilir:

```bash
docker compose down
docker compose up -d
```

Bu daha genis etkilidir:

- Network yeniden olusur.
- Redis dahil compose stack'teki container'lar yeniden olusur.
- Container ID'leri degisir.

Bu yuzden eski ID ile girmek hata verir:

```text
Error response from daemon: No such container: old_container_id
```

Container'a isimle girin:

```bash
docker exec -ti ik-backend bash
```

Env container'a gecti mi kontrol:

```bash
docker inspect ik-backend --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -E "Logging__MongoDB|ORCHESTRATOR_APM"
```

Container icinde:

```bash
printenv | grep -E "Logging__MongoDB|ORCHESTRATOR_APM"
grep -n "Logging__MongoDB" /var/www/.env
```

Not:

- `docker inspect` env'i gosteriyorsa container environment'a gecmistir.
- Buna ragmen Laravel `config()` bos donuyorsa config dosyasi eski veya config cache problemi vardir.

## 5. Vendor Publish Ne Zaman Cozer

`vendor:publish --force` sadece vendor'daki mevcut config dosyasini host projeye kopyalar.

Kullanilabilir oldugu durum:

- Vendor package gunceldir.
- `vendor/fogeto/.../config/server-orchestrator.php` icinde yeni key'ler vardir.
- Host `config/server-orchestrator.php` eski kalmistir.

Komut:

```bash
cp config/server-orchestrator.php config/server-orchestrator.php.bak
php artisan vendor:publish --tag=server-orchestrator-config --force
php artisan optimize:clear
rm -f bootstrap/cache/config.php
```

Yeterli olmadigi durum:

- Vendor config'in kendisi eskiyse.
- `grep -n "'store'" vendor/fogeto/laravel-server-orchestrator/config/server-orchestrator.php` bos donuyorsa.

Bu durumda once composer update gerekir:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
```

Sonra tekrar publish:

```bash
php artisan vendor:publish --tag=server-orchestrator-config --force
php artisan optimize:clear
```

## 6. Manuel Store Testi

Bu test middleware'i aradan cikarir ve store'un direkt yazip okuyabildigini kontrol eder.

```bash
php artisan tinker --execute='$b=app(\Fogeto\ServerOrchestrator\Services\ApmErrorBuffer::class); $b->captureIncoming(["path"=>"/manual-apm-store-test","method"=>"GET","statusCode"=>404,"responseBody"=>"manual"]); dump($b->getAll(5));'
```

Sonucu yorumlama:

| Sonuc | Anlam |
|------|-------|
| Event dondu | Store calisiyor. Public request capture etmiyorsa middleware kaydi/sirasi incelenmeli |
| `[]` dondu | Store write/read calismiyor. Mongo/Redis config veya extension problemi var |
| Exception/log var | Laravel log'daki hata temel alinmali |

Log kontrol:

```bash
tail -n 200 storage/logs/laravel.log | grep -Ei "mongo|redis|apm|serverorchestrator|server orchestrator"
```

## 7. MongoDB Kontrolleri

Mongo shell ile collection ve event kontrolu:

```bash
mongosh "mongodb://USER:PASS@HOST:27017/orchestrator_ik?authSource=admin" --eval 'db.ApmErrors.countDocuments(); db.ApmErrors.find().sort({timestamp:-1}).limit(3).pretty()'
```

Service dagilimini gormek icin:

```bash
mongosh "mongodb://USER:PASS@HOST:27017/orchestrator_ik?authSource=admin" --eval 'db.ApmErrors.aggregate([{$group:{_id:"$service", count:{$sum:1}}}]).toArray()'
```

Onemli:

- Endpoint varsayilan olarak kendi `service` degerindeki event'leri okur.
- `ORCHESTRATOR_APM_SERVICE=ikbackend` ise Mongo dokumanlarinda `service: "ikbackend"` olmali.
- Dokumanlar farkli service ile yazilmissa `/apm/errors` bos donebilir.

Gecici teshis icin service scope kapatilabilir:

```env
ORCHESTRATOR_APM_SCOPE_BY_SERVICE=false
```

Sonra:

```bash
php artisan optimize:clear
```

Bu kalici cozum olarak onerilmez; asil cozum service adlarini standartlastirmaktir.

## 8. Redis APM Kontrolleri

Redis store kullanmak icin:

```env
ORCHESTRATOR_APM_STORE=redis
ORCHESTRATOR_APM_SERVICE=ikbackend
ORCHESTRATOR_APM_REDIS_CONNECTION=default
ORCHESTRATOR_APM_REDIS_PREFIX=ikbackend
REDIS_CLIENT=predis
ORCHESTRATOR_REDIS_CLIENT=predis
```

PhpRedis kullanilacaksa:

```env
REDIS_CLIENT=phpredis
ORCHESTRATOR_REDIS_CLIENT=phpredis
```

Extension kontrolu:

```bash
php -m | grep -i redis
```

Key kontrolu:

```bash
docker exec -it ik-redis redis-cli keys "apm:ikbackend:*"
```

Beklenen:

```text
apm:ikbackend:events
apm:ikbackend:event:{uuid}
```

TTL kontrolu:

```bash
docker exec -it ik-redis redis-cli ttl "apm:ikbackend:event:{uuid}"
```

Varsayilan TTL:

```env
ORCHESTRATOR_APM_TTL=86400
```

Yani event'ler rolling olarak yaklasik 1 gun tutulur.

## 9. Middleware Capture Calisiyor mu

Store manuel testte calisiyor ama public request event yazmiyorsa middleware tarafini kontrol edin.

Route yuzeyi:

```bash
php artisan route:list | grep -E "metrics|apm"
```

Paket config:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.enabled")); dump(config("server-orchestrator.middleware.enabled")); dump(config("server-orchestrator.middleware.groups")); dump(config("server-orchestrator.apm.ignore_paths"));'
```

Notlar:

- `apm.enabled=false` ise APM middleware ve route calismaz.
- `middleware.enabled=false` ise HTTP metrics middleware kaydi kapanir; APM kendi `apm.enabled` kontroluyle ayrica kaydedilir.
- `apm.ignore_paths` icinde `apm/*`, `__apm/*`, `_apm/*`, `metrics` olmasi normaldir.
- APM endpoint'lerinin kendisi capture edilmez.
- `Content-Length > 5MB` veya `multipart/form-data` body capture bypass edilir.

## 10. Error Rate ile APM Events Ayni Sey Degil

Dashboard'da `Error Rate 0.00%` gorunmesi ile `/apm/errors` icinde event olmasi celiski degildir.

Fark:

- APM Error Events: son TTL suresinde yakalanan 4xx/5xx event listesi.
- Error Rate: dashboard'un hesapladigi request/error oranidir. Canli pencere veya Prometheus counter delta'si kullanabilir.

Ornek:

- Request rate `0.0/s` ise kartta `Error Rate 0.00%` gorunebilir.
- Ayni anda APM listesinde gecmisten kalan 404/500 event'leri olabilir.

## 11. PHP Memory Metrikleri Karisik Gorunuyorsa

`v1.1.10` ile bellek metrikleri ayrildi:

| Metrik | Anlam |
|-------|-------|
| `process_memory_usage_bytes` | Anlik gercek PHP kullanimi |
| `process_memory_allocated_bytes` | PHP allocator tarafindan ayrilan anlik bellek |
| `process_memory_peak_bytes` | Peak gercek PHP kullanimi |
| `process_memory_peak_allocated_bytes` | Peak ayrilan bellek |
| `process_memory_limit_bytes` | PHP `memory_limit` |

Dashboard yuzdesi icin dogru formül:

```text
process_memory_usage_bytes / process_memory_limit_bytes * 100
```

`process_memory_limit_bytes=1073741824` degeri 1 GB limittir; kullanilan bellek degildir.

## 12. Sik Semptomlar ve Cozumler

| Semptom | Muhtemel sebep | Cozum |
|--------|----------------|-------|
| `/apm/errors` 404 | Route yok veya paket eski | Paketi guncelle, route cache temizle |
| `/apm/errors` 200 ama `[]` | Event yok, store yazmiyor veya service filtresi | Runtime config, manual store test, Mongo/Redis kontrolu |
| Mongo collection var ama endpoint bos | Index/collection olusmus ama dokuman yok veya service farkli | `countDocuments`, `find`, service aggregate kontrolu |
| `apm.mongo.connection_string` bos | Env container'a gecmemis veya cache eski | `docker inspect`, `optimize:clear`, recreate |
| `apm.store` null | Eski config veya vendor package | `composer require 1.1.10`, publish force |
| `vendor:publish --force` sonrasi hala null | Vendor config eski | Once composer update/require |
| `docker compose restart` sonrasi env gelmedi | Restart env config'ini yenilemez | `up -d --no-deps --force-recreate` veya `down/up` |
| `No such container: eski_id` | Container recreate sonrasi ID degisti | `docker exec -ti ik-backend bash` |
| PhpRedis calismiyor | `ext-redis` yok veya client mismatch | `php -m`, `REDIS_CLIENT=phpredis`, restart/recreate |
| Public domain'de metric yok ama container icinde var | Port/proxy farki | Container, host port, public domain ucunu ayri test et |

## 13. Known-Good Mongo Kurulum Akisi

Env:

```env
ORCHESTRATOR_APM_STORE=mongo
ORCHESTRATOR_APM_SERVICE=ikbackend
ORCHESTRATOR_APM_TTL=86400
Logging__MongoDB__ConnectionString=mongodb://USER:PASS@HOST:27017/?authSource=admin
Logging__MongoDB__DatabaseName=orchestrator_ik
```

Paket:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
```

Config:

```bash
cp config/server-orchestrator.php config/server-orchestrator.php.bak
php artisan vendor:publish --tag=server-orchestrator-config --force
php artisan optimize:clear
rm -f bootstrap/cache/config.php
```

Docker env recreate gerekiyorsa:

```bash
docker compose up -d --no-deps --force-recreate ik-backend
```

Kontrol:

```bash
docker exec -ti ik-backend bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service")); dump(config("server-orchestrator.apm.mongo"));'
```

Beklenen:

```text
"mongo"
"ikbackend"
array:3 [
  "connection_string" => "mongodb://..."
  "database" => "orchestrator_ik"
  "collection" => "ApmErrors"
]
```

Smoke test:

```bash
curl -i https://ikapi.webdekolay.com/codex-apm-test-404
curl -s https://ikapi.webdekolay.com/apm/errors?limit=5
```

## 14. Guvenlik Notu

Mongo connection string, Redis sifresi, API token veya benzeri secret'lari loglara, dokumanlara veya chat'e acik yazmayin.

Eger secret bir yere acik sekilde yazildiysa:

1. Ilgili sifreyi rotate edin.
2. Eski kullaniciyi devre disi birakin veya sifresini degistirin.
3. Uygulama env'lerini yeni secret ile guncelleyin.
4. Container recreate/restart ve Laravel cache clear islemlerini yapin.

Dokumanlarda ornek olarak her zaman su format kullanilmalidir:

```env
Logging__MongoDB__ConnectionString=mongodb://USER:PASS@HOST:27017/?authSource=admin
```

## 15. Hızlı Karar Agaci

```text
/apm/errors 404 mu?
  Evet -> paket/route/cache kontrol et.
  Hayir -> 200 [] mu?
    Evet -> yeni 404/500 uret ve tekrar bak.
      Hala [] -> runtime config kontrol et.
        mongo connection/database bos -> env/cache/docker problemi.
        store/service null -> eski config veya vendor package.
        config dogru -> manuel store testi yap.
          manuel test [] -> Mongo/Redis write/read/log problemi.
          manuel test event donuyor -> middleware kaydi/sirasi problemi.
    Hayir -> 500/log hatasini incele.
```

