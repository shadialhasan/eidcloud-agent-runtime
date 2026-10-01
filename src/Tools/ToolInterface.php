<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

/**
 * Interface for all tools in the EidCloud Agent Runtime.
 */
interface ToolInterface
{
    /**
     * Get the unique name of the tool.
     */
    public function getName(): string;

    /**
     * Get a description of what the tool does and when to use it.
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema-compatible parameter definitions for the tool.
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|string
     * @throws \Throwable If tool execution fails
     */
    public function execute(array $args): array|string;

    /**
     * Check if the tool is allowed to execute based on permission boundaries.
     */
    public function isAllowed(): bool;

    /**
     * Set the permission status of this tool.
     */
    public function setAllowed(bool $allowed): void;

    /**
     * Get the maximum execution timeout in seconds.
     */
    public function getTimeout(): int;

    /**
     * Set the maximum execution timeout in seconds.
     */
    public function setTimeout(int $seconds): void;
}
