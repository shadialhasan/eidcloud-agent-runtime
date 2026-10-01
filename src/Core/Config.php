<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Core;

use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Immutable configuration settings for the Agent runtime.
 */
class Config implements JsonSerializable
{
    private string $task;
    private bool $mockMode;
    private int $maxSteps;
    private int $tokenBudget;
    /** @var list<string> */
    private array $allowedTools;
    private string $model;
    private string $systemPrompt;
    private bool $verbose;
    private int $timeoutSeconds;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->task = (string) ($options['task'] ?? '');
        $this->mockMode = (bool) ($options['mock'] ?? $options['mock_mode'] ?? true);
        $this->maxSteps = (int) ($options['max_steps'] ?? 10);
        $this->tokenBudget = (int) ($options['token_budget'] ?? 8192);
        $this->allowedTools = isset($options['allowed_tools']) && is_array($options['allowed_tools'])
            ? array_values($options['allowed_tools'])
            : [];
        $this->model = (string) ($options['model'] ?? 'eidcloud-agent-v1');
        $this->systemPrompt = (string) ($options['system_prompt'] ?? 'You are EidCloud Autonomous Agent, an intelligent, deterministic problem-solving assistant.');
        $this->verbose = (bool) ($options['verbose'] ?? false);
        $this->timeoutSeconds = (int) ($options['timeout_seconds'] ?? 60);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJsonFile(string $filePath): self
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Configuration file not found: '$filePath'");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException("Could not read configuration file: '$filePath'");
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException("Invalid JSON syntax in configuration file: '$filePath'");
        }

        return new self($decoded);
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function withTask(string $task): self
    {
        $clone = clone $this;
        $clone->task = $task;
        return $clone;
    }

    public function isMockMode(): bool
    {
        return $this->mockMode;
    }

    public function withMockMode(bool $mock): self
    {
        $clone = clone $this;
        $clone->mockMode = $mock;
        return $clone;
    }

    public function getMaxSteps(): int
    {
        return $this->maxSteps;
    }

    public function getTokenBudget(): int
    {
        return $this->tokenBudget;
    }

    /**
     * @return list<string>
     */
    public function getAllowedTools(): array
    {
        return $this->allowedTools;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function isVerbose(): bool
    {
        return $this->verbose;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'mock' => $this->mockMode,
            'max_steps' => $this->maxSteps,
            'token_budget' => $this->tokenBudget,
            'allowed_tools' => $this->allowedTools,
            'model' => $this->model,
            'system_prompt' => $this->systemPrompt,
            'verbose' => $this->verbose,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
