<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Tests\Jobs;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Jobs\AssessWebpConversionsJob;
use Akqa\SilverStripe\ImageOptimiser\Jobs\ConvertWebpCandidatesJob;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class AssessWebpConversionsJobTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('AssessWebpConversionsJobTest');

        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);
        Config::modify()->set(WebpConversionService::class, 'batch_size', 3);
    }


    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }


    private function createNoisyPng(string $name, int $size = 200): Image
    {
        $image = imagecreatetruecolor($size, $size);
        mt_srand(99);

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                imagesetpixel(
                    $image,
                    $x,
                    $y,
                    imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))
                );
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


    /**
     * Drive the job the way the queue does: setup once, then process until it
     * reports itself complete.
     */
    private function runJob(AssessWebpConversionsJob $job): void
    {
        $job->setup();

        $guard = 0;

        while (!$job->jobFinished() && $guard < 50) {
            $job->process();
            $guard++;
        }
    }


    public function testJobAssessesTheWholeBatch(): void
    {
        $this->createNoisyPng('job-one.png');
        $this->createNoisyPng('job-two.png');
        $this->createNoisyPng('job-three.png');

        $job = new AssessWebpConversionsJob();
        $this->runJob($job);

        $this->assertTrue($job->jobFinished());
        $this->assertCount(
            0,
            WebpConversionService::singleton()->getUnassessedFiles()->column('ID'),
            'Every file in the batch should have been assessed'
        );
        $this->assertCount(3, WebpConversionService::singleton()->getCandidates()->column('ID'));
    }


    public function testJobStopsAtTheConfiguredBatchSize(): void
    {
        Config::modify()->set(WebpConversionService::class, 'batch_size', 2);

        $this->createNoisyPng('batch-one.png');
        $this->createNoisyPng('batch-two.png');
        $this->createNoisyPng('batch-three.png');
        $this->createNoisyPng('batch-four.png');

        $job = new AssessWebpConversionsJob();
        $this->runJob($job);

        $this->assertCount(
            2,
            WebpConversionService::singleton()->getUnassessedFiles()->column('ID'),
            'Only one batch should be assessed per run'
        );
    }


    public function testJobCompletesCleanlyWhenThereIsNothingToDo(): void
    {
        $job = new AssessWebpConversionsJob();
        $this->runJob($job);

        $this->assertTrue($job->jobFinished());
    }


    /**
     * A file whose bytes have gone missing must not stall the queue.
     */
    public function testJobRecordsFailuresWithoutStopping(): void
    {
        $broken = Image::create();
        $broken->Name = 'gone.png';
        $broken->write();

        $good = $this->createNoisyPng('still-here.png');

        $job = new AssessWebpConversionsJob();
        $this->runJob($job);

        $this->assertTrue($job->jobFinished());
        $this->assertSame(WebpConversionExtension::STATUS_FAILED, Image::get_by_id($broken->ID)->WebpStatus);
        $this->assertSame(WebpConversionExtension::STATUS_CANDIDATE, Image::get_by_id($good->ID)->WebpStatus);
    }


    public function testJobHasATitle(): void
    {
        $this->assertNotEmpty((new AssessWebpConversionsJob())->getTitle());
    }


    public function testBulkConversionJobConvertsCandidateFiles(): void
    {
        $first = $this->createNoisyPng('bulk-one.png');
        $second = $this->createNoisyPng('bulk-two.png');

        WebpConversionService::singleton()->assess($first);
        WebpConversionService::singleton()->assess($second);

        $job = new ConvertWebpCandidatesJob();
        $job->setup();
        $job->process();

        $this->assertTrue($job->jobFinished());
        $this->assertSame(
            2,
            WebpConversionService::singleton()->getPngFiles()->filter('WebpStatus', WebpConversionExtension::STATUS_CONVERTED)->count()
        );
    }
}
