<?php

namespace Fogeto\ServerOrchestrator\Helpers;

/**
 * SQL sorgularından operation, table ve query_hash bilgilerini çıkaran yardımcı sınıf.
 *
 * Regex tabanlı basit bir parser: SELECT, INSERT, UPDATE, DELETE gibi
 * temel operasyonları ve hedef tabloyu tespit eder.
 */
class SqlParser
{
    /**
     * SQL sorgusunu parse et ve temel bileşenlerini döndür.
     *
     * @param  string  $sql  SQL sorgusu
     * @return array{operation: string, table: string, query_hash: string}
     */
    public static function parse(string $sql): array
    {
        $operation = self::extractOperation($sql);
        $table = self::extractTable($sql, $operation);

        return [
            'operation' => $operation,
            'table' => $table,
            'query_hash' => md5($sql),
        ];
    }

    /**
     * SQL sorgusundan işlem türünü çıkar (SELECT, INSERT, UPDATE, DELETE vb).
     */
    public static function extractOperation(string $sql): string
    {
        $sql = trim($sql);

        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE)\b/i', $sql, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'OTHER';
    }

    /**
     * SQL sorgusundan ana tablo adını çıkar.
     *
     * Backtick, double-quote ve bracket notasyonlarını destekler:
     *   `table_name`, "table_name", [table_name]
     */
    public static function extractTable(string $sql, ?string $operation = null): string
    {
        $operation = $operation ?? self::extractOperation($sql);

        switch ($operation) {
            case 'SELECT':
            case 'DELETE':
                // SELECT ... FROM table_name | DELETE FROM table_name
                if (preg_match('/\bFROM\s+[`"\[\s]*(\w+)[`"\]\s]*/i', $sql, $matches)) {
                    return $matches[1];
                }
                break;

            case 'INSERT':
            case 'REPLACE':
                // INSERT INTO table_name | REPLACE INTO table_name
                if (preg_match('/\bINTO\s+[`"\[\s]*(\w+)[`"\]\s]*/i', $sql, $matches)) {
                    return $matches[1];
                }
                break;

            case 'UPDATE':
                // UPDATE table_name SET ...
                if (preg_match('/\bUPDATE\s+[`"\[\s]*(\w+)[`"\]\s]*/i', $sql, $matches)) {
                    return $matches[1];
                }
                break;

            case 'ALTER':
            case 'DROP':
            case 'TRUNCATE':
                // ALTER TABLE table_name / DROP TABLE table_name
                if (preg_match('/\bTABLE\s+[`"\[\s]*(\w+)[`"\]\s]*/i', $sql, $matches)) {
                    return $matches[1];
                }
                break;

            case 'CREATE':
                // CREATE TABLE [IF NOT EXISTS] table_name
                if (preg_match('/\bTABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[\s]*(\w+)[`"\]\s]*/i', $sql, $matches)) {
                    return $matches[1];
                }
                break;
        }

        return 'unknown';
    }

    /**
     * SQL sorgusundaki binding placeholder'larını (?) gerçek değerlerle değiştirir.
     *
     * Uzun string değerler truncate edilir (max 100 karakter).
     *
     * @param  string  $sql  SQL sorgusu (? placeholder'lı)
     * @param  array  $bindings  Binding değerleri
     * @return string Binding'leri yerleştirilmiş SQL
     */
    public static function interpolateBindings(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }

        $index = 0;

        return preg_replace_callback('/\?/', function () use ($bindings, &$index) {
            if (! isset($bindings[$index])) {
                return '?';
            }

            $value = $bindings[$index++];

            if (is_null($value)) {
                return 'NULL';
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (is_numeric($value)) {
                return (string) $value;
            }

            if ($value instanceof \DateTimeInterface) {
                return "'" . $value->format('Y-m-d H:i:s') . "'";
            }

            // String değerleri truncate et — Prometheus label'larında aşırı uzunluk sorun olur
            $stringValue = (string) $value;
            if (strlen($stringValue) > 100) {
                $stringValue = substr($stringValue, 0, 100) . '…';
            }

            return "'" . addslashes($stringValue) . "'";
        }, $sql);
    }

    /**
     * SQL sorgusunu metrik label'ı olarak güvenli hale getir.
     *
     * Çok uzun sorguları kısaltır ve gereksiz boşlukları temizler.
     *
     * @param  string  $sql  SQL sorgusu
     * @param  int  $maxLength  Maksimum karakter uzunluğu
     * @return string Sanitize edilmiş SQL
     */
    public static function sanitizeForLabel(string $sql, int $maxLength = 200): string
    {
        // Fazla boşlukları tek boşluğa indir
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        if (strlen($sql) > $maxLength) {
            return substr($sql, 0, $maxLength) . '…';
        }

        return $sql;
    }
}
