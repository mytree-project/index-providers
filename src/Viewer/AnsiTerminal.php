<?php

declare(strict_types=1);

namespace MyTree\IndexProviders\Viewer;

use RuntimeException;

final class AnsiTerminal
{
    private ?string $sttyState = null;

    public function enter(): void
    {
        if (!function_exists('stream_isatty') || !stream_isatty(STDIN) || !stream_isatty(STDOUT)) {
            throw new RuntimeException('Interactive TUI requires a TTY on stdin and stdout.');
        }
        if (!function_exists('shell_exec')) {
            throw new RuntimeException('Interactive TUI requires shell_exec() for terminal mode management.');
        }

        $state = shell_exec('stty -g 2>/dev/null');
        if (!is_string($state) || trim($state) === '') {
            throw new RuntimeException('Cannot read terminal mode with stty.');
        }
        $this->sttyState = trim($state);
        shell_exec('stty -icanon -echo min 1 time 0 2>/dev/null');
        fwrite(STDOUT, "\033[?25l");
    }

    public function leave(): void
    {
        if ($this->sttyState !== null && function_exists('shell_exec')) {
            shell_exec('stty ' . escapeshellarg($this->sttyState) . ' 2>/dev/null');
        }
        fwrite(STDOUT, "\033[?25h\033[0m" . PHP_EOL);
        $this->sttyState = null;
    }

    /** @return array{0:int,1:int} */
    public function size(): array
    {
        $rows = 30;
        $columns = 120;
        if (function_exists('shell_exec')) {
            $size = trim((string) shell_exec('stty size 2>/dev/null'));
            if (preg_match('/^(\d+)\s+(\d+)$/', $size, $m) === 1) {
                $rows = max(12, (int) $m[1]);
                $columns = max(60, (int) $m[2]);
            }
        }
        return [$columns, $rows];
    }

    public function draw(string $screen): void
    {
        fwrite(STDOUT, "\033[2J\033[H" . $screen);
    }

    public function readKey(): string
    {
        $first = fread(STDIN, 1);
        if ($first === false || $first === '') {
            return '';
        }
        if ($first !== "\033") {
            $ord = ord($first);
            $remaining = match (true) {
                ($ord & 0xE0) === 0xC0 => 1,
                ($ord & 0xF0) === 0xE0 => 2,
                ($ord & 0xF8) === 0xF0 => 3,
                default => 0,
            };
            while ($remaining > 0) {
                $next = fread(STDIN, $remaining);
                if ($next === false || $next === '') {
                    break;
                }
                $first .= $next;
                $remaining -= strlen($next);
            }
            return $first;
        }

        $sequence = $first;
        $read = [STDIN];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, 30000) > 0) {
            $next = fread(STDIN, 1);
            if ($next !== false) {
                $sequence .= $next;
            }
            if ($next === '[') {
                $read = [STDIN];
                if (stream_select($read, $write, $except, 0, 30000) > 0) {
                    $last = fread(STDIN, 1);
                    if ($last !== false) {
                        $sequence .= $last;
                    }
                }
            }
        }

        return $sequence;
    }
}
