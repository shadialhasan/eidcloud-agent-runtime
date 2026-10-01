<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tests;

use EidCloud\AgentRuntime\Core\Agent;
use EidCloud\AgentRuntime\Core\Config;
use EidCloud\AgentRuntime\Core\TokenBudget;
use EidCloud\AgentRuntime\Memory\MemoryBuffer;
use EidCloud\AgentRuntime\Tools\FilesystemTool;
use EidCloud\AgentRuntime\Tools\MockCalculatorTool;
use EidCloud\AgentRuntime\Tools\MockDatabaseTool;
use EidCloud\AgentRuntime\Tools\MockFileInspectorTool;
use EidCloud\AgentRuntime\Tools\MockSearchTool;
use EidCloud\AgentRuntime\Tools\MockTools;
use EidCloud\AgentRuntime\Tools\ToolRegistry;
use EidCloud\AgentRuntime\Trace\ExecutionTrace;
use TestCase;

/**
 * Comprehensive test suite verifying EidCloud Agent Runtime.
 */
class AgentRuntimeTest extends TestCase
{
    public function testTokenBudgetAccounting(): void
    {
        $budget = new TokenBudget(1000);
        $this->assertEquals(1000, $budget->getMaxTokens());
        $this->assertEquals(1000, $budget->getRemainingTokens());
        $this->assertFalse($budget->isExhausted());

        $est = $budget->estimate('Hello world! Testing token estimation in EidCloud Agent Runtime.');
        $this->assertGreaterThan(5, $est);

        $budget->consume(prompt: 200, completion: 150, tool: 50);
        $this->assertEquals(400, $budget->getTotalTokens());
        $this->assertEquals(600, $budget->getRemainingTokens());
        $this->assertEquals(40.0, $budget->getUsagePercentage());
        $this->assertFalse($budget->isExhausted());

        // Surpass budget
        $budget->consume(prompt: 700);
        $this->assertTrue($budget->isExhausted());
        $this->assertEquals(0, $budget->getRemainingTokens());

        $stats = $budget->getStats();
        $this->assertArrayHasKey('max_tokens', $stats);
        $this->assertArrayHasKey('breakdown', $stats);
    }

    public function testMemoryBufferOperations(): void
    {
        $memory = new MemoryBuffer();

        // Working memory
        $memory->set('user_id', 42);
        $memory->set('session_status', 'active');
        $this->assertTrue($memory->has('user_id'));
        $this->assertEquals(42, $memory->get('user_id'));
        $this->assertEquals('active', $memory->get('session_status'));
        $this->assertNull($memory->get('non_existent'));

        $memory->remove('session_status');
        $this->assertFalse($memory->has('session_status'));

        // Episodic memory
        $turn1 = $memory->addTurn('task', 'Calculate metrics');
        $turn2 = $memory->addTurn('thought', 'Need to sum values');
        $turn3 = $memory->addTurn('observation', ['result' => 150]);

        $this->assertEquals(1, $turn1);
        $this->assertEquals(2, $turn2);
        $this->assertEquals(3, $turn3);

        $turns = $memory->getEpisodicMemory();
        $this->assertCount(3, $turns);

        $thoughts = $memory->getTurnsByType('thought');
        $this->assertCount(1, $thoughts);
        $this->assertEquals('Need to sum values', $thoughts[0]['content']);

        $summary = $memory->summarize();
        $this->assertStringContainsString('Turn 1 [TASK]', $summary);
        $this->assertStringContainsString('Turn 3 [OBSERVATION]', $summary);

        $exported = $memory->toArray();
        $this->assertArrayHasKey('working_memory', $exported);
        $this->assertArrayHasKey('episodic_memory', $exported);
    }

    public function testMockToolsFunctionality(): void
    {
        // 1. Mock Database
        $db = new MockDatabaseTool();
        $tables = $db->execute(['action' => 'list_tables']);
        $this->assertArrayHasKey('tables', $tables);
        $this->assertTrue(in_array('users', $tables['tables'], true));

        $count = $db->execute(['action' => 'count', 'table' => 'users']);
        $this->assertEquals(3, $count['count']);

        $query = $db->execute(['action' => 'query', 'table' => 'users', 'filter' => ['role' => 'admin']]);
        $this->assertEquals(1, $query['found']);
        $this->assertEquals('Alice Johnson', $query['records'][0]['name']);

        $insert = $db->execute(['action' => 'insert', 'table' => 'users', 'data' => ['name' => 'David', 'role' => 'ops']]);
        $this->assertEquals(4, $insert['total_rows']);

        // 2. Mock Calculator
        $calc = new MockCalculatorTool();
        $res1 = $calc->execute(['expression' => '100 + 50 * 2']);
        $this->assertEquals(200, $res1['result']);

        $res2 = $calc->execute(['expression' => 'sum(10, 20, 30, 40)']);
        $this->assertEquals(100.0, $res2['result']);

        // 3. Mock Search
        $search = new MockSearchTool();
        $sRes = $search->execute(['query' => 'eidcloud']);
        $this->assertGreaterThan(0, $sRes['total_hits']);
        $this->assertStringContainsString('EidCloud', $sRes['hits'][0]['snippet']);

        // 4. Mock File Inspector
        $inspector = new MockFileInspectorTool();
        $files = $inspector->execute(['action' => 'list_files']);
        $this->assertGreaterThan(5, $files['total_files']);

        $lines = $inspector->execute(['action' => 'count_lines', 'path' => '.']);
        $this->assertGreaterThan(1000, $lines['total_lines']);
    }

