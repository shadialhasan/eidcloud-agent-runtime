<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Trace;

use EidCloud\AgentRuntime\Core\TokenBudget;
use JsonSerializable;

/**
 * Structured execution trace recording events, latency, tool calls, and token metrics.
 */
class ExecutionTrace implements JsonSerializable
{
    private float $startedAt;
    /** @var list<array{index: int, type: string, timestamp: float, relative_ms: float, duration_ms: ?float, payload: array<string, mixed>}> */
    private array $events = [];
    /** @var array<string, float> */
    private array $timers = [];
    private ?TokenBudget $tokenBudget = null;

    public function __construct(?TokenBudget $tokenBudget = null)
    {
        $this->startedAt = microtime(true);
        $this->tokenBudget = $tokenBudget;
    }

    public function setTokenBudget(TokenBudget $tokenBudget): self
    {
        $this->tokenBudget = $tokenBudget;
        return $this;
    }

    public function startTimer(string $key): void
    {
        $this->timers[$key] = microtime(true);
    }

    public function stopTimer(string $key): float
    {
        if (!isset($this->timers[$key])) {
            return 0.0;
        }

        $durationMs = (microtime(true) - $this->timers[$key]) * 1000;
        unset($this->timers[$key]);
        return round($durationMs, 2);
    }

    /**
     * Record an execution event.
     *
     * @param string $type
     * @param array<string, mixed> $payload
     * @param float|null $durationMs
     */
    public function record(string $type, array $payload = [], ?float $durationMs = null): void
    {
        $now = microtime(true);
        $relativeMs = round(($now - $this->startedAt) * 1000, 2);

        $this->events[] = [
            'index' => count($this->events) + 1,
            'type' => strtoupper($type),
            'timestamp' => $now,
            'relative_ms' => $relativeMs,
            'duration_ms' => $durationMs !== null ? round($durationMs, 2) : null,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array{index: int, type: string, timestamp: float, relative_ms: float, duration_ms: ?float, payload: array<string, mixed>}>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    public function getTotalDurationMs(): float
    {
        if (empty($this->events)) {
            return round((microtime(true) - $this->startedAt) * 1000, 2);
        }

        $last = end($this->events);
        return $last['relative_ms'];
    }

    /**
     * Get summary metrics for tool calls and failures.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $toolInvocations = 0;
        $toolFailures = 0;
        $totalToolDurationMs = 0.0;

        foreach ($this->events as $event) {
            if ($event['type'] === 'TOOL_INVOKED') {
                $toolInvocations++;
            } elseif ($event['type'] === 'TOOL_FAILED') {
                $toolFailures++;
            } elseif ($event['type'] === 'TOOL_COMPLETED' && isset($event['duration_ms'])) {
                $totalToolDurationMs += $event['duration_ms'];
            }
        }

        return [
            'total_events' => count($this->events),
            'total_duration_ms' => $this->getTotalDurationMs(),
            'tool_invocations' => $toolInvocations,
            'tool_failures' => $toolFailures,
            'total_tool_duration_ms' => round($totalToolDurationMs, 2),
            'tokens' => $this->tokenBudget?->getStats() ?? null,
        ];
    }

    /**
     * Export complete structured trace to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'started_at' => date('c', (int) $this->startedAt),
            'metrics' => $this->getMetrics(),
            'events' => $this->events,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($this->toArray(), $flags) ?: '{}';
    }

    /**
     * Render human-readable ANSI colored / formatted trace log.
     */
    public function toPrettyString(bool $useAnsi = true): string
    {
        $cyan = $useAnsi ? "\033[36m" : "";
        $green = $useAnsi ? "\033[32m" : "";
        $yellow = $useAnsi ? "\033[33m" : "";
        $red = $useAnsi ? "\033[31m" : "";
        $gray = $useAnsi ? "\033[90m" : "";
        $bold = $useAnsi ? "\033[1m" : "";
        $reset = $useAnsi ? "\033[0m" : "";

        $out = [];
        $out[] = "{$bold}┌───────────────────────────────────────────────────────────────┐{$reset}";
        $out[] = "{$bold}│               EIDCLOUD AGENT EXECUTION TRACE                  │{$reset}";
        $out[] = "{$bold}└───────────────────────────────────────────────────────────────┘{$reset}";

        foreach ($this->events as $ev) {
            $type = $ev['type'];
            $rel = str_pad(sprintf("+%0.1fms", $ev['relative_ms']), 10);

            $typeColor = match ($type) {
                'AGENT_START', 'AGENT_COMPLETED' => $green . $bold,
                'TOOL_INVOKED' => $cyan,
                'TOOL_COMPLETED' => $green,
                'TOOL_FAILED', 'AGENT_FAILED' => $red . $bold,
                'PLAN_GENERATED', 'STEP_START' => $yellow,
                default => $reset,
            };

            $dur = $ev['duration_ms'] !== null ? " ({$ev['duration_ms']}ms)" : "";
            $out[] = "{$gray}{$rel}{$reset} [{$typeColor}{$type}{$reset}]{$dur}";

            if (!empty($ev['payload'])) {
                foreach ($ev['payload'] as $k => $v) {
                    $valStr = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES);
                    if (strlen($valStr) > 100) {
                        $valStr = substr($valStr, 0, 97) . '...';
                    }
                    $out[] = "   {$gray}├─{$reset} {$cyan}{$k}:{$reset} {$valStr}";
                }
            }
        }

        $metrics = $this->getMetrics();
        $out[] = "";
        $out[] = "{$bold}Summary:{$reset} Duration: {$metrics['total_duration_ms']}ms | Tools Called: {$metrics['tool_invocations']} | Failures: {$metrics['tool_failures']}";
        if ($this->tokenBudget !== null) {
            $tb = $this->tokenBudget->getStats();
            $out[] = "{$bold}Tokens:{$reset} Total Used: {$tb['total_used']} / {$tb['max_tokens']} ({$tb['usage_pct']}%) [Prompt: {$tb['breakdown']['prompt_tokens']}, Tool: {$tb['breakdown']['tool_tokens']}, Completion: {$tb['breakdown']['completion_tokens']}]";
        }

        return implode("\n", $out);
    }
}
