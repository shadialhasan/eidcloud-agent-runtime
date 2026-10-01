<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Core;

use JsonSerializable;

/**
 * Token budget accounting and tracking system for LLM context windows and agent steps.
 */
class TokenBudget implements JsonSerializable
{
    private int $maxTokens;
    private int $promptTokens = 0;
    private int $completionTokens = 0;
    private int $toolTokens = 0;
    private float $warningThreshold; // e.g. 0.85 = 85%

    /**
     * @param int $maxTokens Maximum allowed tokens (default: 8192)
     * @param float $warningThreshold Threshold percentage (0.0 to 1.0) to flag budget warning
     */
    public function __construct(int $maxTokens = 8192, float $warningThreshold = 0.85)
    {
        $this->maxTokens = max(100, $maxTokens);
        $this->warningThreshold = max(0.1, min(1.0, $warningThreshold));
    }

    public function getMaxTokens(): int
    {
        return $this->maxTokens;
    }

    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = max(100, $maxTokens);
        return $this;
    }

    /**
     * Consume tokens across categories.
     */
    public function consume(int $prompt = 0, int $completion = 0, int $tool = 0): void
    {
        $this->promptTokens += max(0, $prompt);
        $this->completionTokens += max(0, $completion);
        $this->toolTokens += max(0, $tool);
    }

    /**
     * Estimate token count for a string or structured data payload using a fast heuristic (~4 chars/token).
     */
    public function estimate(string|array|null $content): int
    {
        if ($content === null) {
            return 0;
        }

        if (is_array($content)) {
            $content = json_encode($content, JSON_UNESCAPED_SLASHES) ?: '';
        }

        $length = mb_strlen($content);
        if ($length === 0) {
            return 0;
        }

        // Fast approximation: ~4 characters per token + word-boundary weighting
        $words = preg_split('/\s+/', trim($content));
        $wordCount = $words === false ? 0 : count($words);

        $charEstimate = (int) ceil($length / 4.0);
        $wordEstimate = (int) ceil($wordCount * 1.3);

        return max(1, (int) round(($charEstimate + $wordEstimate) / 2));
    }

    /**
     * Total tokens consumed so far.
     */
    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens + $this->toolTokens;
    }

    /**
     * Remaining tokens before budget limit.
     */
    public function getRemainingTokens(): int
    {
        return max(0, $this->maxTokens - $this->getTotalTokens());
    }

    /**
     * Check if total tokens exceed or equal max tokens.
     */
    public function isExhausted(): bool
    {
        return $this->getTotalTokens() >= $this->maxTokens;
    }

    /**
     * Check if usage has surpassed the warning threshold.
     */
    public function isWarningReached(): bool
    {
        return ($this->getTotalTokens() / $this->maxTokens) >= $this->warningThreshold;
    }

    /**
     * Get usage percentage (0.0 to 100.0).
     */
    public function getUsagePercentage(): float
    {
        return round(($this->getTotalTokens() / $this->maxTokens) * 100, 2);
    }

    /**
     * Detailed token statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'max_tokens' => $this->maxTokens,
            'total_used' => $this->getTotalTokens(),
            'remaining' => $this->getRemainingTokens(),
            'usage_pct' => $this->getUsagePercentage(),
            'is_exhausted' => $this->isExhausted(),
            'breakdown' => [
                'prompt_tokens' => $this->promptTokens,
                'completion_tokens' => $this->completionTokens,
                'tool_tokens' => $this->toolTokens,
            ],
        ];
    }

    /**
     * Reset token counters.
     */
    public function reset(): self
    {
        $this->promptTokens = 0;
        $this->completionTokens = 0;
        $this->toolTokens = 0;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return $this->getStats();
    }
}
