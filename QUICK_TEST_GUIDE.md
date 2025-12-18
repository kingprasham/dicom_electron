# Quick Testing Guide - Image Deletion Fix

## 🚀 Quick Start Testing (5 minutes)

### Test 1: Basic Single Deletion
1. Load a study from dashboard
2. Click "Insert All"
3. Click on any viewport (should get yellow border)
4. Click "Delete Selected" button
5. ✅ **PASS if**: Image disappears completely, next image loads

### Test 2: Multiple Deletion
1. Hold Ctrl and click 2 viewports
2. Click "Delete Selected"
3. ✅ **PASS if**: Both images disappear, next 2 images load

### Test 3: Delete All
1. Click "Delete Selected" with no selection
2. Click "OK" on confirmation
3. ✅ **PASS if**: All viewports are blank

---

## 🔍 What to Look For

### ✅ Success Indicators
- Image disappears completely (no blink/flash)
- Canvas is completely blank or next image loads
- No console errors in DevTools (F12)
- Deletion happens smoothly in < 500ms

### ❌ Failure Indicators
- Image blinks but comes back
- Image partially visible (ghosting)
- Console shows errors
- Browser freezes or lags

---

## 🐛 Debugging Console Commands

Open browser console (F12) and run these after deletion:

```javascript
// Check if image was removed from cache
cornerstone.imageCache.getCacheInfo()
// Should show decreased numberOfImagesCached

// Check series array
window.DICOM_VIEWER.STATE.currentSeriesImages.length
// Should decrease after each deletion

// Check viewport state
cornerstone.getEnabledElement(document.getElementById('viewport-1'))
// Should throw error or show new image after deletion
```

---

## 📊 Expected Console Output

### Normal Deletion Flow
```
=== DELETE BUTTON CLICKED (NEW LOGIC v2) ===
Selected viewports: 1, Total viewports: 4
CASE 2: Deleting 1 selected viewport(s)
Clearing viewport: viewport-2
Found imageId to purge: wadouri:...
Disabled viewport: viewport-2
Removed image from cache: wadouri:...
✓ Viewport completely cleared: viewport-2
=== RELOADING VIEWPORTS ===
Step 1: Clearing all viewports...
Step 2: Loading new images...
✓ Loaded image into viewport 1
✓ Loaded image into viewport 2
✓ Loaded image into viewport 3
✓ Loaded image into viewport 4
Step 3: Fitting images to viewports...
=== RELOAD COMPLETE ===
Delete completed successfully
```

### If You See Errors
Look for these specific error messages:

**Error**: `Cannot read property 'imageId' of undefined`
- **Meaning**: Viewport was already empty
- **Action**: This is OK, can be ignored

**Error**: `Failed to remove from cache`
- **Meaning**: Image wasn't in cache (already removed)
- **Action**: This is OK, shows defensive programming

**Error**: `Cannot enable viewport`
- **Meaning**: Viewport creation failed
- **Action**: This is a problem, report it

---

## 🎯 Priority Test Scenarios

Test these in order:

| # | Test | Time | Priority |
|---|------|------|----------|
| 1 | Single viewport deletion | 30s | 🔴 Critical |
| 2 | Multiple viewport deletion | 30s | 🔴 Critical |
| 3 | Delete all confirmation | 30s | 🟡 High |
| 4 | Deletion with pagination | 1min | 🟡 High |
| 5 | Deletion with zoom/pan | 1min | 🟢 Medium |
| 6 | Rapid consecutive deletions | 30s | 🟢 Medium |

Total testing time: ~5-10 minutes

---

## 🎬 Video Recording Tips

If recording a demo:

1. Open DevTools console (show logs)
2. Load a study
3. Insert all images
4. Select a viewport (show yellow border)
5. Click Delete
6. Zoom into result (show it's truly gone)
7. Check console (show success logs)

---

## 📸 Screenshot Checklist

Capture these for documentation:

- [ ] Before deletion (show selected viewport with yellow border)
- [ ] During deletion (optional - happens fast)
- [ ] After deletion (show cleared viewport or next image)
- [ ] Console logs showing success
- [ ] Cache info showing decreased count

---

## ⚡ Quick Fixes for Common Issues

**Problem**: "Viewport blinks but image stays"
```javascript
// Check if cache API is available
console.log(cornerstone.imageCache);
// Should not be undefined
```

**Problem**: "Deletion is very slow (> 2 seconds)"
```javascript
// Check network tab for image re-downloads
// Images should load from cache, not network
```

**Problem**: "Some viewports don't clear"
```javascript
// Check if viewports are enabled
document.querySelectorAll('.viewport').forEach(vp => {
  try {
    console.log(vp.id, 'enabled:', !!cornerstone.getEnabledElement(vp));
  } catch(e) {
    console.log(vp.id, 'not enabled');
  }
});
```

---

## 📞 Report Format

If you find a bug, report with:

```
**Bug**: Images not deleting

**Steps**:
1. Loaded study with 10 images
2. Selected viewport #2
3. Clicked Delete

**Expected**: Image #2 removed, image #6 loads
**Actual**: Image #2 blinks but stays

**Console Output**:
[paste full console output]

**Screenshot**:
[attach screenshot]

**Browser**: Chrome 120.0.6099.129
```

---

## 🎉 Success Criteria

✅ Test is **SUCCESSFUL** if:
1. Images delete completely (no residual data)
2. Next images load correctly
3. No console errors
4. Deletion completes in < 500ms per viewport

🚀 **Ready to ship** if:
- All 6 priority tests pass
- No errors in console
- Works on Chrome, Firefox, Edge
- User confirms it works as expected

---

## 🔗 Related Documentation

- [DELETION_TEST_PLAN.md](DELETION_TEST_PLAN.md) - Full testing scenarios
- [DELETION_FIX_TECHNICAL_SUMMARY.md](DELETION_FIX_TECHNICAL_SUMMARY.md) - Technical details

---

**Quick Reference Version**: 1.0
**Last Updated**: 2025-12-18
