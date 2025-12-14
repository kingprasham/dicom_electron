# DICOM Electron Viewer

A desktop DICOM medical image viewer built with Electron, featuring advanced viewport management and image manipulation tools.

## Features

- **Multi-viewport Layout**: Support for various grid layouts (1x1, 2x2, 3x3, 4x4, custom grids)
- **Ordered Image Selection**: Select images in a specific sequence and arrange them automatically
- **Image Manipulation**: Invert, flip, rotate, zoom, pan, and window/level adjustments
- **MPR Views**: Multi-planar reconstruction (Axial, Coronal, Sagittal)
- **Medical Reporting**: Integrated reporting system with measurements
- **Export Options**: Export images and reports

## Installation

1. Clone the repository:
```bash
git clone https://github.com/kingprasham/dicom_electron.git
cd dicom_electron
```

2. Install dependencies:
```bash
npm install
```

3. Start the application:
```bash
npm start
```

## Usage

### Ordered Image Selection
1. Click the **Select** button to enter selection mode
2. Click images in the desired order (numbered badges will appear)
3. Click **Arrange** to automatically layout the selected images
4. The selection will clear automatically after arrangement

### Viewport Management
- Use layout buttons to switch between different grid configurations
- Click on any viewport to make it active
- Use Ctrl+Click to select multiple viewports

### Image Tools
- **Window/Level**: Adjust image brightness and contrast
- **Zoom**: Mouse wheel or zoom tool
- **Pan**: Click and drag with pan tool active
- **Rotate**: 90° rotation clockwise/counterclockwise
- **Flip**: Horizontal/vertical flip

## Development

This repository is set up with Git tracking in the main working directory. You can:

- **See changes**: `git status` to see modified files
- **Commit changes**: `git add .` then `git commit -m "your message"`
- **Push to GitHub**: `git push origin master`
- **Revert changes**: `git checkout -- filename` to discard changes
- **View history**: `git log` to see commit history

## Technology Stack

- **Electron**: Desktop application framework
- **Cornerstone.js**: Medical image rendering
- **PHP**: Backend API server
- **MySQL**: Database for patient and study management

## Project Structure

```
electron/
├── www/                    # Web application files
│   ├── js/                # JavaScript modules
│   │   ├── managers/      # Manager classes (viewport, actions, etc.)
│   │   ├── components/    # UI components
│   │   └── utils/         # Utility functions
│   ├── css/               # Stylesheets
│   ├── api/               # PHP API endpoints
│   └── index.php          # Main application page
├── main.js                # Electron main process
└── package.json           # Node.js dependencies
```

## License

MIT License

## Author

King Prasham
