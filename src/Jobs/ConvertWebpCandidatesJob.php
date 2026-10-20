<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Jobs;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Throwable;

/**
 * Converts every PNG file already marked as a worthwhile WebP candidate.
 *
 * Each run processes a bounded batch to keep memory use predictable, then re-
 * queues itself if more candidate files remain.
 */
class ConvertWebpCandidatesJob extends AbstractQueuedJob implements QueuedJob
{
    /**
     * {@inheritdoc}
     */
    public function getTitle()
    {
        return 'Convert PNG files worth converting to WebP';
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
            ->getCandidates()
            ->limit($batchSize)
            ->column('ID');

        $this->totalSteps = count($fileIDs);
        $this->filesToProcess = $fileIDs;
        $this->convertedBytes = 0;

        if ($this->totalSteps === 0) {
            $this->isComplete = true;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function process()
    {
        if ($this->isComplete) {
            return;
        }

        $remaining = $this->filesToProcess;

        if (!count($remaining)) {
            $this->onJobComplete();
            return;
        }

        $this->currentStep++;
        $fileID = array_shift($remaining);
        $this->filesToProcess = $remaining;

        /** @var null|File $file */
        $file = File::get_by_id($fileID);

        if ($file) {
            try {
                if ($file->WebpStatus !== WebpConversionExtension::STATUS_CANDIDATE) {
                    $this->addMessage(sprintf('%s is no longer a candidate — skipping', $file->getFilename()));
                } else {
                    $replaced = WebpConversionService::singleton()->replace($file);

                    if ($replaced) {
                        $savedBytes = (int) $file->getWebpSaving();
                        $this->convertedBytes = (int) $this->convertedBytes + $savedBytes;
                        $this->addMessage(sprintf(
                            'Converted %s — saved %s',
                            $file->getFilename(),
                            File::format_size($savedBytes)
                        ));
                    } else {
                        $this->addMessage(sprintf(
                            'Unable to convert %s — %s',
                            $file->getFilename(),
                            $file->WebpNote ?: 'unknown error'
                        ), 'ERROR');
                    }
                }
            } catch (Throwable $e) {
                $this->addMessage(
                    sprintf(
                        'Unable to convert file %d (%s). Exception: %s',
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

        if (!count($this->filesToProcess)) {
            $this->onJobComplete();
        }
    }


    /**
     * Steps to run once the job is considered complete.
     */
    private function onJobComplete(): void
    {
        $this->isComplete = true;

        if ((int) $this->convertedBytes > 0) {
            $this->addMessage(sprintf(
                'Converted %s in this batch.',
                File::format_size((int) $this->convertedBytes)
            ));
        }

        $remaining = WebpConversionService::singleton()->getCandidates()->count();

        if ($remaining > 0 && !QueuedJobDescriptor::get()->filter([
            'Implementation' => self::class,
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
                QueuedJob::STATUS_RUN,
                QueuedJob::STATUS_WAIT,
            ],
        ])->exists()) {
            $this->addMessage(sprintf('%d files remaining - creating a new job.', $remaining));
            QueuedJobService::singleton()->queueJob(Injector::inst()->create(self::class));
        }
    }
}
