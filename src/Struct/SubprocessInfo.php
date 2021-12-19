<?php

namespace Unlooped\Struct;

use Carbon\CarbonImmutable;
use function count;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class SubprocessInfo
{
    public const STATUS_WAITING    = 'waiting';
    public const STATUS_RUNNING    = 'running';
    public const STATUS_DONE       = 'done';
    public const STATUS_TERMINATED = 'terminated';
    public const STATUS_ERROR      = 'error';

    private int $maxOutputRows = 1000;

    public Process $process;
    public SymfonyStyle $io;
    public ConsoleSectionOutput $output;

    public ?CarbonImmutable $startTime      = null;
    public ?CarbonImmutable $endTime        = null;
    public ?CarbonImmutable $lastOutputTime = null;

    public bool $manualTerminated  = false;

    private array $outputRows = [];

    public function __construct(
        Process $process,
        SymfonyStyle $io,
        ConsoleSectionOutput $output
    ) {
        $this->process            = $process;
        $this->io                 = $io;
        $this->output             = $output;
    }

    public static function create(
        Process $process,
        SymfonyStyle $io,
        ConsoleSectionOutput $output
    ): self {
        return new self($process, $io, $output);
    }

    public function addOutputRow(string $row): void
    {
        if (count($this->outputRows) > $this->maxOutputRows) {
            array_shift($this->outputRows);
        }

        $this->outputRows[]   = trim($row);
        $this->lastOutputTime = CarbonImmutable::now();
    }

    public function getOutputRows(int $n = -1): string
    {
        if ($n === -1) {
            return implode("\n", $this->outputRows);
        }

        return implode("\n", array_slice($this->outputRows, -$n, $n));
    }

    public function getLastRow()
    {
        return end($this->outputRows);
    }

    public function start(): void
    {
        $this->startTime = CarbonImmutable::now();
        $this->process->start();
    }

    public function getStatus(): string
    {
        if ($this->process->isSuccessful()) {
            return self::STATUS_DONE;
        }

        if (!$this->process->isRunning()) {
            if ($this->manualTerminated) {
                return self::STATUS_TERMINATED;
            }

            return self::STATUS_ERROR;
        }

        return self::STATUS_RUNNING;
    }

    public function getStatusOutput(): string
    {
        $status = $this->getStatus();

        if (self::STATUS_RUNNING === $status) {
            return '<fg=green>running</>';
        }

        if (self::STATUS_DONE === $status) {
            return '<bg=green;fg=black>done</>';
        }

        if (self::STATUS_TERMINATED === $status) {
            return '<bg=yellow;fg=black>terminated</>';
        }

        return '<fg=red>error</>';
    }

    public function update(): void
    {
        $incrementalOutput = $this->process->getIncrementalErrorOutput() . $this->process->getIncrementalOutput();
        if ($incrementalOutput) {
            $lines = explode("\n", $incrementalOutput);
            foreach ($lines as $line) {
                if (!trim($line)) {
                    continue;
                }

                $this->addOutputRow($line);
            }
        }

        if (null === $this->endTime && !$this->process->isRunning()) {
            $this->endTime = CarbonImmutable::now();
        }
    }

    public function getMaxOutputRows(): int
    {
        return $this->maxOutputRows;
    }

    public function setMaxOutputRows(int $maxOutputRows): self
    {
        $this->maxOutputRows = $maxOutputRows;
        return $this;
    }

    public function getLastUpdateDiff(): string
    {
        if ($this->endTime) {
            return $this->endTime->format('H:i:s');
        }

        if (!$this->process->isRunning()) {
            return '';
        }

        if ($this->lastOutputTime) {
            return $this->lastOutputTime->longRelativeDiffForHumans();
        }

        return '';
    }

    public function getRuntime(): string
    {
        return $this->startTime->longAbsoluteDiffForHumans($this->endTime);
    }
}
