<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Reports;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Forms\GridField\GridFieldWebpConvertAction;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldFooter;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;

/**
 * Lists PNG files that have been trialled for WebP conversion, with the before
 * and after sizes and a link to preview each version. Files where WebP was no
 * smaller are marked Skipped by the assessment job and are shown only when
 * explicitly filtered for.
 *
 * The assessment itself is done by {@link \Akqa\SilverStripe\ImageOptimiser\Jobs\AssessWebpConversionsJob}.
 */
class WebpConversionReport extends Report
{
    protected $title = 'PNG files worth converting to WebP';

    protected $description = 'PNG images that would be meaningfully smaller as WebP. '
        . 'Preview both versions, then replace the original in one click — the file keeps its ID, '
        . 'so everything already pointing at it follows automatically.';

    protected $dataClass = File::class;

    public function title()
    {
        return $this->title;
    }


    public function description()
    {
        return $this->description;
    }


    public function dataClass()
    {
        return File::class;
    }


    /**
     * Let an editor switch between candidates, skipped files and failures.
     */
    public function parameterFields(): FieldList
    {
        return FieldList::create(
            DropdownField::create(
                'WebpStatus',
                'Status',
                [
                    WebpConversionExtension::STATUS_CANDIDATE => 'Worth converting',
                    WebpConversionExtension::STATUS_SKIPPED => 'Skipped (no worthwhile saving)',
                    WebpConversionExtension::STATUS_CONVERTED => 'Already converted',
                    WebpConversionExtension::STATUS_FAILED => 'Failed to convert',
                    WebpConversionExtension::STATUS_UNCHECKED => 'Not yet assessed',
                ],
                WebpConversionExtension::STATUS_CANDIDATE
            )
        );
    }


    /**
     * @param array $params
     * @param null|array|string $sort
     * @param null|array|int $limit
     */
    public function sourceRecords($params = [], $sort = null, $limit = null): SS_List
    {
        $status = $params['WebpStatus'] ?? WebpConversionExtension::STATUS_CANDIDATE;

        $list = WebpConversionService::singleton()
            ->getPngFiles()
            ->filter('WebpStatus', $status);

        // Biggest wins first — the default sort only makes sense for rows that
        // actually have a measured saving.
        if ($status === WebpConversionExtension::STATUS_CANDIDATE) {
            $list = $list->sort('WebpSavingBytes', 'DESC');
        } else {
            $list = $list->sort('WebpOriginalSize', 'DESC');
        }

        if ($sort) {
            $list = $list->sort($sort);
        }

        if ($limit) {
            $list = $list->limit($limit);
        }

        return $list;
    }


    /**
     * {@inheritdoc}
     */
    public function columns()
    {
        return [
            'Filename' => [
                'title' => 'File',
                'formatting' => function ($value, $item) {
                    return sprintf(
                        '<a href="%s" title="Edit this file in the CMS">%s</a>',
                        Convert::raw2att($item->CMSEditLink()),
                        Convert::raw2xml($value)
                    );
                },
            ],
            'WebpOriginalSize' => [
                'title' => 'Size before',
                'formatting' => fn ($value) => File::format_size((int) $value),
            ],
            'WebpConvertedSize' => [
                'title' => 'Size after',
                'formatting' => fn ($value) => $value ? File::format_size((int) $value) : '—',
            ],
            'WebpSavingBytes' => [
                'title' => 'Saving',
                'formatting' => fn ($value, $item) => $item->getWebpSavingSummary(),
            ],
            'PngPreviewLink' => [
                'title' => 'Preview PNG',
                'formatting' => fn ($value) => $this->previewAnchor($value, 'View PNG'),
            ],
            'WebpPreviewLink' => [
                'title' => 'Preview WebP',
                'formatting' => fn ($value) => $this->previewAnchor($value, 'View WebP'),
            ],
            'WebpNote' => [
                'title' => 'Notes',
            ],
        ];
    }


    /**
     * Render a preview URL as a link that opens in a new tab, so an editor can
     * compare both versions without losing their place in the report.
     */
    private function previewAnchor(?string $url, string $label): string
    {
        if (!$url) {
            return '—';
        }

        return sprintf(
            '<a href="%s" target="_blank" rel="noopener">%s</a>',
            Convert::raw2att($url),
            Convert::raw2xml($label)
        );
    }


    /**
     * Swap the stock report GridField for one that also carries the per-row
     * convert button, and keep the raw HTML in the preview columns.
     *
     * {@inheritdoc}
     */
    public function getReportField()
    {
        $config = GridFieldConfig::create()->addComponents(
            GridFieldSortableHeader::create(),
            GridFieldDataColumns::create(),
            GridFieldWebpConvertAction::create(),
            GridFieldPaginator::create(50),
            GridFieldFooter::create()
        );

        $gridField = GridField::create(
            'Report',
            null,
            $this->sourceRecords($this->getSourceParams(), null, null),
            $config
        );

        /** @var GridFieldDataColumns $columns */
        $columns = $config->getComponentByType(GridFieldDataColumns::class);

        $displayFields = [];
        $fieldFormatting = [];

        foreach ($this->columns() as $source => $info) {
            if (is_string($info)) {
                $info = ['title' => $info];
            }

            if (isset($info['formatting'])) {
                $fieldFormatting[$source] = $info['formatting'];
            }

            $displayFields[$source] = $info['title'] ?? $source;
        }

        $columns->setDisplayFields($displayFields);
        $columns->setFieldFormatting($fieldFormatting);

        return $gridField;
    }
}
