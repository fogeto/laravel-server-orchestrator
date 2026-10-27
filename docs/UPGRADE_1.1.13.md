# v1.1.13: monitoring hata izolasyonu ve APM guvenligi

Bu PATCH release business request'lerinin monitoring arizalarindan etkilenmesini
engeller. Mevcut uygulama auth/token/permission/abonelik middleware'leri degismez.
Yeni dependency veya DB migration yoktur.

## APM feed yetkisini mevcut uygulama yetkisine baglayin

`/apm/errors`, `/_apm/errors`, `/__apm/errors` servis genelinde hassas telemetry
gosterir. Bu endpoint'ler artik anonymous isteklerde 401, yetkisiz kullanicida 403
doner. Varsayilan Gate ability `viewServerOrchestratorApm` olup tanimlanmadiginda
erisime izin verilmez. Normal business endpoint'lerine yeni auth kontrolu eklenmez.

Mevcut published config'i overwrite etmeden, kullandiginiz guard/admin/abonelik
middleware'lerini `apm.middleware` altina ekleyin. `routes.middleware` da korunur.
Ornek (permission ve middleware isimlerini projenizdeki mevcut isimlerle degistirin):

```php
'apm' => [
    'middleware' => ['auth:sanctum'],
    'authorization_ability' => 'monitoring.view',
    'sensitive_fields' => ['crm_credential'],
    // Mevcut apm mongo/redis/service ayarlarinizi koruyun.
],
```

`monitoring.view` ornek bir mevcut permission adidir; paket bu yetkiyi tanimlamaz
ve kullaniciya yetki vermez. Projenizdeki mevcut Gate/permission'i kullanin.
Alternatif olarak mevcut admin yetkinizi `viewServerOrchestratorApm` Gate'ine
baglayabilirsiniz. Tum authenticated kullanicilara servis geneli okuma izni vermeyin.

## Ariza davranisi

- Business istek bir kez calisir; kendi response veya exception'i korunur.
- Monitoring raporlayicisi hata verse veya tekrar SQL/HTTP event'i uretse de disari exception tasinmaz.
- Metrics storage kurulamiyor/okunamiyorsa `/metrics` generic 503 + no-store doner.
- Redis metrik yazmalari ilk arizadan sonra 5 saniye bastirilir. Sonraki deneme
  basariliysa kayit devam eder; kayip metrikler veya business istekleri replay edilmez.
- Hata raporlamasi paylasilan reporter basina en fazla 60 saniyede bir yapilir.
- Laravel FPM'de durum request/process omruyle sinirlidir; uzun yasayan worker'da
  cooldown process restart gerektirmeden sona erer.
- APM store okunamiyor/devre disi ise yetkili feed istegi bos 200 yerine 503 doner.

Ozel bir `IApmErrorStore` kullaniyorsaniz, okuma arizasini exception ile bildirin
veya `ApmStoreAvailability::isAvailable()` sozlesmesini uygulayin. Arizayi kendi
icinde yutup sadece `[]` donduren bir custom store'un durumunu paket ayirt edemez.

Opsiyonel config/env: `ORCHESTRATOR_FAILURE_COOLDOWN_SECONDS=5`,
`ORCHESTRATOR_REPORTING_COOLDOWN_SECONDS=60`.

## Payload ve eski event'ler

JSON/form alanlari recursive maskelenir. Password/token/secret/cookie/API key ve
benzeri bilinen isimler, request ve response header'lari ve URL/query degerleri
korunur. Relative/protocol-relative URL'ler de temizlenir. Guvenle ayristirilamayan
compound `Link`/`Refresh` header'lari atlanir. Ozel credential alanlarinizi
`apm.sensitive_fields` listesine ekleyin.
Yapisal olmayan, bozuk JSON veya binary body'ler placeholder olur; base64 prefix
tutulmaz. `message` ham response'tan degil maskelenmis response'tan turetilir.
Mevcut event'ler de feed'e cikmadan maskelenir; DB'deki eski kayitlar degistirilmez.
Eski plain-text/HTML error body'leri artik gorunmez; HTTP status/errorType kalir.

## Guncelleme ve deploy

Once gelistirme/CI ortaminda config ve permission eslemesini tamamlayin:

```bash
composer require "fogeto/laravel-server-orchestrator:1.1.13"
php artisan optimize:clear
composer show fogeto/laravel-server-orchestrator
```

`composer.json` ve `composer.lock` degisikliklerini uygulamanizin normal PR/deploy
akisina alin. Production'da test edilmis lock ile `composer install` kullanin.
Octane/queue gibi uzun yasayan runtime'lari projenizin normal deployment akisiyle
yenileyin; bu rehber production worker'larini durdurma talimati degildir.

## Kontrol ve geri donus

Staging'de kendi admin oturumunuz ile APM=200, guest=401, yetkisiz=403 ve abonelik
reddinin korunmasini kontrol edin. Izole test Redis baglantisini arizali yaparak
business response'un degismedigini ve metrics=503 oldugunu dogrulayin. Gercek
production Redis/Mongo servisini test icin durdurmayin. Kaynak kodu testleri gercek
ag/production dogrulamasi yerine gecmez.

Migration yoktur; DB rollback gerekmez. Geri donus uygulamanin onceki lock/artifact
deploy'udur; eski surumun APM guvenlik aciklarini geri getirecegini dikkate alin.
Yeni surumde APM feed'ini kapali tutmak (`apm.enabled=false`), guvenlik kontrollerini
kaldirmaktan daha dar bir gecici secenektir.
