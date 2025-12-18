# Image Deletion Feature - Testing Plan

## Overview
This document provides a comprehensive testing plan for the rewritten viewport image deletion feature.

## What Was Fixed

### Previous Issues
- Images were not deleting properly from viewports
- After clicking "Delete", images would blink/refresh but remain visible
- `cornerstone.disable() + enable()` approach was unreliable
- Image data remained in Cornerstone's internal cache

### New Implementation
1. **Proper Cache Management**: Images are removed from Cornerstone's image cache using `removeImageLoadObject()`
2. **Complete Canvas Clearing**: Canvas elements are fully cleared and dimensions reset
3. **DOM Cleanup**: All Cornerstone-created elements are removed before re-enabling
4. **Reliable State Transitions**: Proper async/await with delays for stability
5. **Better Error Handling**: Comprehensive try-catch blocks and fallback mechanisms

## Test Scenarios

### Test 1: Single Viewport Deletion (Basic)
**Objective**: Verify that deleting a single image removes it completely

**Steps**:
1. Load a study with 4+ images
2. Use "Insert All" to fill viewports in 2x2 layout
3. Click on viewport #2 (top-right) to select it
   - Verify it has a yellow border (selection indicator)
4. Click "Delete Selected" button
5. **Expected Result**:
   - Viewport #2 image should be completely cleared
   - Image should NOT reappear or blink back
   - Remaining images should shift to fill the gap
   - Total image count should decrease by 1

**Pass Criteria**:
- ✅ Image is completely removed (blank viewport or next image loads)
- ✅ No residual image data visible
- ✅ No console errors

---

### Test 2: Multiple Viewport Deletion
**Objective**: Verify batch deletion of multiple selected viewports

**Steps**:
1. Load a study with 8+ images
2. Use "Insert All" to fill 2x2 layout (4 images visible)
3. Hold Ctrl and click viewport #1 and viewport #3
   - Both should have yellow borders
4. Click "Delete Selected" button
5. **Expected Result**:
   - Both selected images should be deleted
   - Next images from the series should load into those viewports
   - Total image count should decrease by 2

**Pass Criteria**:
- ✅ Both images are removed
- ✅ Remaining images load correctly
- ✅ No visual artifacts or residual images

---

### Test 3: Delete with Pagination
**Objective**: Verify deletion works correctly across pages

**Setup**: Study with 12+ images, 2x2 layout (3 pages)

**Test 3a - Delete from Page 1**:
1. Insert all images (page 1 shows images 1-4)
2. Select viewport #3 and delete
3. **Expected**: Image #3 is removed, image #5 (from page 2) should shift into viewport #3
4. Navigate to page 2 using Page Navigator
5. **Expected**: Images 6-8 should be visible (since one was removed from before)

**Test 3b - Delete from Page 2**:
1. Navigate to page 2 (images 5-8)
2. Select viewport #2 (image #6)
3. Delete it
4. **Expected**: Image #6 removed, image #9 shifts in
5. Navigate back to page 1
6. **Expected**: Images 1-4 still intact

**Pass Criteria**:
- ✅ Correct images are deleted based on page position
- ✅ Page navigator updates correctly
- ✅ Navigation between pages shows correct images

---

### Test 4: Delete All (No Selection)
**Objective**: Verify "clear all" behavior when no viewports are selected

**Steps**:
1. Load images into viewports
2. Ensure NO viewports are selected (no yellow borders)
3. Click "Delete Selected" button
4. **Expected**: Confirmation dialog appears: "No viewports selected. Do you want to clear ALL viewports?"
5. Click "OK"
6. **Expected Result**:
   - All viewports should be completely cleared
   - All canvases should be blank
   - No residual images

**Pass Criteria**:
- ✅ Confirmation dialog appears
- ✅ All viewports are cleared after confirmation
- ✅ Clicking "Cancel" aborts the operation

---

### Test 5: Select All + Delete
**Objective**: Test the Select All feature with deletion

