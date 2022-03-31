<?php

namespace Unlooped\Command;

use Unlooped\Struct\SubprocessInfo;
use Carbon\CarbonImmutable;
use Exception;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

abstract class ProcessRunnerCommand extends Command implements SignalableCommandInterface
{
    use LockableTrait;

    protected static $defaultName = 'process:runner';
    protected static $defaultDescription = 'Runs multiple processes';

    protected int $maxConcurrentProcesses = 4;
    protected bool $autoDelay = false;
    protected bool $shuffleCommands = false;
    protected bool $showDetailedLogs = false;

    protected array $subProcesses = [];
    protected string $projectDirectory;
    protected CarbonImmutable $startTime;
    protected InputInterface $input;
    protected ConsoleOutputInterface $output;
    protected bool $terminate = false;

    protected int $totalStartedProcesses = 0;

    public function __construct(
        string $projectDir
    ) {
        parent::__construct();

        $this->projectDirectory  = $projectDir;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(static::$defaultDescription)
            ->addOption('show-detailed_logs', 'l', InputOption::VALUE_OPTIONAL, 'Displays sections for each crawler output with the last X messaged', false)
            ->addOption('auto-delay', 'd', InputOption::VALUE_OPTIONAL, 'Uses autodelay for the accounts so not everything is started at the exact same time', false)
            ->addOption('concurrent-processes', 'c', InputOption::VALUE_OPTIONAL, 'Max concurrent Processes', $this->maxConcurrentProcesses)
        ;
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$output instanceof ConsoleOutputInterface) {
            throw new LogicException('This command accepts only an instance of "ConsoleOutputInterface".');
        }

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $this->input            = $input;
        $this->output           = $output;
        $this->startTime        = CarbonImmutable::now();
        $this->autoDelay        = false !== $input->getOption('auto-delay');
        $this->showDetailedLogs = false !== $input->getOption('show-detailed_logs');

        $commandsToRun = $this->getCommandsToRun();
        $commandsToRunCount = count($commandsToRun);
        if ($this->shuffleCommands) {
            shuffle($commandsToRun);
        }

        $overviewOutput = $output->section();
        $overviewIo     = new SymfonyStyle($input, $overviewOutput);

        $overviewIo->section('Overview');
        $overviewIo->writeln($commandsToRunCount . ' Commands to Run Found.');
        $overviewIo->listing(array_map(function (array $command) {
            return implode(' ', $command);
        }, $commandsToRun));

        $lastStart = null;
        $nextDelay = 0;
        do {
            if ($this->terminate) {
                break;
            }
            $runningProcesses = $this->countRunningProcesses();
            if (!$this->terminate && $runningProcesses < $this->maxConcurrentProcesses && count($commandsToRun) > 0) {
                if (!$lastStart || (time() - $lastStart) >= $nextDelay) {
                    $command = array_pop($commandsToRun);
                    $process = $this->getProcessForCommand($command);

                    $process->io->writeln('Start new process');
                    $this->totalStartedProcesses++;

                    $process->start();
                    $process->update();
                    $this->subProcesses[] = $process;

                    ++$runningProcesses;
                    $lastStart = time();
                    $nextDelay = $this->autoDelay ? random_int(30, 90) : 0;
                }
            }

            foreach ($this->subProcesses as $subProcess) {
                if ($subProcess->endTime === null
                    || SubprocessInfo::STATUS_RUNNING === $subProcess->getStatus())
                {
                    $subProcess->update();
                    $this->updateOverviewForProcess($subProcess);
                }
            }
            usleep(250000);
        } while ($runningProcesses > 0 || $this->totalStartedProcesses < $commandsToRunCount);


        return Command::SUCCESS;
    }

    public function getCommandsToRun(): array
    {
        return [];
    }

    protected function updateOverviewForProcess(SubprocessInfo $subProcess): void
    {
        $subProcess->output->clear();

        $commandLine = str_replace('\'', '', $subProcess->process->getCommandLine());
        $subProcess->io->writeln(sprintf('<fg=yellow>%s</>: <fg=#888888>%s</> --- runtime: <fg=#888888>%s</> --- last update: <fg=#888888>%s</>',
            $commandLine,
            $subProcess->getStatusOutput(),
            $subProcess->getRuntime(),
            $subProcess->getLastUpdateDiff()
        ));

        if (SubprocessInfo::STATUS_ERROR === $subProcess->getStatus()) {
            $subProcess->io->writeln(sprintf('last output: <fg=#BBBBBB>%s</>', $subProcess->getOutputRows() . "\n" . $subProcess->process->getErrorOutput()));
        } elseif (!$this->showDetailedLogs) {
            $subProcess->io->writeln(sprintf('last 5 output rows: <fg=#BBBBBB>%s</>', str_pad($subProcess->getOutputRows(5), 800)));
        } else {
            $subProcess->io->writeln(sprintf('output: <fg=#BBBBBB>%s</>', $subProcess->getOutputRows()));
        }

        $subProcess->io->writeln('----');
    }


    protected function countRunningProcesses(): int
    {
        $i = 0;
        foreach ($this->subProcesses as $subProcess) {
            if (SubprocessInfo::STATUS_RUNNING === $subProcess->getStatus()) {
                ++$i;
            }
        }

        return $i;
    }

    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal): void
    {
        $this->terminate = true;

        foreach ($this->subProcesses as $subProcess) {
            if ($subProcess->process->isRunning()) {
                $subProcess->manualTerminated = true;
                $subProcess->process->signal($signal);
            }
        }
    }

    protected function getProcessForCommand(array $command): SubprocessInfo
    {
        $output = $this->output->section();
        $io     = new SymfonyStyle($this->input, $output);

        $process = new Process($command);

        return SubprocessInfo::create($process, $io, $output);
    }


}
