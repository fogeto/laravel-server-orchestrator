<?php

namespace Fogeto\ServerOrchestrator\Services;

use DateTimeImmutable;
use DateTimeZone;
use Fogeto\ServerOrchestrator\Contracts\IApmErrorStore;

class MongoApmErrorStore implements IApmErrorStore
{
    private static bool $indexesEnsured = false;

    private static bool $errorReported = false;

    private mixed $manager = null;

    private string $database = '';

    private string $collection = 'ApmErrors';

    private string $service = 'laravel';

    private bool $scopeByService = true;

    private bool $enabled = false;

    private bool $flushRegistered = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $pendingEvents = [];

    private int $channelCapacity;

    private int $batchSize;

    private int $maxLimit;

    public function __construct()
    {
        $config = config('server-orchestrator.apm', []);
        $mongo = $config['mongo'] ?? [];

        $this->channelCapacity = max(1, (int) ($config['channel_capacity'] ?? $config['max_buffer_size'] ?? 1000));
        $this->batchSize = max(1, (int) ($config['batch_size'] ?? 50));
        $this->maxLimit = max(1, (int) ($config['max_limit'] ?? 500));
        $this->database = trim((string) ($mongo['database'] ?? ''));
        $this->collection = trim((string) ($mongo['collection'] ?? 'ApmErrors')) ?: 'ApmErrors';
        $this->service = (string) ($config['service'] ?? config('server-orchestrator.prefix', config('app.name', 'laravel')));
        $this->scopeByService = (bool) ($config['scope_by_service'] ?? true);

        $connectionString = trim((string) ($mongo['connection_string'] ?? ''));
        if ($connectionString === '' || $this->database === '' || ! class_exists('MongoDB\\Driver\\Manager')) {
            return;
        }

        try {
            $this->manager = $this->newManager($connectionString);
            $this->enabled = true;
            $this->ensureIndexes();
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    public function tryEnqueue(array $event): bool
    {
        if (! $this->enabled || $this->manager === null) {
            return false;
        }

        if (count($this->pendingEvents) >= $this->channelCapacity) {
            return false;
        }

        $this->pendingEvents[] = $this->prepareDocument($event);
        $this->registerFlushCallback();

        return true;
    }

    public function getRecent(int $limit): array
    {
        $this->flush();

        if (! $this->enabled || $this->manager === null) {
            return [];
        }

        try {
            $query = $this->newQuery($this->queryFilter(), [
                'sort' => ['timestamp' => -1],
                'limit' => min(max(1, $limit), $this->maxLimit),
            ]);

            $cursor = $this->manager->executeQuery($this->namespace(), $query);

            $events = [];
            foreach ($cursor as $document) {
                if (is_object($document)) {
                    $events[] = $this->documentToArray($document);
                }
            }

            return $events;
        } catch (\Throwable $e) {
            $this->reportOnce($e);

            return [];
        }
    }

    public function clear(): void
    {
        $this->flush();

        if (! $this->enabled || $this->manager === null) {
            return;
        }

        try {
            $bulk = $this->newBulkWrite();
            $bulk->delete($this->queryFilter(), ['limit' => 0]);
            $this->manager->executeBulkWrite($this->namespace(), $bulk);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    private function registerFlushCallback(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        app()->terminating(function (): void {
            $this->flush();
        });

        $this->flushRegistered = true;
    }

    private function flush(): void
    {
        if (! $this->enabled || $this->manager === null || empty($this->pendingEvents)) {
            return;
        }

        try {
            foreach (array_chunk($this->pendingEvents, $this->batchSize) as $batch) {
                $bulk = $this->newBulkWrite(['ordered' => false]);
                foreach ($batch as $document) {
                    $bulk->insert($document);
                }

                $this->manager->executeBulkWrite($this->namespace(), $bulk);
            }
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        } finally {
            $this->pendingEvents = [];
        }
    }

    private function ensureIndexes(): void
    {
        if (self::$indexesEnsured || $this->manager === null) {
            return;
        }

        try {
            $ttl = $this->configuredTtl();

            $this->syncTtlIndex($ttl);

            $command = $this->newCommand([
                'createIndexes' => $this->collection,
                'indexes' => [
                    [
                        'name' => 'ix_apm_ttl',
                        'key' => ['timestamp' => 1],
                        'expireAfterSeconds' => $ttl,
                    ],
                    [
                        'name' => 'ix_apm_timestamp_status',
                        'key' => ['timestamp' => -1, 'statusCode' => 1],
                    ],
                    [
                        'name' => 'ix_apm_service_timestamp_status',
                        'key' => ['service' => 1, 'timestamp' => -1, 'statusCode' => 1],
                    ],
                ],
            ]);

            $this->manager->executeCommand($this->database, $command);
            self::$indexesEnsured = true;
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    private function configuredTtl(): int
    {
        return max(1, (int) config('server-orchestrator.apm.ttl', 86400));
    }

    private function syncTtlIndex(int $ttl): void
    {
        if ($this->manager === null) {
            return;
        }

        try {
            $cursor = $this->manager->executeCommand($this->database, $this->newCommand([
                'listIndexes' => $this->collection,
            ]));

            foreach ($cursor as $index) {
                $indexData = (array) $index;
                if (($indexData['name'] ?? '') !== 'ix_apm_ttl') {
                    continue;
                }

                if ((int) ($indexData['expireAfterSeconds'] ?? 0) !== $ttl) {
                    $this->manager->executeCommand($this->database, $this->newCommand([
                        'collMod' => $this->collection,
                        'index' => [
                            'name' => 'ix_apm_ttl',
                            'expireAfterSeconds' => $ttl,
                        ],
                    ]));
                }

                return;
            }
        } catch (\Throwable) {
            // Collection veya index henuz yoksa createIndexes komutu normal akisla olusturur.
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function prepareDocument(array $event): array
    {
        $document = $event;
        $document['service'] = (string) ($event['service'] ?? $this->service);
        $document['timestamp'] = $this->toUtcDateTime((string) ($event['timestamp'] ?? now('UTC')->format('Y-m-d\TH:i:s.v\Z')));

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function queryFilter(): array
    {
        return $this->scopeByService ? ['service' => $this->service] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentToArray(object $document): array
    {
        $result = [];
        foreach ((array) $document as $key => $value) {
            if ($key === '_id') {
                continue;
            }

            $result[(string) $key] = $this->normalizeValue($value);
        }

        return $result;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_object($value) && get_class($value) === 'MongoDB\\BSON\\UTCDateTime') {
            return $value
                ->toDateTime()
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z');
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeValue($item);
            }
        }

        return $value;
    }

    private function toUtcDateTime(string $timestamp): object
    {
        $dateTime = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
        $utc = $dateTime->setTimezone(new DateTimeZone('UTC'));
        $milliseconds = ((int) $utc->format('U')) * 1000 + (int) $utc->format('v');

        $class = 'MongoDB\\BSON\\UTCDateTime';

        return new $class($milliseconds);
    }

    private function namespace(): string
    {
        return $this->database . '.' . $this->collection;
    }

    private function newManager(string $connectionString): object
    {
        $class = 'MongoDB\\Driver\\Manager';

        return new $class($connectionString);
    }

    private function newQuery(array $filter, array $options): object
    {
        $class = 'MongoDB\\Driver\\Query';

        return new $class($filter, $options);
    }

    private function newBulkWrite(array $options = []): object
    {
        $class = 'MongoDB\\Driver\\BulkWrite';

        return new $class($options);
    }

    private function newCommand(array $command): object
    {
        $class = 'MongoDB\\Driver\\Command';

        return new $class($command);
    }

    private function reportOnce(\Throwable $e): void
    {
        if (self::$errorReported) {
            return;
        }

        report($e);
        self::$errorReported = true;
    }
}
