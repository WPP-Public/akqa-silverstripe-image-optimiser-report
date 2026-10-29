<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Extensions;

use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Tracks the outcome of a trial WebP conversion for each file, so that the
 * {@link \Akqa\SilverStripe\ImageOptimiser\Reports\WebpConversionReport} can list worthwhile
 * candidates and each file is only assessed once.
 *
 * Applied to {@link File} rather than Image so the columns live on the
 * existing File table instead of creating a new one.
 *
 * @extends Extension<File>
 */
class WebpConversionExtension extends Extension
{
    public const STATUS_UNCHECKED = 'Unchecked';

    public const STATUS_CANDIDATE = 'Candidate';

    public const STATUS_SKIPPED = 'Skipped';

    public const STATUS_CONVERTED = 'Converted';

    public const STATUS_FAILED = 'Failed';

    private static array $db = [
        'WebpStatus' => 'Enum("Unchecked,Candidate,Skipped,Converted,Failed", "Unchecked")',
        'WebpCheckedAt' => 'Datetime',
        'WebpOriginalSize' => 'Int',
        'WebpConvertedSize' => 'Int',
        'WebpSavingBytes' => 'Int',
        'WebpNote' => 'Varchar(255)',
        'WebpConvertedAt' => 'Datetime',
    ];

    private static array $defaults = [
        'WebpStatus' => self::STATUS_UNCHECKED,
    ];

    private static array $indexes = [
        'WebpStatus' => true,
        'WebpSavingBytes' => true,
    ];

    /**
     * Bytes saved by switching to WebP. Zero when the file has not been
     * assessed, or when WebP turned out to be no smaller.
     *
     * Stored as its own column rather than derived on read, because the report
     * sorts by it and SS6 will not sort on a SQL expression.
     */
    public function getWebpSaving(): int
    {
        $stored = (int) $this->getOwner()->getField('WebpSavingBytes');

        if ($stored > 0) {
            return $stored;
        }

        $original = (int) $this->getOwner()->WebpOriginalSize;
        $converted = (int) $this->getOwner()->WebpConvertedSize;

        if ($original <= 0 || $converted <= 0) {
            return 0;
        }

        return max(0, $original - $converted);
    }


    /**
     * Saving as a percentage of the original file size, rounded to one decimal.
     */
    public function getWebpSavingPercent(): float
    {
        $original = (int) $this->getOwner()->WebpOriginalSize;

        if ($original <= 0) {
            return 0.0;
        }

        return round(($this->getWebpSaving() / $original) * 100, 1);
    }


    /**
     * Whether this file is a PNG that has not yet been assessed or replaced.
     */
    public function isWebpConversionCandidate(): bool
    {
        return $this->getOwner()->WebpStatus === self::STATUS_CANDIDATE;
    }


    /**
     * Public URL of the trial WebP variant, or null when it cannot be
     * generated. The variant is cached in the asset store, so repeated calls
     * do not re-encode the image.
     */
    public function getWebpVariantURL(): ?string
    {
        return WebpConversionService::singleton()->getVariantURL($this->getOwner());
    }


    /**
     * Human-readable saving, for the report's "Saving" column.
     */
    public function getWebpSavingSummary(): string
    {
        $saving = $this->getWebpSaving();

        if ($saving <= 0) {
            return '—';
        }

        return sprintf('%s (%s%%)', File::format_size($saving), $this->getWebpSavingPercent());
    }


    /**
     * URL of the file as it stands today, for the report's preview column.
     */
    public function getPngPreviewLink(): string
    {
        $owner = $this->getOwner();

        return $owner->exists() ? (string) $owner->getURL() : '';
    }


    /**
     * URL of the trial WebP, for the report's preview column.
     */
    public function getWebpPreviewLink(): string
    {
        return (string) $this->getWebpVariantURL();
    }


    /**
     * Surface the assessment on the file's edit form so editors can see why a
     * file was skipped without opening the report.
     */
    protected function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();

        if ($owner->WebpStatus === self::STATUS_UNCHECKED) {
            return;
        }

        $summary = $owner->WebpStatus;

        if ($owner->WebpOriginalSize && $owner->WebpConvertedSize) {
            $summary .= sprintf(
                ' — %s → %s (%s%% saving)',
                File::format_size((int) $owner->WebpOriginalSize),
                File::format_size((int) $owner->WebpConvertedSize),
                $owner->getWebpSavingPercent()
            );
        }

        if ($owner->WebpNote) {
            $summary .= ' — ' . $owner->WebpNote;
        }

        $fields->addFieldToTab(
            'Root.Main',
            ReadonlyField::create(
                'WebpStatusSummary',
                'WebP conversion',
                DBField::create_field('Varchar', $summary)
            )
        );
    }
}
