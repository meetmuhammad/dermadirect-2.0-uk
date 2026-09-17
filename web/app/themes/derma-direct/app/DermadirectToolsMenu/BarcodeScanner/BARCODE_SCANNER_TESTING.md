# Barcode Scanner - Testing Guide

## ✅ Implementation Complete

The barcode scanner functionality has been successfully migrated from the standalone plugin into the theme.

## 📋 Testing Steps

### 1. **Initial Setup**

Navigate to: **WordPress Admin → Tools → Barcode Scanner**

This page will show you:
- Database table status
- AWS S3 configuration status
- Usage instructions

**Action:** If the database table doesn't exist, click "Create Database Table"

---

### 2. **Verify Configuration**

Check that all AWS S3 credentials show as "✓ Configured":
- AWS Key: ✓ Configured
- AWS Secret: ✓ Configured  
- AWS Region: eu-west-2
- S3 Bucket: dermadirect-images

---

### 3. **Create Test Page**

1. Go to **Pages → Add New**
2. Add title: "Barcode Scanner Test"
3. Add the shortcode: `[barcode-scanner]`
4. **Publish** the page

---

### 4. **Test Barcode Scanning**

#### Prerequisites:
- You need a WooCommerce order with a CODE_128 barcode
- Access to a device with camera (laptop webcam or phone)
- Browser must support camera access (Chrome, Safari, Firefox)

#### Steps:
1. Visit the test page you created
2. Click **"Scan Barcode"** button
3. Browser will ask for camera permission → **Allow**
4. Point camera at a CODE_128 barcode containing an order ID
5. When detected, the scanner will:
   - Capture the barcode image
   - Auto-fill the order ID
   - Stop the camera

---

### 5. **Test Photo Upload**

1. After scanning barcode, you'll see the FilePond upload area
2. Click or drag up to 2 photos
3. Photos will show preview
4. Click **"Upload"** button
5. Watch for:
   - "Uploading..." message
   - Success message: "Your photos have been uploaded"
   - Page will reload after 3 seconds

---

### 6. **Verify in WooCommerce Admin**

1. Go to **WooCommerce → Orders**
2. Find the order you scanned
3. Order status should be **"Completed"**
4. In the right sidebar, look for **"Order Photos"** meta box
5. Photos should be displayed with clickable thumbnails
6. Click photo to open full size in new tab (from S3)

---

### 7. **Test Without Barcode (Error Handling)**

1. Go back to test page
2. Try uploading photos WITHOUT scanning barcode
3. Should show error: "You must scan the barcode first"

---

### 8. **Test Without Photos (Error Handling)**

1. Scan a barcode
2. Click "Upload" WITHOUT adding photos
3. Should show error: "Photo is required"

---

### 9. **Test Invalid File Type**

1. Scan a barcode
2. Try uploading a PDF or other non-image file
3. Should show error: "You must upload image"

---

### 10. **Test Invalid Order ID**

1. Manually edit the page source OR use browser console:
   ```javascript
   document.getElementById('barcode-scanner-order-id').value = '99999999';
   ```
2. Upload photos
3. Should show error: "Order not found"

---

## 🔍 What to Check

### Frontend:
- [ ] Shortcode renders properly
- [ ] "Scan Barcode" button appears
- [ ] Camera activates when clicked
- [ ] Barcode detection works
- [ ] FilePond upload area appears
- [ ] Photo preview works
- [ ] Upload button functions
- [ ] Success/error messages display
- [ ] Page reloads after success

### Backend:
- [ ] Order status changes to "Completed"
- [ ] Order meta is updated with S3 keys
- [ ] Photos display in admin meta box
- [ ] Photos are clickable and open S3 URLs
- [ ] Database table has new records

### Database:
```sql
SELECT * FROM wp_barcode_scanner_images ORDER BY created DESC LIMIT 5;
```
Should show:
- `image_key`: Unique identifier
- `delete_date`: 120 days from now
- `created`: Current timestamp

### S3 Bucket:
- Check `dermadirect-images` bucket
- New image files should appear with `image_key` as filename
- Files should be publicly accessible

---

## 🐛 Common Issues & Solutions

### Camera Not Working
- **Issue:** Browser doesn't ask for camera permission
- **Solution:** Use HTTPS or localhost. Some browsers block camera on HTTP.

### AWS Errors
- **Issue:** "Access Denied" or S3 errors
- **Solution:** Check `.env` file has correct credentials

### Barcode Not Detected
- **Issue:** Scanner doesn't recognize barcode
- **Solution:** Ensure it's a CODE_128 barcode. Try better lighting or different angle.

### Scripts Not Loading
- **Issue:** Console errors about missing files
- **Solution:** Check vendor files exist in `resources/js/vendor/` and `resources/css/vendor/`

### Order Not Found
- **Issue:** Valid order ID but still shows "not found"
- **Solution:** Ensure WooCommerce is active and order exists in database

---

## 🎯 Advanced Testing

### Test Cron Job (Optional)
The cleanup cron job runs daily to delete 120-day-old images.

To test manually:
```php
// In WordPress admin → Tools → Site Health → Info → Scheduled Events
// Or via WP-CLI:
wp cron event run barcode_scanner_delete_images
```

### Test Multiple Uploads
1. Create multiple orders
2. Scan and upload photos for each
3. Verify all photos appear in respective order admins
4. Check database has separate records

---

## 📊 Success Criteria

✅ All frontend elements render correctly
✅ Camera scanning works smoothly  
✅ Photo upload completes without errors
✅ Photos appear in admin
✅ Order status updates automatically
✅ S3 upload successful
✅ Error handling works for all scenarios
✅ Database records created properly

---

## 🚀 Next Steps After Testing

Once testing is complete and everything works:
1. Document any issues found
2. Test on staging environment
3. Test with real production orders
4. Consider additional error logging
5. Ready to merge to master branch!
