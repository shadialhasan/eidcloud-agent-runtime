<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

use InvalidArgumentException;
use RuntimeException;

/**
 * Shell Tool for executing local commands within strict permission boundaries and timeout limits.
 */
class ShellTool extends AbstractTool
{
    /** @var list<string> */
    private array $allowedCommandPrefixes;

    /**
     * @param bool $enabled Whether the tool is active by default (default false for safety)
     * @param list<string> $allowedPrefixes List of command prefixes permitted to execute
     */
    public function __construct(bool $enabled = false, array $allowedPrefixes = ['php', 'git', 'echo', 'composer', 'dir', 'ls', 'whoami'])
    {
        $this->name = 'shell_execute';
        $this->description = 'Execute a shell command with timeout limits and security boundary verification.';
        $this->allowed = $enabled;
        $this->allowedCommandPrefixes = $allowedPrefixes;
        $this->timeout = 10;

        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The command line to execute',
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => 'Working directory for the command execution',
                ],
            ],
            'required' => ['command'],
        ];
    }

    /**
     * Set allowed command prefixes.
     *
     * @param list<string> $prefixes
     */
    public function setAllowedPrefixes(array $prefixes): self
    {
        $this->allowedCommandPrefixes = $prefixes;
        return $this;
    }

    public function execute(array $args): array|string
    {
        if (!$this->allowed) {
            throw new RuntimeException("Shell execution is disabled. Explicitly enable shell tool before use.");
        }

        $this->validateRequired($args, ['command']);
        $command = trim((string) $args['command']);
        $cwd = isset($args['cwd']) && is_dir((string) $args['cwd']) ? (string) $args['cwd'] : null;

        // Verify command prefix against allowed list
        $firstToken = strtolower(explode(' ', $command)[0]);
        // Handle path-based commands (e.g., /usr/bin/git or php.exe)
        $firstToken = basename(str_replace('\\', '/', $firstToken));
        $firstToken = preg_replace('/\.exe$/i', '', $firstToken);

        $permitted = false;
        foreach ($this->allowedCommandPrefixes as $prefix) {
            if ($firstToken === strtolower($prefix)) {
                $permitted = true;
                break;
            }
        }

        if (!$permitted) {
            throw new RuntimeException(
                sprintf("Command prefix '%s' is not in the allowed shell command list (%s).",
                    $firstToken,
                    implode(', ', $this->allowedCommandPrefixes)
                )
            );
        }

        // Dangerous keyword checks
        $dangerous = ['rm -rf /', ':(){ :|:& };:', 'mkfs', 'dd if=/dev/'];
        foreach ($dangerous as $danger) {
            if (str_contains($command, $danger)) {
                throw new RuntimeException("Command rejected: contains forbidden hazardous pattern.");
            }
        }

        return $this->runProcess($command, $cwd);
    }

    /**
     * Run process with timeout monitoring.
     *
     * @return array<string, mixed>
     */
    private function runProcess(string $command, ?string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $startTime = microtime(true);
        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if (!is_resource($process)) {
            throw new RuntimeException("Failed to spawn process for command: '$command'");
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timeoutMicro = $this->timeout * 1000000;
        $startMicro = microtime(true);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 0, 50000) > 0) {
                foreach ($read as $r) {
                    if ($r === $pipes[1]) {
                        $stdout .= (string) stream_get_contents($pipes[1]);
                    } elseif ($r === $pipes[2]) {
                        $stderr .= (string) stream_get_contents($pipes[2]);
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            if ((microtime(true) - $startMicro) * 1000000 > $timeoutMicro) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException("Command execution exceeded timeout of {$this->timeout}s: '$command'");
            }

            usleep(20000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $durationMs = (microtime(true) - $startTime) * 1000;

        return [
            'command' => $command,
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'duration_ms' => round($durationMs, 2),
            'success' => $exitCode === 0,
        ];
    }
}
