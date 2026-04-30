# Laravel Server Orchestrator v1.1.10 Güncelleme Rehberi

Bu doküman, `fogeto/laravel-server-orchestrator` paketini kullanan Laravel projelerini son stabil sürüm olan `v1.1.10` sürümüne geçirmek için hazırlanmıştır.

`v1.1.10` ile öne çıkan noktalar:

- APM hata event'leri MongoDB veya Redis üzerinde tutulabilir.
- Redis tarafında `predis` veya `phpredis` seçimi env üzerinden yapılabilir.
- PHP/process metrikleri genişletildi.
- Bellek metrikleri ayrıştırıldı:
  - `process_memory_usage_bytes`: gerçek anlık PHP kullanımı
  - `process_memory_allocated_bytes`: PHP allocator tarafından ayrılan anlık bellek
  - `process_memory_peak_bytes`: peak gerçek PHP kullanımı
  - `process_memory_peak_allocated_bytes`: peak ayrılan bellek
  - `process_memory_limit_bytes`: PHP `memory_limit`
- `/apm/errors` ve `/__apm/errors` endpoint'leri 4xx/5xx hata event'lerini döner.

## Kimler Uygulamalı

- Paketi `v1.1.9` veya daha eski sürümde kullanan projeler
- APM storage seçimini MongoDB veya Redis olarak yönetmek isteyen projeler
- Redis client olarak `predis` veya `phpredis` seçimini env ile yapmak isteyen projeler
- Dashboard tarafında PHP memory yüzdesini doğru göstermek isteyen kurulumlar

## Ön Koşullar

- Laravel 9, 10, 11 veya 12
- PHP 8.0+
- Metrics storage Redis ise çalışan Redis bağlantısı
- APM MongoDB kullanılacaksa `ext-mongodb` ve MongoDB connection bilgileri
- APM Redis kullanılacaksa Redis bağlantısı
- `ORCHESTRATOR_REDIS_CLIENT=phpredis` kullanılacaksa PHP `redis` extension'ı

Kontrol komutları:

```bash
php -v
php -m | grep -Ei "mongodb|redis"
composer show fogeto/laravel-server-orchestrator | grep versions
```

## 1. Paketi v1.1.10'a Güncelle

Proje dizinine girin:

```bash
cd /path/to/your-project
```

Paketi direkt `v1.1.10` sürümüne çekin:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
```

Eğer `composer.json` içinde constraint zaten uygunsa alternatif olarak:

```bash
composer update fogeto/laravel-server-orchestrator --with-all-dependencies
```

Sürümü doğrulayın:

```bash
composer show fogeto/laravel-server-orchestrator | grep versions
```

Beklenen:

```text
versions : * v1.1.10
```

## 2. Cache Temizle ve Runtime Restart Yap

Laravel cache'lerini temizleyin:

```bash
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
```

Docker kullanıyorsanız:

```bash
docker compose restart
```

Horizon, Octane veya queue worker varsa:

```bash
php artisan horizon:terminate
php artisan octane:reload
php artisan queue:restart
```

PHP-FPM kullanıyorsanız PHP-FPM servisinin de reload/restart edilmesi gerekir.

## 3. Vendor Publish Gerekli mi?

Zorunlu değil.

Paket yeni config anahtarlarını runtime'da default config üzerinden tamamlar. Sadece `.env` ile ayar verecekseniz genellikle yeniden publish yapmanız gerekmez.

Ancak host projedeki `config/server-orchestrator.php` dosyasında yeni ayarları fiziksel olarak görmek veya elle düzenlemek istiyorsanız publish kullanabilirsiniz.

Güvenli yöntem:

```bash
cp config/server-orchestrator.php config/server-orchestrator.php.bak
php artisan vendor:publish --tag=server-orchestrator-config --force
php artisan optimize:clear
```

Dikkat: `--force` mevcut config dosyasını ezer. Projeye özel eski ayarlarınız varsa önce yedek alın veya manuel merge yapın.

## 4. APM Store Seçimi

APM store seçimi `ORCHESTRATOR_APM_STORE` ile yapılır.

Desteklenen değerler:

- `mongo`
- `redis`

APM event TTL varsayılan olarak 1 gündür:

```env
ORCHESTRATOR_APM_TTL=86400
```

Bu değer hem MongoDB TTL index'i hem Redis event key TTL'i için kullanılır.

## 5. MongoDB APM Kullanımı

MongoDB kullanmak için:

```env
ORCHESTRATOR_APM_STORE=mongo
ORCHESTRATOR_APM_SERVICE=ecommerce-backend
ORCHESTRATOR_APM_TTL=86400

