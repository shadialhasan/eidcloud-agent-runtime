<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Core;

use EidCloud\AgentRuntime\Memory\MemoryBuffer;
use EidCloud\AgentRuntime\Tools\FilesystemTool;
use EidCloud\AgentRuntime\Tools\HttpTool;
use EidCloud\AgentRuntime\Tools\MockFileInspectorTool;
use EidCloud\AgentRuntime\Tools\MockTools;
use EidCloud\AgentRuntime\Tools\ShellTool;
use EidCloud\AgentRuntime\Tools\ToolRegistry;
use EidCloud\AgentRuntime\Trace\ExecutionTrace;
use Throwable;

/**
 * Core Autonomous Agent orchestrating planner loop, tool calling, memory buffer, and token accounting.
 */
class Agent
{
    private Config $config;
    private ToolRegistry $toolRegistry;
    private MemoryBuffer $memory;
    private TokenBudget $tokenBudget;
    private ExecutionTrace $trace;

    /** @var (callable(array<string, mixed>, list<array<string, mixed>>): array<string, mixed>)|null */
    private $llmDriver = null;

    /**
     * @param Config|null $config
     * @param ToolRegistry|null $toolRegistry
     * @param MemoryBuffer|null $memory
     */
    public function __construct(
        ?Config $config = null,
        ?ToolRegistry $toolRegistry = null,
        ?MemoryBuffer $memory = null
    ) {
        $this->config = $config ?? new Config();
        $this->toolRegistry = $toolRegistry ?? new ToolRegistry();
        $this->memory = $memory ?? new MemoryBuffer();
        $this->tokenBudget = new TokenBudget($this->config->getTokenBudget());
        $this->trace = new ExecutionTrace($this->tokenBudget);

        $this->initializeDefaultTools();
    }

    /**
     * Create a ready-to-run agent with mock tools preconfigured.
     */
    public static function createMockAgent(string $task = '', int $tokenBudget = 8192): self
    {
        $config = new Config([
            'task' => $task,
            'mock' => true,
            'token_budget' => $tokenBudget,
        ]);

        return new self($config);
    }

