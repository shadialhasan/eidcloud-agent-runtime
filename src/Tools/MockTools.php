<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

use InvalidArgumentException;
use RuntimeException;

/**
 * Mock Database Tool for simulating database operations in tests and dry-run agent workflows.
 */
class MockDatabaseTool extends AbstractTool
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $tables = [];

    public function __construct()
    {
        $this->name = 'mock_database';
        $this->description = 'Simulate database queries, insertions, and table counts for mock and dry-run environments.';
        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'The action to perform: query, insert, count, or list_tables',
                    'enum' => ['query', 'insert', 'count', 'list_tables'],
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'The table name (required for query, insert, count)',
                ],
                'data' => [
                    'type' => 'object',
                    'description' => 'Data record to insert (required for insert)',
                ],
                'filter' => [
                    'type' => 'object',
                    'description' => 'Key-value pairs to filter records',
                ],
            ],
            'required' => ['action'],
        ];

        // Seed with sample data
        $this->tables = [
            'users' => [
                ['id' => 1, 'name' => 'Alice Johnson', 'role' => 'admin', 'status' => 'active'],
                ['id' => 2, 'name' => 'Bob Smith', 'role' => 'developer', 'status' => 'active'],
                ['id' => 3, 'name' => 'Carol Williams', 'role' => 'tester', 'status' => 'inactive'],
            ],
            'tasks' => [
                ['id' => 101, 'title' => 'Implement agent planner', 'assigned_to' => 2, 'status' => 'completed'],
                ['id' => 102, 'title' => 'Integrate tool registry', 'assigned_to' => 2, 'status' => 'in_progress'],
                ['id' => 103, 'title' => 'Run CI pipeline checks', 'assigned_to' => 3, 'status' => 'pending'],
            ],
            'metrics' => [
                ['metric' => 'latency_ms', 'value' => 45.2, 'timestamp' => '2026-10-01T12:00:00Z'],
                ['metric' => 'tokens_used', 'value' => 1250, 'timestamp' => '2026-10-01T12:05:00Z'],
            ],
        ];
    }

    public function execute(array $args): array|string
    {
        $this->validateRequired($args, ['action']);
        $action = $args['action'];

        return match ($action) {
            'list_tables' => [
                'tables' => array_keys($this->tables),
                'count' => count($this->tables),
            ],
            'count' => $this->handleCount($args),
            'query' => $this->handleQuery($args),
            'insert' => $this->handleInsert($args),
            default => throw new InvalidArgumentException("Unsupported database action: '$action'"),
        };
    }

    private function handleCount(array $args): array
    {
        $this->validateRequired($args, ['table']);
        $table = $args['table'];

        if (!isset($this->tables[$table])) {
            return ['table' => $table, 'count' => 0, 'exists' => false];
        }

        return [
            'table' => $table,
            'count' => count($this->tables[$table]),
            'exists' => true,
        ];
    }

    private function handleQuery(array $args): array
    {
        $this->validateRequired($args, ['table']);
        $table = $args['table'];

        if (!isset($this->tables[$table])) {
            return ['table' => $table, 'records' => [], 'found' => 0];
        }

        $records = $this->tables[$table];
        $filter = $args['filter'] ?? [];

        if (!empty($filter) && is_array($filter)) {
            $records = array_values(array_filter($records, function (array $row) use ($filter): bool {
                foreach ($filter as $key => $val) {
                    if (!isset($row[$key]) || $row[$key] != $val) {
                        return false;
                    }
                }
                return true;
            }));
        }

        return [
            'table' => $table,
            'records' => $records,
            'found' => count($records),
        ];
    }

    private function handleInsert(array $args): array
    {
        $this->validateRequired($args, ['table', 'data']);
        $table = $args['table'];
        $data = (array) $args['data'];

        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
        }

        if (!isset($data['id'])) {
            $data['id'] = count($this->tables[$table]) + 1;
        }

        $this->tables[$table][] = $data;

        return [
            'table' => $table,
            'inserted' => $data,
            'total_rows' => count($this->tables[$table]),
        ];
    }
}

/**
 * Mock Calculator Tool for arithmetic and mathematical simulations.
 */
