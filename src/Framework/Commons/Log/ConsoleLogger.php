<?php

declare(strict_types=1);

namespace Wdc\Framework\Commons\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Logger PSR-3 mínimo: uma linha por mensagem no stderr (ou outro stream).
 * Para produção, troque por Monolog — a interface é a mesma.
 */
final class ConsoleLogger extends AbstractLogger
{
    private const array SEVERITY = [
        LogLevel::DEBUG => 0, LogLevel::INFO => 1, LogLevel::NOTICE => 2, LogLevel::WARNING => 3,
        LogLevel::ERROR => 4, LogLevel::CRITICAL => 5, LogLevel::ALERT => 6, LogLevel::EMERGENCY => 7,
    ];

    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream
     */
    public function __construct(private readonly string $minLevel = LogLevel::INFO, $stream = null)
    {
        $this->stream = $stream ?? STDERR;
    }

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? $level : LogLevel::INFO;
        if ((self::SEVERITY[$level] ?? 1) < (self::SEVERITY[$this->minLevel] ?? 1)) {
            return;
        }
        $line = sprintf('%s %-7s %s', date('H:i:s'), strtoupper($level), $message);
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $line .= ' | ' . $exception::class . ': ' . $exception->getMessage();
            if (self::SEVERITY[$level] >= self::SEVERITY[LogLevel::ERROR]) {
                $line .= "\n" . $exception->getTraceAsString();
            }
        }
        fwrite($this->stream, $line . "\n");
    }
}
