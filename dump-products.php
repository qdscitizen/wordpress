<?php
require_once 'D:/xampp/htdocs/wordpress/wp-load.php';

$products = wc_get_products(['limit' => 5, 'orderby' => 'date', 'order' => 'DESC']);
foreach ($products as $p) {
    echo "ID: {$p->get_id()} | {$p->get_name()} | Regular: {$p->get_regular_price()} | Sale: {$p->get_sale_price()} | Status: {$p->get_stock_status()}\n";
    if ($p->get_sale_price()) echo "  Sale dates: {$p->get_date_on_sale_from()} -> {$p->get_date_on_sale_to()}\n";
}
