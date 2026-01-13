<?php
/**
 * Login / Validation Page
 * Sets a cookie with hashed validation key
 */

date_default_timezone_set('Europe/Amsterdam');

$validation_hash = null;
$message = '';
$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validation_key'])) {
    $input = trim($_POST['validation_key']);
    
    if (!empty($input)) {
        // Hash the input using SHA-256
        $validation_hash = hash('sha256', $input);
        
        // Set cookie with 30 days expiration
        $expire = time() + (30 * 24 * 60 * 60); // 30 days
        setcookie('validation', $validation_hash, $expire, '/', '', false, true);
        
        $message = 'Validation key has been set successfully!';
    } else {
        $error = 'Please enter a validation key.';
    }
}

// Check if cookie was just set (show hash once)
if (isset($_COOKIE['validation']) && $validation_hash === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $validation_hash = $_COOKIE['validation'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validation Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg,rgb(223, 229, 255) 0%,rgba(235, 216, 255, 0.64) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(4, 0, 255, 0.31);
            padding: 40px;
            width: 100%;
            max-width: 450px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h1 {
            font-size: 2rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .login-header p {
            color: #666;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 0.9rem;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%,rgb(0, 13, 255) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            font-family: inherit;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .message {
            margin-top: 20px;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .validation-display {
            margin-top: 20px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .validation-display label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 8px;
        }

        .validation-hash {
            font-family: 'Monaco', 'Menlo', 'Courier New', monospace;
            font-size: 0.85rem;
            color: #333;
            word-break: break-all;
            background: white;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Validation Login</h1>
            <p>Enter your validation key</p>
        </div>

        <form method="POST" action="">
            <div class="form-group">
                <label for="validation_key">Validation Key</label>
                <input 
                    type="text" 
                    id="validation_key" 
                    name="validation_key" 
                    placeholder="Enter validation key"
                    autocomplete="off"
                    autofocus
                >
            </div>

            <button type="submit" class="btn">Set Validation</button>
        </form>

        <?php if ($message): ?>
            <div class="message success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($validation_hash): ?>
            <div class="validation-display">
                <label>Validation Hash (shown once):</label>
                <div class="validation-hash"><?php echo htmlspecialchars($validation_hash); ?></div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
