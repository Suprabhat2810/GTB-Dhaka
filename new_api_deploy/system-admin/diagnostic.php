<?php
// Simple diagnostic - no includes
header('Content-Type: text/html');
?>
<!DOCTYPE html>
<html>
<head>
    <title>System Admin Diagnostic</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .box { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .success { color: green; }
        .error { color: red; }
        h2 { margin-top: 0; }
    </style>
</head>
<body>
    <h1>System Admin Directory Diagnostic</h1>
    
    <div class="box">
        <h2>✅ PHP is Working!</h2>
        <p>If you see this, PHP files are being processed in this directory.</p>
    </div>
    
    <div class="box">
        <h2>Server Information</h2>
        <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
        <p><strong>Server Software:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></p>
        <p><strong>Document Root:</strong> <?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'; ?></p>
        <p><strong>Script Filename:</strong> <?php echo $_SERVER['SCRIPT_FILENAME'] ?? 'Unknown'; ?></p>
        <p><strong>Request URI:</strong> <?php echo $_SERVER['REQUEST_URI'] ?? 'Unknown'; ?></p>
    </div>
    
    <div class="box">
        <h2>File Paths</h2>
        <p><strong>Current Directory:</strong> <?php echo __DIR__; ?></p>
        <p><strong>Parent Directory:</strong> <?php echo dirname(__DIR__); ?></p>
        <p><strong>Config.php exists:</strong> 
            <?php echo file_exists(__DIR__ . '/../config.php') ? '<span class="success">YES</span>' : '<span class="error">NO</span>'; ?>
        </p>
        <p><strong>Middleware.php exists:</strong> 
            <?php echo file_exists(__DIR__ . '/middleware.php') ? '<span class="success">YES</span>' : '<span class="error">NO</span>'; ?>
        </p>
        <p><strong>Auth.php exists:</strong> 
            <?php echo file_exists(__DIR__ . '/auth.php') ? '<span class="success">YES</span>' : '<span class="error">NO</span>'; ?>
        </p>
    </div>
    
    <div class="box">
        <h2>Test Links</h2>
        <p><a href="test-cors.php">Test CORS Endpoint</a></p>
        <p><a href="auth">Test Auth Endpoint (will fail without POST data)</a></p>
    </div>
    
    <div class="box">
        <h2>Next Steps</h2>
        <ol>
            <li>If you see this page, PHP is working ✅</li>
            <li>Click "Test CORS Endpoint" above</li>
            <li>Check browser console for CORS errors</li>
            <li>Try the login from frontend: <code>http://localhost:5173/system-admin/login</code></li>
        </ol>
    </div>
</body>
</html>
