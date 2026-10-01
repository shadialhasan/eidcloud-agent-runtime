<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

use EidCloud\AgentRuntime\Trace\ExecutionTrace;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Registry for managing tools, permission validation, and safe dispatching.
 */
class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * Register a new tool.
     */
    public function register(ToolInterface $tool): self
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }

    /**
     * Check if a tool is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get a tool by name.
     */
    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Unregister a tool by name.
     */
    public function unregister(string $name): self
    {
        unset($this->tools[$name]);
        return $this;
    }

    /**
     * Return all registered tools.
     *
     * @return array<string, ToolInterface>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Return list of registered tool names.
     *
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Get OpenAI/Anthropic/JSON-schema compatible tool definitions for all registered tools.
     *
     * @return list<array<string, mixed>>
     */
    public function getSchemas(): array
    {
        $schemas = [];
        foreach ($this->tools as $tool) {
            if (!$tool->isAllowed()) {
                continue;
            }
            $schemas[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameters(),
                ],
            ];
        }
        return $schemas;
    }

    /**
     * Execute a tool by name with permission check, error handling, and trace logging.
     *
     * @param string $name
     * @param array<string, mixed> $args
     * @param ExecutionTrace|null $trace
     * @return array<string, mixed> Result array containing status and data/error
     */
    public function execute(string $name, array $args = [], ?ExecutionTrace $trace = null): array
    {
        $tool = $this->get($name);

        if ($tool === null) {
            $errorMsg = sprintf("Tool '%s' not found in registry.", $name);
            if ($trace !== null) {
                $trace->record('TOOL_FAILED', [
                    'tool' => $name,
                    'arguments' => $args,
                    'error' => $errorMsg,
                ]);
            }
            return [
                'success' => false,
                'tool' => $name,
                'error' => $errorMsg,
            ];
        }

        if (!$tool->isAllowed()) {
            $errorMsg = sprintf("Tool '%s' execution is forbidden by permission boundaries.", $name);
            if ($trace !== null) {
                $trace->record('TOOL_FAILED', [
                    'tool' => $name,
                    'arguments' => $args,
                    'error' => $errorMsg,
                ]);
            }
            return [
                'success' => false,
                'tool' => $name,
                'error' => $errorMsg,
            ];
        }

        $startTime = microtime(true);
        if ($trace !== null) {
            $trace->record('TOOL_INVOKED', [
                'tool' => $name,
                'arguments' => $args,
            ]);
        }

        try {
            $result = $tool->execute($args);
            $durationMs = (microtime(true) - $startTime) * 1000;

            if ($trace !== null) {
                $trace->record('TOOL_COMPLETED', [
                    'tool' => $name,
                    'result' => $result,
                ], $durationMs);
            }

            return [
                'success' => true,
                'tool' => $name,
                'result' => $result,
                'duration_ms' => round($durationMs, 2),
            ];
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;
            $errorMsg = $e->getMessage();

            if ($trace !== null) {
                $trace->record('TOOL_FAILED', [
                    'tool' => $name,
                    'arguments' => $args,
                    'error' => $errorMsg,
                    'exception' => get_class($e),
                ], $durationMs);
            }

            return [
                'success' => false,
                'tool' => $name,
                'error' => $errorMsg,
                'duration_ms' => round($durationMs, 2),
            ];
        }
    }
}