Logging__MongoDB__ConnectionString=mongodb://user:pass@host:27017/?authSource=admin
Logging__MongoDB__DatabaseName=ecommerce
```

Notlar:

- Collection adı sabittir: `ApmErrors`
- `ORCHESTRATOR_APM_SERVICE` verilmezse `ORCHESTRATOR_PREFIX`, o da yoksa `APP_NAME` kullanılır.
- Mongo store varsayılan olarak sadece kendi `service` değerindeki kayıtları okur.

Runtime config doğrulama:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.mongo")); dump(config("server-orchestrator.apm.service"));'
```

Beklenen:

```text
"mongo"
```

## 6. Redis APM Kullanımı

Redis store kullanmak için:

```env
ORCHESTRATOR_APM_STORE=redis
ORCHESTRATOR_APM_SERVICE=ecommerce-backend
ORCHESTRATOR_APM_TTL=86400
ORCHESTRATOR_APM_REDIS_CONNECTION=default
ORCHESTRATOR_APM_REDIS_PREFIX=ecommerce-backend
```

Redis APM key'leri şu mantıkla ayrılır:

```text
apm:{prefix}:events
apm:{prefix}:event:{id}
```

`ORCHESTRATOR_APM_REDIS_PREFIX` boşsa `ORCHESTRATOR_PREFIX` kullanılır.

## 7. Predis Kullanımı

Predis kullanmak için:

```env
REDIS_CLIENT=predis
ORCHESTRATOR_REDIS_CLIENT=predis
ORCHESTRATOR_APM_STORE=redis
ORCHESTRATOR_APM_REDIS_CONNECTION=default
ORCHESTRATOR_APM_REDIS_PREFIX=ecommerce-test-predis
```

Sonra:

```bash
php artisan optimize:clear
docker compose restart
```

Doğrulama:

```bash
php artisan tinker --execute='dump(config("database.redis.client")); dump(config("server-orchestrator.redis_client")); dump(config("server-orchestrator.apm.store"));'
```

Beklenen:

```text
"predis"
"predis"
"redis"
```

## 8. PhpRedis Kullanımı

PhpRedis kullanmak için önce extension yüklü mü kontrol edin:

```bash
php -m | grep -i redis
```

Env:

```env
REDIS_CLIENT=phpredis
ORCHESTRATOR_REDIS_CLIENT=phpredis
ORCHESTRATOR_APM_STORE=redis
ORCHESTRATOR_APM_REDIS_CONNECTION=default
ORCHESTRATOR_APM_REDIS_PREFIX=ecommerce-test-phpredis
```

Sonra:

```bash
php artisan optimize:clear
docker compose restart
```

Doğrulama:

```bash
php artisan tinker --execute='dump(config("database.redis.client")); dump(config("server-orchestrator.redis_client")); dump(get_class(\Illuminate\Support\Facades\Redis::connection("default")));'
```

Beklenen connection class Laravel sürümüne göre değişebilir ama `PhpRedisConnection` görmeniz gerekir.

## 9. Route Doğrulama

```bash
php artisan route:list | grep -E "metrics|apm"
```

Beklenen yüzey:

```text
GET|HEAD  metrics
GET|HEAD  __apm/errors
GET|HEAD  apm/errors
```

## 10. Metrics Doğrulama

Container içinden:

```bash
curl -s http://127.0.0.1/metrics | grep -E "php_info|process_memory|process_uptime|php_opcache"
```

Host üzerinden Docker port map varsa örnek:

```bash
curl -s http://127.0.0.1:8085/metrics | grep -E "php_info|process_memory|process_uptime|php_opcache"
```

Public domain üzerinden:

```bash
curl -s https://your-domain.example.com/metrics | grep -E "php_info|process_memory|process_uptime|php_opcache"
```

Beklenen örnek metrikler:

```text
php_info{version="8.3.30"} 1
process_uptime_seconds 12.3
process_memory_usage_bytes 4194304
process_memory_allocated_bytes 4194304
process_memory_peak_bytes 6291456
process_memory_peak_allocated_bytes 6291456
process_memory_limit_bytes 1073741824
php_opcache_enabled 1
php_opcache_hit_rate 98.1
php_opcache_memory_used_bytes 95352064
```

Dashboard tarafında memory yüzdesi için doğru yorum:

- Kullanılan gerçek bellek: `process_memory_usage_bytes`
- Allocator tarafından ayrılmış bellek: `process_memory_allocated_bytes`
- Limit: `process_memory_limit_bytes`
- Yüzde: `process_memory_usage_bytes / process_memory_limit_bytes * 100`

`process_memory_limit_bytes` değeri `1073741824` ise bu 1 GB limit demektir. Bu değer kullanılan bellek değil, üst limittir.

## 11. APM Error Event Doğrulama

Yakalanan status code'lar:

```text
400, 401, 403, 404, 429, 500, 502, 503
```

Test için bir 404 üretin:

```bash
curl -i http://127.0.0.1:8085/apm-test-404
```

APM feed'i okuyun:

