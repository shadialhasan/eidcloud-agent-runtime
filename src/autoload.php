<?php

declare(strict_types=1);

/**
 * EidCloud Agent Runtime - Standalone PSR-4 Autoloader
 *
 * Provides zero-dependency class autoloading when Composer vendor/autoload.php is not present.
 */

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'EidCloud\\AgentRuntime\\Tests\\' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR,
        'EidCloud\\AgentRuntime\\' => __DIR__ . DIRECTORY_SEPARATOR,
    ];

    // Alias mock tool classes that reside in MockTools.php
    $mockClasses = [
        'EidCloud\\AgentRuntime\\Tools\\MockDatabaseTool',
        'EidCloud\\AgentRuntime\\Tools\\MockCalculatorTool',
        'EidCloud\\AgentRuntime\\Tools\\MockSearchTool',
        'EidCloud\\AgentRuntime\\Tools\\MockFileInspectorTool',
    ];

    if (in_array($class, $mockClasses, true)) {
        require_once __DIR__ . DIRECTORY_SEPARATOR . 'Tools' . DIRECTORY_SEPARATOR . 'MockTools.php';
        return;
    }

    foreach ($prefixes as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }

        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});
