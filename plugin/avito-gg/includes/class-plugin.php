<?php
if (!defined('ABSPATH')) { exit; }

final class GG_Avito_Plugin {
    const NS = 'avito-gg/v1';
    const OPTION = 'gg_avito_settings';

    public static function boot(): void {
        add_action('init', [self::class, 'types']);
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('gg_avito_cleanup', [self::class, 'cleanup']);
        add_action('init', static function () {
            if (!wp_next_scheduled('gg_avito_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'gg_avito_cleanup'); }
        });
    }
    public static function types(): void {
        foreach (['gg_avito_item', 'gg_avito_job'] as $type) {
            register_post_type($type, ['public' => false, 'show_ui' => false, 'rewrite' => false, 'supports' => ['title'], 'can_export' => false]);
        }
    }
    public static function menu(): void {
        add_menu_page('Авито → WooCommerce', 'Авито · GG', 'manage_woocommerce', 'avito-gg', [self::class, 'page'], 'dashicons-migrate', 57);
    }
    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        echo '<div class="wrap" id="gg-avito"><p>Загрузка панели…</p></div>';
    }
    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_avito-gg') { return; }
        wp_enqueue_style('gg-avito', plugins_url('../assets/admin.css', __FILE__), [], '0.1.0');
        wp_enqueue_script('gg-avito', plugins_url('../assets/admin.js', __FILE__), [], '0.1.0', true);
        wp_localize_script('gg-avito', 'GG_AVITO', ['root' => rest_url(self::NS . '/'), 'nonce' => wp_create_nonce('wp_rest'), 'maxUpload' => min(wp_max_upload_size(), GG_Avito_Bundle::MAX_FILE)]);
    }
    public static function settings(): array {
        return wp_parse_args(get_option(self::OPTION, []), [
            'profile_url' => '', 'markup' => 0, 'default_category' => 0,
            'update_fields' => ['price'], 'onboarding_done' => false,
        ]);
    }
    public static function routes(): void {
        foreach ([
            ['/state', 'GET', 'state'], ['/settings', 'POST', 'save_settings'],
            ['/items', 'GET', 'items'], ['/items/(?P<id>\d+)', 'POST', 'edit'],
            ['/upload', 'POST', 'upload'], ['/jobs/(?P<id>\d+)/step', 'POST', 'step'],
            ['/jobs/(?P<id>\d+)/retry', 'POST', 'retry'],
            ['/items/(?P<id>\d+)/publish', 'POST', 'publish'],
            ['/export', 'POST', 'export'], ['/demo', 'POST', 'demo'],
        ] as [$route, $method, $handler]) {
            register_rest_route(self::NS, $route, [
                'methods' => $method,
                'permission_callback' => static function () { return current_user_can('manage_woocommerce'); },
                'callback' => static function ($request) use ($handler, $method) {
                    $locked = false;
                    try {
                        if ($method !== 'GET') {
                            $old = (int) get_option('gg_avito_lock', 0);
                            if ($old && $old < time() - 900) { delete_option('gg_avito_lock'); }
                            $locked = add_option('gg_avito_lock', time(), '', false);
                            if (!$locked) { return new WP_Error('gg_busy', 'Другая операция ещё выполняется. Повторите позже.', ['status' => 409]); }
                        }
                        return rest_ensure_response(self::$handler($request));
                    } catch (Throwable $e) {
                        return new WP_Error('gg_error', $e->getMessage(), ['status' => 400]);
                    } finally { if ($locked) { delete_option('gg_avito_lock'); } }
                },
            ]);
        }
    }
    private static function record(int $id, string $type = 'gg_avito_item'): WP_Post {
        $post = get_post($id);
        if (!$post || $post->post_type !== $type) { throw new RuntimeException('Запись не найдена.'); }
        return $post;
    }
    private static function insert(string $type, string $title): int {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'private', 'post_title' => $title], true);
        if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
        return (int) $id;
    }
    private static function log(string $message): void {
        $logs = get_option('gg_avito_logs', []);
        array_unshift($logs, ['time' => current_time('mysql'), 'message' => sanitize_text_field($message)]);
        update_option('gg_avito_logs', array_slice($logs, 0, 100), false);
    }
    private static function job_data(int $id): array {
        self::record($id, 'gg_avito_job');
        return get_post_meta($id, '_gg_job', true);
    }
    public static function state($r): array {
        $jobs = get_posts(['post_type' => 'gg_avito_job', 'post_status' => 'private', 'posts_per_page' => 10]);
        $summaries = [];
        foreach ($jobs as $job) {
            $d = self::job_data($job->ID);
            $summaries[] = ['id' => $job->ID, 'name' => $job->post_title, 'total' => count($d['items']), 'processed' => $d['processed'], 'remaining' => count($d['pending']), 'errors' => $d['errors'], 'skipped' => $d['skipped']];
        }
        $terms = taxonomy_exists('product_cat') ? get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]) : [];
        return [
            'settings' => self::settings(), 'jobs' => $summaries,
            'categories' => is_wp_error($terms) ? [] : array_map(static fn($t) => ['id' => $t->term_id, 'name' => $t->name], $terms),
            'woocommerce' => class_exists('WC_Product_Simple'), 'zip' => class_exists('ZipArchive'),
            'logs' => get_option('gg_avito_logs', []),
            'counts' => (int) wp_count_posts('gg_avito_item')->private,
        ];
    }
    public static function save_settings($r): array {
        $data = $r->get_json_params(); $settings = self::settings();
        if (!is_array($data)) { throw new RuntimeException('Нет настроек.'); }
        if (isset($data['markup'])) {
            if (!is_numeric($data['markup']) || $data['markup'] < -100 || $data['markup'] > 10000) { throw new RuntimeException('Наценка: от −100 до 10000%.'); }
            $settings['markup'] = (float) $data['markup'];
        }
        if (isset($data['profile_url'])) { $settings['profile_url'] = esc_url_raw($data['profile_url'], ['https']); }
        if (isset($data['default_category'])) {
            $cat = absint($data['default_category']);
            if ($cat && !term_exists($cat, 'product_cat')) { throw new RuntimeException('Категория WooCommerce не найдена.'); }
            $settings['default_category'] = $cat;
        }
        if (isset($data['update_fields'])) { $settings['update_fields'] = array_values(array_intersect((array) $data['update_fields'], ['title','description','price','category','attributes','images'])); }
        if (isset($data['onboarding_done'])) { $settings['onboarding_done'] = (bool) $data['onboarding_done']; }
        update_option(self::OPTION, $settings, false);
        return $settings;
    }
    private static function item_data(int $id): array {
        $data = get_post_meta($id, '_gg_payload', true);
        $data['id'] = $id;
        $data['product_id'] = (int) get_post_meta($id, '_gg_product_id', true);
        $data['status'] = $data['product_id'] ? get_post_status($data['product_id']) : 'review';
        $data['photos'] = array_values(array_filter(array_map('wp_get_attachment_url', $data['attachment_ids'])));
        $data['product_edit_url'] = $data['product_id'] ? get_edit_post_link($data['product_id'], 'raw') : '';
        return $data;
    }
    public static function items($r): array {
        $args = ['post_type' => 'gg_avito_item', 'post_status' => 'private', 'posts_per_page' => 25, 'paged' => max(1, (int) $r['page']), 's' => sanitize_text_field($r['search'] ?? ''), 'orderby' => 'ID', 'order' => 'DESC'];
        if ($r['filter'] === 'review') { $args['meta_query'] = [['key' => '_gg_product_id', 'compare' => 'NOT EXISTS']]; }
        if ($r['filter'] === 'linked') { $args['meta_query'] = [['key' => '_gg_product_id', 'compare' => 'EXISTS']]; }
        $q = new WP_Query($args);
        return ['items' => array_map(static fn($p) => self::item_data($p->ID), $q->posts), 'total' => $q->found_posts, 'pages' => $q->max_num_pages];
    }
    public static function edit($r): array {
        $id = (int) $r['id']; self::record($id);
        $old = get_post_meta($id, '_gg_payload', true); $input = $r->get_json_params();
        $data = GG_Avito_Bundle::normalize(array_merge($old, array_intersect_key($input, array_flip(['title','description','price','category','attributes']))));
        $data['attachment_ids'] = $old['attachment_ids'];
        update_post_meta($id, '_gg_payload', wp_slash($data));
        wp_update_post(['ID' => $id, 'post_title' => wp_slash($data['title'])]);
        return self::item_data($id);
    }
    public static function upload($r): array {
        $files = $r->get_file_params(); $f = $files['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { throw new RuntimeException('Файл не загружен. Проверьте лимит загрузки PHP.'); }
        if (filesize($f['tmp_name']) > GG_Avito_Bundle::MAX_FILE) { throw new RuntimeException('Размер файла — до 128 МБ.'); }
        $extension = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $items = GG_Avito_Bundle::read($f['tmp_name'], $extension);
        $dir = realpath(sys_get_temp_dir()); $web = realpath(ABSPATH);
        if (!$dir || ($web && str_starts_with($dir . '/', $web . '/'))) { throw new RuntimeException('На сервере нужен временный каталог вне корня сайта.'); }
        $path = tempnam($dir, 'gg-avito-');
        if (!$path) { throw new RuntimeException('Временный каталог недоступен.'); }
        chmod($path, 0600);
        if (!move_uploaded_file($f['tmp_name'], $path)) { unlink($path); throw new RuntimeException('Не удалось сохранить загрузку.'); }
        try {
            $id = self::insert('gg_avito_job', sanitize_file_name($f['name']));
            update_post_meta($id, '_gg_job', wp_slash(['path' => $path, 'extension' => $extension, 'items' => $items, 'pending' => array_keys($items), 'processed' => 0, 'errors' => [], 'skipped' => 0, 'replace' => $r['replace'] === '1']));
        } catch (Throwable $e) { unlink($path); throw $e; }
        self::log('Загружен файл: ' . sanitize_file_name($f['name']) . ', позиций: ' . count($items));
        return ['job_id' => $id, 'total' => count($items)];
    }
    private static function stage(array $item, string $archive, bool $replace): bool {
        $found = get_posts(['post_type' => 'gg_avito_item', 'post_status' => 'private', 'meta_key' => '_gg_avito_id', 'meta_value' => $item['avito_id'], 'posts_per_page' => 1, 'fields' => 'ids']);
        if ($found && !$replace) { return false; }
        $photos = [];
        foreach ($item['images'] as $reference) { $photos[] = GG_Avito_Bundle::image($reference, $archive); }
        $item['attachment_ids'] = array_values(array_unique($photos));
        $id = $found ? (int) $found[0] : self::insert('gg_avito_item', $item['title']);
        update_post_meta($id, '_gg_avito_id', $item['avito_id']);
        update_post_meta($id, '_gg_payload', wp_slash($item));
        wp_update_post(['ID' => $id, 'post_title' => wp_slash($item['title'])]);
        return true;
    }
    public static function step($r): array {
        $id = (int) $r['id']; $job = self::job_data($id);
        if (!$job['pending']) { return ['done' => true, 'errors' => $job['errors']]; }
        if (!is_file($job['path'])) { throw new RuntimeException('Временный файл удалён сервером. Загрузите архив заново.'); }
        $index = array_shift($job['pending']);
        try {
            if (!self::stage($job['items'][$index], $job['path'], $job['replace'])) { $job['skipped']++; }
            unset($job['errors'][$index]);
        } catch (Throwable $e) {
            $job['errors'][$index] = ['index' => $index, 'avito_id' => $job['items'][$index]['avito_id'], 'message' => $e->getMessage()];
            self::log('Ошибка импорта ' . $job['items'][$index]['avito_id'] . ': ' . $e->getMessage());
        }
        $job['processed']++;
        update_post_meta($id, '_gg_job', wp_slash($job));
        if (!$job['pending']) { self::log('Обработка файла завершена. Ошибок: ' . count($job['errors']) . ', пропущено дублей: ' . $job['skipped']); }
        return ['done' => !$job['pending'], 'remaining' => count($job['pending']), 'total' => count($job['items']), 'errors' => array_values($job['errors'])];
    }
    public static function retry($r): array {
        $id = (int) $r['id']; $job = self::job_data($id);
        $job['pending'] = array_values(array_unique(array_merge($job['pending'], array_keys($job['errors']))));
        update_post_meta($id, '_gg_job', wp_slash($job));
        return ['remaining' => count($job['pending'])];
    }
    public static function publish($r): array {
        if (!class_exists('WC_Product_Simple')) { throw new RuntimeException('Установите и активируйте WooCommerce.'); }
        $id = (int) $r['id']; self::record($id);
        $data = get_post_meta($id, '_gg_payload', true); $settings = self::settings();
        if ($data['price'] === '') { throw new RuntimeException('Укажите цену перед созданием товара.'); }
        $publish = $r->get_param('status') === 'publish';
        if ($publish && !current_user_can('publish_products')) { throw new RuntimeException('Недостаточно прав для публикации товаров.'); }
        if (!current_user_can('edit_products')) { throw new RuntimeException('Недостаточно прав для изменения товаров.'); }
        $product_id = (int) get_post_meta($id, '_gg_product_id', true);
        if (!$product_id) {
            $found = get_posts(['post_type' => 'product', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'], 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_gg_avito_id', 'meta_value' => $data['avito_id']]);
            $product_id = $found ? (int) $found[0] : 0;
        }
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        if (!$product) { throw new RuntimeException('Связанный товар удалён. Восстановите его из корзины.'); }
        if ($product_id && (!current_user_can('edit_post', $product_id) || $product->get_status() === 'trash')) { throw new RuntimeException('Товар недоступен для изменения или находится в корзине.'); }
        if ($product->get_type() !== 'simple') { throw new RuntimeException('Эта версия обновляет только простые товары.'); }
        $fields = $product_id ? $settings['update_fields'] : ['title','description','price','category','attributes','images'];
        if (in_array('title', $fields, true)) { $product->set_name($data['title']); }
        if (in_array('description', $fields, true)) { $product->set_description($data['description']); }
        if (in_array('price', $fields, true)) { $product->set_regular_price(wc_format_decimal(round((float) $data['price'] * (1 + $settings['markup'] / 100), wc_get_price_decimals()))); }
        if (in_array('category', $fields, true)) {
            $cat = $settings['default_category'];
            if ($data['category']) {
                $term = get_term_by('name', $data['category'], 'product_cat');
                if ($term) { $cat = $term->term_id; }
            }
            if ($cat) { $product->set_category_ids([(int) $cat]); }
        }
        if (in_array('attributes', $fields, true)) {
            $attrs = [];
            foreach ($data['attributes'] as $name => $value) {
                $attr = new WC_Product_Attribute(); $attr->set_name($name); $attr->set_options([$value]); $attr->set_visible(true); $attrs[] = $attr;
            }
            $product->set_attributes($attrs);
        }
        if (in_array('images', $fields, true)) {
            $photos = $data['attachment_ids']; $product->set_image_id($photos[0] ?? 0); $product->set_gallery_image_ids(array_slice($photos, 1));
        }
        if (!$product_id) { $product->set_status('draft'); }
        if ($publish) { $product->set_status('publish'); }
        $product->update_meta_data('_gg_avito_id', $data['avito_id']);
        $product->update_meta_data('_gg_avito_url', $data['url']);
        $product_id = $product->save();
        update_post_meta($id, '_gg_product_id', $product_id);
        self::log('Товар #' . $product_id . ' сохранён из объявления ' . $data['avito_id']);
        return self::item_data($id);
    }
    public static function demo($r): array {
        $items = [
            ['avito_id' => 'demo-001', 'title' => 'Демо · Кресло', 'description' => 'Тестовая позиция. Отредактируйте её перед публикацией.', 'price' => '12000', 'category' => 'Мебель', 'attributes' => ['Состояние' => 'Новое']],
            ['avito_id' => 'demo-002', 'title' => 'Демо · Настольная лампа', 'description' => 'Тестовый товар для проверки переноса в WooCommerce.', 'price' => '3500', 'category' => 'Освещение', 'attributes' => ['Цвет' => 'Чёрный']],
        ];
        foreach ($items as $item) { self::stage(GG_Avito_Bundle::normalize($item), '', false); }
        self::log('Добавлены две демонстрационные позиции.');
        return ['ok' => true];
    }
    public static function export($r): array {
        $ids = array_values(array_unique(array_map('absint', (array) $r->get_param('ids'))));
        if (!$ids || count($ids) > 200) { throw new RuntimeException('Выберите от 1 до 200 позиций для одного экспорта.'); }
        $format = $r->get_param('format') === 'csv' ? 'csv' : 'zip';
        $items = [];
        foreach ($ids as $id) { self::record($id); $items[] = get_post_meta($id, '_gg_payload', true); }
        if ($format === 'csv') {
            $f = fopen('php://temp', 'r+');
            $columns = ['avito_id','title','description','price','category','url','images','attributes'];
            fputcsv($f, $columns, ',', '"', '');
            foreach ($items as $item) {
                $item['images'] = implode('|', array_filter(array_map('wp_get_attachment_url', $item['attachment_ids'])));
                $item['attributes'] = wp_json_encode($item['attributes'], JSON_UNESCAPED_UNICODE);
                $row = array_map(static fn($k) => $item[$k], $columns);
                $row = array_map(static fn($v) => preg_match('/^[=+@\-\t\r\n]/u', (string) $v) ? "'" . $v : $v, $row);
                fputcsv($f, $row, ',', '"', '');
            }
            rewind($f); $bytes = stream_get_contents($f); fclose($f);
        } else {
            if (!class_exists('ZipArchive')) { throw new RuntimeException('Требуется PHP ZipArchive.'); }
            $path = wp_tempnam('gg-export'); $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Не удалось создать ZIP.'); }
            $size = 0;
            try {
                foreach ($items as &$item) {
                    $images = [];
                    foreach ($item['attachment_ids'] as $attachment) {
                        $file = get_attached_file($attachment);
                        if (!$file || !is_file($file)) { throw new RuntimeException('Фото #' . $attachment . ' отсутствует.'); }
                        $size += filesize($file);
                        if ($size > 67108864) { throw new RuntimeException('Экспорт больше 64 МБ. Выберите меньше позиций.'); }
                        $name = 'images/' . $attachment . '.' . pathinfo($file, PATHINFO_EXTENSION);
                        $zip->addFile($file, $name); $images[] = $name;
                    }
                    $item['images'] = $images; unset($item['attachment_ids']);
                }
                unset($item);
                $zip->addFromString('products.json', wp_json_encode(['version' => 1, 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                $zip->close(); $bytes = file_get_contents($path);
            } finally { try { $zip->close(); } catch (Throwable $ignored) {} if (is_file($path)) { unlink($path); } }
        }
        return ['name' => 'avito-products.' . $format, 'mime' => $format === 'zip' ? 'application/zip' : 'text/csv;charset=utf-8', 'base64' => base64_encode($bytes)];
    }
    public static function cleanup(): void {
        $jobs = get_posts(['post_type' => 'gg_avito_job', 'post_status' => 'private', 'posts_per_page' => -1, 'date_query' => [['before' => '7 days ago']]]);
        foreach ($jobs as $post) {
            $job = self::job_data($post->ID);
            if (!empty($job['path']) && is_file($job['path']) && str_starts_with(basename($job['path']), 'gg-avito-')) { unlink($job['path']); }
            wp_delete_post($post->ID, true);
        }
    }
}
