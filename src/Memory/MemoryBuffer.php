<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Memory;

use JsonSerializable;

/**
 * Memory Buffer managing working memory (scratchpad key-value) and episodic memory (chronological turn trace).
 */
class MemoryBuffer implements JsonSerializable
{
    /** @var array<string, mixed> Working memory key-value scratchpad */
    private array $workingMemory = [];

    /** @var list<array{turn: int, type: string, content: mixed, metadata: array<string, mixed>, timestamp: float}> Episodic memory turns */
    private array $episodicMemory = [];

    private int $turnCounter = 0;

    /**
     * @param array<string, mixed> $initialWorkingMemory
     */
    public function __construct(array $initialWorkingMemory = [])
    {
        $this->workingMemory = $initialWorkingMemory;
    }

    // --- Working Memory (Scratchpad) ---

    public function set(string $key, mixed $value): self
    {
        $this->workingMemory[$key] = $value;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->workingMemory[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->workingMemory);
    }

    public function remove(string $key): self
    {
        unset($this->workingMemory[$key]);
        return $this;
    }

    public function clearWorkingMemory(): self
    {
        $this->workingMemory = [];
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWorkingMemory(): array
    {
        return $this->workingMemory;
    }

    // --- Episodic Memory (Turn Buffer) ---

    /**
     * Record an episodic event turn (thought, plan, action, observation, reflection, answer).
     *
     * @param string $type
     * @param mixed $content
     * @param array<string, mixed> $metadata
     */
    public function addTurn(string $type, mixed $content, array $metadata = []): int
    {
        $this->turnCounter++;
        $this->episodicMemory[] = [
            'turn' => $this->turnCounter,
            'type' => $type,
            'content' => $content,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ];

        return $this->turnCounter;
    }

    /**
     * @return list<array{turn: int, type: string, content: mixed, metadata: array<string, mixed>, timestamp: float}>
     */
    public function getEpisodicMemory(): array
    {
        return $this->episodicMemory;
    }

    /**
     * Get recent turns up to a given limit.
     *
     * @param int $limit
     * @return list<array{turn: int, type: string, content: mixed, metadata: array<string, mixed>, timestamp: float}>
     */
    public function getRecentTurns(int $limit = 5): array
    {
        return array_slice($this->episodicMemory, -$limit);
    }

    /**
     * Filter episodic turns by type (e.g. 'observation', 'action', 'thought').
     *
     * @param string $type
     * @return list<array{turn: int, type: string, content: mixed, metadata: array<string, mixed>, timestamp: float}>
     */
    public function getTurnsByType(string $type): array
    {
        return array_values(array_filter($this->episodicMemory, fn(array $t) => $t['type'] === $type));
    }

    /**
     * Generate a concise text summary of episodic actions and observations.
     */
    public function summarize(): string
    {
        $lines = [];
        foreach ($this->episodicMemory as $entry) {
            $formattedContent = is_scalar($entry['content'])
                ? (string) $entry['content']
                : json_encode($entry['content'], JSON_UNESCAPED_SLASHES);

            $lines[] = sprintf(
                "Turn %d [%s]: %s",
                $entry['turn'],
                strtoupper($entry['type']),
                substr($formattedContent, 0, 160) . (strlen($formattedContent) > 160 ? '...' : '')
            );
        }

        return implode("\n", $lines);
    }

    public function clearEpisodicMemory(): self
    {
        $this->episodicMemory = [];
        $this->turnCounter = 0;
        return $this;
    }

    public function clearAll(): self
    {
        $this->clearWorkingMemory();
        $this->clearEpisodicMemory();
        return $this;
    }

    // --- Serialization & Export ---

    /**
     * Export complete memory state.
     *
     * @return array{working_memory: array<string, mixed>, episodic_memory: list<mixed>, total_turns: int}
     */
    public function toArray(): array
    {
        return [
            'working_memory' => $this->workingMemory,
            'episodic_memory' => $this->episodicMemory,
            'total_turns' => count($this->episodicMemory),
        ];
    }

    /**
     * Import memory state from an array.
     *
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): self
    {
        if (isset($data['working_memory']) && is_array($data['working_memory'])) {
            $this->workingMemory = $data['working_memory'];
        }
        if (isset($data['episodic_memory']) && is_array($data['episodic_memory'])) {
            $this->episodicMemory = $data['episodic_memory'];
            $this->turnCounter = count($this->episodicMemory);
        }
        return $this;
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
}
