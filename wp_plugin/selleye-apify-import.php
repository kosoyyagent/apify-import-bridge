<?php
/**
 * Plugin Name: Selleye Apify Import
 * Description: Импорт данных из Apify-датасетов в WordPress по расписанию: маппинг полей, дедупликация, статус-лог.
 * Version: 1.0.0
 * Author: Selleye
 *
 * Админка: Настройки → Apify Import.
 * Cron: каждый запуск тянет items датасета и создаёт/обновляет посты.
 * Дедупликация: по meta_key `sai_dedup` (sha1 от dedup-полей).
 */

if (!defined('ABSPATH')) exit;

class Selleye_Apify_Import {

    const OPT = 'sai_settings';
    const LOG = 'sai_log';
    const CRON_HOOK = 'sai_cron_run';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action(self::CRON_HOOK, [$this, 'run']);
        add_filter('cron_schedules', [$this, 'schedules']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public static function defaults() {
        return [
            'token'      => '',
            'dataset'    => '',   // dataset id или act~id (берётся последний успешный прогон)
            'mapping'    => "{\n  \"post_title\": [\"title\", \"name\"],\n  \"post_content\": [\"description\", \"body\"],\n  \"url\": [\"url\", \"link\"]\n}",
            'dedup'      => 'url',
            'post_type'  => 'post',
            'post_status'=> 'draft',
            'schedule'   => 'hourly',
            'enabled'    => 0,
            'limit'      => 500,
        ];
    }

    public function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK))
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
    }
    public function deactivate() { wp_clear_scheduled_hook(self::CRON_HOOK); }
    public function schedules($s) {
        $s['sai_15min'] = ['interval' => 900, 'display' => 'Каждые 15 минут'];
        $s['sai_6h']    = ['interval' => 21600, 'display' => 'Каждые 6 часов'];
        return $s;
    }

    public function menu() {
        add_options_page('Apify Import', 'Apify Import', 'manage_options', 'sai', [$this, 'page']);
    }

    public function register() {
        register_setting('sai', self::OPT);
    }

    private function opt() { return array_merge(self::defaults(), (array) get_option(self::OPT, [])); }

    public function page() {
        if (!current_user_can('manage_options')) return;
        $o = $this->opt();
        if (isset($_POST['sai_save']) && check_admin_referer('sai_save')) {
            $o = array_merge($o, [
                'token'      => sanitize_text_field($_POST['token'] ?? ''),
                'dataset'    => sanitize_text_field($_POST['dataset'] ?? ''),
                'mapping'    => wp_unslash($_POST['mapping'] ?? ''),
                'dedup'      => sanitize_text_field($_POST['dedup'] ?? 'url'),
                'post_type'  => sanitize_text_field($_POST['post_type'] ?? 'post'),
                'post_status'=> sanitize_text_field($_POST['post_status'] ?? 'draft'),
                'schedule'   => sanitize_text_field($_POST['schedule'] ?? 'hourly'),
                'enabled'    => !empty($_POST['enabled']) ? 1 : 0,
                'limit'      => max(1, (int)($_POST['limit'] ?? 500)),
            ]);
            update_option(self::OPT, $o);
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time(), $o['schedule'], self::CRON_HOOK);
            echo '<div class="updated"><p>Сохранено.</p></div>';
        }
        if (isset($_POST['sai_run']) && check_admin_referer('sai_save')) {
            $res = $this->run();
            echo '<div class="updated"><p>Прогон: ' . esc_html(json_encode($res, JSON_UNESCAPED_UNICODE)) . '</p></div>';
        }
        $log = array_slice((array) get_option(self::LOG, []), -10);
        ?>
        <div class="wrap"><h1>Apify Import</h1>
        <form method="post"><?php wp_nonce_field('sai_save'); ?>
        <table class="form-table">
          <tr><th>Apify API token</th><td><input name="token" value="<?php echo esc_attr($o['token']); ?>" class="regular-text"></td></tr>
          <tr><th>Dataset ID или act~ID</th><td><input name="dataset" value="<?php echo esc_attr($o['dataset']); ?>" class="regular-text">
             <p class="description">act~ID — берётся последний успешный прогон актора.</p></td></tr>
          <tr><th>Маппинг (JSON)</th><td><textarea name="mapping" rows="8" class="large-text code"><?php echo esc_textarea($o['mapping']); ?></textarea>
             <p class="description">{"целевое_поле": ["вариант1","вариант2"]}. Цели: post_title, post_content, post_status, любые meta_* и url.</p></td></tr>
          <tr><th>Дедупликация по полю</th><td><input name="dedup" value="<?php echo esc_attr($o['dedup']); ?>" class="regular-text">
             <p class="description">Колонка после маппинга (например url или sku).</p></td></tr>
          <tr><th>Тип записи</th><td><input name="post_type" value="<?php echo esc_attr($o['post_type']); ?>" class="regular-text"></td></tr>
          <tr><th>Статус записи</th><td><select name="post_status">
             <?php foreach (['draft','publish','pending'] as $st) printf('<option %s>%s</option>', selected($o['post_status'],$st,false), $st); ?>
             </select></td></tr>
          <tr><th>Расписание</th><td><select name="schedule">
             <?php foreach (['sai_15min'=>'Каждые 15 мин','hourly'=>'Каждый час','sai_6h'=>'Каждые 6 ч','twicedaily'=>'2 раза в день','daily'=>'Раз в день'] as $k=>$v) printf('<option value="%s" %s>%s</option>',$k,selected($o['schedule'],$k,false),$v); ?>
             </select></td></tr>
          <tr><th>Лимит за прогон</th><td><input type="number" name="limit" value="<?php echo esc_attr($o['limit']); ?>"></td></tr>
          <tr><th>Включено</th><td><label><input type="checkbox" name="enabled" <?php checked($o['enabled']); ?>> импорт по расписанию</label></td></tr>
        </table>
        <p><button class="button button-primary" name="sai_save" value="1">Сохранить</button>
           <button class="button" name="sai_run" value="1">Запустить сейчас</button></p>
        </form>
        <h2>Последние прогоны</h2><pre><?php echo esc_html(implode("\n", $log)); ?></pre>
        </div><?php
    }

    /* ---------- импорт ---------- */

    public function run() {
        $o = $this->opt();
        if (empty($o['token']) || empty($o['dataset'])) return ['error' => 'no token/dataset'];
        $ds = $o['dataset'];
        if (strpos($ds, '~') !== false || strpos($ds, 'act_') === 0 || ctype_alnum($ds) === false) {
            $ds = $this->last_dataset($ds, $o['token']);
            if (!$ds) return ['error' => 'no successful actor run'];
        }
        $items = $this->fetch_items($ds, $o['token'], (int)$o['limit']);
        $map = json_decode($o['mapping'], true);
        if (!is_array($map)) return ['error' => 'bad mapping json'];

        $created = $updated = $skipped = 0;
        foreach ($items as $it) {
            $row = $this->map_row($it, $map);
            $key = $this->dedup_key($row, $o['dedup']);
            if ($key === null) { $skipped++; continue; }
            $existing = $this->find_by_key($key, $o['post_type']);
            $pid = $this->upsert($row, $existing, $o);
            if ($existing) $updated++; else $created++;
        }
        $res = ['items' => count($items), 'created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        $log = (array) get_option(self::LOG, []);
        $log[] = date('Y-m-d H:i') . ' ' . json_encode($res, JSON_UNESCAPED_UNICODE);
        update_option(self::LOG, array_slice($log, -50));
        return $res;
    }

    private function last_dataset($actor, $token) {
        $r = wp_remote_get("https://api.apify.com/v2/acts/{$actor}/runs/last?token={$token}&status=SUCCEEDED", ['timeout' => 30]);
        if (is_wp_error($r)) return null;
        $d = json_decode(wp_remote_retrieve_body($r), true);
        return $d['data']['defaultDatasetId'] ?? null;
    }

    private function fetch_items($ds, $token, $limit) {
        $items = []; $offset = 0;
        while (count($items) < $limit) {
            $r = wp_remote_get("https://api.apify.com/v2/datasets/{$ds}/items?token={$token}&offset={$offset}&limit=1000&clean=true", ['timeout' => 60]);
            if (is_wp_error($r)) break;
            $chunk = json_decode(wp_remote_retrieve_body($r), true);
            if (empty($chunk)) break;
            $items = array_merge($items, $chunk);
            $offset += count($chunk);
            if (count($chunk) < 1000) break;
        }
        return array_slice($items, 0, $limit);
    }

    private function flat($arr, $prefix = '') {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? $k : "$prefix.$k";
            if (is_array($v) && $v && array_keys($v) !== range(0, count($v) - 1))
                $out += $this->flat($v, $key);              // assoc -> recurse
            elseif (is_array($v))
                $out[$key] = implode(', ', array_map('strval', $v)); // list -> csv
            else
                $out[$key] = $v;
        }
        return $out;
    }

    private function map_row($item, $map) {
        $f = $this->flat($item);
        $row = [];
        foreach ($map as $target => $variants) {
            foreach ((array) $variants as $vk) {
                if (isset($f[$vk]) && $f[$vk] !== '' ) { $row[$target] = $f[$vk]; break; }
            }
            if (!isset($row[$target])) $row[$target] = '';
        }
        return $row;
    }

    private function dedup_key($row, $field) {
        $v = $row[$field] ?? '';
        return $v === '' ? null : sha1(strtolower(trim($v)));
    }

    private function find_by_key($key, $post_type) {
        $q = get_posts(['post_type' => $post_type, 'post_status' => 'any',
            'meta_key' => 'sai_dedup', 'meta_value' => $key, 'fields' => 'ids', 'posts_per_page' => 1]);
        return $q ? $q[0] : 0;
    }

    private function upsert($row, $existing, $o) {
        $post = ['post_type' => $o['post_type'], 'post_status' => $o['post_status'],
                 'post_title' => $row['post_title'] ?? '(без названия)',
                 'post_content' => $row['post_content'] ?? ''];
        if ($existing) { $post['ID'] = $existing; }
        $pid = wp_insert_post($post, true);
        if (is_wp_error($pid)) return 0;
        foreach ($row as $k => $v) {
            if (in_array($k, ['post_title','post_content','post_status'], true)) continue;
            update_post_meta($pid, sanitize_key($k), is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        update_post_meta($pid, 'sai_dedup', $this->dedup_key($row, $o['dedup']));
        update_post_meta($pid, 'sai_imported_at', current_time('mysql'));
        return $pid;
    }
}

new Selleye_Apify_Import();
