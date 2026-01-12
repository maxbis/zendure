<?php
/**
 * Zendure SolarFlow Status Viewer - Helper Functions
 * Main loader file that includes all helper modules
 * 
 * This file serves as the entry point for all helper functions.
 * It includes all specialized helper modules in the correct dependency order.
 */

// Load modules in dependency order
require_once __DIR__ . '/formatters.php';      // Formatting and conversion (no dependencies)
require_once __DIR__ . '/colors.php';          // Color functions and constants
require_once __DIR__ . '/status.php';          // Status, icon, and text functions
require_once __DIR__ . '/data.php';            // Data loading functions
require_once __DIR__ . '/bars.php';            // Bar calculation functions (depends on nothing, but used by others)
require_once __DIR__ . '/renderers.php';       // Rendering functions (depends on bars.php)
require_once __DIR__ . '/metrics.php';         // Metric data preparation (depends on bars.php and colors.php)
