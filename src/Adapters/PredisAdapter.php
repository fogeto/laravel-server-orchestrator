<?php

namespace Fogeto\ServerOrchestrator\Adapters;

use Illuminate\Redis\Connections\Connection;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;

class PredisAdapter implements Adapter
{
    private string $prefix;

    private ?int $ttl;

    /**
     * In-memory meta cache — aynı metriğin meta bilgisi tekrar yazılmasını önler.
     * Process başına bir kez yazılır, sonraki çağrılar skip edilir.
     *
     * @var array<string, true>
     */
    private static array $metaWritten = [];

    /**
     * @param  string  $prefix  Redis key prefix'i (proje izolasyonu için)
     * @param  int|null  $ttl  Redis key TTL (saniye). null ise TTL uygulanmaz.
     */
    public function __construct(private Connection $redis, string $prefix = 'prometheus:app:', ?int $ttl = 604800)
    {
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    public function wipeStorage(): void
    {
        // Laravel Redis bağlantısı otomatik prefix ekler (ör. laravel_database_).
        // keys() sonuçları bu prefix ile döner ama del() tekrar prefix ekler (double-prefix).
        // Lua script Redis server tarafında çalıştığı için prefix sorunu olmaz.
        $connectionPrefix = $this->getConnectionPrefix();
        $fullPattern = $connectionPrefix . $this->prefix . '*';

        $luaScript = <<<'LUA'
local keys = redis.call('KEYS', ARGV[1])
local deleted = 0
for i = 1, #keys, 100 do
    local chunk = {}
    for j = i, math.min(i + 99, #keys) do
        table.insert(chunk, keys[j])
    end
    deleted = deleted + redis.call('DEL', unpack(chunk))
end
return deleted
LUA;

        $this->redis->eval($luaScript, 0, $fullPattern);
    }

    /**
     * Redis connection prefix'ini al (ör. laravel_database_).
     * Predis client'a set edilmiş prefix processor'dan okunur.
     */
    private function getConnectionPrefix(): string
    {
        try {
            $client = $this->redis->client();
            $options = $client->getOptions();

            if (isset($options->prefix)) {
                return $options->prefix->getPrefix();
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return '';
    }

    public function updateGauge(array $data): void
    {
        $key = $this->prefix . 'gauges:' . $data['name'];
        $metaKey = $this->prefix . 'gauges:meta';
        $labelKey = $this->encodeLabelValues($data['labelValues']);

        $commands = [
            ['hset', $key, $labelKey, $data['value']],
        ];

        // Meta bilgisi sadece process başına bir kez yazılır
        if (! isset(self::$metaWritten[$metaKey . ':' . $data['name']])) {
            $commands[] = ['hset', $metaKey, $data['name'], json_encode([
                'name' => $data['name'],
                'help' => $data['help'],
                'labelNames' => $data['labelNames'],
            ])];
            self::$metaWritten[$metaKey . ':' . $data['name']] = true;
        }

        $this->executePipeline($commands, $key, $metaKey);
    }

    public function updateCounter(array $data): void
    {
        $key = $this->prefix . 'counters:' . $data['name'];
        $metaKey = $this->prefix . 'counters:meta';
        $labelKey = $this->encodeLabelValues($data['labelValues']);

        $commands = [
            ['hincrbyfloat', $key, $labelKey, $data['value']],
        ];

        if (! isset(self::$metaWritten[$metaKey . ':' . $data['name']])) {
            $commands[] = ['hset', $metaKey, $data['name'], json_encode([
                'name' => $data['name'],
                'help' => $data['help'],
                'labelNames' => $data['labelNames'],
            ])];
            self::$metaWritten[$metaKey . ':' . $data['name']] = true;
        }

        $this->executePipeline($commands, $key, $metaKey);
    }

    public function updateHistogram(array $data): void
    {
        $key = $this->prefix . 'histograms:' . $data['name'];
        $metaKey = $this->prefix . 'histograms:meta';
        $labelKey = $this->encodeLabelValues($data['labelValues']);

        $commands = [
            ['hincrby', $key, $labelKey . ':count', 1],
            ['hincrbyfloat', $key, $labelKey . ':sum', $data['value']],
        ];

        // Bucket'lar (kümülatif — değerden büyük veya eşit tüm bucket'lara 1 ekle)
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $commands[] = ['hincrby', $key, $labelKey . ':bucket:' . $bucket, 1];
            }
        }

        if (! isset(self::$metaWritten[$metaKey . ':' . $data['name']])) {
            $commands[] = ['hset', $metaKey, $data['name'], json_encode([
                'name' => $data['name'],
                'help' => $data['help'],
                'labelNames' => $data['labelNames'],
                'buckets' => $data['buckets'],
            ])];
            self::$metaWritten[$metaKey . ':' . $data['name']] = true;
        }

        $this->executePipeline($commands, $key, $metaKey);
    }

    public function updateSummary(array $data): void
    {
        // Summary desteği gerekirse implement edilebilir
    }

    /**
     * Tüm metrikleri topla ve döndür.
     *
     * @return MetricFamilySamples[]
     */
    public function collect(bool $sortMetrics = true): array
    {
        $metrics = [];

        $metrics = array_merge($metrics, $this->collectGauges());
        $metrics = array_merge($metrics, $this->collectCounters());
        $metrics = array_merge($metrics, $this->collectHistograms());

        if ($sortMetrics) {
            usort($metrics, fn($a, $b) => strcmp($a->getName(), $b->getName()));
        }

        return $metrics;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectGauges(): array
    {
        $metrics = [];
        $metaKey = $this->prefix . 'gauges:meta';
        $metas = $this->redis->hgetall($metaKey);

        foreach ($metas as $name => $metaJson) {
            $meta = json_decode($metaJson, true);
            $key = $this->prefix . 'gauges:' . $name;
            $values = $this->redis->hgetall($key);

            $samples = [];
            foreach ($values as $labelKey => $value) {
                $samples[] = [
                    'name' => $meta['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelKey),
                    'value' => (float) $value,
                ];
            }

            $metrics[] = new MetricFamilySamples([
                'name' => $meta['name'],
                'type' => 'gauge',
                'help' => $meta['help'],
                'labelNames' => $meta['labelNames'],
                'samples' => $samples,
            ]);
        }

        return $metrics;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectCounters(): array
    {
        $metrics = [];
        $metaKey = $this->prefix . 'counters:meta';
        $metas = $this->redis->hgetall($metaKey);

        foreach ($metas as $name => $metaJson) {
            $meta = json_decode($metaJson, true);
            $key = $this->prefix . 'counters:' . $name;
            $values = $this->redis->hgetall($key);

            $samples = [];
            foreach ($values as $labelKey => $value) {
                $samples[] = [
                    'name' => $meta['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelKey),
                    'value' => (float) $value,
                ];
            }

            $metrics[] = new MetricFamilySamples([
                'name' => $meta['name'],
                'type' => 'counter',
                'help' => $meta['help'],
                'labelNames' => $meta['labelNames'],
                'samples' => $samples,
            ]);
        }

        return $metrics;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectHistograms(): array
    {
        $metrics = [];
        $metaKey = $this->prefix . 'histograms:meta';
        $metas = $this->redis->hgetall($metaKey);

        foreach ($metas as $name => $metaJson) {
            $meta = json_decode($metaJson, true);
            $key = $this->prefix . 'histograms:' . $name;
            $values = $this->redis->hgetall($key);

            // Label değerlerine göre grupla
            // ÖNEMLİ: labelKey kendisi base64 değerlerini ':' ile birleştirir,
            // bu yüzden suffix'i sondan ayırmak gerekiyor (explode kullanma!).
            $grouped = [];
            foreach ($values as $field => $value) {
                if (str_ends_with($field, ':sum')) {
                    $labelKey = substr($field, 0, -4);

                    if (! isset($grouped[$labelKey])) {
                        $grouped[$labelKey] = ['sum' => 0, 'count' => 0, 'buckets' => []];
                    }

                    $grouped[$labelKey]['sum'] = (float) $value;
                } elseif (str_ends_with($field, ':count')) {
                    $labelKey = substr($field, 0, -6);

                    if (! isset($grouped[$labelKey])) {
                        $grouped[$labelKey] = ['sum' => 0, 'count' => 0, 'buckets' => []];
                    }

                    $grouped[$labelKey]['count'] = (int) $value;
                } elseif (preg_match('/^(.+):bucket:([^:]+)$/', $field, $matches)) {
                    $labelKey = $matches[1];
                    $le = $matches[2];

                    if (! isset($grouped[$labelKey])) {
                        $grouped[$labelKey] = ['sum' => 0, 'count' => 0, 'buckets' => []];
                    }

                    $grouped[$labelKey]['buckets'][$le] = (int) $value;
                }
            }

            // Her label grubu için Prometheus sample'ları oluştur
            $samples = [];
            foreach ($grouped as $labelKey => $data) {
                $labelValues = $this->decodeLabelValues($labelKey);

                // Bucket sample'ları (kümülatif)
                foreach ($meta['buckets'] as $bucket) {
                    $samples[] = [
                        'name' => $meta['name'] . '_bucket',
                        'labelNames' => ['le'],
                        'labelValues' => array_merge($labelValues, [(string) $bucket]),
                        'value' => $data['buckets'][(string) $bucket] ?? 0,
                    ];
                }

                // +Inf bucket
                $samples[] = [
                    'name' => $meta['name'] . '_bucket',
                    'labelNames' => ['le'],
                    'labelValues' => array_merge($labelValues, ['+Inf']),
                    'value' => $data['count'],
                ];

                // Sum
                $samples[] = [
                    'name' => $meta['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $data['sum'],
                ];

                // Count
                $samples[] = [
                    'name' => $meta['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $data['count'],
                ];
            }

            $metrics[] = new MetricFamilySamples([
                'name' => $meta['name'],
                'type' => 'histogram',
                'help' => $meta['help'],
                'labelNames' => $meta['labelNames'],
                'samples' => $samples,
            ]);
        }

        return $metrics;
    }

    /**
     * Tüm komutları tek bir Redis pipeline'da çalıştır.
     * TTL varsa %5 olasılıkla EXPIRE ekler (her istekte değil).
     *
     * Optimizasyon: 20+ ayrı round-trip → 1 round-trip.
     * Predis client'ın native pipeline'ı kullanılır.
     */
    private function executePipeline(array $commands, string ...$ttlKeys): void
    {
        // TTL throttle — her istekte EXPIRE çağırmak yerine
        // %5 olasılıkla (ortalama her 20 istekte bir) TTL yenile.
        // 7 günlük TTL'de bu yeterince güvenli.
        $shouldExpire = $this->ttl !== null && random_int(1, 20) === 1;

        $client = $this->redis->client();

        $responses = $client->pipeline(function ($pipe) use ($commands, $ttlKeys, $shouldExpire) {
            foreach ($commands as $cmd) {
                $method = $cmd[0];
                $args = array_slice($cmd, 1);
                $pipe->$method(...$args);
            }

            if ($shouldExpire) {
                foreach ($ttlKeys as $key) {
                    $pipe->expire($key, $this->ttl);
                }
            }
        });
    }

    /**
     * Label değerlerini Redis field key'ine encode et.
     * Her değer base64 ile encode edilir ve ':' ile birleştirilir.
     */
    private function encodeLabelValues(array $labelValues): string
    {
        return implode(':', array_map(fn($v) => base64_encode((string) $v), $labelValues));
    }

    /**
     * Redis field key'inden label değerlerini decode et.
     */
    private function decodeLabelValues(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }

        return array_map(fn($v) => base64_decode($v), explode(':', $encoded));
    }
}