class MockCalculatorTool extends AbstractTool
{
    public function __construct()
    {
        $this->name = 'mock_calculator';
        $this->description = 'Perform arithmetic calculations and mathematical aggregations safely.';
        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'expression' => [
                    'type' => 'string',
                    'description' => 'Math expression to evaluate, e.g., "120 + 45 * 2" or "sum(10, 20, 30)"',
                ],
            ],
            'required' => ['expression'],
        ];
    }

    public function execute(array $args): array|string
    {
        $this->validateRequired($args, ['expression']);
        $expr = trim((string) $args['expression']);

        // Handle sum(1, 2, 3)
        if (preg_match('/^sum\(([\d\s,\.\-]+)\)$/i', $expr, $matches)) {
            $parts = array_map('trim', explode(',', $matches[1]));
            $sum = 0.0;
            foreach ($parts as $p) {
                if (!is_numeric($p)) {
                    throw new InvalidArgumentException("Non-numeric argument in sum: '$p'");
                }
                $sum += (float) $p;
            }
            return ['expression' => $expr, 'result' => $sum];
        }

        // Handle basic arithmetic safely without dynamic evaluation
        $sanitized = preg_replace('/[^0-9\\+\\-\\*\\/\\(\\)\\.\\s]/', '', $expr);
        if ($sanitized !== $expr || empty(trim($sanitized))) {
            throw new InvalidArgumentException("Invalid characters in math expression: '$expr'");
        }

        try {
            $tokens = preg_split('/([+\\-*\\/()])|\\s+/', $sanitized, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            $pos = 0;

            $parseFactor = function() use (&$parseExpr, &$parseFactor, &$tokens, &$pos): float {
                if ($pos >= count($tokens)) return 0.0;
                $t = $tokens[$pos++];
                if ($t === '+') return +$parseFactor();
                if ($t === '-') return -$parseFactor();
                if ($t === '(') {
                    $val = $parseExpr();
                    if ($pos < count($tokens) && $tokens[$pos] === ')') $pos++;
                    return $val;
                }
                return is_numeric($t) ? (float)$t : 0.0;
            };

            $parseTerm = function() use (&$parseTerm, &$parseFactor, &$tokens, &$pos): float {
                $val = $parseFactor();
                while ($pos < count($tokens) && in_array($tokens[$pos], ['*', '/'], true)) {
                    $op = $tokens[$pos++];
                    $rhs = $parseFactor();
                    if ($op === '*') $val *= $rhs;
                    elseif ($op === '/') {
                        if ($rhs != 0.0) $val /= $rhs;
                    }
                }
                return $val;
            };

            $parseExpr = function() use (&$parseExpr, &$parseTerm, &$tokens, &$pos): float {
                $val = $parseTerm();
                while ($pos < count($tokens) && in_array($tokens[$pos], ['+', '-'], true)) {
                    $op = $tokens[$pos++];
                    $rhs = $parseTerm();
                    $val = $op === '+' ? $val + $rhs : $val - $rhs;
                }
                return $val;
            };

            $calc = $parseExpr();
            return ['expression' => $expr, 'result' => (floor($calc) === $calc) ? (int)$calc : $calc];
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to calculate: " . $e->getMessage());
        }
    }
}

/**
 * Mock Search Tool for simulating retrieval of knowledge or documentation.
 */
class MockSearchTool extends AbstractTool
{
    /** @var array<string, string> */
    private array $corpus = [
        'php' => 'PHP 8.2 introduced readonly classes, disjunctive normal form types, and true/false/null types.',
        'agents' => 'Autonomous AI agents utilize prompt orchestration, memory buffers, tool dispatch, and iterative trace inspection.',
        'eidcloud' => 'EidCloud provides next-generation cloud infrastructure, AI runtime services, and serverless computing.',
        'runtime' => 'Agent runtime executes ReAct loops (Plan, Act, Observe, Reflect) within token budgets and permission boundaries.',
        'architecture' => 'Modular architecture isolates Core, Memory, Tools, and Trace for high testability and reliability.',
    ];