```bash
curl -s http://127.0.0.1:8085/apm/errors?limit=5
```

Beklenen:

- JSON array dönmeli.
- Son event içinde `statusCode: 404` olmalı.
- `path` alanında test ettiğiniz path görünmeli.
- `service` alanı proje/service adınız olmalı.

Public domain üzerinden:

```bash
curl -i https://your-domain.example.com/apm-test-404
curl -s https://your-domain.example.com/apm/errors?limit=5
```

## 12. Redis Store Gerçekten Yazıyor mu?

APM store Redis ise test event ürettikten sonra Redis key'lerini kontrol edin:

```bash
docker exec -it ecommerce-redis redis-cli keys "apm:ecommerce-test-predis:*"
```

veya phpredis testi için:

```bash
docker exec -it ecommerce-redis redis-cli keys "apm:ecommerce-test-phpredis:*"
```

Beklenen:

```text
apm:ecommerce-test-predis:events
apm:ecommerce-test-predis:event:{uuid}
```

TTL kontrolü:

```bash
docker exec -it ecommerce-redis redis-cli ttl "apm:ecommerce-test-predis:event:{uuid}"
```

Beklenen TTL yaklaşık `86400` saniyeden geriye saymalıdır.

## 13. Mongo Store Gerçekten Yazıyor mu?

APM store Mongo ise:

- Database: `Logging__MongoDB__DatabaseName`
- Collection: `ApmErrors`
- Event alanları içinde `service`, `timestamp`, `path`, `method`, `statusCode`, `requestBody`, `responseBody` bulunmalıdır.

Tinker ile hızlı config kontrolü:

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.mongo.database"));'
```

## 14. Sık Sorunlar

### `/apm/errors` boş dönüyor

Kontrol edin:

- `ORCHESTRATOR_APM_ENABLED=true` mi?
- `ORCHESTRATOR_APM_STORE` doğru mu?
- Mongo seçildiyse `ext-mongodb` yüklü mü?
- Redis seçildiyse Redis connection çalışıyor mu?
- `phpredis` seçildiyse `php -m | grep -i redis` çıktı veriyor mu?
- Gerçekten yakalanan status kodlarından biri oluştu mu?
- Runtime restart yapıldı mı?

### `process_memory_limit_bytes` public domain'de görünmüyor ama container içinde görünüyor

Bu genellikle package problemi değil, routing/proxy/container ayrımıdır.

Kontrol sırası:

```bash
curl -s http://127.0.0.1/metrics | grep process_memory_limit_bytes
curl -s http://127.0.0.1:8085/metrics | grep process_memory_limit_bytes
curl -s https://your-domain.example.com/metrics | grep process_memory_limit_bytes
```

Container içinde `http://127.0.0.1/metrics`, host üzerinde port map varsa `http://127.0.0.1:8085/metrics` kullanılmalıdır.

### Dashboard PHP memory `100%` gösteriyor

Dashboard tarafında denominator olarak `process_memory_allocated_bytes` veya `process_memory_peak_bytes` kullanılıyorsa 100% görünebilir.

Doğru denominator `process_memory_limit_bytes` olmalıdır.

### Error Rate `0.00%` görünüyor ama Error Events dolu

APM error events ve request error rate aynı şey değildir.

- APM events: son TTL süresindeki yakalanmış 4xx/5xx kayıt listesi
- Error Rate: dashboard'un kullandığı toplam veya canlı request oranı

Anlık request rate `0.0/s` ise karttaki error rate'in `0.00%` görünmesi normal olabilir.

## 15. Geri Dönüş Planı

Sorun halinde önceki çalışan sürüme dönebilirsiniz:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.9 --with-all-dependencies
php artisan optimize:clear
docker compose restart
```

Force publish yaptıysanız yedeği geri alın:

```bash
cp config/server-orchestrator.php.bak config/server-orchestrator.php
php artisan optimize:clear
```

## Minimum Güncelleme Akışı

```bash
composer require fogeto/laravel-server-orchestrator:1.1.10 --with-all-dependencies
php artisan optimize:clear
docker compose restart
composer show fogeto/laravel-server-orchestrator | grep versions
curl -s http://127.0.0.1:8085/metrics | grep -E "process_memory_(usage|allocated|peak|peak_allocated|limit)_bytes"
curl -i http://127.0.0.1:8085/apm-test-404
curl -s http://127.0.0.1:8085/apm/errors?limit=5
```

Bu akıştan sonra:

- `/metrics` PHP/process metriklerini vermeli.
- `/apm/errors` yeni 4xx/5xx event'ini göstermeli.
- Mongo veya Redis store seçimi `.env` değerine göre çalışmalı.
- Redis seçildiyse `predis/phpredis` seçimi `REDIS_CLIENT` ve `ORCHESTRATOR_REDIS_CLIENT` ile yönetilmeli.
