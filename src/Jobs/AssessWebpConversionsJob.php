<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Jobs;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * Works through PNG files, trials a WebP conversion for each and records
 * whether the saving is worth taking. Nothing is replaced here — the outcome
 * feeds {@link \Akqa\SilverStripe\ImageOptimiser\Reports\WebpConversionReport}, where an editor
 * decides what to swap.
 *
 * Assessing one file per step keeps peak memory predictable, since a large PNG
 * has to be decoded in full to be re-encoded. The job re-queues itself while
 * unassessed files remain.
 *
 * @package Akqa\SilverStripe\ImageOptimiser\Jobs
 */
class AssessWebpConversionsJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * {@inheritdoc}
     */
    public function getTitle()
    {
        return 'Assess PNG files for WebP conversion';
    }


    /**
     * {@inheritdoc}
     */
    public function getJobType()
    {
        return QueuedJob::QUEUED;
    }


    /**
     * {@inheritdoc}
     */
    public function setup()
    {
        $batchSize = (int) WebpConversionService::config()->get('batch_size');

        $fileIDs = WebpConversionService::singleton()
            ->getUnassessedFiles()
            ->limit($batchSize)
            ->column('ID');

        $this->totalSteps = count($fileIDs);
        $this->filesToProcess = $fileIDs;
        $this->savedBytes = 0;
    }


    /**
     * {@inheritdoc}
     */
    public function process()
    {
        $remaining = $this->filesToProcess;

        if (!count($remaining)) {
            $this->onJobComplete();
            return;
        }

        $this->currentStep++;

        $fileID = array_shift($remaining);

        /** @var null|File $file */
        $file = File::get_by_id($fileID);

        if ($file) {
            try {
                $status = WebpConversionService::singleton()->assess($file);

                if ($status === WebpConversionExtension::STATUS_CANDIDATE) {
                    $this->savedBytes = (int) $this->savedBytes + $file->getWebpSaving();

                    $this->addMessage(sprintf(
                        'Candidate: %s — %s → %s (%s%% saving)',
                        $file->getFilename(),
                        File::format_size((int) $file->WebpOriginalSize),
                        File::format_size((int) $file->WebpConvertedSize),
                        $file->getWebpSavingPercent()
                    ));
                } else {
                    $this->addMessage(sprintf(
                        '%s: %s — %s',
                        $status,
                        $file->getFilename(),
                        $file->WebpNote
                    ));
                }
            } catch (Throwable $e) {
                $this->addMessage(
                    sprintf(
                        'Unable to assess file %d (%s). Exception: %s',
                        $fileID,
                        $file->getFilename(),
                        $e->getMessage()
                    ),
                    'ERROR'
                );
            }
        } else {
            $this->addMessage(sprintf('File %d no longer exists — skipping', $fileID), 'WARN');
        }

        $this->filesToProcess = $remaining;

        if (!count($remaining)) {
            $this->onJobComplete();
        }
    }


    /**
     * Steps to run once the job is considered complete.
     */
    private function onJobComplete(): void
    {
        $this->isComplete = true;

        if ((int) $this->savedBytes > 0) {
            $this->addMessage(sprintf(
                'Identified %s of potential savings in this batch.',
                File::format_size((int) $this->savedBytes)
            ));
        }

        $remaining = WebpConversionService::singleton()->getUnassessedFiles()->count();

        if ($remaining > 0 && $remaining >= $this->totalSteps) {
            $this->addMessage(sprintf('%d files remaining - creating a new job.', $remaining));
            QueuedJobService::singleton()->queueJob(Injector::inst()->create(self::class));
        }
    }
}
