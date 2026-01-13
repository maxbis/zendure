<?php
/**
 * Validation function for checking user access
 * Validates the validation cookie against validkeys.txt
 * 
 * @return bool True if validation cookie exists and matches a key in validkeys.txt, false otherwise
 */
function validateUser() {
    // Check if validation cookie exists
    if (!isset($_COOKIE['validation'])) {
        return false;
    }
    
    $cookieValue = trim($_COOKIE['validation']);
    
    // If cookie value is empty, return false
    if (empty($cookieValue)) {
        return false;
    }
    
    // Get the path to validkeys.txt (same directory as this file)
    $validKeysFile = __DIR__ . '/validkeys.txt';
    
    // Check if file exists
    if (!file_exists($validKeysFile)) {
        return false;
    }
    
    // Read the file
    $fileContent = file_get_contents($validKeysFile);
    if ($fileContent === false) {
        return false;
    }
    
    // Split into lines and check each line
    $lines = explode("\n", $fileContent);
    
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip empty lines
        if (empty($line)) {
            continue;
        }
        
        // Compare cookie value with line (case-sensitive)
        if ($cookieValue === $line) {
            return true;
        }
    }
    
    // No match found
    return false;
}


if (!validateUser()) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                background: linear-gradient(135deg, rgb(223, 229, 255) 0%, rgba(235, 216, 255, 0.64) 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-container {
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(4, 0, 255, 0.31);
                padding: 40px;
                text-align: center;
                max-width: 500px;
            }
            h1 {
                color: #d32f2f;
                margin-bottom: 16px;
                font-size: 2rem;
            }
            p {
                color: #666;
                margin-bottom: 24px;
                font-size: 1rem;
            }
            a {
                display: inline-block;
                padding: 12px 24px;
                background: linear-gradient(135deg, #667eea 0%, rgb(0, 13, 255) 100%);
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
            }
            a:hover {
                opacity: 0.9;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>Access Denied</h1>
            <p>Network and/or workstation not authorized to access this page.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
} else {
    // Validation successful - reset cookie with 3 months expiration
    if (isset($_COOKIE['validation'])) {
        $cookieValue = $_COOKIE['validation'];
        $expire = time() + (3 * 30 * 24 * 60 * 60); // 3 months from now
        setcookie('validation', $cookieValue, $expire, '/', '', false, true);
    }
}