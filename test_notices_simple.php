<!DOCTYPE html>
<html>
<head>
    <title>Test Notice Notifications</title>
</head>
<body>
    <h2>Test Notice Notifications</h2>
    <p>User ID: 30</p>
    <p>Check if notice notifications appear in the Notifications module:</p>
    <ol>
        <li>Go to <a href="index.php?module=notifications">Notifications Module</a></li>
        <li>Click on the "Notices" filter</li>
        <li>You should see notice notifications there</li>
    </ol>
    <hr>
    <h3>To test NEW popup alerts:</h3>
    <ol>
        <li>Go to <a href="index.php?module=notices">Notices Module</a></li>
        <li>Create a NEW notice (target to "All Employees")</li>
        <li>Wait up to 30 seconds</li>
        <li>A popup toast should appear in the bottom-right corner</li>
    </ol>
    <hr>
    <p><strong>Note:</strong> The previous notice notifications were created before your last poll check, so they won't show as NEW popup alerts. But they should appear in the Notifications module list.</p>
</body>
</html>
