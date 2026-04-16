# Changelog

Tüm önemli değişiklikler bu dosyada belgelenir.  
Format [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) standardına uygundur.

## [2.0.0] - 2026-04-16

### Changed
- Varsayılan metrics surface rehberle birebir hizalandı; public route artık yalnızca `GET /metrics`
- HTTP metrics surface sadece `http_request_duration_seconds`, `http_requests_received_total` ve `http_requests_in_progress` ailelerini üretir
- SQL metrics label sırası rehbere göre `query_hash`, `operation`, `table`, `query` olarak değiştirildi
- SQL normalize/truncate davranışı rehbere göre suffix eklemeden düz kesme yapacak şekilde güncellendi
- DB metrics surface sadece `db_client_connections_max`, `db_client_connections_usage` ve `db_client_connections_pending_requests` ailelerini üretir
- APM JSON surface yalnızca GET endpoint'leri ile rehbere hizalandı; filtreler kaldırıldı ve varsayılan listede sadece incoming event'ler gösterilir
- APM IP koruması env üzerinden yönetilir hale getirildi ve varsayılanı `false` yapıldı

### Removed
- Varsayılan route surface'tan `/api/metrics`, `/wipe-metrics` ve `/api/wipe-metrics` kaldırıldı
- Varsayılan metrics surface'tan `http_requests_total`, `http_errors_total`, `db_connections_active`, `db_connections_max`, `php_info`, `process_*`, `php_opcache_*` ve `app_health_status` kaldırıldı
- APM DELETE endpoint'leri kaldırıldı

### Fixed
- APM timestamp, header redaction ve body truncation davranışı rehber formatına çekildi
- README ve docs varsayılan runtime surface ile yeniden hizalandı

## [1.1.0] - 2026-04-16

### Added
- HTTP tarafında `http_requests_received_total` ve `http_requests_in_progress` metrikleri eklendi
- SQL tarafında `sql_query_errors_total` metriği eklendi
- `/metrics` ve `/wipe-metrics` için kök route alias'ları eklendi
- DB pool görünürlüğü için `db_client_connections_usage`, `db_client_connections_max` ve `db_client_connections_pending_requests` metrikleri eklendi
- `ORCHESTRATOR_SQL_MAX_UNIQUE_QUERIES` env variable desteği eklendi

### Changed
- SQL query hash üretimi normalize edilmiş sorgu üzerinden kısa SHA-256 formatına geçirildi
- SQL query label davranışı rehber uyumu için varsayılan olarak açık hale getirildi
- APM outgoing capture davranışı `capture_outgoing` config ayarına bağlandı
- README ve dokümanlar yeni metrik seti ve `/metrics` kullanımıyla hizalandı

### Fixed
- `QueryException` durumlarında SQL hata metriğinin exception handler üzerinden raporlanması sağlandı
- Middleware ekleme akışında kernel method kontrolleri güvenli helper metodlara taşındı

## [1.0.6] - 2026-02-11

### Fixed
- **SQL observe() hata raporlaması eklendi:** Redis bağlantı veya serialization hataları artık `report()` ile loglanıyor (flood önlemek için istek başına sadece ilk hata)
- Production debug kolaylığı: hata artık sessizce yutulmuyor, Laravel log'larından izlenebilir

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
