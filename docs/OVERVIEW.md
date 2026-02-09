# Proje Genel Bakış

## Amaç

**Laravel Server Orchestrator**, birden fazla Laravel projesinin tek bir Redis sunucusu üzerinden Prometheus metrikleri toplamasını sağlayan bir Composer paketidir. Her projeye benzersiz Redis key prefix'i atanarak veri izolasyonu garanti edilir.

### Problem

- Şirket bünyesinde birden fazla Laravel projesi çalışıyor (IK Backend, HR Portal, CRM vb.)
- Tüm projeler aynı Redis sunucusunu paylaşıyor
- Her projeye inline (elle) Prometheus entegrasyonu yazmak tekrar eden iş ve hata kaynağı
- Redis key'leri çakışıyor, projeler birbirinin verilerini eziyor

### Çözüm

Tek bir Composer paketi:
1. `composer require fogeto/laravel-server-orchestrator`
2. `.env`'e `ORCHESTRATOR_PREFIX=proje_adi`
3. Bitti — HTTP metrikleri, sistem metrikleri, sağlık kontrolü otomatik

---

## Paket Kimliği

| Alan | Değer |
|------|-------|
| Paket adı | `fogeto/laravel-server-orchestrator` |
| Namespace | `Fogeto\ServerOrchestrator` |
| GitHub | https://github.com/fogeto/laravel-server-orchestrator |
| Lisans | MIT |
| PHP | ^8.0 |
| Laravel | ^9.0, ^10.0, ^11.0, ^12.0 |
| Bağımlılıklar | `predis/predis ^2.0\|^3.0`, `promphp/prometheus_client_php ^2.2` |

---

## Dosya Yapısı

```
laravel-server-orchestrator/
├── composer.json
├── README.md
├── .gitignore
├── config/
│   └── server-orchestrator.php          # Tüm konfigürasyon
├── docs/
│   ├── OVERVIEW.md                      # Bu dosya
│   ├── ARCHITECTURE.md                  # Mimari ve bileşen detayları
│   ├── METRICS_REFERENCE.md             # Tüm metriklerin referansı
│   ├── TECHNICAL_NOTES.md               # Bug fix'ler, edge case'ler, kararlar
│   └── EXPECTED_OUTPUTS.md              # Beklenen metrik çıktıları
├── routes/
│   └── metrics.php                      # GET /metrics, POST /wipe-metrics
└── src/
    ├── Adapters/
    │   └── PredisAdapter.php            # Redis storage adapter (custom)
    ├── Console/
    │   └── Commands/
    │       └── MigrateFromInlineCommand.php  # orchestrator:migrate komutu
    ├── Http/
    │   ├── Controllers/
    │   │   └── MetricsController.php    # Metrics endpoint + sistem metrikleri
    │   └── Middleware/
    │       └── PrometheusMiddleware.php  # HTTP istek metrikleri
    └── Providers/
        └── ServerOrchestratorServiceProvider.php  # Auto-discovery provider
```

---

## Akış Diyagramı

```
HTTP İsteği gelir
       │
       ▼
┌──────────────────────┐
│  PrometheusMiddleware │  ← API middleware grubunda otomatik kayıtlı
│  (before + after)     │
└──────┬───────────────┘
       │
       │  1. shouldIgnore() → ignore_paths kontrolü
       │  2. $start = microtime(true)
       │  3. $response = $next($request)
       │  4. $duration = microtime(true) - $start
       │  5. resolveEndpoint() → URI normalizasyonu ({id}, {uuid})
       │  6. resolveControllerAction() → Controller@method
       │
       ├── histogram.observe($duration, labels)
       ├── counter.inc(labels)
       └── if (4xx/5xx) → errorCounter.inc(labels)
              │
              ▼
         Redis'e yazılır
         (PredisAdapter)
              │
              ▼
┌──────────────────────────┐
│  GET /api/metrics        │
│  MetricsController@index │
│  1. collectSystemMetrics │  ← Anlık gauge'lar (PHP, memory, DB, OPcache)
│  2. registry->collect()  │  ← Redis'ten histogram/counter/gauge oku
│  3. RenderTextFormat     │  ← Prometheus text format'a çevir
└──────────────────────────┘
              │
              ▼
    Prometheus scrape eder
              │
              ▼
    Grafana'da dashboard
```

---

## Hızlı Başlangıç (Geliştirici)

### Lokal Geliştirme

```bash
# 1. Repo'yu klonla
git clone https://github.com/fogeto/laravel-server-orchestrator.git
cd laravel-server-orchestrator

# 2. Bağımlılıkları yükle
composer install

# 3. Bir Laravel projesine symlink olarak bağla
# Ayrı projenin composer.json'ına ekle:
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-server-orchestrator"
        }
    ],
    "require": {
        "fogeto/laravel-server-orchestrator": "@dev"
    }
}

# 4. composer update
cd ../laravel-projesi
composer update fogeto/laravel-server-orchestrator
```

### Test Etme

```bash
# Sunucuyu başlat
php artisan serve

# Metrikleri temizle
curl -X POST http://localhost:8000/api/wipe-metrics

# Birkaç istek at
curl http://localhost:8000/api/users
curl http://localhost:8000/api/users
curl -X POST http://localhost:8000/api/admin/login

# Metrikleri kontrol et
curl http://localhost:8000/api/metrics
```

### Değişiklik Yaptıktan Sonra

```bash
# Paketin bağlı olduğu projede autoload'u güncelle
cd ../laravel-projesi
composer dump-autoload

# Config cache'ini temizle
php artisan config:clear
```
