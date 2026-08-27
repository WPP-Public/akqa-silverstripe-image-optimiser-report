# Silverstripe Image Optimiser Report

CMS report and tooling that trials WebP conversions for PNG assets, surfaces
worthwhile candidates, and lets editors replace originals in place.

## Installation

```bash
composer require akqa/silverstripe-image-optimiser-report
```

Run `dev/build` after installing so the WebP assessment columns are added to
`File`.

## Usage

1. Queue assessment of unassessed PNG files:

   ```bash
   sake tasks:WebpConversionTask --queue
   ```

   Or assess a small batch inline:

   ```bash
   sake tasks:WebpConversionTask --assess --limit=10
   ```

2. Open **Reports → Image conversion report** in the CMS.
3. Use **Queue assessment** to process files in the background, or filter to
   **Not yet assessed** and assess files one at a time.
4. Preview PNG vs WebP, then use **Replace with WebP** on candidates you want
   to swap. The `File` record ID is preserved so existing relations follow.

## Configuration

Override defaults in your project `_config`:

```yaml
Akqa\SilverStripe\ImageOptimiser\Services\WebpConversionService:
  quality: 85
  minimum_saving_percent: 5.0
  batch_size: 25
  minimum_file_size: 10240
```

## Requirements

- PHP ^8.3
- Silverstripe Framework ^6
- `symbiote/silverstripe-queuedjobs` ^6
