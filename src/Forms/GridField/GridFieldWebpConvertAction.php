<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Forms\GridField;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;

/**
 * Adds a single-click "Replace with WebP" button to each row of the
 * {@link \Akqa\SilverStripe\ImageOptimiser\Reports\WebpConversionReport}.
 *
 * The button only appears for files still marked as a candidate, so a file
 * cannot be converted twice, and rows that were deliberately skipped do not
 * offer an action.
 */
class GridFieldWebpConvertAction extends AbstractGridFieldComponent implements
    GridField_ColumnProvider,
    GridField_ActionProvider
{
    private const COLUMN = 'WebpConvertAction';

    private const ACTION = 'replacewithwebp';

    /**
     * {@inheritdoc}
     */
    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array(self::COLUMN, $columns, true)) {
            $columns[] = self::COLUMN;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function getColumnsHandled($gridField)
    {
        return [self::COLUMN];
    }


    /**
     * @param GridField $gridField
     * @param File $record
     * @param string $columnName
     * @return array<string, string>
     */
    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'grid-field__col-compact'];
    }


    /**
     * {@inheritdoc}
     */
    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => 'Action'];
    }


    /**
     * {@inheritdoc}
     */
    public function getActions($gridField)
    {
        return [self::ACTION];
    }


    /**
     * @param GridField $gridField
     * @param File $record
     * @param string $columnName
     * @return null|string HTML for the column, or null to render nothing.
     */
    public function getColumnContent($gridField, $record, $columnName)
    {
        if (!$record instanceof File || !$record->canEdit()) {
            return null;
        }

        if ($record->WebpStatus !== WebpConversionExtension::STATUS_CANDIDATE) {
            return null;
        }

        $title = sprintf('Replace with WebP (saves %s)', File::format_size($record->getWebpSaving()));

        return GridField_FormAction::create(
            $gridField,
            'ReplaceWithWebp' . $record->ID,
            'Replace with WebP',
            self::ACTION,
            ['RecordID' => $record->ID]
        )
            ->addExtraClass('btn btn-outline-primary btn-sm')
            ->setDescription($title)
            ->setAttribute('aria-label', $title)
            ->Field()
            ->forTemplate();
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

        $file = File::get_by_id((int) $arguments['RecordID']);

        if (!$file) {
            throw ValidationException::create('That file no longer exists.');
        }

        if (!$file->canEdit()) {
            throw ValidationException::create('You do not have permission to edit that file.');
        }

        $originalName = (string) $file->getFilename();
        $saving = $file->getWebpSaving();

        if (!WebpConversionService::singleton()->replace($file)) {
            throw ValidationException::create(sprintf(
                'Could not convert %s: %s',
                $originalName,
                $file->WebpNote ?: 'unknown error'
            ));
        }

        $gridField->getForm()
            ->sessionMessage(
                sprintf(
                    'Replaced %s with %s, saving %s.',
                    $originalName,
                    $file->getFilename(),
                    File::format_size($saving)
                ),
                'good'
            );
    }
}
