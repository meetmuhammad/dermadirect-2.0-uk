# Barcode Scanner Implementation Notes

## Overview
Migrating barcode scanner functionality from standalone plugin to Sage theme structure.

## Original Plugin Features
1. **Shortcode**: `[barcode-scanner]` - Renders scanner interface
2. **Barcode Scanning**: Uses Quagga.js for CODE_128 barcode detection via camera
3. **Photo Upload**: FilePond multi-file upload with image compression
4. **AWS S3 Storage**: Uploads images to S3 bucket (dermadirect-images)
5. **Order Integration**: Links photos to WooCommerce orders, auto-completes orders
6. **Admin Meta Box**: Displays uploaded photos in order admin
7. **Auto-Cleanup**: Scheduled daily deletion of images after 120 days

## Dependencies
- **Quagga.js** (0.12.1): Barcode scanner library
- **FilePond** (4.30.3): File upload UI
- **FilePond Image Preview Plugin** (4.6.10)
- **FilePond Image Resize Plugin** (2.0.10)
- **FilePond Image Transform Plugin** (3.8.7)
- **Compressor.js** (1.1.1): Image compression
- **AWS SDK PHP** (3.0.0): S3 integration
- **Intervention Image** (2.7): Image manipulation

## Database Table
- `wp_barcode_scanner_images`
  - image_key (unique S3 key)
  - delete_date (120 days from upload)
  - created (timestamp)

## Implementation Plan
1. Create app/BarcodeScanner/ directory for organization
2. Create Blade component for scanner UI
3. Extract JS into theme's resources/js/
4. Copy CSS libraries to resources/css/
5. Copy JS libraries to resources/js/vendor/
6. Register assets via Vite
7. Add AJAX handlers to app/ajax-callbacks/
8. Add WooCommerce admin hooks to app/filters.php or separate file
9. Add activation hook alternative (migration command or setup check)
10. Store AWS credentials in .env file (SECURITY!)

## Security Concerns
- ⚠️ AWS credentials are HARDCODED in plugin
- Must move to .env file: AWS_S3_KEY, AWS_S3_SECRET, AWS_S3_REGION, AWS_S3_BUCKET

## Testing Checklist
- [ ] Shortcode renders correctly
- [ ] Camera access works
- [ ] Barcode scanning detects CODE_128
- [ ] FilePond upload works with compression
- [ ] Images upload to S3
- [ ] Order meta updates with image keys
- [ ] Order status changes to completed
- [ ] Admin meta box displays images
- [ ] Cleanup cron job scheduled
- [ ] Database table created on activation
