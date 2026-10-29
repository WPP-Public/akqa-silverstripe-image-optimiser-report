<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Forms\GridField;

use Akqa\SilverStripe\ImageOptimiser\Jobs\AssessWebpConversionsJob;
use Akqa\SilverStripe\ImageOptimiser\Jobs\ConvertWebpCandidatesJob;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\View\Requirements;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Toolbar controls for the image conversion report: queue the assessment job,
 * and show a spinner while one is already running.
 */
class GridFieldWebpQueueAction extends AbstractGridFieldComponent implements
    GridField_HTMLProvider,
    GridField_ActionProvider
{
    private const ACTION = 'queuewebpassessment';

    private const ACTION_BATCH_CONVERT = 'queuewebpconversion';

    /**
     * {@inheritdoc}
     */
    public function getHTMLFragments($gridField)
    {
        $fragments = [];

        if ($this->isAnyWebpJobRunning()) {
            $fragments['buttons-before-right'] = $this->renderProgressIndicator();
            Requirements::customCSS(
                '.webp-assessment-progress .font-icon-spinner {'
                . ' display: inline-block;'
                . ' animation: webp-assessment-spin 1s linear infinite;'
                . '}'
                . '@keyframes webp-assessment-spin { to { transform: rotate(360deg); } }'
            );
            Requirements::customScript(
                <<<'JS'
(function () {
  if (window.__webpAssessmentReloadScheduled) {
    return;
  }
  window.__webpAssessmentReloadScheduled = true;
  window.setTimeout(function () {
    window.location.reload();
  }, 10000);
})();
JS
            );
        }

        $assessmentButton = GridField_FormAction::create(
            $gridField,
            'QueueWebpAssessment',
            'Queue assessment',
            self::ACTION,
            []
        )
            ->addExtraClass('btn btn-primary btn-sm')
            ->setAttribute(
                'aria-label',
                'Queue a background job to assess PNG files for WebP conversion'
            )
            ->setDescription('Queue a background job to assess unassessed PNG files');

        $convertButton = GridField_FormAction::create(
            $gridField,
            'QueueWebpConversion',
            'Queue conversion',
            self::ACTION_BATCH_CONVERT,
            []
        )
            ->addExtraClass('btn btn-primary btn-sm')
            ->setAttribute(
                'aria-label',
                'Queue a background job to convert all PNG files worth converting to WebP'
            )
            ->setDescription('Queue a background job to replace every candidate PNG file with a WebP');

        if ($this->isAnyWebpJobRunning()) {
            $assessmentButton->setDisabled(true);
            $assessmentButton->setTitle('WebP job running…');
            $convertButton->setDisabled(true);
            $convertButton->setTitle('WebP job running…');
        }

        $fragments['buttons-before-left'] = $assessmentButton->Field()->forTemplate()
            . ' '
            . $convertButton->Field()->forTemplate();

        return $fragments;
    }


    /**
     * {@inheritdoc}
     */
    public function getActions($gridField)
    {
        return [self::ACTION, self::ACTION_BATCH_CONVERT];
    }


    /**
     * {@inheritdoc}
     *
     * @throws ValidationException
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName === self::ACTION) {
            $this->handleAssessmentQueue($gridField);
            return;
        }

        if ($actionName === self::ACTION_BATCH_CONVERT) {
            $this->handleConversionQueue($gridField);
        }
    }

    /**
     * @throws ValidationException
     */
    private function handleAssessmentQueue(GridField $gridField): void
    {
        if ($this->isAnyWebpJobRunning()) {
            $gridField->getForm()->sessionMessage(
                'An assessment or conversion job is already running.',
                'warning'
            );

            return;
        }

        QueuedJobService::singleton()->queueJob(
            Injector::inst()->create(AssessWebpConversionsJob::class)
        );

        $gridField->getForm()->sessionMessage(
            'Queued assessment of PNG files for WebP conversion.',
            'good'
        );
    }

    /**
     * @throws ValidationException
     */
    private function handleConversionQueue(GridField $gridField): void
    {
        if ($this->isAnyWebpJobRunning()) {
            $gridField->getForm()->sessionMessage(
                'An assessment or conversion job is already running.',
                'warning'
            );

            return;
        }

        $candidateCount = WebpConversionService::singleton()->getCandidates()->count();

        if ($candidateCount <= 0) {
            $gridField->getForm()->sessionMessage(
                'There are no PNG files worth converting right now.',
                'warning'
            );

            return;
        }

        QueuedJobService::singleton()->queueJob(
            Injector::inst()->create(ConvertWebpCandidatesJob::class)
        );

        $gridField->getForm()->sessionMessage(
            sprintf(
                'Queued conversion of %d PNG file%s worth converting.',
                $candidateCount,
                $candidateCount === 1 ? '' : 's'
            ),
            'good'
        );
    }


    /**
     * Whether either queued WebP job is currently waiting or running.
     *
     * @deprecated 2.1.0 This method now checks both job types and therefore has broader
     *             semantics than its original assessment-only name; migrate to
     *             isAnyWebpJobRunning().
     * @see isAnyWebpJobRunning()
     */
    public function isAssessmentRunning(): bool
    {
        return $this->isAnyWebpJobRunning();
    }

    /**
     * Whether either queued WebP job is currently waiting or running.
     */
    public function isAnyWebpJobRunning(): bool
    {
        return QueuedJobDescriptor::get()
            ->filter([
                'Implementation' => [
                    AssessWebpConversionsJob::class,
                    ConvertWebpCandidatesJob::class,
                ],
                'JobStatus' => [
                    QueuedJob::STATUS_NEW,
                    QueuedJob::STATUS_INIT,
                    QueuedJob::STATUS_RUN,
                    QueuedJob::STATUS_WAIT,
                ],
            ])
            ->exists();
    }


    private function renderProgressIndicator(): string
    {
        return '<div class="webp-assessment-progress d-inline-flex align-items-center gap-2 text-muted"'
            . ' role="status" aria-live="polite">'
            . '<span class="font-icon-spinner" aria-hidden="true"></span>'
            . '<span>Assessing PNG files for WebP conversion…</span>'
            . '</div>';
    }
}
