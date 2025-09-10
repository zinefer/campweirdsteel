<?php
/**
 * Clear Stale Authentication Cookies
 * Use this if you're getting authentication errors due to stale cookies
 */

// Clear any Flarum cookies
$cookiesToClear = [];
foreach ($_COOKIE as $name => $value) {
    if (strpos($name, 'flarum') !== false) {
        $cookiesToClear[] = $name;
        setcookie($name, '', time() - 3600, '/');
    }
}

// Clear session
session_start();
session_destroy();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Authentication - Weird Steel Gallery</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background-color: #1a1a1a;
            color: #fff;
        }
        .success {
            background-color: #2d5a3d;
            border: 1px solid #4a7c59;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .info {
            background-color: #2d4a5a;
            border: 1px solid #4a6c7c;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        a {
            color: #ff6b35;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <h1>Authentication Cleared</h1>
    
    <div class="success">
        <strong>Success!</strong> Stale authentication cookies have been cleared.
        <?php if (count($cookiesToClear) > 0): ?>
            <br><br>Cleared cookies: <?php echo implode(', ', $cookiesToClear); ?>
        <?php endif; ?>
    </div>
    
    <div class="info">
        <strong>Next Steps:</strong>
        <ol>
            <li><a href="../forum/">Go to the Flarum forum</a> and log in</li>
            <li>Once logged in, <a href="./">return to the gallery</a></li>
        </ol>
    </div>
    
    <p><a href="../">← Back to main site</a></p>
</body>
</html>
