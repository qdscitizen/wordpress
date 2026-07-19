<?php
require_once 'D:/xampp/htdocs/wordpress/wp-load.php';

$result = WooAI_Desc_Product_Handler::create_draft([
    'keywords' => 'Ergonomic Office Chair, mesh back, lumbar support, adjustable armrests, 5-year warranty',
    'language' => 'en',
    'tone'     => 'professional',
]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
