<?php
/**
 * Apps Module
 * User application shortcuts
 * Core PHP HRMS Application
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';

$db = Database::getInstance();
$user = getCurrentUser();

// Fetch user's apps ordered by sort_order
$apps = $db->fetchAll(
    "SELECT * FROM user_apps WHERE user_id = ? AND is_active = 1 ORDER BY sort_order, id",
    [$user['id']]
);

// Get available HRMS modules for shortcuts
$availableModules = $db->fetchAll(
    "SELECT name, display_name FROM modules WHERE is_active = 1 ORDER BY display_name"
);
?>

<div class="panel-head">
    <div class="panel-title"><i class="bi bi-grid"></i> My Apps</div>
    <button class="btn primary" onclick="openAddAppModal()"><i class="bi bi-plus-lg"></i> Add App</button>
</div>

<?php if (empty($apps)): ?>
    <div class="empty-state">
        <i class="bi bi-grid"></i>
        <p>No apps added yet. Click "Add App" to create your first shortcut.</p>
    </div>
<?php else: ?>
    <div class="app-grid" id="appGrid">
        <?php foreach ($apps as $app): ?>
            <?php
            // Determine icon
            $icon = $app['icon'];
            if (empty($icon)) {
                switch ($app['app_type']) {
                    case 'website':
                        $icon = '🌐';
                        break;
                    case 'desktop_app':
                        $icon = '🖥';
                        break;
                    case 'file':
                        $icon = '📄';
                        break;
                    case 'folder':
                        $icon = '📁';
                        break;
                    case 'hrms_module':
                        $icon = '📋';
                        break;
                    default:
                        $icon = '📱';
                }
            }
            ?>
            <div class="app-card" data-id="<?php echo $app['id']; ?>" onclick="launchApp(<?php echo $app['id']; ?>, '<?php echo $app['app_type']; ?>', '<?php echo htmlspecialchars($app['target'], ENT_QUOTES); ?>')">
                <div class="app-menu-btn" onclick="event.stopPropagation(); toggleAppMenu(<?php echo $app['id']; ?>)">
                    <i class="bi bi-three-dots-vertical"></i>
                </div>
                <div class="app-icon"><?php echo $icon; ?></div>
                <div class="app-name"><?php echo htmlspecialchars($app['app_name']); ?></div>
                <div class="app-menu" id="appMenu<?php echo $app['id']; ?>" style="display:none;">
                    <div class="app-menu-item" onclick="event.stopPropagation(); openEditAppModal(<?php echo $app['id']; ?>)">
                        <i class="bi bi-pencil"></i> Edit
                    </div>
                    <div class="app-menu-item delete" onclick="event.stopPropagation(); deleteApp(<?php echo $app['id']; ?>)">
                        <i class="bi bi-trash"></i> Delete
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
// Close all app menus when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.app-menu-btn') && !e.target.closest('.app-menu')) {
        document.querySelectorAll('.app-menu').forEach(menu => {
            menu.style.display = 'none';
        });
    }
});

function toggleAppMenu(appId) {
    const menu = document.getElementById('appMenu' + appId);
    const isVisible = menu.style.display === 'block';

    // Close all menus first
    document.querySelectorAll('.app-menu').forEach(m => {
        m.style.display = 'none';
    });

    // Toggle this menu
    menu.style.display = isVisible ? 'none' : 'block';
}

function launchApp(appId, appType, target) {
    switch(appType) {
        case 'website':
            if (window.electronAPI && window.electronAPI.openExternal) {
                window.electronAPI.openExternal(target);
            } else {
                window.open(target, '_blank');
            }
            break;
        case 'desktop_app':
            if (window.electronAPI && window.electronAPI.launchApp) {
                window.electronAPI.launchApp(target);
            } else {
                ModalSystem.error('Desktop apps can only be launched in the Electron HRMS desktop application.');
            }
            break;
        case 'file':
        case 'folder':
            if (window.electronAPI && window.electronAPI.openPath) {
                window.electronAPI.openPath(target);
            } else {
                ModalSystem.error('Files and folders can only be opened in the Electron HRMS desktop application.');
            }
            break;
        case 'hrms_module':
            window.location.href = '?module=' + target;
            break;
        default:
            ModalSystem.error('Unknown app type: ' + appType);
    }
}

function openAddAppModal() {
    showAppFormModal();
}

function openEditAppModal(appId) {
    fetch('api/apps.php', {
        method: 'GET',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const app = data.apps.find(a => a.id === appId);
            if (app) {
                showAppFormModal(app);
            }
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error loading app details');
    });
}

function showAppFormModal(app = null) {
    const isEdit = app !== null;
    const title = isEdit ? 'Edit App' : 'Add App';

    // Build HRMS module options
    const moduleOptions = <?php echo json_encode($availableModules); ?>.map(m =>
        `<option value="${m.name}">${m.display_name}</option>`
    ).join('');

    const formHtml = `
        <div class="field">
            <label>App Name <span class="req">*</span></label>
            <input type="text" id="appName" value="${isEdit ? app.app_name : ''}" placeholder="e.g., Google">
        </div>
        <div class="field">
            <label>App Type <span class="req">*</span></label>
            <select id="appType" onchange="handleAppTypeChange()">
                <option value="website" ${isEdit && app.app_type === 'website' ? 'selected' : ''}>Website</option>
                <option value="desktop_app" ${isEdit && app.app_type === 'desktop_app' ? 'selected' : ''}>Desktop Application</option>
                <option value="file" ${isEdit && app.app_type === 'file' ? 'selected' : ''}>File</option>
                <option value="folder" ${isEdit && app.app_type === 'folder' ? 'selected' : ''}>Folder</option>
                <option value="hrms_module" ${isEdit && app.app_type === 'hrms_module' ? 'selected' : ''}>HRMS Module</option>
            </select>
        </div>
        <div class="field" id="targetField">
            <label id="targetLabel">Website URL <span class="req">*</span></label>
            <input type="text" id="appTarget" value="${isEdit ? app.target : ''}" placeholder="https://example.com">
        </div>
        <div class="field" id="browseField" style="display:none;">
            <button type="button" class="btn sm" onclick="browseForPath()">Browse</button>
        </div>
        <div class="field" id="moduleField" style="display:none;">
            <label>HRMS Module <span class="req">*</span></label>
            <select id="appModule">
                ${moduleOptions}
            </select>
        </div>
        <div class="field">
            <label>Icon (emoji or leave blank for default)</label>
            <input type="text" id="appIcon" value="${isEdit ? (app.icon || '') : ''}" placeholder="🌐" maxlength="2">
        </div>
    `;

    ModalSystem.html(formHtml, {
        title: title,
        width: '480px'
    }).then(() => {
        if (isEdit) {
            handleAppTypeChange();
            if (app.app_type === 'hrms_module') {
                document.getElementById('appModule').value = app.target;
            }
        }
    });

    // Add save button to modal
    setTimeout(() => {
        const modal = document.querySelector('.modal-container');
        const footer = document.createElement('div');
        footer.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;margin-top:20px;';
        footer.innerHTML = `
            <button class="btn modal-btn-secondary" onclick="ModalSystem.close()">Cancel</button>
            <button class="btn primary" onclick="saveApp(${isEdit ? app.id : 'null'})">${isEdit ? 'Update' : 'Add'}</button>
        `;
        modal.appendChild(footer);
    }, 100);
}

function handleAppTypeChange() {
    const appType = document.getElementById('appType').value;
    const targetField = document.getElementById('targetField');
    const browseField = document.getElementById('browseField');
    const moduleField = document.getElementById('moduleField');
    const targetLabel = document.getElementById('targetLabel');
    const appTarget = document.getElementById('appTarget');

    // Reset visibility
    targetField.style.display = 'none';
    browseField.style.display = 'none';
    moduleField.style.display = 'none';

    switch(appType) {
        case 'website':
            targetField.style.display = 'block';
            targetLabel.textContent = 'Website URL *';
            appTarget.placeholder = 'https://example.com';
            appTarget.type = 'url';
            break;
        case 'desktop_app':
            targetField.style.display = 'block';
            browseField.style.display = 'block';
            targetLabel.textContent = 'Application Path *';
            appTarget.placeholder = 'C:\\Program Files\\App\\app.exe';
            appTarget.type = 'text';
            break;
        case 'file':
            targetField.style.display = 'block';
            browseField.style.display = 'block';
            targetLabel.textContent = 'File Path *';
            appTarget.placeholder = 'C:\\Users\\...\\file.pdf';
            appTarget.type = 'text';
            break;
        case 'folder':
            targetField.style.display = 'block';
            browseField.style.display = 'block';
            targetLabel.textContent = 'Folder Path *';
            appTarget.placeholder = 'C:\\Users\\...\\Documents';
            appTarget.type = 'text';
            break;
        case 'hrms_module':
            moduleField.style.display = 'block';
            break;
    }
}

function browseForPath() {
    if (window.electronAPI && window.electronAPI.browseForPath) {
        const appType = document.getElementById('appType').value;
        window.electronAPI.browseForPath(appType).then(path => {
            if (path) {
                document.getElementById('appTarget').value = path;
            }
        });
    } else {
        ModalSystem.error('File browsing is only available in the Electron HRMS desktop application.');
    }
}

function saveApp(appId) {
    const appName = document.getElementById('appName').value.trim();
    const appType = document.getElementById('appType').value;
    const appIcon = document.getElementById('appIcon').value.trim();
    let target = '';

    if (!appName) {
        ModalSystem.error('Please enter an app name');
        return;
    }

    switch(appType) {
        case 'website':
            target = document.getElementById('appTarget').value.trim();
            if (!target) {
                ModalSystem.error('Please enter a website URL');
                return;
            }
            // Basic URL validation
            if (!target.startsWith('http://') && !target.startsWith('https://')) {
                target = 'https://' + target;
            }
            break;
        case 'desktop_app':
        case 'file':
        case 'folder':
            target = document.getElementById('appTarget').value.trim();
            if (!target) {
                ModalSystem.error('Please enter a file/folder path');
                return;
            }
            break;
        case 'hrms_module':
            target = document.getElementById('appModule').value;
            break;
    }

    const action = appId ? 'update' : 'create';
    const data = {
        action: action,
        app_name: appName,
        app_type: appType,
        target: target,
        icon: appIcon || null
    };

    if (appId) {
        data.id = appId;
    }

    fetch('api/apps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            ModalSystem.close();
            ModalSystem.success(data.message || 'App saved successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            ModalSystem.error(data.message || 'Error saving app');
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error saving app');
    });
}

function deleteApp(appId) {
    ModalSystem.confirm({
        message: 'Are you sure you want to delete this app shortcut?',
        destructive: true,
        confirmText: 'Delete'
    }).then(confirmed => {
        if (confirmed) {
            fetch('api/apps.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', id: appId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    ModalSystem.success('App deleted successfully');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    ModalSystem.error(data.message || 'Error deleting app');
                }
            })
            .catch(err => {
                console.error(err);
                ModalSystem.error('Error deleting app');
            });
        }
    });
}
</script>
