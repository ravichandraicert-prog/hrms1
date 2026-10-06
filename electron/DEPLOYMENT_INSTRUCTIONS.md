# HRMS Desktop Application - Deployment Instructions

## Overview
The HRMS application has been converted to standalone .exe files that can be deployed to your company server. Users can run these without installing XAMPP or Node.js locally.

## Generated Files
Two executable files have been created in the `dist` folder:

1. **HRMS Setup 1.0.1.exe** (177 MB) - Installer version
   - Creates desktop shortcuts
   - Adds to Start Menu
   - Can be uninstalled properly
   - Recommended for company-wide deployment

2. **HRMS-Portable.exe** (177 MB) - Portable version
   - No installation required
   - Can be run from any location
   - No shortcuts created
   - Good for testing or temporary use

## Server Configuration

### Before Deployment
You must configure the server URL in `server-config.json`:

1. Open `electron/server-config.json`
2. Replace the URL with your company server URL:
   ```json
   {
     "url": "http://your-company-server.com/hrms/",
     "description": "HRMS Server Configuration",
     "instructions": "Replace the URL above with your company server URL"
   }
   ```

3. Rebuild the application with the new configuration:
   ```bash
   cd electron
   npm run build
   ```

### Example Server URLs
- `http://192.168.1.100/HRMS/` (local network IP)
- `http://hrms.company.com/` (domain name)
- `https://hrms.company.com/` (HTTPS - recommended for production)

## Deployment Steps

### Option 1: Using Installer (Recommended)
1. Copy `HRMS Setup 1.0.1.exe` to your company server or network share
2. Distribute to users via:
   - Network shared folder
   - Email attachment
   - Company intranet download
3. Users run the installer and follow the prompts
4. Application will be available from:
   - Desktop shortcut
   - Start Menu

### Option 2: Using Portable Version
1. Copy `HRMS-Portable.exe` to your company server or network share
2. Users download and run directly - no installation needed
3. Can be placed on USB drives for portable use

## Requirements for Users

### Minimum Requirements
- Windows 10 or later (64-bit)
- Network connection to company server
- No additional software installation required

### Network Access
Users must have network access to the server where HRMS is hosted. Ensure:
- Firewall allows HTTP/HTTPS traffic to the server
- Server is accessible from user machines
- DNS resolution (if using domain names)

## Troubleshooting

### Application won't start
- Check network connectivity to server
- Verify server URL in `server-config.json` is correct
- Ensure HRMS web application is running on the server

### Blank screen or loading issues
- Verify the HRMS web application is accessible in browser
- Check server logs for errors
- Ensure database connection is working on the server

### Certificate errors (HTTPS)
- The application is configured to accept self-signed certificates
- If using corporate SSL, ensure proper certificate chain

## Security Considerations

1. **Server Security**: Ensure your HRMS server is properly secured
2. **Network Security**: Use HTTPS in production if possible
3. **Access Control**: Implement proper authentication in the HRMS application
4. **Updates**: When updating the application, rebuild and redistribute the .exe

## Updating the Application

When you need to update the HRMS application:

1. Make changes to the PHP application on your server
2. If Electron wrapper needs changes:
   - Modify files in `electron` folder
   - Update `server-config.json` if needed
   - Run `npm run build` in electron folder
   - Distribute new .exe files

## Support

For issues or questions:
- Check the HRMS web application is working in browser
- Verify network connectivity
- Review server logs
- Ensure database is accessible
