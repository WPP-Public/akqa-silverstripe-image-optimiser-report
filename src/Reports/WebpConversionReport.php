<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Reports;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Forms\GridField\GridFieldWebpConvertAction;
use Akqa\SilverStripe\ImageOptimiser\Forms\GridField\GridFieldWebpQueueAction;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Forms\GridField\GridFieldFooter;
use SilverStripe\Forms\GridField\GridFieldPaginator;
use SilverStripe\Forms\GridField\GridFieldSortableHeader;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Reports\Report;

/**
 * Lists PNG files that have been trialled for WebP conversion, with the before
 * and after sizes and a link to preview each version.
 *
 * The assessment itself is done by {@link \Akqa\SilverStripe\ImageOptimiser\Jobs\AssessWebpConversionsJob},
 * which can be queued from the report toolbar.
 */
class WebpConversionReport extends Report
{
    protected $title = 'Image conversion report';

    protected $description = 'Trial WebP conversions for PNG images, preview the saving, '
        . 'then replace the original in one click — the file keeps its ID, so everything '
        . 'already pointing at it follows automatically.';

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
     * Let an editor switch between candidates, failures and unassessed files.
     */
    public function parameterFields(): FieldList
    {
        return FieldList::create(
            TextField::create(
                'FilenameFilter',
                'Search'
            )->setDescription('Wildcard search for file or folder names like "images/*" or "*report*.png"'),
            DropdownField::create(
                'WebpStatus',
                'Status',
                [
                    WebpConversionExtension::STATUS_CANDIDATE => 'Worth converting',
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
        $filenameFilter = trim((string) ($params['FilenameFilter'] ?? ''));

        $list = WebpConversionService::singleton()
            ->getPngFiles()
            ->filter('WebpStatus', $status);

        if ($filenameFilter !== '') {
            $pattern = $this->convertWildcardSearchToSqlLike($filenameFilter);
            $list = $list->filterAny([
                'Filename:PartialMatch' => $pattern,
                'Name:PartialMatch' => $pattern,
            ]);
        }

        // Biggest wins first — the default sort only makes sense for rows that
        // actually have a measured saving.
        if ($status === WebpConversionExtension::STATUS_CANDIDATE) {
            $list = $list->sort('WebpSavingBytes', 'DESC');
        } else {
            $list = $list->sort('Filename', 'ASC');
        }

        if ($sort) {
            $list = $list->sort($sort);
        }

        if ($limit) {
            $list = $list->limit($limit);
        }

        return $list;
    }

    private function convertWildcardSearchToSqlLike(string $filter): string
    {
        if ($filter === '') {
            return '%';
        }

        $hasWildcard = str_contains($filter, '*');
        $filter = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filter);
        $filter = str_replace('*', '%', $filter);

        if (!$hasWildcard) {
            $filter = '%' . $filter . '%';
        }

        return $filter;
    }


    /**
     * {@inheritdoc}
     *
     * Column set depends on the active status filter so we do not trigger
     * trial conversions while listing unassessed files.
     */
    public function columns()
    {
        $status = $this->getSourceParams()['WebpStatus'] ?? WebpConversionExtension::STATUS_CANDIDATE;

        $columns = [
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
        ];

        if ($status === WebpConversionExtension::STATUS_UNCHECKED) {
            return $columns;
        }

        $columns['WebpOriginalSize'] = [
            'title' => 'Size before',
            'formatting' => fn ($value) => File::format_size((int) $value),
        ];
        $columns['WebpConvertedSize'] = [
            'title' => 'Size after',
            'formatting' => fn ($value) => $value ? File::format_size((int) $value) : '—',
        ];
        $columns['WebpSavingBytes'] = [
            'title' => 'Saving',
            'formatting' => fn ($value, $item) => $item->getWebpSavingSummary(),
        ];

        if ($status === WebpConversionExtension::STATUS_CANDIDATE) {
            $columns['PngPreviewLink'] = [
                'title' => 'Preview PNG',
                'formatting' => fn ($value) => $this->previewAnchor($value, 'View PNG'),
            ];
            $columns['WebpPreviewLink'] = [
                'title' => 'Preview WebP',
                'formatting' => fn ($value) => $this->previewAnchor($value, 'View WebP'),
            ];
        }

        $columns['WebpNote'] = [
            'title' => 'Notes',
        ];

        return $columns;
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
     * Swap the stock report GridField for one that also carries the queue
     * toolbar, per-row actions, and keeps the raw HTML in the preview columns.
     *
     * {@inheritdoc}
     */
    public function getReportField()
    {
        $config = GridFieldConfig::create()->addComponents(
            GridFieldButtonRow::create('before'),
            GridFieldWebpQueueAction::create(),
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