    /**
     * Register default runtime tools.
     */
    private function initializeDefaultTools(): void
    {
        // Register mock tools
        MockTools::registerAll($this->toolRegistry);

        // Register sandboxed filesystem tool
        $fsTool = new FilesystemTool();
        $this->toolRegistry->register($fsTool);

        // Register HTTP tool
        $httpTool = new HttpTool();
        $this->toolRegistry->register($httpTool);

        // Register Shell tool (disabled by default for security)
        $shellTool = new ShellTool(false);
        $this->toolRegistry->register($shellTool);

        // Filter by allowed tools configuration if specified
        $allowed = $this->config->getAllowedTools();
        if (!empty($allowed)) {
            foreach ($this->toolRegistry->all() as $name => $tool) {
                $tool->setAllowed(in_array($name, $allowed, true));
            }
        }
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getToolRegistry(): ToolRegistry
    {
        return $this->toolRegistry;
    }

    public function getMemory(): MemoryBuffer
    {
        return $this->memory;
    }

    public function getTokenBudget(): TokenBudget
    {
        return $this->tokenBudget;
    }

    public function getTrace(): ExecutionTrace
    {
        return $this->trace;
    }

    /**
     * Set a custom LLM driver callable for non-mock executions.
     *
     * @param callable(array<string, mixed>, list<array<string, mixed>>): array<string, mixed> $driver
     */
    public function setLlmDriver(callable $driver): self
    {
        $this->llmDriver = $driver;
        return $this;
    }

    /**
     * Execute the autonomous agent loop on a specified task.
     *
     * @param string|null $task Optional task override
     * @return array<string, mixed> Execution result summary
     */
    public function run(?string $task = null): array
    {
        $effectiveTask = trim($task ?? $this->config->getTask());
        if ($effectiveTask === '') {
            $effectiveTask = 'Inspect project files and count lines';
        }

        $this->trace->record('AGENT_START', [
            'task' => $effectiveTask,
            'mock_mode' => $this->config->isMockMode(),
            'max_steps' => $this->config->getMaxSteps(),
            'token_budget' => $this->tokenBudget->getMaxTokens(),
        ]);

        $this->memory->set('task', $effectiveTask);
        $this->memory->addTurn('task', $effectiveTask);

        // Consume prompt tokens
        $promptTokens = $this->tokenBudget->estimate($this->config->getSystemPrompt())
            + $this->tokenBudget->estimate($effectiveTask);
        $this->tokenBudget->consume(prompt: $promptTokens);

        $step = 0;
        $maxSteps = $this->config->getMaxSteps();
        $finalAnswer = null;

        while ($step < $maxSteps) {
            $step++;

            if ($this->tokenBudget->isExhausted()) {
                $this->trace->record('AGENT_STOPPED', [
                    'reason' => 'Token budget exhausted',
                    'step' => $step,
                ]);
                $finalAnswer = "Execution halted: Token budget of {$this->tokenBudget->getMaxTokens()} tokens exhausted.";
                break;
            }

            $this->trace->record('STEP_START', ['step' => $step]);

            // Formulate Plan & Action
            $actionDecision = $this->decideNextAction($effectiveTask, $step);

            if ($actionDecision['type'] === 'answer') {
                $finalAnswer = $actionDecision['answer'];
                $this->memory->addTurn('answer', $finalAnswer);
                $this->tokenBudget->consume(completion: $this->tokenBudget->estimate($finalAnswer));
                break;
            }

            if ($actionDecision['type'] === 'tool_call') {
                $toolName = $actionDecision['tool'];
                $toolArgs = $actionDecision['args'] ?? [];
                $thought = $actionDecision['thought'] ?? "Executing tool $toolName";

                $this->memory->addTurn('thought', $thought);
                $this->memory->addTurn('action', ['tool' => $toolName, 'args' => $toolArgs]);

                $this->trace->record('PLAN_GENERATED', [
                    'step' => $step,
                    'thought' => $thought,
                    'tool' => $toolName,
                    'args' => $toolArgs,
                ]);

                // Execute tool
                $toolResult = $this->toolRegistry->execute($toolName, $toolArgs, $this->trace);

                // Accounting tokens for tool input and output
                $inputTokens = $this->tokenBudget->estimate($toolArgs);
                $outputTokens = $this->tokenBudget->estimate($toolResult);
                $this->tokenBudget->consume(completion: $this->tokenBudget->estimate($thought), tool: $inputTokens + $outputTokens);

                $this->memory->addTurn('observation', $toolResult);
                $this->memory->set("step_{$step}_result", $toolResult);

                // Reflection step
                $reflection = $this->reflectOnObservation($effectiveTask, $step, $toolName, $toolResult);
                $this->memory->addTurn('reflection', $reflection);
                $this->trace->record('REFLECTION', [
                    'step' => $step,
                    'reflection' => $reflection,
                ]);

                if (isset($actionDecision['final_step']) && $actionDecision['final_step'] === true) {
                    $finalAnswer = $reflection;
                    break;
                }
            }
        }

        if ($finalAnswer === null) {
            $finalAnswer = sprintf("Completed %d execution steps. Memory and tool observations collected.", $step);
        }

        $this->memory->set('final_answer', $finalAnswer);
        $this->trace->record('AGENT_COMPLETED', [
            'steps_completed' => $step,
            'final_answer' => $finalAnswer,
        ]);

        return [
            'success' => true,
            'task' => $effectiveTask,
            'answer' => $finalAnswer,
            'steps_executed' => $step,
            'token_stats' => $this->tokenBudget->getStats(),
            'trace' => $this->trace->toArray(),
            'memory' => $this->memory->toArray(),
        ];
    }

    /**
     * Decide next action: either through custom LLM driver or mock planner.
     *
     * @return array{type: 'tool_call'|'answer', tool?: string, args?: array<string, mixed>, thought?: string, answer?: string, final_step?: bool}
     */
    private function decideNextAction(string $task, int $step): array
    {
        if (!$this->config->isMockMode() && $this->llmDriver !== null) {
            return ($this->llmDriver)([
                'task' => $task,
                'step' => $step,
                'memory' => $this->memory->getWorkingMemory(),
                'turns' => $this->memory->getEpisodicMemory(),
            ], $this->toolRegistry->getSchemas());
        }

        // Deterministic Mock Planner
        return $this->mockPlanner($task, $step);
    }

    /**
     * Built-in intelligent Mock Planner simulating LLM reasoning and multi-step tool execution.
     *
     * @return array{type: 'tool_call'|'answer', tool?: string, args?: array<string, mixed>, thought?: string, answer?: string, final_step?: bool}
     */
    private function mockPlanner(string $task, int $step): array
    {
        $normalized = strtolower($task);

        // Pattern 1: Database / SQL simulation (prioritized if database/sql/table/users is mentioned)
        if (str_contains($normalized, 'database') || str_contains($normalized, 'query') || str_contains($normalized, 'table') || str_contains($normalized, 'sql')) {
            if ($step === 1) {
                return [
                    'type' => 'tool_call',
                    'tool' => 'mock_database',
                    'args' => ['action' => 'query', 'table' => 'users'],
                    'thought' => 'Querying mock database users table to inspect user records.',
                ];
            }

            if ($step === 2) {
                return [
                    'type' => 'tool_call',
                    'tool' => 'mock_database',
                    'args' => ['action' => 'count', 'table' => 'tasks'],
                    'thought' => 'Checking task count in mock database for active workload.',
                ];
            }

            $usersRes = $this->memory->get('step_1_result');
            $tasksRes = $this->memory->get('step_2_result');
            $userCount = $usersRes['result']['found'] ?? 3;
            $taskCount = $tasksRes['result']['count'] ?? 3;

            return [
                'type' => 'answer',
                'answer' => "Database inspection finished. Found {$userCount} users and {$taskCount} active project tasks.",
            ];
        }

        // Pattern 2: Calculation / Math
        if (str_contains($normalized, 'calculate') || str_contains($normalized, 'math') || preg_match('/\d+[\s\+\-\*\/]+\d+/', $normalized)) {
            $expr = '150 * 12 + 450';
            if (preg_match('/(?:calculate|math|compute|budget)?\s*:?\s*([\d\s\+\-\*\/\(\)\.]+)/i', $task, $m) && !empty(trim($m[1]))) {
                $candidate = trim($m[1]);
                if (preg_match('/\d+[\s\+\-\*\/]+\d+/', $candidate)) {
                    $expr = $candidate;
                }
            }

            if ($step === 1) {
                return [
                    'type' => 'tool_call',
                    'tool' => 'mock_calculator',
                    'args' => ['expression' => $expr],
                    'thought' => "Calculating mathematical expression '{$expr}' using mock calculator.",
                ];
            }

            $calc = $this->memory->get('step_1_result');
            $res = $calc['result']['result'] ?? 2250;

            return [
                'type' => 'answer',
                'answer' => "Calculation completed: result is {$res}.",
            ];
        }

        // Pattern 3: Project inspection and line counting
        if (str_contains($normalized, 'file') || str_contains($normalized, 'line') || str_contains($normalized, 'inspect')) {
            if ($step === 1) {
                return [
                    'type' => 'tool_call',
                    'tool' => 'mock_file_inspector',
                    'args' => ['action' => 'list_files'],
                    'thought' => 'I will first discover all repository project files using the mock file inspector.',
                ];
            }

            if ($step === 2) {
                return [
                    'type' => 'tool_call',
                    'tool' => 'mock_file_inspector',
                    'args' => ['action' => 'count_lines', 'path' => '.'],
                    'thought' => 'Now I will count total source code lines across all discovered project files.',
                ];
            }

            // Step 3: Synthesis and final answer
            $step2Result = $this->memory->get('step_2_result');
            $totalLines = $step2Result['result']['total_lines'] ?? 1667;
            $fileCount = $step2Result['result']['file_count'] ?? 12;

            return [
                'type' => 'answer',
                'answer' => sprintf(
                    "Project inspection completed successfully. Analyzed %d files across the repository containing a total of %d lines of code. Working memory and execution trace have been updated.",
                    $fileCount,
                    $totalLines
                ),
            ];
        }

        // Pattern 4: Search knowledge
        if (str_contains($normalized, 'search') || str_contains($normalized, 'know') || str_contains($normalized, 'eidcloud')) {
            if ($step === 1) {
                return [
                    'type' => 'tool_call',
                    'tool' => 'mock_search',
                    'args' => ['query' => 'eidcloud'],
                    'thought' => 'Querying mock search index for information on eidcloud runtime.',
                ];
            }

            $searchRes = $this->memory->get('step_1_result');
            $hits = $searchRes['result']['hits'] ?? [];
            $snippet = $hits[0]['snippet'] ?? 'EidCloud provides next-generation cloud infrastructure.';

            return [
                'type' => 'answer',
                'answer' => "Search completed. Knowledge retrieved: {$snippet}",
            ];
        }

        // Default multi-step fallback
        if ($step === 1) {
            return [
                'type' => 'tool_call',
                'tool' => 'mock_search',
                'args' => ['query' => $task],
                'thought' => 'Analyzing initial context for task using mock search tool.',
            ];
        }

        return [
            'type' => 'answer',
            'answer' => sprintf("Task '%s' completed successfully in %d steps within allocated token budget.", $task, $step),
        ];
    }

    /**
     * Generate reflection on tool execution observation.
     *
     * @param array<string, mixed> $toolResult
     */
    private function reflectOnObservation(string $task, int $step, string $tool, array $toolResult): string
    {
        if (!$toolResult['success']) {
            return sprintf("Step %d: Tool '%s' failed with error '%s'. Re-evaluating next step.", $step, $tool, $toolResult['error'] ?? 'Unknown error');
        }

        return sprintf("Step %d: Tool '%s' completed successfully in %0.2fms. Observations stored in working memory.", $step, $tool, $toolResult['duration_ms'] ?? 0.0);
    }
}
