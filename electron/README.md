# HRMS Desktop Application

This Electron wrapper converts the HRMS PHP application into a desktop application.

## Prerequisites

1. **XAMPP** must be installed (default location: `C:\xampp`)
2. **Node.js** must be installed (version 14 or higher)
3. Apache and MySQL services should be running

## Installation

1. Navigate to the electron directory:
   ```bash
   cd electron
   ```

2. Install dependencies:
   ```bash
   npm install
   ```

## Running the Application

### Option 1: Using the batch script (Recommended)
Double-click `start.bat` to launch the application. This will:
- Start Apache and MySQL services
- Install dependencies if needed
- Launch the Electron application

### Option 2: Manual start
1. Ensure XAMPP services are running:
   - Open XAMPP Control Panel
   - Start Apache and MySQL

2. Run the application:
   ```bash
   npm start
   ```

## Building the Executable

To create a distributable `.exe` file:

```bash
npm run build-win
```

The executable will be created in the `dist` directory.

## Configuration

### Changing the URL
If your HRMS is running on a different port or path, edit `main.js`:
```javascript
mainWindow.loadURL('http://localhost:8080/HRMS/');
```

### Window Settings
Modify window size and behavior in `main.js`:
```javascript
width: 1400,
height: 900,
minWidth: 1024,
minHeight: 768,
```

## Troubleshooting

### Application won't start
- Ensure XAMPP is installed and services are running
- Check that Apache is running on port 80
- Verify the HRMS application is accessible in a browser at `http://localhost/HRMS/`

### Blank screen
- Open DevTools by uncommenting this line in `main.js`:
  ```javascript
  mainWindow.webContents.openDevTools();
  ```
- Check the console for errors

### Build fails
- Ensure all dependencies are installed: `npm install`
- Check that you have sufficient disk space
- Verify Node.js version is compatible

## Notes

- This wrapper does not modify any PHP code or database structure
- All existing HRMS functionalities remain unchanged
- The application requires an active internet connection for CDN resources (Bootstrap, etc.)
- For production use, consider bundling CDN resources locally
