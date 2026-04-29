# Laravel Server Orchestrator v1.1.3 Guncelleme Rehberi

Bu dokuman, paketi kendi Laravel projelerinde kullanan developer'lar icin hazirlanmistir.

v1.1.3 ile gelen kritik noktalar:

- APM capture middleware'i global kayit mantigina alindi. Boylece route 404'leri ve grup disi hata response'lari daha tutarli yakalanir.
- Eski Redis metric verileri yeni label semasiyla uyusmazsa `/metrics` endpoint'inin cökmesi engellendi.
- Mongo APM kullanan projelerde publish edilmis eski config dosyalari icin yeniden publish veya manuel merge zorunlu olabilir.

## Kimler Bu Rehberi Uygulamali

- `fogeto/laravel-server-orchestrator` paketini hali hazirda kullanan tum Laravel projeleri
- Ozellikle APM'i MongoDB ile aktif kullanmak isteyen projeler
- `/metrics` endpoint'inde eski Redis verisinden kaynakli render hatasi goren projeler

## On Kosullar

- Host proje paketi Composer ile kullaniyor olmali.
- Uygulama sunucusunda `ext-mongodb` yuklu olmali.
- Varsayilan metrics storage kullaniliyorsa Redis erisimi olmali.
- Production ortaminda APM endpoint'i acik kalacagi icin gerekiyorsa reverse proxy, firewall veya auth katmani planlanmis olmali.

## Desteklenen Mongo Env'leri

Paket sadece asagidaki env anahtarlarini okur:

```env
Logging__MongoDB__ConnectionString=mongodb://user:pass@host:27017/?authSource=admin
Logging__MongoDB__DatabaseName=ecommerce
```

Notlar:

- Her proje MongoDB database adini kendi proje ismine gore vermelidir.
- Ornek isimler: `ecommerce`, `crm`, `hrportal`, `ikportal`.
- Collection adi env'den okunmaz.
- Paket collection adini sabit olarak `ApmErrors` kullanir.
- `Logging__MongoDB__CollectionName` bu paket tarafinda desteklenmez.
- Mümkünse `root` yerine sadece ilgili database'e yetkili ayri bir Mongo kullanicisi kullanin.

## Guncelleme Adimlari

### 1. Proje dizinine gir

```bash
cd /path/to/your-project
```

### 2. Paketi v1.1.3'e cek

```bash
composer require fogeto/laravel-server-orchestrator:1.1.3 --with-all-dependencies
```

Alternatif olarak paket zaten tanimliysa su komut da kullanilabilir:

```bash
composer update fogeto/laravel-server-orchestrator --with-all-dependencies
```

### 3. Publish edilmis config var mi kontrol et

Host projede daha once `server-orchestrator` config'i publish edildiyse eski config yeni nested anahtarlari otomatik almayabilir.

Config publish edilmisse iki secenek vardir:

#### Secenek A: Guvenli manuel merge

- Host projedeki `config/server-orchestrator.php` dosyasini acin.
- Paketin yeni `apm.mongo` blogunu ve gerekiyorsa yeni APM anahtarlarini elle ekleyin.

#### Secenek B: Force publish

Bu yontem host projedeki o config dosyasindaki degisiklikleri ezer. Once yedek alin.

```bash
cp config/server-orchestrator.php config/server-orchestrator.php.bak
php artisan vendor:publish --tag=server-orchestrator-config --force
```

### 4. Env degiskenlerini guncelle

Ornek:

```env
Logging__MongoDB__ConnectionString=mongodb://user:pass@172.168.30.99:27017/?authSource=admin
Logging__MongoDB__DatabaseName=ecommerce
ORCHESTRATOR_PREFIX=ecommerce_api
```

Notlar:

- `ORCHESTRATOR_PREFIX` ayni Redis sunucusunu kullanan her proje icin benzersiz olmali.
- Connection string icinde database yazsaniz bile, paket ayri olarak `Logging__MongoDB__DatabaseName` degerini kullanir.
- Database adini ortak tek bir isim yerine proje bazli secin. Ornek: `ecommerce` projesi icin `ecommerce`, `crm` projesi icin `crm`.

### 5. Cache temizle

```bash
php artisan optimize:clear
```

