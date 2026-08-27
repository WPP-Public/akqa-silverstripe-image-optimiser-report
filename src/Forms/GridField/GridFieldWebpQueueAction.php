<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Forms\GridField;

use Akqa\SilverStripe\ImageOptimiser\Jobs\AssessWebpConversionsJob;
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

    /**
     * {@inheritdoc}
     */
    public function getHTMLFragments($gridField)
    {
        $fragments = [];

        if ($this->isAssessmentRunning()) {
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

        $button = GridField_FormAction::create(
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

        if ($this->isAssessmentRunning()) {
            $button->setDisabled(true);
            $button->setTitle('Assessment running…');
        }

        $fragments['buttons-before-left'] = $button->Field()->forTemplate();

        return $fragments;
    }


    /**
     * {@inheritdoc}
     */
    public function getActions($gridField)
    {
        return [self::ACTION];
    }


    /**
     * {@inheritdoc}
     *
     * @throws ValidationException
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== self::ACTION) {
            return;
        }

        if ($this->isAssessmentRunning()) {
            $gridField->getForm()->sessionMessage(
                'An assessment job is already running.',
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
     * Whether an AssessWebpConversionsJob is currently waiting or running.
     */
    public function isAssessmentRunning(): bool
    {
        return QueuedJobDescriptor::get()
            ->filter([
                'Implementation' => AssessWebpConversionsJob::class,
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
