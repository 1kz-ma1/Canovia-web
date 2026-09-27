<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;

final class RequestPerformance
{
    public const ATTRIBUTE = 'canovia.performance';

    public int $queryCount = 0;
    public float $queryMs = 0.0;
    public int $sessionQueries = 0;
    public float $sessionMs = 0.0;

    /** @var array<string,array{count:int,ms:float,max_ms:float,table:string,operation:string}> */
    private array $shapes = [];

    /** @var array<string,array{count:int,ms:float,max_ms:float}> */
    private array $tables = [];

    public function add(QueryExecuted $event): void
    {
        $time = max(0.0, (float) $event->time);
        $this->queryCount++;
        $this->queryMs += $time;

        $operation = $this->operation($event->sql);
        $table = $this->table($event->sql, $operation);
        $shape = substr(hash('sha256', $event->sql), 0, 16);

        if (isset($this->shapes[$shape])) {
            $this->shapes[$shape]['count']++;
            $this->shapes[$shape]['ms'] += $time;
            $this->shapes[$shape]['max_ms'] = max($this->shapes[$shape]['max_ms'], $time);
        } elseif (count($this->shapes) < 120) {
            $this->shapes[$shape] = [
                'count' => 1,
                'ms' => $time,
                'max_ms' => $time,
                'table' => $table,
                'operation' => $operation,
            ];
        }

        if (! isset($this->tables[$table])) {
            $this->tables[$table] = ['count' => 0, 'ms' => 0.0, 'max_ms' => 0.0];
        }
        $this->tables[$table]['count']++;
        $this->tables[$table]['ms'] += $time;
        $this->tables[$table]['max_ms'] = max($this->tables[$table]['max_ms'], $time);

        if ($table === (string) config('session.table', 'sessions')) {
            $this->sessionQueries++;
            $this->sessionMs += $time;
        }
    }

    /** @return array<string,array{count:int,ms:float,max_ms:float}> */
    public function tableSummary(int $limit = 20): array
    {
        $items = $this->tables;
        uasort($items, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);

        return collect($items)
            ->take($limit)
            ->map(fn (array $item) => [
                'count' => $item['count'],
                'ms' => round($item['ms'], 2),
                'max_ms' => round($item['max_ms'], 2),
            ])
            ->all();
    }

    /** @return array<int,array{shape:string,count:int,ms:float,max_ms:float,table:string,operation:string}> */
    public function duplicateShapes(int $limit = 12): array
    {
        return collect($this->shapes)
            ->filter(fn (array $item) => $item['count'] > 1)
            ->sortByDesc('ms')
            ->take($limit)
            ->map(fn (array $item, string $shape) => [
                'shape' => $shape,
                'count' => $item['count'],
                'ms' => round($item['ms'], 2),
                'max_ms' => round($item['max_ms'], 2),
                'table' => $item['table'],
                'operation' => $item['operation'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int,array{shape:string,count:int,ms:float,max_ms:float,table:string,operation:string}> */
    public function slowestShapes(int $limit = 8): array
    {
        return collect($this->shapes)
            ->sortByDesc('max_ms')
            ->take($limit)
            ->map(fn (array $item, string $shape) => [
                'shape' => $shape,
                'count' => $item['count'],
                'ms' => round($item['ms'], 2),
                'max_ms' => round($item['max_ms'], 2),
                'table' => $item['table'],
                'operation' => $item['operation'],
            ])
            ->values()
            ->all();
    }

    public function duplicateQueryCount(): int
    {
        return (int) collect($this->shapes)
            ->sum(fn (array $item) => max(0, $item['count'] - 1));
    }

    private function operation(string $sql): string
    {
        return preg_match('/^\\s*(select|insert|update|delete|replace)\\b/i', $sql, $matches)
            ? strtolower($matches[1])
            : 'other';
    }

    private function table(string $sql, string $operation): string
    {
        $pattern = match ($operation) {
            'insert', 'replace' => '/\\binto\\s+["`]?([a-zA-Z0-9_]+)/i',
            'update' => '/^\\s*update\\s+["`]?([a-zA-Z0-9_]+)/i',
            'delete' => '/\\bfrom\\s+["`]?([a-zA-Z0-9_]+)/i',
            default => '/\\bfrom\\s+["`]?([a-zA-Z0-9_]+)/i',
        };

        if (! preg_match($pattern, $sql, $matches)) {
            return '_other';
        }

        return strtolower((string) $matches[1]);
    }
}
