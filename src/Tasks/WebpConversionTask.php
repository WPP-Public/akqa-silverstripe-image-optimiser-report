<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Tasks;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Jobs\AssessWebpConversionsJob;
use Akqa\SilverStripe\ImageOptimiser\Jobs\ConvertWebpCandidatesJob;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Kicks off (or inspects) the WebP assessment of PNG files.
 *
 * Normal use is `--queue`, which hands the work to
 * {@link AssessWebpConversionsJob}; the results then show up in the
 * "PNG files worth converting to WebP" report where an editor decides what to
 * replace. `--assess` runs a batch inline, which is handy for a quick look
 * without waiting for the queue.
 */
class WebpConversionTask extends BuildTask
{
    protected string $title = 'Assess PNG files for WebP conversion';

    protected static string $description = 'Trials a WebP conversion for PNG files and records the size difference. '
        . 'Nothing is replaced — results appear in the WebP conversion report.';

    private static $segment = 'WebpConversionTask';

    public function getOptions(): array
    {
        return [
            new InputOption('queue', null, InputOption::VALUE_NONE, 'Queue the assessment job'),
            new InputOption(
                'convert',
                null,
                InputOption::VALUE_NONE,
                'Queue the bulk conversion job for all candidate files'
            ),
            new InputOption('assess', null, InputOption::VALUE_NONE, 'Assess a batch inline instead of queueing'),
            new InputOption('limit', null, InputOption::VALUE_OPTIONAL, 'How many files to assess inline', 10),
            new InputOption('reset', null, InputOption::VALUE_NONE, 'Clear all previous assessments and start again'),
        ];
    }


    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $service = WebpConversionService::singleton();

        if ($input->getOption('reset')) {
            $count = $this->reset();
            $output->writeln(sprintf('Cleared %d previous assessments.', $count));
        }

        $this->summarise($output, $service);

        if ($input->getOption('queue')) {
            QueuedJobService::singleton()->queueJob(Injector::inst()->create(AssessWebpConversionsJob::class));
            $output->writeln('Queued the assessment job.');

            return 0;
        }

        if ($input->getOption('convert')) {
            $candidates = $service->getCandidates()->count();
            if ($candidates <= 0) {
                $output->writeln('There are no PNG files worth converting right now.');
                return 0;
            }

            QueuedJobService::singleton()->queueJob(Injector::inst()->create(ConvertWebpCandidatesJob::class));
            $output->writeln(sprintf(
                'Queued the conversion job for %d candidate file%s.',
                $candidates,
                $candidates === 1 ? '' : 's'
            ));

            return 0;
        }

        if ($input->getOption('assess')) {
            $this->assessInline($output, $service, (int) $input->getOption('limit'));

            return 0;
        }

        $output->writeln(
            'Nothing to do — pass --queue to assess files, --convert to replace candidates, '
            . 'or --assess for an inline batch.'
        );

        return 0;
    }


    /**
     * Assess a batch without going through the queue.
     */
    private function assessInline(PolyOutput $output, WebpConversionService $service, int $limit): void
    {
        $files = $service->getUnassessedFiles()->limit($limit);
        $saving = 0;

        foreach ($files as $file) {
            /** @var File $file */
            $status = $service->assess($file);

            if ($status === WebpConversionExtension::STATUS_CANDIDATE) {
                $saving += $file->getWebpSaving();

                $output->writeln(sprintf(
                    '  CANDIDATE  %s  %s -> %s  (%s%% saving)',
                    $file->getFilename(),
                    File::format_size((int) $file->WebpOriginalSize),
                    File::format_size((int) $file->WebpConvertedSize),
                    $file->getWebpSavingPercent()
                ));
            } else {
                $output->writeln(sprintf(
                    '  %-10s %s  — %s',
                    strtoupper($status),
                    $file->getFilename(),
                    $file->WebpNote
                ));
            }
        }

        if ($saving > 0) {
            $output->writeln(sprintf('Potential saving in this batch: %s', File::format_size($saving)));
        }
    }


    /**
     * Print the current state of play across all PNG files.
     */
    private function summarise(PolyOutput $output, WebpConversionService $service): void
    {
        $statuses = [
            WebpConversionExtension::STATUS_UNCHECKED,
            WebpConversionExtension::STATUS_CANDIDATE,
            WebpConversionExtension::STATUS_SKIPPED,
            WebpConversionExtension::STATUS_CONVERTED,
            WebpConversionExtension::STATUS_FAILED,
        ];

        $output->writeln(sprintf('PNG files: %d', $service->getPngFiles()->count()));

        foreach ($statuses as $status) {
            $output->writeln(sprintf(
                '  %-10s %d',
                $status,
                $service->getPngFiles()->filter('WebpStatus', $status)->count()
            ));
        }

        $candidates = $service->getCandidates();

        if ($candidates->count()) {
            $total = 0;

            foreach ($candidates as $candidate) {
                /** @var File $candidate */
                $total += $candidate->getWebpSaving();
            }

            $output->writeln(sprintf(
                'Outstanding candidates would save %s in total.',
                File::format_size($total)
            ));
        }
    }


    /**
     * Clear every previous assessment so the job starts from scratch. Files
     * that have already been replaced are left alone.
     */
    private function reset(): int
    {
        $files = File::get()->exclude('WebpStatus', [
            WebpConversionExtension::STATUS_UNCHECKED,
            WebpConversionExtension::STATUS_CONVERTED,
        ]);

        $count = 0;

        foreach ($files as $file) {
            $file->WebpStatus = WebpConversionExtension::STATUS_UNCHECKED;
            $file->WebpNote = null;
            $file->WebpCheckedAt = null;
            $file->WebpOriginalSize = 0;
            $file->WebpConvertedSize = 0;
            $file->write();
            $count++;
        }

        return $count;
    }
}
