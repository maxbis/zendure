<?php
// When set to true, the login validator will expose detailed
// reasons for access being denied. Leave this false in production.
if (!defined('LOGIN_VALIDATION_DEBUG')) {
    define('LOGIN_VALIDATION_DEBUG', true);
}

/**
 * Validation function for checking user access
 * Validates the validation cookie against validkeys.txt
 * 
 * @return bool True if validation cookie exists and matches a key in validkeys.txt, false otherwise
 */
function validateUser() {
    // Clear previous debug reason
    $GLOBALS['validationDebugReason'] = null;

    // Check if validation cookie exists
    if (!isset($_COOKIE['validation'])) {
        if (LOGIN_VALIDATION_DEBUG) {
            $GLOBALS['validationDebugReason'] = 'Validation cookie is not set.';
        }
        return false;
    }
 
    $cookieValue = trim($_COOKIE['validation']);
    
    // If cookie value is empty, return false
    if (empty($cookieValue)) {
        if (LOGIN_VALIDATION_DEBUG) {
            $GLOBALS['validationDebugReason'] = 'Validation cookie is empty.';
        }
        return false;
    }
    
    // Get the path to validkeys.txt (same directory as this file)
    $validKeysFile = __DIR__ . '/validkeys.txt';
    
    // Check if file exists
    if (!file_exists($validKeysFile)) {
        if (LOGIN_VALIDATION_DEBUG) {
            $GLOBALS['validationDebugReason'] = 'The valid keys file (validkeys.txt) was not found.';
        }
        return false;
    }
    
    // Read the file
    $fileContent = file_get_contents($validKeysFile);
    if ($fileContent === false) {
        if (LOGIN_VALIDATION_DEBUG) {
            $GLOBALS['validationDebugReason'] = 'The valid keys file (validkeys.txt) could not be read.';
        }
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
            // Successful validation clears any previous error
            $GLOBALS['validationDebugReason'] = null;
            return true;
        }
    }
    
    // No match found
    if (LOGIN_VALIDATION_DEBUG) {
        $GLOBALS['validationDebugReason'] = 'Validation cookie did not match any allowed keys.';
    }
    return false;
}

if (!validateUser()) {
    // Capture debug reason (if enabled) before outputting HTML
    $debugReason = (LOGIN_VALIDATION_DEBUG && !empty($GLOBALS['validationDebugReason']))
        ? $GLOBALS['validationDebugReason']
        : null;

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
            <?php if (!empty($debugReason)) : ?>
                <p style="margin-top: 12px; font-size: 0.9rem; color: #999;">
                    Debug: <?php echo htmlspecialchars($debugReason, ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php endif; ?>
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