<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

use InvalidArgumentException;

/**
 * Abstract base class providing common tool capabilities, validation, and permissions.
 */
abstract class AbstractTool implements ToolInterface
{
    protected string $name;
    protected string $description;
    /** @var array<string, mixed> */
    protected array $parameters = [];
    protected bool $allowed = true;
    protected int $timeout = 30;

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function setAllowed(bool $allowed): void
    {
        $this->allowed = $allowed;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    /**
     * Validate that required arguments are present.
     *
     * @param array<string, mixed> $args
     * @param list<string> $required
     * @throws InvalidArgumentException
     */
    protected function validateRequired(array $args, array $required): void
    {
        foreach ($required as $param) {
            if (!array_key_exists($param, $args)) {
                throw new InvalidArgumentException(
                    sprintf("Tool '%s' missing required parameter: '%s'", $this->getName(), $param)
                );
            }
        }
    }
}
