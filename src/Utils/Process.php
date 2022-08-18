<?php

declare(strict_types=1);

/**
 * Process and external command execution functions.
 *
 * @package lucatume\WPBrowser
 */

namespace lucatume\WPBrowser\Utils;

use Closure;
use InvalidArgumentException;
use RuntimeException;

class Process
{
    public const PROC_WRITE = 'proc_write';
    public const PROC_READ = 'proc_read';
    public const PROC_ERROR = 'proc_error';
    public const PROC_REALTIME = 'proc_realtime';
    public const PROC_CLOSE = 'proc_close';
    public const PROC_STATUS = 'proc_status';

    /**
     * Returns a map to check on a process status.
     *
     * @param resource $proc_handle The process handle.
     *
     * @return Closure A function to check and return the process status map.
     *
     * @throws RuntimeException If the process current status cannot be fetched.
     */
    public static function processStatus($proc_handle): Closure
    {
        return static function ($what, $default = null) use ($proc_handle) {
            $status = proc_get_status($proc_handle);

            if ($status === false) {
                throw new RuntimeException('Failed to gather the process current status.');
            }

            $map = new Map($status);
            $value = $map($what, $default);
            unset($map);

            return $value;
        };
    }

    /**
     * Reads the whole content of a process pipe.
     *
     * @param resource $pipe The pipe to read from.
     * @param int|null $length Either the length of the string to read, or `null` to read the whole pipe contents.
     *
     * @return string The string read from the pipe.
     */
    public static function processReadPipe($pipe, int $length = null): string
    {
        $read = [];
        while (false !== $line = fgets($pipe)) {
            $read[] = $line;
        }

        $readString = implode('', $read);

        return $length ? (string)substr($readString, 0, $length) : $readString;
    }

    /**
     * Opens a process handle, starting the process, and returns a closure to read, write or terminate the process.
     *
     * The command is NOT escaped and should be escaped before being input into this function.
     *
     * @param array<string>|string $cmd The command to run, escaped if required..
     * @param string|null $cwd The process working directory, or `null` to use the current one.
     * @param array<string,mixed>|null $env A map of the process environment variables; or `null` to use the current ones.
     *
     * @return Closure A closure to read ($what = PROC_READ), write ($what = PROC_WRITE), read errors ($what = PROC_ERROR)
     *                  or close the process ($what = PROC_STATUS) and get its exit status.
     *
     * @throws RuntimeException If the process cannot be started.
     */
    public static function process(array|string $cmd = [], string $cwd = null, array $env = null): Closure
    {
        // PHP 7.4 has introduced support for array commands and will handle the escaping.
        $escapedCommand = $cmd;

        // `0` is STDIN, `1` is STDOUT, `2` is STDERR.
        $descriptors = [
            // Read from STDIN.
            0 => ['pipe', 'r'],
            // Write to STDOUT.
            1 => ['pipe', 'w'],
            // Write to STDERR.
            2 => ['pipe', 'w'],
        ];

        if (is_string($escapedCommand)) {
            codecept_debug('Running command: ' . $escapedCommand);
        } else {
            codecept_debug('Running command: ' . implode(' ', $escapedCommand));
        }

        // @phpstan-ignore-next-line
        $proc = proc_open($escapedCommand, $descriptors, $pipes, $cwd, $env);

        if (!is_resource($proc)) {
            $cmd = is_array($cmd) ? implode(' ', $cmd) : $cmd;
            throw new RuntimeException('Process "' . $cmd . '" could not be started.');
        }

        return static function ($what = self::PROC_STATUS, ...$args) use ($proc, $pipes) {
            switch ($what) {
                case self::PROC_WRITE:
                    return fwrite($pipes[0], reset($args));
                case self::PROC_READ:
                    $length = isset($args[0]) ? (int)$args[0] : null;

                    return self::processReadPipe($pipes[1], $length);
                case self::PROC_ERROR:
                    $length = isset($args[0]) ? (int)$args[0] : null;

                    return self::processReadPipe($pipes[2], $length);
                /** @noinspection PhpMissingBreakStatementInspection */
                case self::PROC_REALTIME:
                    $callback = $args[0];
                    if (!is_callable($callback)) {
                        throw new InvalidArgumentException('Realtime callback should be callable.');
                    }
                    do {
                        $currentStatus = self::processStatus($proc);
                        foreach ([2 => self::PROC_ERROR, 1 => self::PROC_READ] as $pipe => $type) {
                            $callback($type, self::processReadPipe($pipes[$pipe]));
                        }
                    } while ($currentStatus('running', false));
                // Let the process close after realtime.
                case self::PROC_CLOSE:
                case self::PROC_STATUS:
                default:
                    if (!(fclose($pipes[0]))) {
                        throw new RuntimeException('Could not close the process STDIN pipe.');
                    }
                    if (!(fclose($pipes[1]))) {
                        throw new RuntimeException('Could not close the process STDOUT pipe.');
                    }
                    if (!(fclose($pipes[2]))) {
                        throw new RuntimeException('Could not close the process STDERR pipe.');
                    }

                    return proc_close($proc);
            }
        };
    }

    /**
     * Builds an array format command line from a string command line.
     *
     * @param string|array<string> $command The command line to parse, if in array format it will not be modified.
     *
     * @return array<string> The parsed command line, in array format. Untouched if originally already an array.
     */
    public static function buildCommandline(string|array $command): array
    {
        if (empty($command) || is_array($command)) {
            return array_values(array_filter((array)$command, static function ($commandArg): bool {
                // Drop only the empty fragments.
                return !empty($commandArg) || is_numeric($commandArg);
            }));
        }
        $escapedCommandLine = [];
        $pattern = '/' .
            '-{1,2}[A-Za-z0-9_-]+=\'(\\\\\'|[^\'])*\'' . // Format `--opt='foo \'esc\' bar' -o='foo \'esc\' bar'`.
            '|-{1,2}[A-Za-z0-9_-]+="(\\\\"|[^"])*"' . // Format `--opt="foo \"esc\" bar" -o="foo \"esc\" bar"`.
            '|-{1,2}[A-Za-z0-9_-]+=[^\\s]+' . // Format `-o=val --opt=val`.
            '|-{1,2}[A-Za-z0-9_-]+' . // Format `-f --flag`.
            '|[^\\s"\']+' . // Format `command`.
            '|"(\\\\"|[^"])+"' . // Format `"some \"esc\" value"`.
            '|\'(\\\\\'|[^\'])+\'' . // Format `'some \'esc\' value'`
            '/';
        $singleEsc = '/^\'(\\\\\'|[^\'])*\'$/';
        $doubleEsc = '/^"(\\\\"|[^"])*"$/';
        preg_replace_callback($pattern,
            static function ($match) use (&$escapedCommandLine, $singleEsc, $doubleEsc): void {
                $value = reset($match);

                if (empty($value)) {
                    return;
                }

                if (str_contains($value, '=')) {
                    // option=value format.
                    $keyAndValue = explode('=', $value, 2);
                    if (is_array($keyAndValue) && count($keyAndValue) === 2) {
                        // Assume the caller has already correctly escaped the value if single or double quoted.
                        $candidateValue = $keyAndValue[1];
                        if (preg_match($singleEsc, $keyAndValue[1]) || preg_match($doubleEsc, $keyAndValue[1])) {
                            $escapedCommandLine[] = $keyAndValue[0] . '=' . $candidateValue;
                            return;
                        }
                    }
                }

                $escapedCommandLine [] = escapeshellarg($value);
            }, $command);

        return $escapedCommandLine;
    }
}

