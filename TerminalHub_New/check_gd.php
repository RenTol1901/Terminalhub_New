<?php
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "Loaded php.ini: " . php_ini_loaded_file() . "<br>";

if (extension_loaded('gd')) {
    echo "GD STATUS: ENABLED";
} else {
    echo "GD STATUS: DISABLED";
}
?>