### 6. Runtime'i yeniden yukle

Kullandiginiz ortama gore asagidakilerden uygun olani uygulayin:

```bash
php artisan horizon:terminate
php artisan octane:reload
```

Docker kullaniyorsaniz ilgili app container'ini yeniden baslatin.

PHP-FPM kullaniyorsaniz PHP-FPM servis restart'i yapin.

## Opsiyonel: Eski Metrics Verisini Temizleme

Eger `/metrics` endpoint'inde eski Redis verisinden kaynakli label uyumsuzlugu yasadiysaniz, guncelleme sonrasi bir kere asagidaki komutu calistirin:

```bash
php artisan tinker --execute='app(\Prometheus\CollectorRegistry::class)->wipeStorage();'
```

Bu komut mevcut `ORCHESTRATOR_PREFIX` altindaki metric storage'i temizler.

## Dogrulama Adimlari

### 1. Mongo config runtime'da yukleniyor mu

```bash
php artisan tinker --execute='dump(config("server-orchestrator.apm.mongo"));'
```

Beklenen:

```php
array:3 [
  "connection_string" => "mongodb://..."
  "database" => "ecommerce"
  "collection" => "ApmErrors"
]
```

### 2. Route yuzeyi dogru mu

```bash
php artisan route:list | grep -E "metrics|apm"
```

Beklenen yuzey:

```text
GET|HEAD  metrics
GET|HEAD  __apm/errors
GET|HEAD  apm/errors
```

### 3. APM capture testi

Bir `400`, `404` veya `500` olusturun.

Ornek:

```bash
curl -i https://your-domain.example.com/olmayan-endpoint
curl https://your-domain.example.com/apm/errors?limit=5
```

Beklenen:

- `/apm/errors` JSON array donmeli
- Yeni olusan hata event'i listede gorunmeli

### 4. Mongo tarafi dogrulama

Mongo Express veya Mongo shell ile su yapinin olustugunu kontrol edin:

- Database: proje adinizla olusturdugunuz DB. Ornek: `ecommerce`
- Collection: `ApmErrors`
- Indexes:
  - `_id`
  - `ix_apm_ttl`
  - `ix_apm_timestamp_status`

## Sik Karsilasilan Sorunlar

### `config("server-orchestrator.apm.mongo")` null donuyor

Sebep:

- Host projede eski publish edilmis config dosyasi yeni `apm.mongo` blogunu icermiyor.

Cozum:

- `config/server-orchestrator.php` dosyasini manuel merge edin veya
- `php artisan vendor:publish --tag=server-orchestrator-config --force` calistirin.

### `/apm/errors` bos donuyor

Kontrol edin:

- `ext-mongodb` yuklu mu
- runtime restart yapildi mi
- gercekten yakalanabilir bir `4xx/5xx` event olustu mu

### `/metrics` endpoint'i `array_combine()` hatasi veriyor

Sebep:

- Redis'te eski label semasina ait metric verisi kalmistir.

Cozum:

```bash
php artisan tinker --execute='app(\Prometheus\CollectorRegistry::class)->wipeStorage();'
```

### Mongo Express'te `ApmErrors` gorunmuyor

Kontrol edin:

- Dogru Mongo instance'a mi bakiyorsunuz
- Dogru database'e mi bakiyorsunuz
- yeni hata event'i gonderildikten sonra sayfa yenilendi mi

## Geri Donus Plani

Sorun halinde gecici olarak onceki versiyona donebilirsiniz:

```bash
composer require fogeto/laravel-server-orchestrator:1.1.2 --with-all-dependencies
```

Eger force publish yapip host config'i ezdiyseniz, yedeginizi geri donun:

```bash
cp config/server-orchestrator.php.bak config/server-orchestrator.php
php artisan optimize:clear
```

## Ozet

Minimum guncelleme akisi:

1. `composer require fogeto/laravel-server-orchestrator:1.1.3 --with-all-dependencies`
2. Gerekirse `vendor:publish --force`
3. Mongo env'lerini kontrol et
4. `php artisan optimize:clear`
5. Runtime restart yap
6. Gerekirse `wipeStorage()` calistir
7. `/apm/errors` ve `/metrics` ile dogrula