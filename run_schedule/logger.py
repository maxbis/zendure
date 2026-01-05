#!/usr/bin/env python3
"""
Unified logging utility for run_schedule scripts.

Provides consistent logging format across all scripts with timestamps,
log levels, and emoji indicators.
"""

from datetime import datetime
from zoneinfo import ZoneInfo

# ============================================================================
# CONFIGURATION
# ============================================================================

# Timezone for log timestamps (default: Europe/Amsterdam)
TIMEZONE = 'Europe/Amsterdam'

# ============================================================================
# LOGGING FUNCTIONS
# ============================================================================

def log(level: str, message: str, include_timestamp: bool = True):
    """
    Unified logging function with consistent formatting.
    
    Args:
        level: Log level ('INFO', 'DEBUG', 'WARNING', 'ERROR', 'SUCCESS')
        message: Log message
        include_timestamp: If True, prepend timestamp in [YYYY-MM-DD HH:MM:SS] format
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
    
    print(output)


def log_info(message: str, include_timestamp: bool = True):
    """Log an informational message."""
    log('INFO', message, include_timestamp)


def log_debug(message: str, include_timestamp: bool = False):
    """Log a debug message (typically without timestamp for brevity)."""
    log('DEBUG', message, include_timestamp)


def log_warning(message: str, include_timestamp: bool = True):
    """Log a warning message."""
    log('WARNING', message, include_timestamp)


def log_error(message: str, include_timestamp: bool = True):
    """Log an error message."""
    log('ERROR', message, include_timestamp)


def log_success(message: str, include_timestamp: bool = True):
    """Log a success message."""
    log('SUCCESS', message, include_timestamp)
