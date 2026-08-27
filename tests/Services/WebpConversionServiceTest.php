<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Tests\Services;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the WebP assessment and replacement flow against real image bytes,
 * so the size comparison and the 5% threshold are verified rather than mocked.
 */
class WebpConversionServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('WebpConversionServiceTest');
    }


    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }


    /**
     * Deterministic photographic-style noise: PNG cannot compress it, lossy
     * WebP can, so this is reliably a worthwhile conversion. A smooth gradient
     * would not be — PNG filters handle those better than lossy WebP does.
     */
    private function createNoisyPng(string $name = 'noisy.png', int $size = 400): Image
    {
        $image = imagecreatetruecolor($size, $size);

        // Seeded so the assertions stay stable between runs.
        mt_srand(1234);

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                $colour = imagecolorallocate(
                    $image,
                    mt_rand(0, 255),
                    mt_rand(0, 255),
                    mt_rand(0, 255)
                );
                imagesetpixel($image, $x, $y, $colour);
            }
        }

        ob_start();
        imagepng($image, null, 9);
        $data = (string) ob_get_clean();

        $file = Image::create();
        $file->setFromString($data, $name);
        $file->write();

        return $file;
    }


    public function testGetWebpFilenameSwapsTheExtension(): void
    {
        $service = WebpConversionService::singleton();
        $file = $this->createNoisyPng('swap-me.png');

        $this->assertStringEndsWith('swap-me.webp', $service->getWebpFilename($file));
    }


    public function testSmallFilesAreSkippedWithoutConverting(): void
    {
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 10_000_000);

        $file = $this->createNoisyPng('tiny.png');
        $status = WebpConversionService::singleton()->assess($file);

        $this->assertSame(WebpConversionExtension::STATUS_SKIPPED, $status);
        $this->assertSame(0, (int) $file->WebpConvertedSize, 'No conversion should have been attempted');
        $this->assertNotEmpty($file->WebpNote);
    }


    public function testNoisyPngIsMarkedAsACandidate(): void
    {
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);
        Config::modify()->set(WebpConversionService::class, 'minimum_saving_percent', 5.0);

        $file = $this->createNoisyPng('candidate.png');
        $status = WebpConversionService::singleton()->assess($file);

        $this->assertSame(WebpConversionExtension::STATUS_CANDIDATE, $status);
        $this->assertGreaterThan(0, (int) $file->WebpOriginalSize);
        $this->assertGreaterThan(0, (int) $file->WebpConvertedSize);
        $this->assertLessThan(
            (int) $file->WebpOriginalSize,
            (int) $file->WebpConvertedSize,
            'WebP should be smaller than the source PNG'
        );
        $this->assertSame(
            (int) $file->WebpOriginalSize - (int) $file->WebpConvertedSize,
            $file->getWebpSaving()
        );
        $this->assertGreaterThan(5.0, $file->getWebpSavingPercent());
        $this->assertNotEmpty($file->WebpCheckedAt);
    }


    /**
     * The whole point of the threshold: a saving that is real but not worth the
     * churn of replacing the file is recorded as Skipped, not offered up.
     */
    public function testSavingBelowThresholdIsSkipped(): void
    {
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);
        Config::modify()->set(WebpConversionService::class, 'minimum_saving_percent', 99.9);

        $file = $this->createNoisyPng('below-threshold.png');
        $status = WebpConversionService::singleton()->assess($file);

        $this->assertSame(WebpConversionExtension::STATUS_SKIPPED, $status);
        $this->assertGreaterThan(0, (int) $file->WebpConvertedSize, 'Conversion still ran, it just was not worth it');
        $this->assertStringContainsString('threshold', (string) $file->WebpNote);
    }


    public function testMissingSourceFileIsRecordedAsFailed(): void
    {
        $file = Image::create();
        $file->Name = 'not-really-here.png';
        $file->write();

        $status = WebpConversionService::singleton()->assess($file);

        $this->assertSame(WebpConversionExtension::STATUS_FAILED, $status);
        $this->assertNotEmpty($file->WebpNote);
    }


    public function testReplaceSwapsContentsAndKeepsTheSameRecord(): void
    {
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);

        $file = $this->createNoisyPng('replace-me.png');
        $originalID = $file->ID;
        $originalSize = (int) $file->getAbsoluteSize();

        WebpConversionService::singleton()->assess($file);
        $this->assertTrue(WebpConversionService::singleton()->replace($file));

        $this->assertSame($originalID, $file->ID, 'Replacing must not create a new File record');
        $this->assertSame('replace-me.webp', $file->Name);
        $this->assertStringEndsWith('.webp', (string) $file->getFilename());
        $this->assertSame(WebpConversionExtension::STATUS_CONVERTED, $file->WebpStatus);
        $this->assertNotEmpty($file->WebpConvertedAt);
        $this->assertLessThan($originalSize, (int) $file->getAbsoluteSize());

        // The bytes on disk really are a WebP now.
        $this->assertStringStartsWith('RIFF', (string) $file->getString());
        $this->assertStringContainsString('WEBP', substr((string) $file->getString(), 0, 16));
    }


    public function testReplacedFileIsNoLongerOfferedAsACandidate(): void
    {
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);

        $file = $this->createNoisyPng('once-only.png');
        WebpConversionService::singleton()->assess($file);

        $this->assertCount(1, WebpConversionService::singleton()->getCandidates()->column('ID'));

        WebpConversionService::singleton()->replace($file);

        $this->assertCount(
            0,
            WebpConversionService::singleton()->getCandidates()->column('ID'),
            'A converted file should drop out of the candidate list'
        );
    }


    public function testGetPngFilesIgnoresOtherFormats(): void
    {
        $png = $this->createNoisyPng('included.png');

        $other = File::create();
        $other->setFromString('not an image', 'excluded.txt');
        $other->write();

        $ids = WebpConversionService::singleton()->getPngFiles()->column('ID');

        $this->assertContains($png->ID, $ids);
        $this->assertNotContains($other->ID, $ids);
    }


    public function testUnassessedListShrinksAsFilesAreAssessed(): void
    {
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);

        $this->createNoisyPng('one.png');
        $two = $this->createNoisyPng('two.png');

        $service = WebpConversionService::singleton();
        $this->assertCount(2, $service->getUnassessedFiles()->column('ID'));

        $service->assess($two);

        $this->assertCount(1, $service->getUnassessedFiles()->column('ID'));
    }
}
