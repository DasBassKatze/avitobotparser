<?php
if (!defined('ABSPATH')) { exit; }

final class GG_Avito_Bundle {
    const MAX_ITEMS = 5000;
    const MAX_FILE = 134217728;

    public static function normalize(array $item): array {
        $id = trim((string) ($item['avito_id'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $id)) {
            throw new RuntimeException('Нужен avito_id: до 80 букв, цифр, дефисов или подчёркиваний.');
        }
        $title = sanitize_text_field($item['title'] ?? '');
        if (!$title) { throw new RuntimeException("Объявление $id: нет названия."); }
        $price = str_replace(',', '.', preg_replace('/[\s\x{00a0}]/u', '', (string) ($item['price'] ?? '')));
        if ($price !== '' && (!is_numeric($price) || !is_finite((float) $price) || (float) $price < 0)) {
            throw new RuntimeException("Объявление $id: неверная цена.");
        }
        $images = $item['images'] ?? [];
        if (is_string($images)) { $images = array_filter(array_map('trim', explode('|', $images))); }
        if (!is_array($images) || count($images) > 30) { throw new RuntimeException('Допустимо до 30 фотографий на объявление.'); }
        $attrs = $item['attributes'] ?? [];
        if (is_string($attrs)) { $attrs = json_decode($attrs, true, 32, JSON_THROW_ON_ERROR); }
        if (!is_array($attrs) || count($attrs) > 100) { throw new RuntimeException('Неверный список характеристик.'); }
        $clean_attrs = [];
        foreach ($attrs as $key => $value) {
            if (!is_scalar($value)) { throw new RuntimeException('Значения характеристик должны быть текстом.'); }
            $clean_attrs[sanitize_text_field($key)] = sanitize_text_field((string) $value);
        }
        return [
            'avito_id' => $id, 'title' => $title,
            'description' => wp_kses_post($item['description'] ?? ''),
            'price' => $price === '' ? '' : number_format((float) $price, 2, '.', ''),
            'category' => sanitize_text_field($item['category'] ?? ''),
            'url' => esc_url_raw($item['url'] ?? '', ['https']),
            'attributes' => $clean_attrs,
            'images' => array_values(array_map('strval', $images)),
            'attachment_ids' => array_values(array_filter(array_map('absint', (array) ($item['attachment_ids'] ?? [])))),
        ];
    }

    public static function read(string $path, string $extension): array {
        if ($extension === 'zip') {
            if (!class_exists('ZipArchive')) { throw new RuntimeException('На хостинге требуется PHP ZipArchive.'); }
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) { throw new RuntimeException('Не удалось открыть ZIP.'); }
            try {
                if ($zip->numFiles > 50000) { throw new RuntimeException('Слишком много файлов в архиве.'); }
                $size = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $name = $stat['name'];
                    if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, ':')) {
                        throw new RuntimeException('Недопустимый путь внутри ZIP.');
                    }
                    $size += $stat['size'];
                    if ($stat['size'] > 20971520 || $size > 536870912) { throw new RuntimeException('Распакованный архив слишком большой (лимит 512 МБ, файл 20 МБ).'); }
                }
                $data = $zip->getFromName('products.json');
                if ($data === false || strlen($data) > 16777216) { throw new RuntimeException('В корне ZIP нужен products.json размером до 16 МБ.'); }
                $manifest = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                if (($manifest['version'] ?? null) !== 1) { throw new RuntimeException('Неподдерживаемая версия архива. Нужна version: 1.'); }
                $items = $manifest['items'] ?? null;
            } finally { $zip->close(); }
        } elseif ($extension === 'csv') {
            $file = fopen($path, 'r');
            if (!$file) { throw new RuntimeException('Не удалось прочитать CSV.'); }
            try {
                $head = fgetcsv($file, 0, ',', '"', '');
                if (!$head) { throw new RuntimeException('CSV пуст.'); }
                $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
                if (!in_array('avito_id', $head, true) || !in_array('title', $head, true)) { throw new RuntimeException('В CSV нужны столбцы avito_id и title. Разделитель — запятая, кодировка UTF-8.'); }
                $items = [];
                while (($row = fgetcsv($file, 0, ',', '"', '')) !== false) {
                    if ($row === [null]) { continue; }
                    if (count($row) !== count($head)) { throw new RuntimeException('В строке CSV неверное количество столбцов.'); }
                    $items[] = array_combine($head, $row);
                    if (count($items) > self::MAX_ITEMS) { throw new RuntimeException('До 5000 позиций за одну загрузку.'); }
                }
            } finally { fclose($file); }
        } else { throw new RuntimeException('Поддерживаются ZIP и CSV.'); }
        if (!is_array($items) || !count($items) || count($items) > self::MAX_ITEMS) { throw new RuntimeException('Файл должен содержать от 1 до 5000 позиций.'); }
        $result = []; $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) { throw new RuntimeException('Неверный формат позиции.'); }
            $clean = self::normalize($item);
            $clean['attachment_ids'] = [];
            if (isset($seen[$clean['avito_id']])) { throw new RuntimeException('В файле повторяется ID: ' . $clean['avito_id']); }
            $seen[$clean['avito_id']] = true;
            $result[] = $clean;
        }
        return $result;
    }

    public static function image(string $reference, string $archive): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        if (preg_match('#^https://#i', $reference)) {
            $temp = wp_tempnam('gg-photo');
            if (!$temp) { throw new RuntimeException('Недоступен временный каталог.'); }
            $response = wp_safe_remote_get($reference, ['timeout' => 15, 'stream' => true, 'filename' => $temp, 'limit_response_size' => 20971521]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                if (is_file($temp)) { unlink($temp); }
                throw new RuntimeException('Не удалось скачать фото по HTTPS-ссылке.');
            }
        } else {
            if (!preg_match('#^images/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$#D', $reference)) { throw new RuntimeException('Фото должно быть HTTPS-ссылкой или images/имя.jpg (png/webp).'); }
            $zip = new ZipArchive();
            if ($zip->open($archive) !== true) { throw new RuntimeException('Архив недоступен.'); }
            try {
                $bytes = $zip->getFromName($reference);
                if ($bytes === false) { throw new RuntimeException('В архиве нет фото: ' . $reference); }
                $temp = wp_tempnam('gg-photo');
                if (!$temp || file_put_contents($temp, $bytes) === false) { throw new RuntimeException('Не удалось сохранить временное фото.'); }
            } finally { $zip->close(); }
        }
        try {
            if (filesize($temp) > 20971520) { throw new RuntimeException('Фото больше 20 МБ.'); }
            $mime = wp_get_image_mime($temp);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) { throw new RuntimeException('Допустимы только JPEG, PNG и WebP.'); }
            $hash = hash_file('sha256', $temp);
            $existing = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_gg_image_hash', 'meta_value' => $hash, 'fields' => 'ids', 'posts_per_page' => 1]);
            if ($existing) { return (int) $existing[0]; }
            $id = media_handle_sideload(['name' => 'avito-' . substr($hash, 0, 20) . '.' . $extensions[$mime], 'tmp_name' => $temp], 0);
            if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
            update_post_meta($id, '_gg_image_hash', $hash);
            return (int) $id;
        } finally { if (is_file($temp)) { unlink($temp); } }
    }
}
