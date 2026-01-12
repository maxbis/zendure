#!/usr/bin/env python3
"""
Unified logging utility for run_schedule scripts.

Provides consistent logging format across all scripts with timestamps,
log levels, and emoji indicators.
"""

from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo

# ============================================================================
# CONFIGURATION
# ============================================================================

# Timezone for log timestamps (default: Europe/Amsterdam)
TIMEZONE = 'Europe/Amsterdam'

# ============================================================================
# LOGGING FUNCTIONS
# ============================================================================

def log(level: str, message: str, include_timestamp: bool = True, file_path: str = None):
    """
    Unified logging function with consistent formatting.
    
    Args:
        level: Log level ('INFO', 'DEBUG', 'WARNING', 'ERROR', 'SUCCESS')
        message: Log message
        include_timestamp: If True, prepend timestamp in [YYYY-MM-DD HH:MM:SS] format
        file_path: Optional path to log file. If provided, message will also be written to file.
    """
    emoji_map = {
        'INFO': '',
        'DEBUG': '🔍',
        'WARNING': '⚠️',
        'ERROR': '❌',
        'SUCCESS': '✅',
    }
    
    emoji = emoji_map.get(level, '')
    
    if include_timestamp:
        timestamp = datetime.now(ZoneInfo(TIMEZONE)).strftime('%Y-%m-%d %H:%M:%S')
        prefix = f"[{timestamp}]"
    else:
        prefix = ""
    
    if emoji:
        output = f"{prefix} {emoji} {message}".strip()
    else:
        output = f"{prefix} {message}".strip() if prefix else message
    
    # Print to stdout
    print(output)
    
    # Write to file if specified
    if file_path:
        try:
            log_file = Path(file_path)
            # Create parent directory if it doesn't exist
            log_file.parent.mkdir(parents=True, exist_ok=True)
            # Append to file
            with open(log_file, 'a', encoding='utf-8') as f:
                f.write(output + '\n')
        except Exception as e:
            # Don't fail if file logging fails, just print error
            print(f"[ERROR] Failed to write to log file {file_path}: {e}")


def log_info(message: str, include_timestamp: bool = True, file_path: str = None):
    """Log an informational message."""
    log('INFO', message, include_timestamp, file_path)


def log_debug(message: str, include_timestamp: bool = False, file_path: str = None):
    """Log a debug message (typically without timestamp for brevity)."""
    log('DEBUG', message, include_timestamp, file_path)


def log_warning(message: str, include_timestamp: bool = True, file_path: str = None):
    """Log a warning message."""
    log('WARNING', message, include_timestamp, file_path)


def log_error(message: str, include_timestamp: bool = True, file_path: str = None):
    """Log an error message."""
    log('ERROR', message, include_timestamp, file_path)


def log_success(message: str, include_timestamp: bool = True, file_path: str = None):
    """Log a success message."""
    log('SUCCESS', message, include_timestamp, file_path)
