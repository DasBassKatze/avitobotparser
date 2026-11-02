<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_kses_post($v) { return strip_tags((string) $v, '<p><strong><em><br>'); }
function esc_url_raw($v, $protocols = []) { return filter_var($v, FILTER_VALIDATE_URL) ? $v : ''; }
function absint($v) { return abs((int) $v); }
require __DIR__ . '/../plugin/avito-gg/includes/class-bundle.php';
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function fails($fn, $message) { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$item = GG_Avito_Bundle::normalize(['avito_id' => '123', 'title' => 'Кресло', 'price' => '1 234,50', 'attributes' => '{"Цвет":"Белый"}']);
check($item['price'] === '1234.50', 'Russian price normalization failed');
check($item['attributes']['Цвет'] === 'Белый', 'Attributes lost');
fails(fn() => GG_Avito_Bundle::normalize(['avito_id' => '../a', 'title' => 'x']), 'Path-like ID accepted');
fails(fn() => GG_Avito_Bundle::normalize(['avito_id' => 'a', 'title' => 'x', 'price' => '-1']), 'Negative price accepted');
fails(fn() => GG_Avito_Bundle::normalize(['avito_id' => 'a', 'title' => 'x', 'price' => '1e999']), 'Infinite price accepted');
$items = GG_Avito_Bundle::read(__DIR__ . '/../examples/products.csv', 'csv');
check(count($items) === 1 && $items[0]['price'] === '1500.00', 'CSV example invalid');
$path = tempnam(sys_get_temp_dir(), 'gg-test');
try {
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('products.json', json_encode(['version' => 1, 'items' => [$item]])); $zip->close();
    check(count(GG_Avito_Bundle::read($path, 'zip')) === 1, 'Valid ZIP rejected');
    $zip->open($path); $zip->addFromString('../evil.php', 'bad'); $zip->close();
    fails(fn() => GG_Avito_Bundle::read($path, 'zip'), 'ZIP traversal accepted');
    $zip->open($path, ZipArchive::OVERWRITE); $zip->addFromString('products.json', json_encode(['version' => 1, 'items' => [$item, $item]])); $zip->close();
    fails(fn() => GG_Avito_Bundle::read($path, 'zip'), 'Duplicate source IDs accepted');
} finally { unlink($path); }
echo "Bundle validation: passed\n";