**Steps**:
1. Load 4 images into 2x2 layout
2. Click "Select All" button (or press Ctrl+A)
3. All 4 viewports should have yellow borders
4. Click "Delete Selected"
5. **Expected Result**:
   - All 4 images should be deleted from the series
   - All viewports should be blank
   - Message: "Deleted 4 images, 0 remaining"

**Pass Criteria**:
- ✅ Select All selects all viewports
- ✅ All images are deleted
- ✅ Series array is empty (`STATE.currentSeriesImages.length === 0`)

---

### Test 6: Delete with Zoom/Pan Applied
**Objective**: Ensure deletion works when viewports have transformations

**Steps**:
1. Load images into viewports
2. Select Pan tool
3. Pan image #2 to a different position
4. Select Zoom tool
5. Zoom in on image #3 (2x zoom)
6. Select viewport #2 and delete
7. **Expected Result**:
   - Image #2 is deleted completely (pan state doesn't persist)
   - Next image loads in default zoom/pan state
   - No transformation artifacts

**Pass Criteria**:
- ✅ Deletion works regardless of zoom/pan
- ✅ New images load with default viewport settings
- ✅ No visual glitches

---

### Test 7: Delete with Measurements
**Objective**: Verify deletion removes both image and any drawn measurements

**Steps**:
1. Load images
2. Select "Length" tool
3. Draw a measurement on image #2
4. Select "Angle" tool
5. Draw an angle on image #3
6. Delete viewport #2
7. **Expected Result**:
   - Image #2 is deleted along with the length measurement
   - No orphaned measurement tools remain
8. Delete viewport #3
9. **Expected Result**:
   - Image #3 and angle measurement are both removed

**Pass Criteria**:
- ✅ Measurements are removed with the image
- ✅ No orphaned tool overlays
- ✅ No console errors about missing elements

---

### Test 8: Rapid Consecutive Deletions
**Objective**: Test stability under rapid deletion operations

**Steps**:
1. Load 8+ images
2. Rapidly delete viewports in sequence:
   - Click viewport #1, click Delete
   - Immediately click viewport #2, click Delete
   - Immediately click viewport #3, click Delete
3. **Expected Result**:
   - All 3 deletions should complete successfully
   - No race conditions or errors
   - Final state should have 3 fewer images

**Pass Criteria**:
- ✅ System handles rapid deletions without errors
- ✅ Final image count is correct
- ✅ No locked or unresponsive viewports

---

### Test 9: Delete + Drag-Drop
**Objective**: Verify deletion works after manual image arrangement

**Steps**:
1. Load 4 images into 2x2 layout
2. Drag image from viewport #1 to viewport #3 (swap positions)
3. Select viewport #1 (now has the swapped image)
4. Delete it
5. **Expected Result**:
   - The swapped image is deleted
   - Remaining images load correctly

**Pass Criteria**:
- ✅ Deletion works after drag-drop
- ✅ Correct image is deleted (the one visually in the viewport)

---

### Test 10: Delete + Layout Switch
**Objective**: Test deletion followed by layout changes

**Steps**:
1. Load 6 images into 2x2 layout
2. Delete image #2
3. Switch to 2x1 layout
4. **Expected Result**:
   - Only 2 images visible (first 2 from remaining 5)
   - No errors during layout switch
5. Switch back to 2x2
6. **Expected Result**:
   - First 4 images from remaining 5 are visible

**Pass Criteria**:
- ✅ Layout switches work after deletion
- ✅ Correct images are displayed
- ✅ Page navigator adjusts correctly

---

## Debugging Tips

### Browser Console Checks
Open browser DevTools (F12) and check the Console tab for:

1. **Successful Deletion Messages**:
   ```
   === DELETE BUTTON CLICKED (NEW LOGIC v2) ===
   Clearing viewport: viewport-1
   Found imageId to purge: wadouri:...
   Disabled viewport: viewport-1
   Removed image from cache: wadouri:...
   ✓ Viewport completely cleared: viewport-1
   === RELOADING VIEWPORTS ===
   ✓ Loaded image into viewport 1
   === RELOAD COMPLETE ===
   ```

