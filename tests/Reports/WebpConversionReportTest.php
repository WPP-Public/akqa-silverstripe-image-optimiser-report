<?php

declare(strict_types=1);

namespace Akqa\SilverStripe\ImageOptimiser\Tests\Reports;

use Akqa\SilverStripe\ImageOptimiser\Extensions\WebpConversionExtension;
use Akqa\SilverStripe\ImageOptimiser\Forms\GridField\GridFieldWebpConvertAction;
use Akqa\SilverStripe\ImageOptimiser\Reports\WebpConversionReport;
use Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Security\Member;

/**
 * The report is the part an editor actually touches, so this covers the pieces
 * that only fail at render time — column wiring, the per-row action, and the
 * status filter.
 */
class WebpConversionReportTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        TestAssetStore::activate('WebpConversionReportTest');
        Config::modify()->set(WebpConversionService::class, 'minimum_file_size', 0);
    }


    protected function tearDown(): void
    {
        TestAssetStore::reset();

        parent::tearDown();
    }


    private function createNoisyPng(string $name, int $size = 200): Image
    {
        $image = imagecreatetruecolor($size, $size);
        mt_srand(7);

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
     * Report grids always live inside a form in the CMS; a GridField_FormAction
     * cannot render its link without one.
     */
    private function buildGrid(): GridField
    {
        $grid = (new WebpConversionReport())->getReportField();

        Form::create(null, 'TestForm', FieldList::create($grid), FieldList::create());

        return $grid;
    }


    public function testReportFieldBuildsWithoutErrors(): void
    {
        $this->createNoisyPng('report-me.png');
        WebpConversionService::singleton()->assess(Image::get()->first());

        $field = (new WebpConversionReport())->getReportField();

        $this->assertInstanceOf(GridField::class, $field);
        $this->assertNotNull(
            $field->getConfig()->getComponentByType(GridFieldWebpConvertAction::class),
            'The convert action must be wired into the report grid'
        );
    }


    public function testReportExposesTheRequestedColumns(): void
    {
        $field = (new WebpConversionReport())->getReportField();

        /** @var GridFieldDataColumns $columns */
        $columns = $field->getConfig()->getComponentByType(GridFieldDataColumns::class);
        $displayFields = $columns->getDisplayFields($field);

        $this->assertArrayHasKey('WebpOriginalSize', $displayFields, 'Size before');
        $this->assertArrayHasKey('WebpConvertedSize', $displayFields, 'Size after');
        $this->assertArrayHasKey('PngPreviewLink', $displayFields, 'Preview link to the PNG');
        $this->assertArrayHasKey('WebpPreviewLink', $displayFields, 'Preview link to the WebP');
        $this->assertSame('Size before', $displayFields['WebpOriginalSize']);
        $this->assertSame('Size after', $displayFields['WebpConvertedSize']);
    }


    public function testReportListsCandidatesByDefaultAndCanFilterToSkipped(): void
    {
        $candidate = $this->createNoisyPng('is-candidate.png');
        WebpConversionService::singleton()->assess($candidate);

        Config::modify()->set(WebpConversionService::class, 'minimum_saving_percent', 99.9);
        $skipped = $this->createNoisyPng('is-skipped.png');
        WebpConversionService::singleton()->assess($skipped);

        $report = new WebpConversionReport();

        $defaultIDs = $report->sourceRecords([])->column('ID');
        $this->assertContains($candidate->ID, $defaultIDs);
        $this->assertNotContains($skipped->ID, $defaultIDs);

        $skippedIDs = $report->sourceRecords(['WebpStatus' => WebpConversionExtension::STATUS_SKIPPED])
            ->column('ID');
        $this->assertContains($skipped->ID, $skippedIDs);
        $this->assertNotContains($candidate->ID, $skippedIDs);
    }


    public function testConvertActionRendersOnlyForCandidates(): void
    {
        $this->logInWithPermission('ADMIN');

        $file = $this->createNoisyPng('actionable.png');
        WebpConversionService::singleton()->assess($file);

        $grid = $this->buildGrid();
        $action = new GridFieldWebpConvertAction();

        $this->assertNotNull(
            $action->getColumnContent($grid, $file, 'WebpConvertAction'),
            'A candidate should offer the convert button'
        );

        $file->WebpStatus = WebpConversionExtension::STATUS_SKIPPED;
        $file->write();

        $this->assertNull(
            $action->getColumnContent($grid, $file, 'WebpConvertAction'),
            'A skipped file should not offer the convert button'
        );
    }


    public function testConvertActionReplacesTheFile(): void
    {
        $this->logInWithPermission('ADMIN');

        $file = $this->createNoisyPng('click-to-convert.png');
        WebpConversionService::singleton()->assess($file);

        $grid = $this->buildGrid();

        (new GridFieldWebpConvertAction())->handleAction(
            $grid,
            'replacewithwebp',
            ['RecordID' => $file->ID],
            []
        );

        $reloaded = File::get_by_id($file->ID);

        $this->assertSame(WebpConversionExtension::STATUS_CONVERTED, $reloaded->WebpStatus);
        $this->assertSame('click-to-convert.webp', $reloaded->Name);
    }


    public function testReportIsViewableByAdmin(): void
    {
        $admin = $this->logInWithPermission('ADMIN');

        $this->assertTrue((new WebpConversionReport())->canView($admin));
    }


    public function testReportIsNotViewableByAnonymousVisitors(): void
    {
        $this->logOut();

        $this->assertFalse((new WebpConversionReport())->canView(Member::create()));
    }
}
