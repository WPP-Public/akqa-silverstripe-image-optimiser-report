<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Services;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use SilverStripe\Assets\Conversion\FileConverterException;
use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use Throwable;

/**
 * Trials a WebP conversion for PNG files, records whether the saving is worth
 * taking, and replaces the original when asked to.
 *
 * The trial conversion is stored as a normal asset-store variant of the source
 * file (the same mechanism as a resample), so assessing a file is
 * non-destructive and the resulting WebP has a public URL that can be previewed
 * before anything is replaced.
 */
class WebpConversionService
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * Quality passed to the Intervention Image encoder.
     */
    private static int $quality = 85;

    /**
     * A WebP has to be at least this much smaller than the PNG to be worth
     * replacing. Anything below this is marked Skipped.
     */
    private static float $minimum_saving_percent = 5.0;

    /**
     * How many files a single job run assesses before re-queueing itself.
     */
    private static int $batch_size = 25;

    /**
     * Files this size or smaller are not worth the churn of a replacement.
     */
    private static int $minimum_file_size = 10240;

    /**
     * PNG files that have never been assessed.
     */
    public function getUnassessedFiles(): DataList
    {
        return $this->getPngFiles()
            ->filter('WebpStatus', WebpConversionExtension::STATUS_UNCHECKED);
    }


    /**
     * Files where WebP was measurably smaller and no replacement has happened yet.
     */
    public function getCandidates(): DataList
    {
        return $this->getPngFiles()
            ->filter('WebpStatus', WebpConversionExtension::STATUS_CANDIDATE)
            ->sort('WebpSavingBytes', 'DESC');
    }


    /**
     * All PNG files, regardless of assessment state.
     */
    public function getPngFiles(): DataList
    {
        return File::get()
            ->filter('Name:EndsWith:nocase', '.png');
    }


    /**
     * Run a trial conversion and record the outcome against the file.
     *
     * Returns the status the file ended up in. Never throws — a file that
     * cannot be converted is marked Failed with the reason, so the job can
     * keep moving and the problem is visible in the report.
     */
    public function assess(File $file): string
    {
        if (!$file->exists()) {
            return $this->record(
                $file,
                WebpConversionExtension::STATUS_FAILED,
                'Source file is missing from the asset store'
            );
        }

        $originalSize = (int) $file->getAbsoluteSize();

        if ($originalSize <= (int) static::config()->get('minimum_file_size')) {
            return $this->record(
                $file,
                WebpConversionExtension::STATUS_SKIPPED,
                'Already small enough to leave alone',
                $originalSize
            );
        }

        try {
            $variant = $this->convertToVariant($file);
        } catch (FileConverterException $e) {
            return $this->record($file, WebpConversionExtension::STATUS_FAILED, $e->getMessage(), $originalSize);
        } catch (Throwable $e) {
            return $this->record($file, WebpConversionExtension::STATUS_FAILED, $e->getMessage(), $originalSize);
        }

        if (!$variant || !$variant->exists()) {
            return $this->record(
                $file,
                WebpConversionExtension::STATUS_FAILED,
                'Conversion produced no output',
                $originalSize
            );
        }

        $convertedSize = (int) $variant->getAbsoluteSize();

        if ($convertedSize <= 0) {
            return $this->record(
                $file,
                WebpConversionExtension::STATUS_FAILED,
                'Converted file is empty',
                $originalSize
            );
        }

        $threshold = (float) static::config()->get('minimum_saving_percent');
        $savingPercent = (($originalSize - $convertedSize) / $originalSize) * 100;

        if ($savingPercent < $threshold) {
            return $this->record(
                $file,
                WebpConversionExtension::STATUS_SKIPPED,
                $convertedSize >= $originalSize
                    ? 'WebP is larger than the PNG'
                    : sprintf('Saving of %.1f%% is below the %.1f%% threshold', $savingPercent, $threshold),
                $originalSize,
                $convertedSize
            );
        }

        return $this->record(
            $file,
            WebpConversionExtension::STATUS_CANDIDATE,
            sprintf('WebP is %.1f%% smaller', $savingPercent),
            $originalSize,
            $convertedSize
        );
    }


    /**
     * Replace the file's contents with its WebP equivalent, keeping the same
     * File record so every relation pointing at it follows automatically.
     *
     * The filename extension changes from .png to .webp, so the public URL
     * changes too.
     */
    public function replace(File $file): bool
    {
        if (!$file->exists()) {
            $this->record(
                $file,
                WebpConversionExtension::STATUS_FAILED,
                'Source file is missing from the asset store'
            );

            return false;
        }

        try {
            $variant = $this->convertToVariant($file);

            if (!$variant || !$variant->exists()) {
                $this->record($file, WebpConversionExtension::STATUS_FAILED, 'Conversion produced no output');
                return false;
            }

            $data = $variant->getString();

            if ($data === null || $data === '') {
                $this->record($file, WebpConversionExtension::STATUS_FAILED, 'Converted file is empty');
                return false;
            }

            $originalSize = (int) $file->getAbsoluteSize();
            $wasPublished = $file->isPublished();
            $newFilename = $this->getWebpFilename($file);

            $file->setFromString($data, $newFilename);
            $file->WebpStatus = WebpConversionExtension::STATUS_CONVERTED;
            $file->WebpOriginalSize = $originalSize;
            $file->WebpConvertedSize = strlen($data);
            $file->WebpSavingBytes = max(0, $originalSize - strlen($data));
            $file->WebpConvertedAt = DBDatetime::now()->Rfc2822();
            $file->WebpNote = sprintf(
                'Replaced %s with WebP',
                pathinfo($newFilename, PATHINFO_BASENAME)
            );
            $file->write();

            if ($wasPublished) {
                $file->publishSingle();
            }

            $this->extend('onAfterReplace', $file);

            return true;
        } catch (Throwable $e) {
            $this->record($file, WebpConversionExtension::STATUS_FAILED, $e->getMessage());
            return false;
        }
    }


    /**
     * Public URL of the trial WebP variant, for previewing before replacing.
     */
    public function getVariantURL(File $file): ?string
    {
        if (!$file->exists() || strtolower((string) $file->getExtension()) !== 'png') {
            return null;
        }

        try {
            $variant = $this->convertToVariant($file);
        } catch (Throwable) {
            return null;
        }

        if (!$variant || !$variant->exists()) {
            return null;
        }

        return $variant->getURL();
    }


    /**
     * The .webp equivalent of the file's current name.
     */
    public function getWebpFilename(File $file): string
    {
        $filename = (string) $file->getFilename();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        if ($extension === '') {
            return $filename . '.webp';
        }

        return substr($filename, 0, -strlen($extension)) . 'webp';
    }


    /**
     * Ask the conversion API for a WebP variant of this file. Variants are
     * cached by the asset store, so calling this repeatedly for the same file
     * only encodes once.
     */
    protected function convertToVariant(File $file): ?DBFile
    {
        return Injector::inst()->get(FileConverterManager::class)->convert(
            $file,
            'webp',
            ['quality' => (int) static::config()->get('quality')]
        );
    }


    /**
     * Persist the outcome of an assessment without touching the file's content.
     */
    protected function record(
        File $file,
        string $status,
        string $note = '',
        int $originalSize = 0,
        int $convertedSize = 0
    ): string {
        $file->WebpStatus = $status;
        $file->WebpNote = substr($note, 0, 255);
        $file->WebpCheckedAt = DBDatetime::now()->Rfc2822();
        $file->WebpOriginalSize = $originalSize;
        $file->WebpConvertedSize = $convertedSize;
        $file->WebpSavingBytes = ($originalSize > 0 && $convertedSize > 0)
            ? max(0, $originalSize - $convertedSize)
            : 0;

        // Assessment is metadata only, so write to the current stage without
        // creating a publish-worthy change to the asset itself.
        $file->write();

        return $status;
    }
}