2. **Image Cache Confirmation**:
   After deletion, you can check the cache status:
   ```javascript
   // In browser console
   cornerstone.imageCache.getCacheInfo()
   // Should show decreased number of cached images
   ```

3. **State Verification**:
   ```javascript
   // In browser console
   window.DICOM_VIEWER.STATE.currentSeriesImages.length
   // Should decrease after each deletion
   ```

### Common Issues and Solutions

**Issue**: Image blinks but comes back
- **Cause**: Cache not being cleared
- **Check**: Look for "Removed image from cache" log message
- **Solution**: Verify `cornerstone.imageCache` is available

**Issue**: Viewport shows black/gray after deletion
- **Cause**: Canvas not being cleared or viewport not re-enabled
- **Check**: Look for "Re-enabled viewport with clean state" message
- **Solution**: Check if delays are sufficient (may need to increase)

**Issue**: Wrong image is deleted
- **Cause**: Viewport index calculation error
- **Check**: Console logs for "Selected viewport indices" and "Series indices to remove"
- **Solution**: Verify page number and viewport count are correct

**Issue**: Deletion doesn't work after using tools
- **Cause**: Tool events might be preventing viewport state access
- **Check**: Try disabling active tool before deletion
- **Solution**: Ensure tools are properly deactivated

---

## Performance Benchmarks

Expected performance (approximate):

| Operation | Time | Notes |
|-----------|------|-------|
| Single deletion | < 500ms | Including viewport reload |
| Multiple deletions (4 viewports) | < 1 second | Sequential clearing + reload |
| Delete all (2x2 layout) | < 1 second | Full series clear |
| Delete with 20+ images | < 800ms | Cache purge may add minimal overhead |

---

## Success Criteria Summary

The deletion feature is considered **FULLY WORKING** if:

1. ✅ Single image deletion removes image completely (no residual data)
2. ✅ Multiple image deletion works correctly
3. ✅ Pagination correctly adjusts after deletions
4. ✅ Deletion works with all tools (zoom, pan, W/L, measurements)
5. ✅ Delete All (no selection) clears all viewports
6. ✅ Select All + Delete removes all images
7. ✅ No console errors during any deletion operation
8. ✅ Image cache is properly purged (verified in DevTools)
9. ✅ Viewport states are completely reset after deletion
10. ✅ Rapid consecutive deletions work without issues

---

## Browser Compatibility

Test on these browsers:

- ✅ Chrome/Edge (Chromium-based)
- ✅ Firefox
- ✅ Safari (if available)
- ✅ Electron Desktop App

---

## Automated Testing (Future)

Recommended automated tests to add:

```javascript
// Example test structure (using Jest or similar)
describe('Viewport Image Deletion', () => {
  it('should remove image from cache after deletion', async () => {
    const initialCacheSize = cornerstone.imageCache.getCacheInfo().cacheSizeInBytes;
    await deleteViewportImage(0);
    const newCacheSize = cornerstone.imageCache.getCacheInfo().cacheSizeInBytes;
    expect(newCacheSize).toBeLessThan(initialCacheSize);
  });

  it('should decrease series length after deletion', async () => {
    const initialLength = STATE.currentSeriesImages.length;
    await deleteViewportImage(0);
    expect(STATE.currentSeriesImages.length).toBe(initialLength - 1);
  });

  it('should clear canvas completely', async () => {
    await deleteViewportImage(0);
    const canvas = document.querySelector('#viewport-1 canvas');
    const ctx = canvas.getContext('2d');
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const isEmpty = imageData.data.every(pixel => pixel === 0);
    expect(isEmpty).toBe(true);
  });
});
```

---

## Reporting Issues

If any test fails, please report with:

1. **Test scenario** that failed (from above)
2. **Browser console logs** (full output)
3. **Screenshots** showing the issue
4. **Steps to reproduce**
5. **Expected vs Actual result**

---

**Version**: 1.0
**Last Updated**: 2025-12-18
**Author**: Claude Sonnet 4.5 via Claude Code
