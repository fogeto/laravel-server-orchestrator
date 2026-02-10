# Changelog

Tüm önemli değişiklikler bu dosyada belgelenir.  
Format [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) standardına uygundur.

## [1.0.2] - 2026-02-10

### Added
- Redis key'lerine otomatik TTL desteği eklendi (varsayılan 7 gün = 604800 saniye)
- `metrics_ttl` config ayarı (`ORCHESTRATOR_METRICS_TTL` env variable ile değiştirilebilir)
- `PredisAdapter::applyTtl()` helper metodu — her yazma işleminde TTL yenilenir
- Aktif metrikler canlı kalır, kullanılmayan metrikler otomatik temizlenir
- TTL'yi `null` yaparak sonsuz saklama moduna geçilebilir

## [1.0.1] - 2025-12-01

### Fixed
- VCS repository migration düzeltmesi
- `ext-sodium` bağımlılık sorunu giderildi

## [1.0.0] - 2025-11-15

### Added
- Prometheus metrics middleware (HTTP request duration, status code, method)
- SQL query metrics (DB::listen ile otomatik yakalama)
- Redis-backed storage adapter (PredisAdapter)
- Sistem metrikleri (PHP info, memory, uptime, database, opcache, health)
- Configurable histogram buckets
- Wipe endpoint ile metrik temizleme
- `MigrateFromInlineCommand` artisan komutu
- PostgreSQL desteği
