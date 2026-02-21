"""
Load automate/config/config.jsonc with support for comments.

Supports:
- // line comments (whole lines only; safe when // appears in URLs inside strings)
- /* */ block comments (can span lines; replace with space when filtering)
"""

import json
import re
from pathlib import Path
from typing import Any, Dict, Union


def strip_json_comments(text: str) -> str:
    """
    Remove // and /* */ comments from JSON-like text so it can be parsed by json.loads.
    - Full-line // comments are removed (line is replaced with newline).
    - /* ... */ block comments are replaced with a single space.
    Do not place block comments between a key and its value (e.g. "key": /* comment */ "value").
    """
    # Remove block comments first (replace with space to avoid joining tokens)
    text = re.sub(r"/\*.*?\*/", " ", text, flags=re.DOTALL)
    # Remove lines that are only whitespace and // comment
    lines = []
    for line in text.split("\n"):
        if line.strip().startswith("//"):
            lines.append("")
        else:
            lines.append(line)
    return "\n".join(lines)


def load_config(config_path: Union[Path, str]) -> Dict[str, Any]:
    """
    Load config JSON from file, stripping // and /* */ comments before parsing.

    Raises:
        FileNotFoundError: If the file does not exist.
        ValueError: If the content is invalid JSON.
    """
    path = Path(config_path)
    if not path.exists():
        raise FileNotFoundError(f"Config file not found: {path}")
    text = path.read_text(encoding="utf-8")
    try:
        return json.loads(strip_json_comments(text))
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON in config file {path}: {e}")
