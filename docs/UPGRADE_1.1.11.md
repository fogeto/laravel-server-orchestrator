# Laravel Server Orchestrator v1.1.11 Guncelleme Rehberi

Bu surum `v1.1.10` uzerine iki APM hardening fix'i ekler:

- Mongo TTL index uyumsuzlugu: `ix_apm_ttl already exists with different options`
- Mongo invalid UTF-8 body hatasi: `Detected invalid UTF-8 for field path "requestBody"`

## Guncelleme

```bash
composer require fogeto/laravel-server-orchestrator:1.1.11 --with-all-dependencies
php artisan optimize:clear
```

Docker ortaminda env/config degisti ise container'i recreate edin:

```bash
docker compose up -d --no-deps --force-recreate your-service-name
docker exec -ti your-container-name bash
php artisan optimize:clear
```

## Dogrulama

```bash
composer show fogeto/laravel-server-orchestrator | grep versions
php artisan tinker --execute='dump(config("server-orchestrator.apm.store")); dump(config("server-orchestrator.apm.service"));'
curl -i http://127.0.0.1/metrics
curl -i http://127.0.0.1/codex-apm-test-404
curl -s http://127.0.0.1/apm/errors?limit=5
```

Beklenen:

- `/metrics` `200 OK` doner.
- `/apm/errors` JSON array doner.
- Yeni 404 event'inde `path`, `method`, `statusCode`, `service` alanlari gorunur.

## Mongo TTL Index Hatasi

Sebep: Eski `ApmErrors.ix_apm_ttl` index'i ayni isimle ama farkli key/options ile kalmistir. `v1.1.11` uyumsuz TTL index'i otomatik dusurup dogru index'i yeniden olusturur.

Acil manuel temizlik:

```javascript
use orchestrator_ecommerce
db.ApmErrors.dropIndex("ix_apm_ttl")
```

Test verilerini de temizlemek istiyorsaniz:

```javascript
use orchestrator_ecommerce
db.ApmErrors.drop()
```

## Invalid UTF-8 Body Hatasi

Sebep: Bot/scanner binary body gonderir, ornek `Content-Type: application/dns-message`. MongoDB string alanlarda valid UTF-8 istedigi icin raw binary `requestBody` insert'i hata verir.

`v1.1.11` sonrasi invalid UTF-8 raw yazilmaz; guvenli placeholder yazilir:

```text
[non-utf8 string omitted; bytes=41; base64_prefix=...]
```

Bu degisiklik business request/response akisini etkilemez; sadece APM event persistence katmaninda veri sanitize eder.

## Full Rehber

Genel Mongo/Redis/APM/CORS kurulum adimlari icin `docs/UPGRADE_1.1.10.md` ve `docs/APM_TROUBLESHOOTING.md` dosyalarini da kullanin.
