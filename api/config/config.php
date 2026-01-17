<?php
// Beyblade X Scorer - XAMPP Configuration
// This file is for future PHP functionality if needed

// Database configuration (for future use)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'beyblade_x');

// Application settings
define('APP_NAME', 'Beyblade X Scorer');
define('APP_VERSION', '1.0.0');

// Supabase configuration (for reference)
define('SUPABASE_URL', 'https://scckhaegyrpvvtgqaiht.supabase.co');
define('SUPABASE_ANON_KEY', 'sb_publishable_suGilNls1WKqs2bgqCVVvg_U2N3EpK8');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('UTC');
?>
