
<?php
session_start();

// Clear all session data
$_SESSION = [];

// Destroy the current session
session_destroy();

// Redirect all account types to the main login page
header("Location: login.php");
exit;