    public function __construct()
    {
        $this->name = 'mock_search';
        $this->description = 'Search mock knowledge base for keywords and documentation snippets.';
        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query or keyword',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args): array|string
    {
        $this->validateRequired($args, ['query']);
        $query = strtolower(trim((string) $args['query']));
        $matches = [];

        foreach ($this->corpus as $key => $snippet) {
            if (str_contains(strtolower($key), $query) || str_contains(strtolower($snippet), $query)) {
                $matches[] = [
                    'key' => $key,
                    'snippet' => $snippet,
                    'relevance' => 0.95,
                ];
            }
        }

        if (empty($matches)) {
            $matches[] = [
                'key' => 'general',
                'snippet' => "No specific matches found for '$query'. Returning default runtime knowledge.",
                'relevance' => 0.50,
            ];
        }

        return [
            'query' => $query,
            'hits' => $matches,
            'total_hits' => count($matches),
        ];
    }
}

/**
 * Mock File Inspector Tool for dry-run simulation of project inspections and line counts.
 */
class MockFileInspectorTool extends AbstractTool
{
    /** @var array<string, array{lines: int, size_bytes: int, type: string}> */
    private array $virtualFiles = [
        'composer.json' => ['lines' => 38, 'size_bytes' => 840, 'type' => 'config'],
        'repo-metadata.json' => ['lines' => 12, 'size_bytes' => 280, 'type' => 'config'],
        'src/Core/Agent.php' => ['lines' => 310, 'size_bytes' => 9500, 'type' => 'php'],
        'src/Core/TokenBudget.php' => ['lines' => 140, 'size_bytes' => 4100, 'type' => 'php'],
        'src/Memory/MemoryBuffer.php' => ['lines' => 165, 'size_bytes' => 4900, 'type' => 'php'],
        'src/Tools/ToolInterface.php' => ['lines' => 55, 'size_bytes' => 1600, 'type' => 'php'],
        'src/Tools/MockTools.php' => ['lines' => 240, 'size_bytes' => 7800, 'type' => 'php'],
        'src/Trace/ExecutionTrace.php' => ['lines' => 190, 'size_bytes' => 5600, 'type' => 'php'],
        'bin/eidcloud-agent' => ['lines' => 210, 'size_bytes' => 6400, 'type' => 'cli'],
        'tests/run_tests.php' => ['lines' => 180, 'size_bytes' => 5200, 'type' => 'test'],
        'tests/AgentRuntimeTest.php' => ['lines' => 260, 'size_bytes' => 8100, 'type' => 'test'],
        'README.md' => ['lines' => 150, 'size_bytes' => 4800, 'type' => 'docs'],
    ];

    public function __construct()
    {
        $this->name = 'mock_file_inspector';
        $this->description = 'Simulate project file discovery and line counting without touching the physical filesystem.';
        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: list_files, count_lines, or file_stats',
                    'enum' => ['list_files', 'count_lines', 'file_stats'],
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Specific file path or prefix filter',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): array|string
    {
        $this->validateRequired($args, ['action']);
        $action = $args['action'];
        $pathFilter = $args['path'] ?? '';

        return match ($action) {
            'list_files' => $this->handleListFiles($pathFilter),
            'count_lines' => $this->handleCountLines($pathFilter),
            'file_stats' => $this->handleFileStats($pathFilter),
            default => throw new InvalidArgumentException("Unsupported file inspector action: '$action'"),
        };
    }

    private function handleListFiles(string $filter): array
    {
        $files = array_keys($this->virtualFiles);
        if ($filter !== '') {
            $files = array_values(array_filter($files, fn(string $f) => str_starts_with($f, $filter) || str_contains($f, $filter)));
        }

        return [
            'files' => $files,
            'total_files' => count($files),
        ];
    }

    private function handleCountLines(string $path): array
    {
        if ($path === '' || $path === '.') {
            $totalLines = 0;
            $breakdown = [];
            foreach ($this->virtualFiles as $file => $meta) {
                $totalLines += $meta['lines'];
                $breakdown[$file] = $meta['lines'];
            }
            return [
                'total_lines' => $totalLines,
                'file_count' => count($this->virtualFiles),
                'breakdown' => $breakdown,
            ];
        }

        if (isset($this->virtualFiles[$path])) {
            return [
                'file' => $path,
                'lines' => $this->virtualFiles[$path]['lines'],
            ];
        }

        // Filter prefix
        $matches = [];
        $totalLines = 0;
        foreach ($this->virtualFiles as $file => $meta) {
            if (str_starts_with($file, $path) || str_contains($file, $path)) {
                $matches[$file] = $meta['lines'];
                $totalLines += $meta['lines'];
            }
        }

        return [
            'filter' => $path,
            'total_lines' => $totalLines,
            'matching_files' => count($matches),
            'breakdown' => $matches,
        ];
    }

    private function handleFileStats(string $path): array
    {
        if (isset($this->virtualFiles[$path])) {
            return array_merge(['file' => $path], $this->virtualFiles[$path]);
        }

        throw new InvalidArgumentException("File not found in virtual project: '$path'");
    }
}

/**
 * Factory class providing quick access to all mock tools.
 */
final class MockTools
{
    /**
     * Get an array of all mock tool instances.
     *
     * @return list<ToolInterface>
     */
    public static function all(): array
    {
        return [
            new MockDatabaseTool(),
            new MockCalculatorTool(),
            new MockSearchTool(),
            new MockFileInspectorTool(),
        ];
    }

    /**
     * Register all mock tools into the given registry.
     */
    public static function registerAll(ToolRegistry $registry): void
    {
        foreach (self::all() as $tool) {
            $registry->register($tool);
        }
    }
}
