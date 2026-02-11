# Changelog

Tüm önemli değişiklikler bu dosyada belgelenir.  
Format [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) standardına uygundur.

## [1.0.5] - 2026-02-11

### Fixed
- **SQL metrik verisi boş gelme sorunu çözüldü:** Buffer/flush pattern kaldırıldı, immediate observation'a geçildi
- Her SQL sorgusu anında Redis'e yazılır (PrometheusMiddleware ile aynı güvenilir yaklaşım)
- `app()->terminating()` callback zamanlaması kaynaklı veri kaybı problemi ortadan kalktı

### Changed
- Histogram nesnesi lazy olarak oluşturulur (ilk geçerli SQL sorgusunda) ve closure içinde cache'lenir
- Hata raporlama iyileştirildi: histogram oluşturma hatası `report()` ile loglanıyor
- Statik `$sqlBuffer` ve `$flushRegistered` property'leri kaldırıldı (artık gerekli değil)

## [1.0.4] - 2026-02-11

### Added
- **SQL Query Metrikleri aktif:** `DB::listen()` ile tüm SQL sorguları otomatik yakalanıyor
- `sql_query_duration_seconds` histogram — operation, table, query_hash label'ları ile
- `SqlParser` artık ServiceProvider tarafından çağrılıyor (önceden implement edilmiş ama bağlanmamıştı)
- `sql_metrics` config bloğu: enabled, include_query_label, query_max_length, ignore_patterns, histogram_buckets
- Ignore patterns: SHOW, SET, DESCRIBE, EXPLAIN, SAVEPOINT, migrations otomatik filtrelenir
- `ORCHESTRATOR_SQL_ENABLED` ve `ORCHESTRATOR_SQL_QUERY_LABEL` env variable desteği

### Changed
- **Buffer + Flush optimizasyonu:** SQL observation'ları istek boyunca buffer'da birikir, `app->terminating()` ile tek seferde Redis'e yazılır (N sorgu = 1 pipeline round-trip)
- Octane/Swoole uyumlu: buffer ve flag her istek sonunda sıfırlanır

## [1.0.3] - 2026-02-10

### Changed
- **Pipeline optimizasyonu:** Tüm Redis komutları tek round-trip'te gönderiliyor (20+ → 1)
- **Meta cache:** Metrik meta bilgisi process başına bir kez yazılır (gereksiz HSET eliminasyonu)
- **TTL throttle:** EXPIRE %5 olasılıkla çağrılır (her istekte değil, 7 günlük TTL'de güvenli)
- `executePipeline()` metodu — Predis native pipeline kullanır

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
