<?php

declare(strict_types=1);

/**
 * EidCloud Agent Runtime - Zero-Dependency Automated Test Runner
 */

require_once __DIR__ . '/../src/autoload.php';

// Base TestCase class
abstract class TestCase
{
    private int $assertions = 0;

    public function getAssertionsCount(): int
    {
        return $this->assertions;
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new AssertionError($message ?: 'Failed asserting that condition is true.');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertions++;
        if ($condition) {
            throw new AssertionError($message ?: 'Failed asserting that condition is false.');
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $expectedStr = is_scalar($expected) ? (string) $expected : json_encode($expected);
            $actualStr = is_scalar($actual) ? (string) $actual : json_encode($actual);
            throw new AssertionError($message ?: "Failed asserting that '$actualStr' equals expected '$expectedStr'.");
        }
    }

    protected function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected === $actual) {
            throw new AssertionError($message ?: "Failed asserting that values are not equal.");
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual !== null) {
            throw new AssertionError($message ?: 'Failed asserting that value is null.');
        }
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual === null) {
            throw new AssertionError($message ?: 'Failed asserting that value is not null.');
        }
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        $this->assertions++;
        if (!array_key_exists($key, $array)) {
            throw new AssertionError($message ?: "Failed asserting that array contains key '$key'.");
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionError($message ?: "Failed asserting that '$haystack' contains '$needle'.");
        }
    }

    protected function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($actual <= $expected) {
            throw new AssertionError($message ?: "Failed asserting that $actual is greater than $expected.");
        }
    }

    protected function assertCount(int $expectedCount, countable|array $array, string $message = ''): void
    {
        $this->assertions++;
        $actualCount = count($array);
        if ($actualCount !== $expectedCount) {
            throw new AssertionError($message ?: "Failed asserting that count $actualCount matches expected $expectedCount.");
        }
    }
}

// Discover and execute tests
echo "\033[1;36m=================================================================\033[0m\n";
echo "\033[1;36m        🧪 EidCloud Agent Runtime - Automated Test Suite        \033[0m\n";
echo "\033[1;36m=================================================================\033[0m\n\n";

$testFiles = glob(__DIR__ . '/*Test.php') ?: [];
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;
$totalAssertions = 0;
$failures = [];
$startTime = microtime(true);

foreach ($testFiles as $file) {
    require_once $file;
    $declaredClasses = get_declared_classes();
    $className = end($declaredClasses);

    if (!is_subclass_of($className, TestCase::class)) {
        continue;
    }

    $reflector = new ReflectionClass($className);
    $methods = $reflector->getMethods(ReflectionMethod::IS_PUBLIC);

    echo "\033[1mTesting: " . $reflector->getShortName() . "\033[0m\n";

    foreach ($methods as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }

        $totalTests++;
        $testName = $method->getName();
        /** @var TestCase $testInstance */
        $testInstance = new $className();

        $testStart = microtime(true);
        try {
            $testInstance->setUp();
            $testInstance->{$testName}();
            $testInstance->tearDown();

            $dur = (microtime(true) - $testStart) * 1000;
            $passedTests++;
            $totalAssertions += $testInstance->getAssertionsCount();

            echo "  \033[32m✔\033[0m {$testName} \033[90m(" . sprintf('%0.2fms', $dur) . ")\033[0m\n";
        } catch (Throwable $e) {
            $dur = (microtime(true) - $testStart) * 1000;
            $failedTests++;
            $totalAssertions += $testInstance->getAssertionsCount();
            $failures[] = [
                'class' => $reflector->getShortName(),
                'method' => $testName,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];

            echo "  \033[31m✖\033[0m {$testName} \033[90m(" . sprintf('%0.2fms', $dur) . ")\033[0m\n";
            echo "    \033[31mError: " . $e->getMessage() . "\033[0m\n";
        }
    }
    echo "\n";
}

$totalDuration = (microtime(true) - $startTime) * 1000;

echo "\033[1;36m-----------------------------------------------------------------\033[0m\n";
if ($failedTests === 0) {
    echo "\033[1;32m✓ PASSED ALL TESTS (100%)\033[0m\n";
    echo "  Tests:      \033[32m{$passedTests} passed\033[0m, {$totalTests} total\n";
    echo "  Assertions: \033[32m{$totalAssertions}\033[0m\n";
    echo "  Duration:   " . sprintf('%0.2fms', $totalDuration) . "\n";
    echo "  Memory:     " . sprintf('%0.2fMB', memory_get_peak_usage(true) / 1024 / 1024) . "\n";
    echo "\033[1;36m=================================================================\033[0m\n";
    exit(0);
} else {
    echo "\033[1;31m✖ TEST FAILURES DETECTED\033[0m\n";
    echo "  Passed:     \033[32m{$passedTests}\033[0m\n";
    echo "  Failed:     \033[31m{$failedTests}\033[0m\n";
    echo "  Assertions: {$totalAssertions}\n";
    echo "  Duration:   " . sprintf('%0.2fms', $totalDuration) . "\n\n";

    foreach ($failures as $f) {
        echo "  - \033[31m{$f['class']}::{$f['method']}\033[0m at {$f['file']}:{$f['line']}\n";
        echo "    {$f['message']}\n";
    }
    echo "\033[1;36m=================================================================\033[0m\n";
    exit(1);
}
