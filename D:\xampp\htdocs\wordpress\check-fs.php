<?php
require_once '/mnt/d/xampp/htdocs/wordpress/wp-load.php';

global $wpdb;

echo "=== Freemius-related options found ===\n";
$rows = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%fs_%' OR option_name LIKE '%freemius%' OR option_name LIKE '%wooai_desc%' ORDER BY option_name");
foreach ($rows as $r) {
    echo "  " . $r->option_name . "\n";
}

echo "\n=== Option sizes ===\n";
$rows2 = $wpdb->get_results("SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE option_name LIKE '%fs_%' OR option_name LIKE '%freemius%' OR option_name LIKE '%wooai_desc%' ORDER BY option_name");
foreach ($rows2 as $r) {
    echo "  " . $r->option_name . " = " . $r->size . " bytes\n";
}