    public function testToolRegistryAndPermissions(): void
    {
        $registry = new ToolRegistry();
        $calc = new MockCalculatorTool();
        $registry->register($calc);

        $this->assertTrue($registry->has('mock_calculator'));
        $this->assertNotNull($registry->get('mock_calculator'));
        $this->assertCount(1, $registry->all());

        $schemas = $registry->getSchemas();
        $this->assertCount(1, $schemas);
        $this->assertEquals('mock_calculator', $schemas[0]['function']['name']);

        // Execute allowed
        $res = $registry->execute('mock_calculator', ['expression' => '25 * 4']);
        $this->assertTrue($res['success']);
        $this->assertEquals(100, $res['result']['result']);

        // Permission boundary check
        $calc->setAllowed(false);
        $deniedRes = $registry->execute('mock_calculator', ['expression' => '25 * 4']);
        $this->assertFalse($deniedRes['success']);
        $this->assertStringContainsString('forbidden', $deniedRes['error']);
    }

    public function testFilesystemToolSandboxing(): void
    {
        $baseDir = dirname(__DIR__);
        $fs = new FilesystemTool($baseDir);

        $this->assertEquals($baseDir, $fs->getBaseDir());

        // Test listing directory
        $list = $fs->execute(['action' => 'list_dir', 'path' => 'src']);
        $this->assertTrue(is_array($list));
        $this->assertArrayHasKey('items', $list);

        // Test counting lines
        $count = $fs->execute(['action' => 'count_lines', 'path' => 'src/autoload.php']);
        $this->assertGreaterThan(10, $count['total_lines']);

        // Test directory traversal prevention
        $traversalBlocked = false;
        try {
            $fs->execute(['action' => 'read_file', 'path' => '../../../../../../windows/system32/cmd.exe']);
        } catch (\Throwable $e) {
            $traversalBlocked = true;
        }
        $this->assertTrue($traversalBlocked, 'Directory traversal outside sandbox must be blocked.');
    }

    public function testExecutionTrace(): void
    {
        $budget = new TokenBudget(5000);
        $trace = new ExecutionTrace($budget);

        $trace->record('AGENT_START', ['task' => 'Test trace']);
        $trace->startTimer('step1');
        usleep(5000); // 5ms
        $dur = $trace->stopTimer('step1');
        $trace->record('STEP_COMPLETE', ['step' => 1], $dur);

        $this->assertCount(2, $trace->getEvents());
        $metrics = $trace->getMetrics();
        $this->assertEquals(2, $metrics['total_events']);
        $this->assertGreaterThan(0, $metrics['total_duration_ms']);

        $pretty = $trace->toPrettyString(false);
        $this->assertStringContainsString('EIDCLOUD AGENT EXECUTION TRACE', $pretty);
        $this->assertStringContainsString('AGENT_START', $pretty);
    }

    public function testAgentFullExecutionProjectInspection(): void
    {
        $agent = Agent::createMockAgent('Inspect project files and count lines');
        $result = $agent->run();

        $this->assertTrue($result['success']);
        $this->assertEquals('Inspect project files and count lines', $result['task']);
        $this->assertStringContainsString('Project inspection completed successfully', $result['answer']);
        $this->assertGreaterThan(1, $result['steps_executed']);

        // Check memory
        $memory = $agent->getMemory();
        $this->assertTrue($memory->has('task'));
        $this->assertTrue($memory->has('final_answer'));
        $this->assertTrue($memory->has('step_1_result'));
        $this->assertTrue($memory->has('step_2_result'));

        // Check trace events
        $events = $agent->getTrace()->getEvents();
        $types = array_column($events, 'type');
        $this->assertTrue(in_array('AGENT_START', $types, true));
        $this->assertTrue(in_array('TOOL_INVOKED', $types, true));
        $this->assertTrue(in_array('TOOL_COMPLETED', $types, true));
        $this->assertTrue(in_array('AGENT_COMPLETED', $types, true));

        // Check token budget consumption
        $this->assertGreaterThan(0, $agent->getTokenBudget()->getTotalTokens());
    }

    public function testAgentFullExecutionDatabaseTask(): void
    {
        $agent = Agent::createMockAgent('Query database users and count tasks');
        $result = $agent->run();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Database inspection finished', $result['answer']);
        $this->assertStringContainsString('users', $result['answer']);
    }

    public function testAgentFullExecutionMathTask(): void
    {
        $agent = Agent::createMockAgent('Calculate project budget: 150 * 12 + 450');
        $result = $agent->run();

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Calculation completed', $result['answer']);
        $this->assertStringContainsString('2250', $result['answer']);
    }

    public function testAgentConfigFromJsonFile(): void
    {
        $configPath = dirname(__DIR__) . '/agent.example.json';
        $config = Config::fromJsonFile($configPath);

        $this->assertEquals('Inspect project files and count lines', $config->getTask());
        $this->assertTrue($config->isMockMode());
        $this->assertEquals(5, $config->getMaxSteps());
        $this->assertEquals(8192, $config->getTokenBudget());
        $this->assertTrue(in_array('mock_file_inspector', $config->getAllowedTools(), true));
    }
}
