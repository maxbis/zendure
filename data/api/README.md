# Data API Documentation

This document describes all functions available in the Data API system.

## Overview

The Data API consists of two main files:
- `data_api.php` - Main API endpoint handler (REST API)
- `data_functions.php` - Helper functions used by the API

## Functions in `data_functions.php`

### `getDataFilePath($type, $params)`

Gets the file path for a given data type and parameters.

**Parameters:**
- `$type` (string) - Type of data: `price`, `zendure`, `zendure_p1`, `schedule`, `automation_status`, or `file`
- `$params` (array) - Parameters array:
  - For `price`: requires `date` (YYYYMMDD format)
  - For `file`: requires `name` (must end with `.json`)
  - Other types: empty array

**Returns:**
- `string|null` - Full file path or `null` on error

**Description:**
Constructs the appropriate file path based on the data type. For price files, validates the date format (8 digits). For file type, validates the filename and ensures it ends with `.json`. Sanitizes filenames to prevent directory traversal attacks.

---

### `readDataFile($filePath)`

Reads a JSON data file safely.

**Parameters:**
- `$filePath` (string) - Full path to the JSON file

**Returns:**
- `array|null` - Decoded JSON data as array, or `null` if file doesn't exist or JSON is invalid

**Description:**
Safely reads and decodes a JSON file. Returns `null` if the file doesn't exist, cannot be read, or contains invalid JSON. Logs errors to the error log.

---

### `writeDataFileAtomic($filePath, $data)`

Writes JSON data to a file atomically (prevents corruption during writes).

**Parameters:**
- `$filePath` (string) - Full path to the file
- `$data` (mixed) - Data to write (will be JSON encoded with pretty printing)

**Returns:**
- `bool` - `true` on success, `false` on error

**Description:**
Atomically writes data to a JSON file by:
1. Creating the directory if it doesn't exist
2. Writing to a temporary file first
3. Renaming the temp file to the target file (atomic operation)
4. Cleaning up temp file if rename fails

This prevents file corruption if the process is interrupted during writing. Uses `JSON_PRETTY_PRINT` and `JSON_UNESCAPED_UNICODE` flags for readable output.

---

### `validatePriceData($data)`

Validates price data structure.

**Parameters:**
- `$data` (mixed) - Data to validate

**Returns:**
- `array` - Validation result with keys:
  - `valid` (bool) - Whether data is valid
  - `error` (string|null) - Error message if invalid, `null` if valid

**Description:**
Validates that price data is:
- An array
- Contains only hour keys from `00` to `23` (24-hour format)
- Each hour value is numeric

Used to ensure price files have the correct structure before saving.

---

### `listDataFiles($pattern = null)`

Lists all JSON files in the data directory, optionally filtered by pattern.

**Parameters:**
- `$pattern` (string|null) - Optional glob-like pattern to filter files (e.g., `"price*.json"`). Supports `*` wildcard.

**Returns:**
- `array` - Array of filenames (sorted alphabetically)

**Description:**
Scans the data directory and returns all `.json` files. If a pattern is provided, only files matching the pattern are returned. Patterns support `*` wildcard (e.g., `price*.json` matches all price files). Returns empty array if directory doesn't exist or cannot be scanned.

---

### `sanitizeFileName($name)`

Sanitizes a filename to prevent directory traversal attacks.

**Parameters:**
- `$name` (string) - Filename to sanitize

**Returns:**
- `string|null` - Sanitized filename or `null` if invalid

**Description:**
Security function that:
- Removes path components using `basename()`
- Removes null bytes
- Prevents directory traversal (`..`, `/`, `\`)
- Only allows alphanumeric characters, dots, dashes, and underscores

Returns `null` if the filename contains invalid characters or path traversal attempts.

---

### `cleanupOldPriceFiles($retentionDays = 4, $dataDir = null, $archiveDir = null)`

Cleans up old price files by moving them to an archive directory.

**Parameters:**
- `$retentionDays` (int) - Number of days to keep files before archiving (default: 4)
- `$dataDir` (string|null) - Data directory path (default: parent of current directory)
- `$archiveDir` (string|null) - Archive directory path (default: `$dataDir/price_archive`)

**Returns:**
- `array` - Statistics with keys:
  - `moved` (int) - Number of files successfully moved to archive
  - `skipped` (int) - Number of files still within retention period
  - `errors` (array) - Array of error messages for failed operations

**Description:**
Automatically archives price files older than the retention period:
1. Creates archive directory if it doesn't exist
2. Finds all price files matching `price*.json` pattern
3. Extracts date from filename or uses file modification time
4. Moves files older than retention period to archive
5. Appends timestamp to archived filename if duplicate exists

This function is automatically called after successful price file writes to keep the data directory clean.

---

## API Endpoint Handler (`data_api.php`)

The `data_api.php` file is a REST API endpoint handler that uses the functions above. It handles:

- **GET requests**: Read data files
- **POST requests**: Create/update data files
- **PUT requests**: Update schedule entries
- **DELETE requests**: Delete data files

### Supported Data Types

- `price` - Price data files (requires `date` parameter: YYYYMMDD)
- `zendure` - Zendure device data
- `zendure_p1` - Zendure P1 meter data
- `schedule` - Charge schedule data
- `automation_status` - Automation status data
- `file` - Generic JSON files (requires `name` parameter)
- `list` - List all JSON files (optionally with pattern filter)

### Special Features

- **Schedule resolution**: GET requests for schedule type can include `resolved=true` or `format=resolved` to get resolved schedule data for a specific date
- **Schedule cleanup**: POST requests to schedule with `{"action": "simulate"}` or `{"action": "delete"}` to clean up old schedule entries
- **Price file archiving**: Automatically archives old price files after writes
- **CORS support**: Allows cross-origin requests
- **Atomic writes**: All file writes use atomic operations to prevent corruption
