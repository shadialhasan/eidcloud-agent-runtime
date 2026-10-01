<?php

declare(strict_types=1);

namespace EidCloud\AgentRuntime\Tools;

use InvalidArgumentException;
use RuntimeException;

/**
 * Real Filesystem Tool with directory sandboxing and permission boundaries.
 */
class FilesystemTool extends AbstractTool
{
    private string $baseDir;

    /**
     * @param string|null $baseDir Base directory to confine file operations to (defaults to getcwd())
     */
    public function __construct(?string $baseDir = null)
    {
        $this->name = 'filesystem';
        $this->description = 'Perform sandboxed filesystem operations: list files, read file contents, count lines, or check file existence.';
        $this->baseDir = rtrim(realpath($baseDir ?? (string) getcwd()) ?: (string) getcwd(), DIRECTORY_SEPARATOR);

        $this->parameters = [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Filesystem action: list_dir, read_file, count_lines, file_exists, or write_file',
                    'enum' => ['list_dir', 'read_file', 'count_lines', 'file_exists', 'write_file'],
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Relative or absolute file/directory path within the sandboxed directory',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Content to write (required for write_file)',
                ],
                'recursive' => [
                    'type' => 'boolean',
                    'description' => 'Whether to list directories recursively',
                    'default' => false,
                ],
            ],
            'required' => ['action', 'path'],
        ];
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function setBaseDir(string $baseDir): self
    {
        $resolved = realpath($baseDir);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException("Invalid base directory: '$baseDir'");
        }
        $this->baseDir = $resolved;
        return $this;
    }

    public function execute(array $args): array|string
    {
        $this->validateRequired($args, ['action', 'path']);
        $action = $args['action'];
        $rawPath = (string) $args['path'];
        $safePath = $this->resolvePath($rawPath, $action === 'write_file');

        return match ($action) {
            'list_dir' => $this->handleListDir($safePath, (bool) ($args['recursive'] ?? false)),
            'read_file' => $this->handleReadFile($safePath),
            'count_lines' => $this->handleCountLines($safePath),
            'file_exists' => ['path' => $rawPath, 'exists' => file_exists($safePath)],
            'write_file' => $this->handleWriteFile($safePath, (string) ($args['content'] ?? '')),
            default => throw new InvalidArgumentException("Unsupported filesystem action: '$action'"),
        };
    }

    /**
     * Resolve and verify that the target path does not escape the sandbox base directory.
     */
    private function resolvePath(string $path, bool $forWriting = false): string
    {
        $target = $path;
        if (!str_starts_with($target, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $target)) {
            $target = $this->baseDir . DIRECTORY_SEPARATOR . ltrim($target, '\\/');
        }

        // For non-existent files to be written, normalize parent directory
        if ($forWriting && !file_exists($target)) {
            $parent = dirname($target);
            $parentReal = realpath($parent);
            if ($parentReal === false || !str_starts_with($parentReal, $this->baseDir)) {
                throw new RuntimeException("Directory traversal detected or invalid parent directory: '$path'");
            }
            return $parentReal . DIRECTORY_SEPARATOR . basename($target);
        }

        $real = realpath($target);
        if ($real === false && !$forWriting) {
            throw new RuntimeException("File or path not found: '$path'");
        }

        if ($real !== false && !str_starts_with($real, $this->baseDir)) {
            throw new RuntimeException("Directory traversal denied: '$path' is outside sandboxed boundary.");
        }

        return $real ?: $target;
    }

    private function handleListDir(string $path, bool $recursive): array
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Path is not a directory: '$path'");
        }

        $items = [];
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                $rel = str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname());
                $items[] = [
                    'path' => str_replace('\\', '/', $rel),
                    'type' => $fileInfo->isDir() ? 'dir' : 'file',
                    'size' => $fileInfo->isFile() ? $fileInfo->getSize() : null,
                ];
            }
        } else {
            $entries = scandir($path) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $path . DIRECTORY_SEPARATOR . $entry;
                $rel = str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $full);
                $items[] = [
                    'name' => $entry,
                    'path' => str_replace('\\', '/', $rel),
                    'type' => is_dir($full) ? 'dir' : 'file',
                    'size' => is_file($full) ? (int) filesize($full) : null,
                ];
            }
        }

        return [
            'directory' => str_replace('\\', '/', str_replace($this->baseDir, '.', $path)),
            'total_items' => count($items),
            'items' => $items,
        ];
    }

    private function handleReadFile(string $path): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Path is not a file: '$path'");
        }

        $size = filesize($path);
        // Max 512KB for tool read buffer to avoid memory exhaustion
        if ($size > 512 * 1024) {
            throw new RuntimeException("File size exceeds safe tool read limit (512KB): '$path'");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Could not read file contents: '$path'");
        }

        return [
            'path' => str_replace('\\', '/', str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $path)),
            'size' => $size,
            'lines' => substr_count($content, "\n") + (strlen($content) > 0 ? 1 : 0),
            'content' => $content,
        ];
    }

    private function handleCountLines(string $path): array
    {
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            $totalLines = 0;
            $fileCount = 0;
            $breakdown = [];

            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if ($fileInfo->isFile()) {
                    $lines = count(file($fileInfo->getPathname()) ?: []);
                    $totalLines += $lines;
                    $fileCount++;
                    $rel = str_replace('\\', '/', str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $fileInfo->getPathname()));
                    $breakdown[$rel] = $lines;
                }
            }

            return [
                'path' => str_replace('\\', '/', str_replace($this->baseDir, '.', $path)),
                'is_directory' => true,
                'total_lines' => $totalLines,
                'file_count' => $fileCount,
                'breakdown' => $breakdown,
            ];
        }

        if (is_file($path)) {
            $lines = count(file($path) ?: []);
            return [
                'path' => str_replace('\\', '/', str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $path)),
                'is_directory' => false,
                'total_lines' => $lines,
            ];
        }

        throw new InvalidArgumentException("Target path is neither a file nor directory: '$path'");
    }

    private function handleWriteFile(string $path, string $content): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $bytes = file_put_contents($path, $content);
        if ($bytes === false) {
            throw new RuntimeException("Failed to write to file: '$path'");
        }

        return [
            'path' => str_replace('\\', '/', str_replace($this->baseDir . DIRECTORY_SEPARATOR, '', $path)),
            'bytes_written' => $bytes,
            'success' => true,
        ];
    }
}
