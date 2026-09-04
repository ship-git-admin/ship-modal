<?php

if (! defined('ABSPATH')) {
    exit;
}

final class Ship_Modal
{
    private static $instance;
    private $rendered_modal_ids = array();
    private $active_modal_ids_cache = null;
    private $stats_db_error = '';
    private $event_claim_db_error = '';

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'maybe_upgrade_event_claim_table'), 1);
        add_action('init', array($this, 'maybe_upgrade_stats_table'), 2);
        // 権限を先に準備してからCPTを登録し、編集者にも管理画面を表示できるようにする。
        add_action('init', array($this, 'ensure_admin_capabilities'), 2);
        add_action('init', array($this, 'register_post_type'), 10);
        add_action('admin_menu', array($this, 'hide_non_admin_menu'), 999);
        add_action('admin_menu', array($this, 'register_settings_page'), 20);
        add_action('admin_init', array($this, 'restrict_non_admin_access'));
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('save_post_ship_modal', array($this, 'save_modal'), 10, 2);
        add_filter('redirect_post_location', array($this, 'redirect_to_preview_after_save'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'render_validation_notice'));
        add_action('admin_notices', array($this, 'render_stats_reset_notice'));
        add_filter('manage_ship_modal_posts_columns', array($this, 'admin_columns'));
        add_action('manage_ship_modal_posts_custom_column', array($this, 'render_admin_column'), 10, 2);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
        add_action('wp_footer', array($this, 'render_sitewide_modals'), 30);
        add_shortcode('ship_modal', array($this, 'shortcode'));
        add_action('wp_ajax_ship_modal_event', array($this, 'record_event'));
        add_action('wp_ajax_nopriv_ship_modal_event', array($this, 'record_event'));
        add_action('wp_ajax_ship_modal_search_targets', array($this, 'search_targets'));
        add_action('admin_post_ship_modal_preview', array($this, 'preview'));
        add_action('admin_post_ship_modal_save_settings', array($this, 'save_settings'));
        add_action('admin_post_ship_modal_export_stats', array($this, 'export_stats'));
        add_action('admin_post_ship_modal_reset_stats', array($this, 'reset_stats'));
        add_action('before_delete_post', array($this, 'delete_modal_stats'));
        // Yoast Duplicate Postがコピーした計測値を、Ship Modalの複製後だけ初期化する。
        // 他の投稿タイプの複製には影響させない。
        add_action('duplicate_post_after_duplicated', array($this, 'reset_duplicated_modal_stats'), 100, 4);
        add_action('ship_modal_cleanup_event_claims', array($this, 'cleanup_event_claims'));
    }

    public static function activate()
    {
        $instance = self::instance();
        $instance->register_post_type();
        $instance->maybe_upgrade_event_claim_table();
        $instance->maybe_upgrade_stats_table();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook('ship_modal_cleanup_event_claims');
        flush_rewrite_rules();
    }

    /**
     * イベントIDの重複排除テーブルを作成・更新する。
     *
     * イベント単位のWordPress transientは、DB負荷と同時実行時の競合を
     * 避けられないため使用しない。claim_keyの一意キーを使ったINSERTで
     * 予約し、期限切れの行だけ同じSQL文で再利用する。
     */
    public function maybe_upgrade_event_claim_table()
    {
        global $wpdb;

        $table = $this->event_claim_table_name();
        $version = get_option('ship_modal_event_claim_db_version', '');
        if ('1.0' === $version && '1' === get_transient('ship_modal_event_claim_schema_checked')) {
            $this->schedule_event_claim_cleanup();
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            claim_key varchar(80) NOT NULL,
            claim_token char(64) NOT NULL,
            modal_id bigint(20) unsigned NOT NULL,
            event_name varchar(20) NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (claim_key),
            KEY expires_at (expires_at),
            KEY modal_id (modal_id)
        ) {$charset_collate};";
        dbDelta($sql);

        if ($this->event_claim_table_is_valid($table)) {
            update_option('ship_modal_event_claim_db_version', '1.0', false);
            set_transient('ship_modal_event_claim_schema_checked', '1', 12 * HOUR_IN_SECONDS);
            $this->event_claim_db_error = '';
            $this->schedule_event_claim_cleanup();
            return true;
        }

        delete_option('ship_modal_event_claim_db_version');
        delete_transient('ship_modal_event_claim_schema_checked');
        $this->event_claim_db_error = 'イベント重複排除テーブルを安全に準備できませんでした。';
        return false;
    }

    private function schedule_event_claim_cleanup()
    {
        if (! wp_next_scheduled('ship_modal_cleanup_event_claims')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ship_modal_cleanup_event_claims');
        }
    }

    private function event_claim_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'ship_modal_event_claims';
    }

    private function event_claim_table_is_valid($table)
    {
        global $wpdb;

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($table_exists !== $table || '' !== $wpdb->last_error) {
            return false;
        }
        $columns = array();
        foreach ((array) $wpdb->get_results("SHOW FULL COLUMNS FROM {$table}") as $column) {
            if (isset($column->Field)) {
                $columns[$column->Field] = $column;
            }
        }
        foreach (array('claim_key', 'claim_token', 'modal_id', 'event_name', 'expires_at') as $required_column) {
            if (! isset($columns[$required_column]) || 'NO' !== strtoupper((string) $columns[$required_column]->Null)) {
                return false;
            }
        }
        if (! preg_match('/^varchar\((\d+)\)/i', (string) $columns['claim_key']->Type, $claim_key_type) || (int) $claim_key_type[1] < 80) {
            return false;
        }
        if (! preg_match('/^char\((\d+)\)/i', (string) $columns['claim_token']->Type, $claim_token_type) || (int) $claim_token_type[1] < 64) {
            return false;
        }
        if (0 !== stripos((string) $columns['modal_id']->Type, 'bigint') || false === stripos((string) $columns['modal_id']->Type, 'unsigned')) {
            return false;
        }
        if (! preg_match('/^varchar\((\d+)\)/i', (string) $columns['event_name']->Type, $event_name_type) || (int) $event_name_type[1] < 20) {
            return false;
        }
        if ('datetime' !== strtolower((string) $columns['expires_at']->Type)) {
            return false;
        }

        $indexes = array();
        $index_uniqueness = array();
        foreach ((array) $wpdb->get_results("SHOW INDEX FROM {$table}") as $index) {
            if (! isset($index->Key_name, $index->Column_name, $index->Seq_in_index, $index->Non_unique)) {
                continue;
            }
            $indexes[$index->Key_name][(int) $index->Seq_in_index] = $index->Column_name;
            $index_uniqueness[$index->Key_name] = (int) $index->Non_unique;
        }
        foreach ($indexes as &$index_columns) {
            ksort($index_columns);
            $index_columns = array_values($index_columns);
        }
        unset($index_columns);
        if (! isset($indexes['PRIMARY'], $indexes['expires_at'], $indexes['modal_id'])) {
            return false;
        }
        return array('claim_key') === $indexes['PRIMARY']
            && 0 === $index_uniqueness['PRIMARY']
            && array('expires_at') === $indexes['expires_at']
            && 1 === $index_uniqueness['expires_at']
            && array('modal_id') === $indexes['modal_id']
            && 1 === $index_uniqueness['modal_id'];
    }

    /**
     * 期限切れのイベント予約を少量ずつ削除する。失敗しても計測処理は止めない。
     */
    public function cleanup_event_claims()
    {
        global $wpdb;

        if (! $this->maybe_upgrade_event_claim_table()) {
            return false;
        }
        return false !== $wpdb->query("DELETE FROM {$this->event_claim_table_name()} WHERE expires_at < UTC_TIMESTAMP() LIMIT 500");
    }

    /**
     * 日別のイベント集計テーブルを作成・更新する。
     *
     * 既存サイトでは再有効化されないままアップデートされることがあるため、
     * activation hookだけでなくinitでも一度だけ確認する。
     */
    public function maybe_upgrade_stats_table()
    {
        global $wpdb;

        $table = $this->stats_table_name();
        $version = get_option('ship_modal_stats_db_version', '');
        if ('1.1' === $version) {
            if ('1' === get_transient('ship_modal_stats_schema_checked')) {
                return true;
            }
            if ($this->stats_table_is_valid($table)) {
                set_transient('ship_modal_stats_schema_checked', '1', 12 * HOUR_IN_SECONDS);
                $this->stats_db_error = '';
                return true;
            }
            delete_option('ship_modal_stats_db_version');
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($table_exists === $table && ! $this->repair_stats_secondary_indexes($table, false)) {
            $this->stats_db_error = '日別集計テーブルの副インデックスを安全に修復できませんでした。';
            return false;
        }
        $sql = "CREATE TABLE {$table} (
            modal_id bigint(20) unsigned NOT NULL,
            stat_date date NOT NULL,
            event_name varchar(20) NOT NULL,
            event_count bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (modal_id,event_name,stat_date),
            KEY modal_date (modal_id,stat_date),
            KEY stat_date (stat_date)
        ) {$charset_collate};";
        dbDelta($sql);
        $indexes_repaired = $this->repair_stats_secondary_indexes($table);
        if ($indexes_repaired && $this->stats_table_is_valid($table) && $this->seed_counter_meta()) {
            update_option('ship_modal_stats_db_version', '1.1', false);
            set_transient('ship_modal_stats_schema_checked', '1', 12 * HOUR_IN_SECONDS);
            $this->stats_db_error = '';
            return true;
        } else {
            delete_option('ship_modal_stats_db_version');
            delete_transient('ship_modal_stats_schema_checked');
            $this->stats_db_error = '日別集計テーブルのスキーマを安全に準備できませんでした。';
            return false;
        }
    }

    /**
     * ON DUPLICATE KEY UPDATEが別イベントへ誤適用されないよう、
     * プラグイン所有テーブルの副インデックスだけを正規化する。
     * PRIMARY異常はデータを失う可能性があるため自動変更せず、検証側で停止する。
     */
    private function repair_stats_secondary_indexes($table, $add_missing = true)
    {
        global $wpdb;

        $rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
        if (! is_array($rows) || ! empty($wpdb->last_error)) {
            return false;
        }
        $indexes = array();
        $uniqueness = array();
        foreach ($rows as $row) {
            if (! isset($row->Key_name, $row->Column_name, $row->Seq_in_index, $row->Non_unique)) {
                continue;
            }
            $indexes[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
            $uniqueness[$row->Key_name] = (int) $row->Non_unique;
        }
        foreach ($indexes as &$columns) {
            ksort($columns);
            $columns = array_values($columns);
        }
        unset($columns);

        $required = array(
            'modal_date' => array('modal_id', 'stat_date'),
            'stat_date' => array('stat_date'),
        );
        foreach ($indexes as $name => $columns) {
            if ('PRIMARY' === $name) {
                continue;
            }
            $is_required_valid = isset($required[$name]) && $required[$name] === $columns && isset($uniqueness[$name]) && 1 === $uniqueness[$name];
            $is_unsafe_unique = isset($uniqueness[$name]) && 0 === $uniqueness[$name];
            if (! $is_required_valid && (isset($required[$name]) || $is_unsafe_unique)) {
                $index_name = '`' . str_replace('`', '``', $name) . '`';
                if (false === $wpdb->query("ALTER TABLE {$table} DROP INDEX {$index_name}")) {
                    return false;
                }
                unset($indexes[$name], $uniqueness[$name]);
            }
        }

        if (! $add_missing) {
            return true;
        }
        foreach ($required as $name => $columns) {
            if (isset($indexes[$name]) && $columns === $indexes[$name] && isset($uniqueness[$name]) && 1 === $uniqueness[$name]) {
                continue;
            }
            $definition = 'modal_date' === $name ? 'KEY modal_date (modal_id,stat_date)' : 'KEY stat_date (stat_date)';
            if (false === $wpdb->query("ALTER TABLE {$table} ADD {$definition}")) {
                return false;
            }
        }
        return true;
    }

    private function stats_table_is_valid($table)
    {
        global $wpdb;

        $column_rows = $wpdb->get_results("SHOW FULL COLUMNS FROM {$table}");
        $columns = array();
        foreach ((array) $column_rows as $column) {
            if (isset($column->Field)) {
                $columns[$column->Field] = $column;
            }
        }
        foreach (array('modal_id', 'stat_date', 'event_name', 'event_count') as $required_column) {
            if (! isset($columns[$required_column]) || 'NO' !== strtoupper((string) $columns[$required_column]->Null)) {
                return false;
            }
        }
        if (0 !== stripos((string) $columns['modal_id']->Type, 'bigint') || false === stripos((string) $columns['modal_id']->Type, 'unsigned')) {
            return false;
        }
        if ('date' !== strtolower((string) $columns['stat_date']->Type)) {
            return false;
        }
        if (! preg_match('/^varchar\((\d+)\)/i', (string) $columns['event_name']->Type, $event_type) || (int) $event_type[1] < 20) {
            return false;
        }
        if (0 !== stripos((string) $columns['event_count']->Type, 'bigint') || false === stripos((string) $columns['event_count']->Type, 'unsigned') || '0' !== (string) $columns['event_count']->Default) {
            return false;
        }

        $index_rows = $wpdb->get_results("SHOW INDEX FROM {$table}");
        $indexes = array();
        $index_uniqueness = array();
        foreach ((array) $index_rows as $index) {
            if (! isset($index->Key_name, $index->Column_name, $index->Seq_in_index, $index->Non_unique)) {
                continue;
            }
            $indexes[$index->Key_name][(int) $index->Seq_in_index] = $index->Column_name;
            $index_uniqueness[$index->Key_name] = (int) $index->Non_unique;
        }
        foreach ($indexes as &$index_columns) {
            ksort($index_columns);
            $index_columns = array_values($index_columns);
        }
        unset($index_columns);
        if (! isset($indexes['PRIMARY'], $indexes['modal_date'], $indexes['stat_date'])) {
            return false;
        }
        if (array('modal_id', 'event_name', 'stat_date') !== $indexes['PRIMARY'] || array('modal_id', 'stat_date') !== $indexes['modal_date'] || array('stat_date') !== $indexes['stat_date']) {
            return false;
        }
        if (0 !== $index_uniqueness['PRIMARY'] || 1 !== $index_uniqueness['modal_date'] || 1 !== $index_uniqueness['stat_date']) {
            return false;
        }
        foreach ($index_uniqueness as $name => $non_unique) {
            if ('PRIMARY' !== $name && 0 === $non_unique) {
                return false;
            }
        }

        return true;
    }

    private function stats_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'ship_modal_stats';
    }

    private function stats_event_labels()
    {
        return array(
            'impression' => '表示',
            'click' => 'クリック',
            'close' => '閉じる',
            'page_view' => 'ページャー閲覧',
        );
    }

    private function counter_meta_keys()
    {
        return array(
            'impression' => '_ship_modal_impressions',
            'click' => '_ship_modal_clicks',
            'close' => '_ship_modal_closes',
            'page_view' => '_ship_modal_page_views',
        );
    }

    private function ensure_counter_meta($post_id)
    {
        foreach ($this->counter_meta_keys() as $counter_key) {
            if (! metadata_exists('post', $post_id, $counter_key) && ! add_post_meta($post_id, $counter_key, 0, true) && ! metadata_exists('post', $post_id, $counter_key)) {
                return false;
            }
        }
        return true;
    }

    private function seed_counter_meta()
    {
        global $wpdb;

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            'ship_modal'
        ));
        foreach ((array) $post_ids as $post_id) {
            if (! $this->ensure_counter_meta(absint($post_id))) {
                return false;
            }
        }
        return true;
    }

    private function stats_date($value, $fallback)
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];
            if (checkdate($month, $day, $year)) {
                return $value;
            }
        }

        return $fallback;
    }

    private function get_daily_stats($post_id, $from = '', $to = '')
    {
        global $wpdb;

        if (! $this->maybe_upgrade_stats_table()) {
            return array();
        }
        $today = current_time('Y-m-d');
        $from = $this->stats_date($from, '');
        $to = $this->stats_date($to, '');
        $where = 'modal_id = %d';
        $params = array(absint($post_id));
        if ($from !== '') {
            $where .= ' AND stat_date >= %s';
            $params[] = $from;
        }
        if ($to !== '') {
            $where .= ' AND stat_date <= %s';
            $params[] = $to;
        }
        if ($to === '') {
            // 終了日の指定がない場合も、未来日が混ざらないようにする。
            $where .= ' AND stat_date <= %s';
            $params[] = $today;
        }

        $query = "SELECT stat_date, event_name, event_count FROM {$this->stats_table_name()} WHERE {$where} ORDER BY stat_date DESC, event_name ASC";
        $prepared_query = $wpdb->prepare($query, $params);
        $rows = $wpdb->get_results($prepared_query);
        if ('' === $wpdb->last_error) {
            return $rows;
        }

        // テーブルが外部操作などで失われた場合は、その場で一度だけ再作成して読み直す。
        delete_option('ship_modal_stats_db_version');
        delete_transient('ship_modal_stats_schema_checked');
        if (! $this->maybe_upgrade_stats_table()) {
            return array();
        }
        $rows = $wpdb->get_results($prepared_query);
        if ('' !== $wpdb->last_error || ! is_array($rows)) {
            $this->stats_db_error = $wpdb->last_error ?: '日別集計テーブルを読み込めませんでした。';
            return array();
        }
        return $rows;
    }

    public function register_post_type()
    {
        register_post_type('ship_modal', array(
            'labels' => array(
                'name' => 'モーダル',
                'singular_name' => 'モーダル',
                'add_new' => '新規追加',
                'add_new_item' => 'モーダルを追加',
                'edit_item' => 'モーダルを編集',
                'new_item' => '新しいモーダル',
                'view_item' => 'モーダルを表示',
                'search_items' => 'モーダルを検索',
                'not_found' => 'モーダルが見つかりません',
                'menu_name' => 'モーダル',
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => $this->can_manage_modal(),
            'show_in_menu' => $this->can_manage_modal(),
            'menu_icon' => 'dashicons-welcome-view-site',
            'supports' => array('title'),
            'capability_type' => array('ship_modal', 'ship_modals'),
            'capabilities' => array(
                'edit_post' => 'edit_ship_modal',
                'read_post' => 'read_ship_modal',
                'delete_post' => 'delete_ship_modal',
                'edit_posts' => 'edit_ship_modals',
                'edit_others_posts' => 'edit_others_ship_modals',
                'publish_posts' => 'publish_ship_modals',
                'read_private_posts' => 'read_private_ship_modals',
                'delete_posts' => 'delete_ship_modals',
                'delete_private_posts' => 'delete_private_ship_modals',
                'delete_published_posts' => 'delete_published_ship_modals',
                'delete_others_posts' => 'delete_others_ship_modals',
                'edit_private_posts' => 'edit_private_ship_modals',
                'edit_published_posts' => 'edit_published_ship_modals',
                'create_posts' => 'create_ship_modals',
            ),
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
        ));
    }

    public function ensure_admin_capabilities()
    {
        if ('3' === get_option('ship_modal_capabilities_version')) {
            return;
        }
        $capabilities = array(
            'edit_ship_modal', 'read_ship_modal', 'delete_ship_modal',
            'edit_ship_modals', 'edit_others_ship_modals', 'publish_ship_modals',
            'read_private_ship_modals', 'delete_ship_modals', 'delete_private_ship_modals',
            'delete_published_ship_modals', 'delete_others_ship_modals',
            'edit_private_ship_modals', 'edit_published_ship_modals', 'create_ship_modals',
        );
        foreach (wp_roles()->roles as $role_name => $role_data) {
            $role = get_role($role_name);
            if (! $role) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if (in_array($role_name, array('administrator', 'editor'), true)) {
                    $role->add_cap($capability);
                } else {
                    $role->remove_cap($capability);
                }
            }
        }
        update_option('ship_modal_capabilities_version', '3', false);
    }

    private function can_manage_modal()
    {
        $user = wp_get_current_user();
        return is_super_admin() || ($user && current_user_can('edit_ship_modals'));
    }

    /**
     * 内容入力UIを画像専用にする運用フラグ。
     *
     * 既存のHTML・ページャーなどの保存データはこのフラグでは削除せず、
     * falseへ戻した時に再編集できるよう保持する。サイト固有の再開は
     * wp-config.phpでSHIP_MODAL_IMAGE_ONLY_MODEをfalseにするか、
     * ship_modal_image_only_modeフィルターでfalseを返して行う。
     */
    private function is_image_only_mode()
    {
        $default = defined('SHIP_MODAL_IMAGE_ONLY_MODE') ? SHIP_MODAL_IMAGE_ONLY_MODE : true;
        return (bool) apply_filters('ship_modal_image_only_mode', (bool) $default);
    }

    /**
     * フルスクリーンレイアウトの管理画面での選択を許可するか返す。
     * 保存済みのレイアウト値と公開表示は、停止中も変更しない。
     */
    private function is_fullscreen_enabled()
    {
        $default = defined('SHIP_MODAL_ENABLE_FULLSCREEN') ? SHIP_MODAL_ENABLE_FULLSCREEN : false;
        return (bool) apply_filters('ship_modal_enable_fullscreen', (bool) $default);
    }

    /**
     * 投稿・固定ページ（全て）表示範囲の管理画面での選択を許可するか返す。
     * 保存済みの表示範囲と公開表示は、停止中も変更しない。
     */
    private function is_singular_scope_enabled()
    {
        $default = defined('SHIP_MODAL_ENABLE_SINGULAR_SCOPE') ? SHIP_MODAL_ENABLE_SINGULAR_SCOPE : false;
        return (bool) apply_filters('ship_modal_enable_singular_scope', (bool) $default);
    }

    public function hide_non_admin_menu()
    {
        if (! $this->can_manage_modal()) {
            remove_menu_page('edit.php?post_type=ship_modal');
        }
    }

    /**
     * GA4連携のサイト共通設定を返す。
     *
     * 測定IDは公開情報だが、送信方式は二重計測に関わるため、
     * 管理画面から保存した値だけをフロントへ渡す。
     */
    private function ga4_settings()
    {
        $defaults = array(
            'measurement_id' => '',
            'transport' => 'auto',
        );
        $stored = get_option('ship_modal_ga4_settings', array());
        if (! is_array($stored)) {
            $stored = array();
        }
        $measurement_id = isset($stored['measurement_id']) && is_scalar($stored['measurement_id']) ? strtoupper(trim((string) $stored['measurement_id'])) : '';
        if (! preg_match('/^G-[A-Z0-9]+$/', $measurement_id)) {
            $measurement_id = '';
        }
        $transport = isset($stored['transport']) ? sanitize_key($stored['transport']) : $defaults['transport'];
        if (! in_array($transport, array('auto', 'direct', 'datalayer'), true)) {
            $transport = $defaults['transport'];
        }
        return array(
            'measurement_id' => $measurement_id,
            'transport' => $transport,
        );
    }

    private function sanitize_ga4_measurement_id($value)
    {
        $value = strtoupper(trim(sanitize_text_field((string) $value)));
        return preg_match('/^G-[A-Z0-9]+$/', $value) ? $value : '';
    }

    public function register_settings_page()
    {
        if (! $this->can_manage_modal()) {
            return;
        }
        add_submenu_page(
            'edit.php?post_type=ship_modal',
            '計測・GA4連携設定',
            '計測・GA4連携設定',
            'edit_ship_modals',
            'ship-modal-settings',
            array($this, 'render_settings_page')
        );
    }

    public function save_settings()
    {
        if (! $this->can_manage_modal()) {
            wp_die('モーダル設定を操作する権限がありません。', 'Ship Modal', array('response' => 403));
        }
        check_admin_referer('ship_modal_save_settings');

        $raw_measurement_id = isset($_POST['ship_modal_ga4_measurement_id']) && is_scalar($_POST['ship_modal_ga4_measurement_id'])
            ? wp_unslash($_POST['ship_modal_ga4_measurement_id'])
            : '';
        $measurement_id = $this->sanitize_ga4_measurement_id($raw_measurement_id);
        $transport = isset($_POST['ship_modal_ga4_transport'])
            ? sanitize_key(wp_unslash($_POST['ship_modal_ga4_transport']))
            : 'auto';
        if (! in_array($transport, array('auto', 'direct', 'datalayer'), true)) {
            $transport = 'auto';
        }
        update_option('ship_modal_ga4_settings', array(
            'measurement_id' => $measurement_id,
            'transport' => $transport,
        ), false);

        $args = array(
            'post_type' => 'ship_modal',
            'page' => 'ship-modal-settings',
            'ship_modal_settings_saved' => '1',
        );
        if (trim((string) $raw_measurement_id) !== '' && '' === $measurement_id) {
            $args['ship_modal_settings_error'] = 'measurement_id';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('edit.php')));
        exit;
    }

    public function render_settings_page()
    {
        if (! $this->can_manage_modal()) {
            wp_die('モーダル設定を操作する権限がありません。', 'Ship Modal', array('response' => 403));
        }
        $settings = $this->ga4_settings();
        $image_only_mode = $this->is_image_only_mode();
        $saved = isset($_GET['ship_modal_settings_saved']) && '1' === sanitize_text_field(wp_unslash($_GET['ship_modal_settings_saved']));
        $invalid_measurement_id = isset($_GET['ship_modal_settings_error']) && 'measurement_id' === sanitize_key(wp_unslash($_GET['ship_modal_settings_error']));
        ?>
        <div class="wrap ship-modal-settings-page">
            <h1>計測・GA4連携設定</h1>
            <?php if ($saved) : ?><div class="notice notice-success is-dismissible"><p>計測設定を保存しました。</p></div><?php endif; ?>
            <?php if ($invalid_measurement_id) : ?><div class="notice notice-warning"><p>GA4測定IDの形式が正しくないため、空欄として保存しました。<code>G-XXXXXXXXXX</code> の形式で入力してください。</p></div><?php endif; ?>
            <div class="notice notice-info inline ship-modal-settings-intro">
                <p><strong>サイトごとに一度だけ設定してください。</strong></p>
                <p>GTMでモーダル用のカスタムイベントタグを作らなくても、既存のGA4へ直接イベントを送信できます。測定IDが空欄で、ページ上に既存の <code>gtag()</code> がない場合は、<code>dataLayer</code> へ出力します。Cookie同意管理と連動する場合は、初期値を <code>ship_modal_ga4_enabled</code> フィルターで返し、同意が変わった時は公開ページ上で <code>window.ShipModalConsent.setAnalyticsConsent(true)</code> / <code>false</code> を呼び出してください。falseの間はGoogleタグとdataLayerの両方へ送信しません。</p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ship_modal_save_settings">
                <?php wp_nonce_field('ship_modal_save_settings'); ?>
                <table class="form-table ship-modal-settings-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ship-modal-ga4-measurement-id">GA4測定ID</label></th>
                        <td>
                            <input type="text" class="regular-text code" name="ship_modal_ga4_measurement_id" id="ship-modal-ga4-measurement-id" value="<?php echo esc_attr($settings['measurement_id']); ?>" placeholder="G-XXXXXXXXXX" pattern="G-[A-Za-z0-9]+" autocomplete="off">
                            <p class="description">GA4管理画面の「データストリーム」にある測定ID（<code>G-</code>から始まる値）を入力します。測定IDはパスワードではありませんが、サイト内で公開される値です。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ship-modal-ga4-transport">送信方式</label></th>
                        <td>
                            <select name="ship_modal_ga4_transport" id="ship-modal-ga4-transport">
                                <option value="auto" <?php selected($settings['transport'], 'auto'); ?>>自動（推奨）</option>
                                <option value="direct" <?php selected($settings['transport'], 'direct'); ?>>GA4へ直接送信</option>
                                <option value="datalayer" <?php selected($settings['transport'], 'datalayer'); ?>>GTMのdataLayerのみ</option>
                            </select>
                            <div class="ship-modal-settings-choice-list">
                                <p><strong>自動（推奨）</strong>：既存の <code>gtag()</code> があれば直接送信し、なければ測定IDからGoogleタグを読み込みます。どちらも使えない場合はdataLayerへ切り替えます。</p>
                                <p><strong>GA4へ直接送信</strong>：測定IDまたは既存の <code>gtag()</code> を使って送信します。GTM側で同じイベントを転送している場合は二重計測に注意してください。</p>
                                <p><strong>GTMのdataLayerのみ</strong>：現在の方式です。GTM側でGA4イベントタグを設定している場合に選択します。</p>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php submit_button('計測設定を保存'); ?>
            </form>
            <div class="card ship-modal-settings-card">
                <h2>送信されるイベント</h2>
                <p><code>ship_modal_impression</code>（表示）、<code>ship_modal_click</code>（モーダル内リンク）、<code>ship_modal_close</code>（閉じる）<?php if (! $image_only_mode) : ?>、<code>ship_modal_page_view</code>（ページャー閲覧）<?php endif; ?>を送信します。</p>
                <p class="description">プレビュー中はGA4・dataLayer・プラグイン内部の計測を行いません。保存後、公開ページで確認してください。</p>
            </div>
        </div>
        <?php
    }

    public function restrict_non_admin_access()
    {
        if ($this->can_manage_modal()) {
            return;
        }
        $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ('ship_modal' === $post_type || ($post_id && 'ship_modal' === get_post_type($post_id))) {
            wp_die('モーダルを操作する権限がありません。', 'Ship Modal', array('response' => 403));
        }
    }

    public function register_meta_boxes()
    {
        add_meta_box('ship_modal_content', 'モーダルの内容', array($this, 'render_content_box'), 'ship_modal', 'normal', 'high');
        add_meta_box('ship_modal_display', '表示設定', array($this, 'render_display_box'), 'ship_modal', 'normal', 'high');
        add_meta_box('ship_modal_stats', '計測', array($this, 'render_stats_box'), 'ship_modal', 'side', 'default');
    }

    private function meta($post_id, $key, $default = '')
    {
        $value = get_post_meta($post_id, '_ship_modal_' . $key, true);
        return $value === '' ? $default : $value;
    }

    /**
     * admin-post.php用のURLを未エスケープのまま組み立てる。
     *
     * wp_nonce_url()の返り値はHTMLエスケープ済みなので、リダイレクトや
     * add_query_arg()の入力へ再利用すると「amp;post_id」のような壊れた
     * クエリ名になる。HTMLへ出力する箇所でのみesc_url()/esc_attr()する。
     */
    private function admin_action_url($action, $post_id, $nonce_action)
    {
        return add_query_arg(array(
            'action' => sanitize_key($action),
            'post_id' => absint($post_id),
            '_wpnonce' => wp_create_nonce($nonce_action),
        ), admin_url('admin-post.php'));
    }

    private function sanitize_custom_css($css)
    {
        $css = str_replace("\0", '', (string) $css);
        $css = preg_replace('/<\s*\/?\s*(?:style|script)[^>]*>/i', '', $css);
        $css = preg_replace('/@import\s+/i', '/* @import removed */ ', $css);
        $css = preg_replace('/expression\s*\(/i', '/* expression removed */(', $css);
        $css = preg_replace('/url\s*\(\s*([\'\"]?)\s*javascript:/i', 'url($1', $css);
        return trim((string) $css);
    }

    private function theme_color_defaults()
    {
        return array(
            'surface' => '#ffffff',
            'accent' => '#f97316',
            'text' => '#334155',
        );
    }

    private function theme_colors($post_id)
    {
        $colors = array();
        foreach ($this->theme_color_defaults() as $key => $default) {
            // モーダル本体の背景は視認性・可読性確保のため白固定。
            if ('surface' === $key) {
                $colors[$key] = '#ffffff';
                continue;
            }
            $colors[$key] = sanitize_hex_color($this->meta($post_id, 'theme_' . $key, $default)) ?: $default;
        }
        return $colors;
    }

    private function select($name, $value, $options)
    {
        echo '<select name="ship_modal_' . esc_attr($name) . '" id="ship-modal-' . esc_attr($name) . '" class="widefat">';
        foreach ($options as $option_value => $label) {
            $option_label = is_array($label) ? (isset($label['label']) ? $label['label'] : '') : $label;
            $disabled = is_array($label) && ! empty($label['disabled']) ? ' disabled' : '';
            echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, $option_value, false) . $disabled . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select>';
    }

    private function targetable_post_types()
    {
        $types = get_post_types(array('public' => true), 'objects');
        foreach (array('attachment', 'ship_modal') as $excluded_type) {
            unset($types[$excluded_type]);
        }
        return $types;
    }

    public function search_targets()
    {
        check_ajax_referer('ship_modal_target_search', 'nonce');
        $modal_post_id = isset($_POST['modal_post_id']) ? absint($_POST['modal_post_id']) : 0;
        if (! $this->can_manage_modal() || ($modal_post_id && ! current_user_can('edit_post', $modal_post_id))) {
            wp_send_json_error(array('message' => '権限がありません。'), 403);
        }
        $search = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $search_length = function_exists('mb_strlen')
            ? mb_strlen($search, 'UTF-8')
            : preg_match_all('/./us', $search, $unused_matches);
        if ($search !== '' && $search_length < 2) {
            wp_send_json_success(array());
        }
        $types = $this->targetable_post_types();
        $requested_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
        $post_types = $requested_type && isset($types[$requested_type]) ? array($requested_type) : array_keys($types);
        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 30,
            's' => $search,
            'orderby' => $search !== '' ? 'relevance' : 'date',
            'order' => 'DESC',
        ));
        $results = array();
        foreach ($posts as $target_post) {
            $type = get_post_type($target_post);
            $results[] = array(
                'id' => (int) $target_post->ID,
                'title' => get_the_title($target_post),
                'type' => isset($types[$type]) ? $types[$type]->labels->singular_name : $type,
            );
        }
        wp_send_json_success($results);
    }

    private function render_page_row($index, $page = array())
    {
        $image_id = isset($page['image_id']) ? absint($page['image_id']) : 0;
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'medium') : '';
        $heading = isset($page['heading']) ? $page['heading'] : '';
        $body = isset($page['body']) ? $page['body'] : (isset($page['html']) ? wp_strip_all_tags($page['html']) : '');
        $link_url = isset($page['link_url']) ? $page['link_url'] : '';
        $link_new_tab = ! empty($page['link_new_tab']);
        $buttons = isset($page['buttons']) && is_array($page['buttons']) ? $page['buttons'] : array();
        ?>
        <div class="ship-modal-page-row" data-page-index="<?php echo esc_attr($index); ?>">
            <div class="ship-modal-page-row__header"><strong>ページ <?php echo esc_html(is_numeric($index) ? ((int) $index + 1) : '__NUMBER__'); ?></strong><button type="button" class="button-link-delete ship-modal-remove-page">このページを削除</button></div>
            <div class="ship-modal-page-row__grid">
                <div>
                    <input type="hidden" name="ship_modal_pages[<?php echo esc_attr($index); ?>][image_id]" id="ship-modal-page-image-<?php echo esc_attr($index); ?>" value="<?php echo esc_attr($image_id); ?>">
                    <div id="ship-modal-page-preview-<?php echo esc_attr($index); ?>" class="ship-modal-page-preview"><?php if ($image_url) : ?><img src="<?php echo esc_url($image_url); ?>" alt=""><?php endif; ?></div>
                    <button type="button" class="button ship-modal-page-select-image" data-target-id="ship-modal-page-image-<?php echo esc_attr($index); ?>" data-target-preview="ship-modal-page-preview-<?php echo esc_attr($index); ?>">画像を選択</button>
                    <button type="button" class="button ship-modal-page-remove-image" data-target-id="ship-modal-page-image-<?php echo esc_attr($index); ?>" data-target-preview="ship-modal-page-preview-<?php echo esc_attr($index); ?>">画像を解除</button>
                </div>
                <div>
                    <label>ページ見出し</label>
                    <input type="text" class="widefat" name="ship_modal_pages[<?php echo esc_attr($index); ?>][heading]" value="<?php echo esc_attr($heading); ?>">
                    <label>ページ本文</label>
                    <textarea class="large-text" rows="4" name="ship_modal_pages[<?php echo esc_attr($index); ?>][body]"><?php echo esc_textarea($body); ?></textarea>
                    <label>画像クリック先URL</label>
                    <input type="url" class="widefat" name="ship_modal_pages[<?php echo esc_attr($index); ?>][link_url]" value="<?php echo esc_attr($link_url); ?>" placeholder="https://example.com/">
                    <label><input type="checkbox" name="ship_modal_pages[<?php echo esc_attr($index); ?>][link_new_tab]" value="1" <?php checked($link_new_tab, true); ?>> 別タブで開く</label>
                    <strong class="ship-modal-admin-subheading">ボタン（最大2個）</strong>
                    <?php $this->render_button_fields($buttons, 2, 'ship_modal_pages[' . $index . '][buttons]'); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_button_fields($buttons, $max, $prefix)
    {
        $buttons = is_array($buttons) ? array_values($buttons) : array();
        $label_limits = $this->button_label_limits();
        echo '<p class="description ship-modal-button-help">1行' . esc_html($label_limits['chars_per_line']) . '文字・最大' . esc_html($label_limits['lines']) . '行まで。改行は <code>&lt;br&gt;</code> を入力してください。「閉じる」を選んだボタンはURL不要です。超過分は保存時に自動調整されます。</p>';
        for ($index = 0; $index < $max; $index++) {
            $button = isset($buttons[$index]) && is_array($buttons[$index]) ? $buttons[$index] : array();
            $label = isset($button['label']) ? $button['label'] : '';
            $url = isset($button['url']) ? $button['url'] : '';
            $action = isset($button['action']) && 'close' === $button['action'] ? 'close' : 'link';
            $style = isset($button['style']) && in_array($button['style'], array('primary', 'secondary'), true) ? $button['style'] : 'primary';
            $new_tab = ! empty($button['new_tab']);
            $base = $prefix . '[' . $index . ']';
            ?>
            <div class="ship-modal-button-field">
                <span class="ship-modal-button-field__number"><?php echo esc_html((string) ($index + 1)); ?></span>
                <div class="ship-modal-button-label-wrap">
                    <input type="text" class="ship-modal-button-label" name="<?php echo esc_attr($base . '[label]'); ?>" value="<?php echo esc_attr($label); ?>" placeholder="ボタン文言（改行は&lt;br&gt;）" data-max-lines="<?php echo esc_attr($label_limits['lines']); ?>" data-max-chars-per-line="<?php echo esc_attr($label_limits['chars_per_line']); ?>">
                    <span class="ship-modal-button-label-meta" aria-live="polite"></span>
                </div>
                <input type="url" class="ship-modal-button-url" name="<?php echo esc_attr($base . '[url]'); ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/">
                <select class="ship-modal-button-action" name="<?php echo esc_attr($base . '[action]'); ?>"><option value="link" <?php selected($action, 'link'); ?>>リンク</option><option value="close" <?php selected($action, 'close'); ?>>閉じる</option></select>
                <select name="<?php echo esc_attr($base . '[style]'); ?>"><option value="primary" <?php selected($style, 'primary'); ?>>メイン</option><option value="secondary" <?php selected($style, 'secondary'); ?>>サブ</option></select>
                <label><input type="checkbox" name="<?php echo esc_attr($base . '[new_tab]'); ?>" value="1" <?php checked($new_tab, true); ?>> 別タブ</label>
            </div>
            <?php
        }
    }

    private function button_label_limits()
    {
        $limits = apply_filters('ship_modal_button_label_limits', array(
            'lines' => 2,
            'chars_per_line' => 16,
        ));
        $lines = isset($limits['lines']) ? absint($limits['lines']) : 2;
        $chars_per_line = isset($limits['chars_per_line']) ? absint($limits['chars_per_line']) : 16;
        return array(
            'lines' => max(1, min(3, $lines)),
            'chars_per_line' => max(4, min(30, $chars_per_line)),
        );
    }

    private function truncate_text_chars($text, $limit)
    {
        $chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($chars)) {
            return '';
        }
        return implode('', array_slice($chars, 0, absint($limit)));
    }

    private function normalize_button_label($raw_label)
    {
        $label = wp_kses((string) $raw_label, array('br' => array()));
        $label = preg_replace('/\s*<br\s*\/?>\s*/i', '<br>', trim($label));
        $lines = preg_split('/<br\s*\/?>/i', $label);
        $limits = $this->button_label_limits();
        if (! is_array($lines)) {
            $lines = array($label);
        }
        $lines = array_slice($lines, 0, $limits['lines']);
        $normalized_lines = array();
        foreach ($lines as $line) {
            $line = trim(wp_strip_all_tags($line));
            $normalized_lines[] = $this->truncate_text_chars($line, $limits['chars_per_line']);
        }
        return implode('<br>', $normalized_lines);
    }

    public function render_content_box($post)
    {
        wp_nonce_field('ship_modal_save', 'ship_modal_nonce');
        $image_only_mode = $this->is_image_only_mode();
        $allowed_types = array('html', 'image', 'hybrid', 'text', 'pager');
        $stored_type = $this->meta($post->ID, 'content_type', 'image');
        $stored_type = in_array($stored_type, $allowed_types, true) ? $stored_type : 'image';
        $type = $stored_type;
        $design = $this->meta($post->ID, 'design', 'center');
        $html = $this->meta($post->ID, 'html');
        $custom_css = $this->sanitize_custom_css($this->meta($post->ID, 'custom_css', ''));
        $image_id = absint($this->meta($post->ID, 'image_id'));
        $mobile_image_id = absint($this->meta($post->ID, 'image_id_mobile'));
        $image_alt = $this->meta($post->ID, 'image_alt', '');
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
        $link_url = $this->meta($post->ID, 'link_url');
        $link_new_tab = '1' === $this->meta($post->ID, 'link_new_tab', '0');
        $image_position = $this->meta($post->ID, 'image_position', 'top');
        $heading = $this->meta($post->ID, 'heading');
        $body = $this->meta($post->ID, 'body', '');
        if ($body === '') {
            $body = wp_strip_all_tags($html);
        }
        $buttons = $this->meta($post->ID, 'buttons', array());
        $pages = $this->meta($post->ID, 'pages', array());
        $form_state_key = 'ship_modal_form_' . get_current_user_id() . '_' . $post->ID;
        $form_state = get_transient($form_state_key);
        if (! is_array($form_state)) {
            $form_state = get_post_meta($post->ID, '_ship_modal_form_state_' . get_current_user_id(), true);
            if (is_array($form_state)) {
                delete_post_meta($post->ID, '_ship_modal_form_state_' . get_current_user_id());
            }
        }
        if (is_array($form_state)) {
            delete_transient($form_state_key);
            $type = isset($form_state['type']) ? $form_state['type'] : $type;
            $html = isset($form_state['html']) ? $form_state['html'] : $html;
            $heading = isset($form_state['heading']) ? $form_state['heading'] : $heading;
            $body = isset($form_state['body']) ? $form_state['body'] : $body;
            $image_id = isset($form_state['image_id']) ? absint($form_state['image_id']) : $image_id;
            $mobile_image_id = isset($form_state['mobile_image_id']) ? absint($form_state['mobile_image_id']) : $mobile_image_id;
            $image_alt = isset($form_state['image_alt']) ? $form_state['image_alt'] : $image_alt;
            $link_url = isset($form_state['link_url']) ? $form_state['link_url'] : $link_url;
            $buttons = isset($form_state['buttons']) && is_array($form_state['buttons']) ? $form_state['buttons'] : $buttons;
            $pages = isset($form_state['pages']) && is_array($form_state['pages']) ? $form_state['pages'] : $pages;
            $custom_css = isset($form_state['custom_css']) ? $this->sanitize_custom_css($form_state['custom_css']) : $custom_css;
        }
        // 画像専用モード中は、保存済みの内容形式を変更できないようにする。
        // 既存のHTML系データをフォーム送信で上書きしないため、画面状態も固定する。
        if ($image_only_mode) {
            $type = $stored_type;
        }
        if (! is_array($pages) || ! $pages) {
            $pages = array(array());
        }
        $border_radius = min(48, max(0, (int) $this->meta($post->ID, 'border_radius', 0)));
        $padding = min(64, max(0, (int) $this->meta($post->ID, 'padding', 20)));
        $max_width = min(1200, max(280, (int) $this->meta($post->ID, 'max_width', 620)));
        $preview_url = $this->admin_action_url(
            'ship_modal_preview',
            $post->ID,
            'ship_modal_preview_' . absint($post->ID)
        );
        $image_only_guide = in_array($type, array('image', 'hybrid'), true)
            ? '基本画像を1枚設定します。スマホ用画像が必要な場合だけ追加できます。'
            : '既存の内容形式は変更せず、そのまま公開表示します。';
        $fullscreen_enabled = $this->is_fullscreen_enabled();
        $design_options = array(
            'center' => '中央カード',
            'bottom' => '画面下部バナー',
            'side' => '右下ポップアップ',
        );
        if ($fullscreen_enabled) {
            $design_options['fullscreen'] = 'フルスクリーン';
        } elseif ('fullscreen' === $design) {
            // 既存設定は保持し、停止中であることだけを表示する。選択して再設定することはできない。
            $design_options['fullscreen'] = array('label' => 'フルスクリーン（新規選択停止）', 'disabled' => true);
        }
        ?>
        <?php if (! $image_only_mode) : ?><p class="description">HTML、画像バナー、画像＋HTML、複数ページのページャーから選べます。ページャーは各ページに画像とHTMLを設定できます。</p><?php endif; ?>
        <div class="notice notice-info inline ship-modal-admin-guide">
            <p><strong>使い方ガイド</strong></p>
            <ol>
                <li><?php echo $image_only_mode ? $image_only_guide : '「フレーム」で内容の形式を選び、画像・見出し・本文・ボタンを入力します。'; ?></li>
                <li>下の「表示設定」で表示対象、起動方法、表示期間、閉じる操作を設定します。</li>
                <li>「更新」を押したあと、対象ページを実際に開いてPC・スマホの表示を確認してください。</li>
            </ol>
            <p><strong>注意：</strong>管理画面のプレビューだけではなく、保存後の公開ページで画像の大きさ・角丸・ボタン・表示タイミングを必ず確認してください。</p>
        </div>
        <?php if ('publish' !== $post->post_status) : ?><div class="notice notice-warning inline ship-modal-status-warning"><p><strong>現在は公開状態ではありません。</strong>このモーダルは公開ページには表示されません。まず下書きとして保存し、確認後に右上の「公開」または「更新」で公開してください。</p></div><?php endif; ?>
        <?php if ($post->ID) : ?><div class="ship-modal-preview-bar"><input type="hidden" name="ship_modal_preview_post_id" value="<?php echo absint($post->ID); ?>"><?php if ('auto-draft' !== $post->post_status) : ?><a class="button" href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">保存済み内容をプレビュー</a><?php endif; ?><button type="submit" name="ship_modal_preview_after_save" value="1" class="button button-primary">更新してプレビュー</button><span>編集中の内容を保存してから、プレビュー画面を開きます。</span></div><?php endif; ?>
        <?php if ($image_only_mode) : ?><input type="hidden" name="ship_modal_content_type" id="ship-modal-content_type" value="<?php echo esc_attr($type ?: 'image'); ?>"><?php endif; ?>
        <table class="form-table ship-modal-form-table">
            <?php if (! $image_only_mode) : ?>
            <tr><th><label for="ship-modal-content_type">フレーム</label></th><td><?php $this->select('content_type', $type, array('html' => '旧：自由HTML', 'image' => '画像のみ', 'hybrid' => '画像＋テキスト（ボタン任意）', 'text' => 'テキスト（ボタン任意）', 'pager' => 'ページャー（複数ページ）')); ?></td></tr>
            <tr class="ship-modal-legacy-html-row"><th><label for="ship-modal-html">HTML</label></th><td><?php wp_editor($html, 'ship_modal_html', array('textarea_name' => 'ship_modal_html', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true)); ?></td></tr>
            <tr class="ship-modal-copy-row"><th><label for="ship-modal-heading">見出し</label></th><td><input type="text" class="widefat" name="ship_modal_heading" id="ship-modal-heading" value="<?php echo esc_attr($heading); ?>" placeholder="見出し（任意）"><p class="description">必須ではありません。長い文言は画面幅に合わせて折り返します。</p></td></tr>
            <tr class="ship-modal-copy-row"><th><label for="ship-modal-body">本文</label></th><td><textarea class="large-text" rows="5" name="ship_modal_body" id="ship-modal-body" placeholder="本文（任意）"><?php echo esc_textarea($body); ?></textarea><p class="description">本文はレイアウト用HTML不可。長さによる保存制限はありません。</p></td></tr>
            <?php endif; ?>
            <tr class="ship-modal-single-image-row">
                <th>基本画像</th>
                <td>
                    <input type="hidden" name="ship_modal_image_id" id="ship-modal-image-id" value="<?php echo esc_attr($image_id); ?>">
                    <div id="ship-modal-image-preview"><?php if ($image_url) : ?><img src="<?php echo esc_url($image_url); ?>" alt="" style="max-width:100%;height:auto;"><?php endif; ?></div>
                    <p><button type="button" class="button" id="ship-modal-select-image">画像を選択</button> <button type="button" class="button" id="ship-modal-remove-image">削除</button></p>
                    <p class="description">基本はこの画像1枚だけで運用できます。スマホ用画像が不要なら、ここだけ設定してください。</p>
                </td>
            </tr>
            <tr class="ship-modal-single-image-row">
                <th>スマホ用画像<br><span class="description">任意</span></th>
                <td>
                    <?php $mobile_image_url = $mobile_image_id ? wp_get_attachment_image_url($mobile_image_id, 'large') : ''; ?>
                    <input type="hidden" name="ship_modal_image_id_mobile" id="ship-modal-image-id-mobile" value="<?php echo esc_attr($mobile_image_id); ?>">
                    <div id="ship-modal-image-preview-mobile"><?php if ($mobile_image_url) : ?><img src="<?php echo esc_url($mobile_image_url); ?>" alt="" style="max-width:100%;height:auto;"><?php endif; ?></div>
                    <p><button type="button" class="button" id="ship-modal-select-image-mobile">画像を選択</button> <button type="button" class="button" id="ship-modal-remove-image-mobile">削除</button></p>
                    <p class="description">スマホ幅（767px以下）で切り替えます。未設定なら基本画像をそのまま使用します。</p>
                </td>
            </tr>
            <tr class="ship-modal-single-image-alt-row">
                <th><label for="ship-modal-image-alt">画像のalt</label></th>
                <td>
                    <input type="text" class="widefat" name="ship_modal_image_alt" id="ship-modal-image-alt" value="<?php echo esc_attr($image_alt); ?>" placeholder="画像の内容を説明（任意）">
                    <p class="description">基本画像・スマホ用画像で共通です。空欄にするとモーダルタイトルをaltに使用します。</p>
                </td>
            </tr>
            <tr class="ship-modal-single-image-row"><th><label for="ship-modal-link_url">クリック先URL</label></th><td><input type="url" class="widefat" name="ship_modal_link_url" id="ship-modal-link_url" value="<?php echo esc_attr($link_url); ?>" placeholder="https://example.com/"><br><input type="hidden" name="ship_modal_link_new_tab" value="0"><label><input type="checkbox" name="ship_modal_link_new_tab" value="1" <?php checked($link_new_tab, true); ?>> 別タブで開く</label><p class="description">空欄なら画像はリンクになりません。</p></td></tr>
            <?php if (! $image_only_mode) : ?>
            <tr class="ship-modal-hybrid-image-row"><th><label for="ship-modal-image_position">画像の位置</label></th><td><?php $this->select('image_position', $image_position, array('top' => '上', 'left' => '左', 'right' => '右')); ?></td></tr>
            <tr class="ship-modal-buttons-row"><th>ボタン</th><td><p class="description">任意・最大3個。1行あたりの文字数と行数に上限があります。</p><?php $this->render_button_fields($buttons, 3, 'ship_modal_buttons'); ?></td></tr>
            <tr class="ship-modal-pages-row"><th>ページ</th><td><div id="ship-modal-pages"><?php foreach ($pages as $index => $page) { $this->render_page_row($index, is_array($page) ? $page : array()); } ?></div><p><button type="button" class="button" id="ship-modal-add-page">＋ ページを追加</button></p><p class="description">各ページに画像・見出し・本文・ボタンを個別に設定できます。画像だけのページも作成できます。</p></td></tr>
            <?php endif; ?>
            <tr><th><label for="ship-modal-design">表示レイアウト</label></th><td><?php $this->select('design', $design, $design_options); ?><p class="description">モーダル全体の表示位置・形状を選択します。内容の形式は上の「フレーム」で設定します。</p></td></tr>
            <?php if (! $image_only_mode) : ?>
            <tr><th><label for="ship-modal-border_radius">角丸（border-radius）</label></th><td><input type="number" min="0" max="48" step="1" class="small-text" name="ship_modal_border_radius" id="ship-modal-border_radius" value="<?php echo esc_attr($border_radius); ?>"> px <p class="description">0〜48px。0なら角丸なし。</p></td></tr>
            <tr><th><label for="ship-modal-padding">内側の余白（padding）</label></th><td><input type="number" min="0" max="64" step="1" class="small-text" name="ship_modal_padding" id="ship-modal-padding" value="<?php echo esc_attr($padding); ?>"> px <p class="description">0〜64px。画像のみフレームは画像をコンテナいっぱいに表示します。</p></td></tr>
            <?php endif; ?>
            <tr><th><label for="ship-modal-max_width">最大幅（max-width）</label></th><td><input type="number" min="280" max="1200" step="1" class="small-text" name="ship_modal_max_width" id="ship-modal-max_width" value="<?php echo esc_attr($max_width); ?>"> px <p class="description">280〜1200px、1px刻みで設定できます。基本画像を選択するとPC用画像の横幅に合わせて自動設定します（推奨）。必要なら保存前に手動変更でき、スマホでは画面幅に合わせて縮小します。</p></td></tr>
            <?php if (! $image_only_mode) : ?><tr><th><label for="ship-modal-custom-css">上級者向け</label></th><td><details class="ship-modal-advanced-settings"><summary>このモーダル専用のCustom CSS</summary><textarea class="large-text code" rows="8" name="ship_modal_custom_css" id="ship-modal-custom-css" spellcheck="false"><?php echo esc_textarea($custom_css); ?></textarea><p class="description">このモーダルだけに適用するCSSを入力できます。<code>.ship-modal--id-<?php echo absint($post->ID); ?></code> を先頭に付けて指定してください。<code>&lt;style&gt;</code>タグは不要です。保存後に公開ページで必ず確認してください。</p></details></td></tr><?php endif; ?>
        </table>
        <?php if (! $image_only_mode) : ?><script type="text/html" id="ship-modal-page-template"><?php $this->render_page_row('__INDEX__', array()); ?></script><?php endif; ?>
        <?php
    }

    public function render_display_box($post)
    {
        $scope = $this->meta($post->ID, 'scope', 'front');
        // このボックス内の案内で使用するため、ここで現在のモーダルIDから生成する。
        $shortcode_example = '[ship_modal id="' . absint($post->ID) . '"]';
        $php_shortcode_example = "<?php echo do_shortcode('" . $shortcode_example . "'); ?>";
        $singular_scope_enabled = $this->is_singular_scope_enabled();
        $scope_options = array(
            'front' => 'トップページのみ',
            'selected' => '指定ページのみ',
            'shortcode' => 'ショートコードのみ',
            'all' => '全ページ（注意）',
        );
        if ($singular_scope_enabled) {
            $scope_options = array(
                'front' => 'トップページのみ',
                'singular' => '投稿・固定ページ（全て）',
                'selected' => '指定ページのみ',
                'shortcode' => 'ショートコードのみ',
                'all' => '全ページ（注意）',
            );
        } elseif ('singular' === $scope) {
            // 既存設定を破棄せず、停止中の値として表示する。公開表示は従来どおり維持する。
            $scope_options['singular'] = array('label' => '投稿・固定ページ（全て・新規選択停止）', 'disabled' => true);
        }
        if (! isset($scope_options[$scope])) {
            $scope = 'front';
        }
        $trigger = $this->meta($post->ID, 'trigger', 'auto');
        $delay = max(0, (int) $this->meta($post->ID, 'delay', 2));
        $scroll_threshold = min(95, max(10, (int) $this->meta($post->ID, 'scroll_threshold', 50)));
        $frequency = $this->meta($post->ID, 'frequency', 'session');
        $start = $this->meta($post->ID, 'start_at');
        $end = $this->meta($post->ID, 'end_at');
        $show_close = $this->meta($post->ID, 'show_close', '1');
        $close_overlay = $this->meta($post->ID, 'close_overlay', '1');
        $show_backdrop = $this->meta($post->ID, 'show_backdrop', '1');
        $trigger_text = $this->meta($post->ID, 'trigger_text', 'キャンペーン詳細を見る');
        $trigger_bg_color = sanitize_hex_color($this->meta($post->ID, 'trigger_bg_color', '#0f766e')) ?: '#0f766e';
        $trigger_text_color = sanitize_hex_color($this->meta($post->ID, 'trigger_text_color', '#ffffff')) ?: '#ffffff';
        $theme_colors = $this->theme_colors($post->ID);
        $trigger_position = $this->meta($post->ID, 'trigger_position', 'right');
        if (! in_array($trigger_position, array('left', 'center', 'right'), true)) {
            $trigger_position = 'right';
        }
        $button_label_limits = $this->button_label_limits();
        $target_ids = array_values(array_filter(array_map('absint', (array) $this->meta($post->ID, 'target_ids', array()))));
        $targetable_types = $this->targetable_post_types();
        $selected_posts = $target_ids ? get_posts(array(
            'post__in' => $target_ids,
            'post_type' => array_keys($targetable_types),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'post__in',
        )) : array();
        ?>
        <table class="form-table ship-modal-form-table">
            <tr><th>表示対象</th><td>
                <div class="ship-modal-scope-options" role="radiogroup" aria-label="表示対象">
                    <?php foreach ($scope_options as $scope_value => $scope_label) : ?>
                        <?php $scope_label_text = is_array($scope_label) ? (isset($scope_label['label']) ? $scope_label['label'] : '') : $scope_label; $scope_disabled = is_array($scope_label) && ! empty($scope_label['disabled']); ?>
                        <label class="<?php echo 'all' === $scope_value ? 'ship-modal-scope-option--all' : ''; ?><?php echo $scope_disabled ? ' ship-modal-scope-option--disabled' : ''; ?>"><input type="radio" name="ship_modal_scope" value="<?php echo esc_attr($scope_value); ?>" <?php checked($scope, $scope_value); ?><?php disabled($scope_disabled, true); ?>> <?php echo esc_html($scope_label_text); ?></label>
                    <?php endforeach; ?>
                </div>
                <p class="description ship-modal-scope-warning">新規モーダルの初期値は「トップページのみ」です。「全ページ（注意）」はサイト全体に表示されるため、必要な場合だけ選択してください。</p>
                <div class="ship-modal-target-picker">
                    <div class="ship-modal-target-picker__heading"><strong>指定ページを追加</strong><span class="ship-modal-target-count" aria-live="polite"></span></div>
                    <div class="ship-modal-target-search-row"><input type="search" id="ship-modal-target-search" class="widefat" placeholder="ページ名・記事タイトルで検索（2文字以上）"><select id="ship-modal-target-post-type"><option value="">すべての種類</option><?php foreach ($targetable_types as $target_type => $target_type_object) : ?><option value="<?php echo esc_attr($target_type); ?>"><?php echo esc_html($target_type_object->labels->name); ?></option><?php endforeach; ?></select></div>
                    <div id="ship-modal-target-results" class="ship-modal-target-results"><p class="description">検索結果から表示対象を追加できます。</p></div>
                    <div class="ship-modal-target-picker__selected-heading"><strong>選択中</strong><button type="button" class="button-link" id="ship-modal-target-clear">すべて解除</button></div>
                    <div id="ship-modal-target-selected" class="ship-modal-target-selected">
                        <?php foreach ($selected_posts as $selected_post) : $selected_type = get_post_type($selected_post); $selected_type_label = isset($targetable_types[$selected_type]) ? $targetable_types[$selected_type]->labels->singular_name : $selected_type; ?>
                            <span class="ship-modal-target-chip" data-target-id="<?php echo esc_attr($selected_post->ID); ?>"><input type="hidden" name="ship_modal_target_ids[]" value="<?php echo esc_attr($selected_post->ID); ?>"><span><?php echo esc_html('[' . $selected_type_label . '] ' . get_the_title($selected_post)); ?></span><button type="button" class="ship-modal-target-remove" aria-label="選択を解除">×</button></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="description">公開中のページ・記事だけが検索対象です。指定ページのみを選んだ場合、ここで選択したページにだけ表示されます。</p>
                </div>
            </td></tr>
            <tr><th><label for="ship-modal-trigger">起動方法</label></th><td>
                <?php $this->select('trigger', $trigger, array('auto' => '遅延して自動表示', 'scroll' => 'スクロール到達で表示', 'exit_intent' => '離脱意図で表示（PCのみ）', 'manual' => 'ボタンから表示')); ?>
                <div class="ship-modal-trigger-help" aria-label="起動方法の説明">
                    <div class="ship-modal-trigger-help__item" data-trigger="auto"><strong>遅延して自動表示</strong><span>ページを開いて指定秒数後に自動で表示します。</span></div>
                    <div class="ship-modal-trigger-help__item" data-trigger="scroll"><strong>スクロール到達で表示</strong><span>ページ全体の指定割合まで読んだ時点で表示します。</span></div>
                    <div class="ship-modal-trigger-help__item" data-trigger="exit_intent"><strong>離脱意図で表示（PCのみ）</strong><span>マウスをブラウザ上端へ移動した時に表示します。スマホでは動作しません。</span></div>
                    <div class="ship-modal-trigger-help__item" data-trigger="manual"><strong>ボタンから表示</strong><span>ページ上の専用ボタンを押した時だけ表示します。</span></div>
                </div>
                <p class="description">表示期間・表示頻度・表示対象の条件と組み合わせて動作します。戻るボタンの履歴フックは、誤操作・アクセシビリティ・検索評価への影響があるため対応していません。</p>
            </td></tr>
            <tr class="ship-modal-delay-row"><th><label for="ship-modal-delay">表示までの秒数</label></th><td><input type="number" min="0" max="120" step="1" class="small-text" name="ship_modal_delay" id="ship-modal-delay" value="<?php echo esc_attr($delay); ?>"> 秒</td></tr>
            <tr class="ship-modal-scroll-row"><th><label for="ship-modal-scroll_threshold">スクロール到達率</label></th><td><input type="number" min="10" max="95" step="5" class="small-text" name="ship_modal_scroll_threshold" id="ship-modal-scroll_threshold" value="<?php echo esc_attr($scroll_threshold); ?>"> ％<p class="description">ページ全体の指定割合までスクロールすると表示します。</p></td></tr>
            <tr class="ship-modal-trigger-text-row"><th><label for="ship-modal-trigger_text">ボタン文言</label></th><td><div class="ship-modal-button-label-wrap"><input type="text" class="widefat ship-modal-button-label" name="ship_modal_trigger_text" id="ship-modal-trigger_text" value="<?php echo esc_attr($trigger_text); ?>" data-max-lines="<?php echo esc_attr($button_label_limits['lines']); ?>" data-max-chars-per-line="<?php echo esc_attr($button_label_limits['chars_per_line']); ?>"><span class="ship-modal-button-label-meta" aria-live="polite"></span></div><p class="description">1行<?php echo esc_html($button_label_limits['chars_per_line']); ?>文字・最大<?php echo esc_html($button_label_limits['lines']); ?>行まで。改行は&lt;br&gt;を入力してください。超過分は保存時に自動調整されます。</p></td></tr>
            <tr class="ship-modal-trigger-style-row"><th>ボタンデザイン</th><td><label>背景色 <input type="color" name="ship_modal_trigger_bg_color" value="<?php echo esc_attr($trigger_bg_color); ?>"></label> <label>文字色 <input type="color" name="ship_modal_trigger_text_color" value="<?php echo esc_attr($trigger_text_color); ?>"></label><br><label for="ship-modal-trigger_position">配置 </label><?php $this->select('trigger_position', $trigger_position, array('left' => '左下', 'center' => '中央下', 'right' => '右下')); ?><p class="description">手動表示ボタンの背景色・文字色・画面下部の配置を設定します。</p></td></tr>
            <tr><th><label for="ship-modal-frequency">表示頻度</label></th><td><?php $this->select('frequency', $frequency, array('always' => '毎回', 'session' => 'このタブで1回', 'day' => '端末の現地日付で1日1回', 'once' => 'このブラウザで1回')); ?></td></tr>
            <tr><th><label for="ship-modal-start_at">開始日時</label></th><td><input type="datetime-local" class="widefat" name="ship_modal_start_at" id="ship-modal-start_at" value="<?php echo esc_attr($start); ?>"><p class="description">空欄ならすぐ表示</p></td></tr>
            <tr><th><label for="ship-modal-end_at">終了日時</label></th><td><input type="datetime-local" class="widefat" name="ship_modal_end_at" id="ship-modal-end_at" value="<?php echo esc_attr($end); ?>"><p class="description">空欄なら期限なし</p></td></tr>
            <tr><th>テーマカラー</th><td>
                <div class="ship-modal-color-grid">
                    <label>メインカラー <input type="color" name="ship_modal_theme_accent" value="<?php echo esc_attr($theme_colors['accent']); ?>"></label>
                    <label>文字色 <input type="color" name="ship_modal_theme_text" value="<?php echo esc_attr($theme_colors['text']); ?>"></label>
                </div>
                <p class="description">背景は白固定です。右側のカラーパレットが実際に反映される色です。メインカラーはリンク・主ボタン・閉じるボタンに、文字色は本文・見出し・サブボタンに反映されます。保存後に公開ページで確認してください。</p>
            </td></tr>
            <tr><th>閉じる操作</th><td><input type="hidden" name="ship_modal_show_close" value="0"><label><input type="checkbox" name="ship_modal_show_close" value="1" <?php checked($show_close, '1'); ?>> 閉じるボタンを表示</label><br><input type="hidden" name="ship_modal_close_overlay" value="0"><label><input type="checkbox" name="ship_modal_close_overlay" value="1" <?php checked($close_overlay, '1'); ?>> 背景クリックで閉じる</label><br><input type="hidden" name="ship_modal_show_backdrop" value="0"><label><input type="checkbox" name="ship_modal_show_backdrop" value="1" <?php checked($show_backdrop, '1'); ?>> 背景を暗くする</label><p class="description">「背景を暗くする」を外すと、背後のページを暗転させずに表示します。閉じる手段がなくなる設定は安全のため保存せず、両方を外した場合は閉じるボタンを自動で有効にします。</p></td></tr>
        </table>
        <div class="notice notice-info inline ship-modal-shortcode-help">
            <p><strong>ショートコードの使い方・設置位置</strong></p>
            <p>本文・固定ページ・ブロックには <code><?php echo esc_html($shortcode_example); ?></code> を記述します。テーマPHPへ直接設置する場合は、表示したい位置に次のコードを記述してください。</p>
            <p><code><?php echo esc_html($php_shortcode_example); ?></code></p>
            <p class="description">表示対象で「ショートコードのみ」を選ぶと、ショートコードを記述した位置に出力します。手動表示ボタンはその位置に表示され、自動表示の場合は記述位置に関係なく画面上へモーダルを表示します。</p>
        </div>
        <?php
    }

    public function render_stats_box($post)
    {
        $image_only_mode = $this->is_image_only_mode();
        $impressions = (int) get_post_meta($post->ID, '_ship_modal_impressions', true);
        $clicks = (int) get_post_meta($post->ID, '_ship_modal_clicks', true);
        $closes = (int) get_post_meta($post->ID, '_ship_modal_closes', true);
        $page_views = (int) get_post_meta($post->ID, '_ship_modal_page_views', true);
        $rate = $impressions > 0 ? round(($clicks / $impressions) * 100, 1) : 0;
        $event_labels = $this->stats_event_labels();
        $today = current_time('Y-m-d');
        $recent_from = date_i18n('Y-m-d', strtotime($today . ' -13 days'));
        $daily_rows = $this->get_daily_stats($post->ID, $recent_from, $today);
        $daily = array();
        foreach ($daily_rows as $row) {
            if (! isset($daily[$row->stat_date])) {
                $daily[$row->stat_date] = array_fill_keys(array_keys($event_labels), 0);
            }
            if (isset($daily[$row->stat_date][$row->event_name])) {
                $daily[$row->stat_date][$row->event_name] = (int) $row->event_count;
            }
        }
        if ($this->stats_db_error !== '') {
            echo '<div class="notice notice-error inline"><p><strong>日別集計を読み込めませんでした。</strong> データベースを確認してから再読み込みしてください。CSV出力はエラー解消まで停止します。</p></div>';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ship Modal stats error: ' . $this->stats_db_error); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        echo '<div class="ship-modal-stats-summary">';
        echo '<p><strong>表示回数：</strong> ' . number_format_i18n($impressions) . '</p>';
        echo '<p><strong>クリック数：</strong> ' . number_format_i18n($clicks) . '</p>';
        echo '<p><strong>クリック率：</strong> ' . esc_html($rate) . '%</p>';
        echo '<p><strong>閉じる回数：</strong> ' . number_format_i18n($closes) . '</p>';
        if (! $image_only_mode) {
            echo '<p><strong>ページャー閲覧数：</strong> ' . number_format_i18n($page_views) . '</p>';
        }
        echo '</div>';
        $page_view_description = $image_only_mode ? '' : 'ページャー閲覧数はページャーのページ表示・切り替えを記録します。';
        echo '<p class="description">' . $page_view_description . '表示・クリック・閉じる' . ($image_only_mode ? '' : '・ページャー閲覧') . 'はプラグイン内に記録されます。GA4へ直接送信する場合は「モーダル」→「計測・GA4連携設定」でGA4測定ID（<code>G-XXXXXXXXXX</code>）を登録してください。GTMを使う場合は同設定で「GTMのdataLayerのみ」を選び、GTM側で各カスタムイベントをGA4イベントタグへ紐付ける設定が必要です。設定後は公開ページで動作を確認してください。日別集計の導入前から累計がある場合、全期間CSVには「日別導入前」として差分を補完します。</p>';

        echo '<h4 class="ship-modal-stats-heading">直近14日の日別集計</h4>';
        if ($daily) {
            $table_head = '<tr><th>日付</th><th>表示</th><th>クリック</th><th>CTR</th><th>閉じる</th>' . ($image_only_mode ? '' : '<th>ページャー閲覧</th>') . '</tr>';
            echo '<div class="ship-modal-stats-table-wrap"><table class="widefat striped ship-modal-stats-table"><thead>' . $table_head . '</thead><tbody>';
            foreach ($daily as $date => $values) {
                $daily_rate = $values['impression'] > 0 ? round(($values['click'] / $values['impression']) * 100, 1) : 0;
                echo '<tr><td>' . esc_html($date) . '</td><td>' . number_format_i18n($values['impression']) . '</td><td>' . number_format_i18n($values['click']) . '</td><td>' . esc_html($daily_rate) . '%</td><td>' . number_format_i18n($values['close']) . '</td>' . ($image_only_mode ? '' : '<td>' . number_format_i18n($values['page_view']) . '</td>') . '</tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="description">日別データはまだありません。公開ページでモーダルを表示すると記録されます。</p>';
        }

        echo '<details class="ship-modal-stats-tools"><summary>CSV出力・計測リセット</summary>';
        echo '<p class="description">期間を指定して、日別の表示・クリック・閉じる' . ($image_only_mode ? '' : '・ページャー閲覧') . 'をCSVでダウンロードできます。</p>';
        $export_base_url = $this->admin_action_url(
            'ship_modal_export_stats',
            $post->ID,
            'ship_modal_export_stats_' . absint($post->ID)
        );
        $export_all_url = $export_base_url;
        $export_recent_url = add_query_arg(array('from' => $recent_from, 'to' => $today), $export_base_url);
        // 投稿編集画面全体がformで囲まれているため、リセットはJSで独立したPOST formを生成する。
        echo '<div class="ship-modal-stats-export-form"><label>開始 <input type="date" id="ship-modal-stats-from-' . absint($post->ID) . '" value="' . esc_attr($recent_from) . '"></label> <label>終了 <input type="date" id="ship-modal-stats-to-' . absint($post->ID) . '" value="' . esc_attr($today) . '"></label> <a class="button ship-modal-stats-export-link" data-base-url="' . esc_attr($export_base_url) . '" data-from-id="ship-modal-stats-from-' . absint($post->ID) . '" data-to-id="ship-modal-stats-to-' . absint($post->ID) . '" href="' . esc_url($export_recent_url) . '" target="_blank" rel="noopener">CSVをダウンロード</a> <a class="button" href="' . esc_url($export_all_url) . '" target="_blank" rel="noopener">全期間CSV</a></div>';
        echo '<div class="ship-modal-stats-reset-form"><button type="button" class="button ship-modal-stats-reset-button" data-action-url="' . esc_attr(admin_url('admin-post.php')) . '" data-post-id="' . absint($post->ID) . '" data-nonce="' . esc_attr(wp_create_nonce('ship_modal_reset_stats_' . absint($post->ID))) . '">計測をリセット</button><span class="description">全期間の集計と日別データを削除します。</span></div></details>';
    }

    private function normalize_buttons($raw_buttons, $max, $context, &$errors)
    {
        $normalized = array();
        if (! is_array($raw_buttons)) {
            return $normalized;
        }
        foreach (array_slice(wp_unslash($raw_buttons), 0, $max) as $button) {
            if (! is_array($button)) {
                continue;
            }
            $label = isset($button['label']) ? $this->normalize_button_label($button['label']) : '';
            $label_text = trim(wp_strip_all_tags($label));
            $url = isset($button['url']) ? esc_url_raw($button['url']) : '';
            $action = isset($button['action']) && 'close' === $button['action'] ? 'close' : 'link';
            $style = isset($button['style']) && in_array($button['style'], array('primary', 'secondary'), true) ? $button['style'] : 'primary';
            $new_tab = ! empty($button['new_tab']) ? '1' : '0';
            if ($label_text === '') {
                continue;
            }
            if ('link' === $action) {
                if ($url === '' || ! preg_match('/^(https?:\\/\\/|tel:|mailto:)/i', $url)) {
                    continue;
                }
            }
            $normalized[] = array('label' => $label, 'url' => 'close' === $action ? '' : $url, 'action' => $action, 'style' => $style, 'new_tab' => 'close' === $action ? '0' : $new_tab);
        }
        return $normalized;
    }

    public function save_modal($post_id, $post)
    {
        if (! isset($_POST['ship_modal_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ship_modal_nonce'])), 'ship_modal_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (! $this->can_manage_modal() || ! current_user_can('edit_post', $post_id)) {
            return;
        }

        // チェックボックスは未チェック時にPOST自体が送信されないため、値を先に確定する。
        // 内容側でエラーが発生しても、表示設定が意図せず戻らないようにする。
        $show_close = ! empty($_POST['ship_modal_show_close']) ? '1' : '0';
        $close_overlay = ! empty($_POST['ship_modal_close_overlay']) ? '1' : '0';
        $show_backdrop = ! empty($_POST['ship_modal_show_backdrop']) ? '1' : '0';
        // タッチ端末でも必ず閉じられるよう、閉じる手段の全OFFは許可しない。
        if ('0' === $show_close && '0' === $close_overlay) {
            $show_close = '1';
        }
        update_post_meta($post_id, '_ship_modal_show_close', $show_close);
        update_post_meta($post_id, '_ship_modal_close_overlay', $close_overlay);
        update_post_meta($post_id, '_ship_modal_show_backdrop', $show_backdrop);
        foreach ($this->theme_color_defaults() as $theme_key => $theme_default) {
            $theme_meta_key = 'theme_' . $theme_key;
            $theme_value = 'surface' === $theme_key
                ? '#ffffff'
                : (isset($_POST['ship_modal_' . $theme_meta_key]) ? sanitize_hex_color(wp_unslash($_POST['ship_modal_' . $theme_meta_key])) : $this->meta($post_id, $theme_meta_key, $theme_default));
            update_post_meta($post_id, '_ship_modal_' . $theme_meta_key, $theme_value ?: $theme_default);
        }

        $allowed_types = array('html', 'image', 'hybrid', 'text', 'pager');
        $image_only_mode = $this->is_image_only_mode();
        $stored_type = $this->meta($post_id, 'content_type', 'image');
        $stored_type = in_array($stored_type, $allowed_types, true) ? $stored_type : 'image';
        $submitted_type = isset($_POST['ship_modal_content_type']) ? sanitize_key(wp_unslash($_POST['ship_modal_content_type'])) : '';
        if ($image_only_mode) {
            // 既存の非画像モーダルは内容形式を保持し、画像専用UIからの送信で変換しない。
            $type = $stored_type;
        } else {
            $type = $submitted_type !== '' ? $submitted_type : $stored_type;
            if (! in_array($type, $allowed_types, true)) {
                $type = 'html';
            }
        }
        $errors = array();
        $heading = isset($_POST['ship_modal_heading']) ? sanitize_text_field(wp_unslash($_POST['ship_modal_heading'])) : $this->meta($post_id, 'heading', '');
        $body_raw = isset($_POST['ship_modal_body']) ? wp_unslash($_POST['ship_modal_body']) : $this->meta($post_id, 'body', '');
        $body = wp_kses($body_raw, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true)));
        $html = isset($_POST['ship_modal_html']) ? wp_unslash($_POST['ship_modal_html']) : $this->meta($post_id, 'html', '');
        $custom_css = isset($_POST['ship_modal_custom_css']) ? $this->sanitize_custom_css(wp_unslash($_POST['ship_modal_custom_css'])) : $this->sanitize_custom_css($this->meta($post_id, 'custom_css', ''));
        $image_id = isset($_POST['ship_modal_image_id']) ? absint($_POST['ship_modal_image_id']) : absint($this->meta($post_id, 'image_id', 0));
        $mobile_image_id = isset($_POST['ship_modal_image_id_mobile']) ? absint($_POST['ship_modal_image_id_mobile']) : absint($this->meta($post_id, 'image_id_mobile', 0));
        $image_alt = isset($_POST['ship_modal_image_alt']) ? sanitize_text_field(wp_unslash($_POST['ship_modal_image_alt'])) : $this->meta($post_id, 'image_alt', '');
        $buttons = $this->normalize_buttons(isset($_POST['ship_modal_buttons']) ? $_POST['ship_modal_buttons'] : $this->meta($post_id, 'buttons', array()), 3, 'ボタン', $errors);

        $border_radius = isset($_POST['ship_modal_border_radius'])
            ? min(48, max(0, absint($_POST['ship_modal_border_radius'])))
            : min(48, max(0, (int) $this->meta($post_id, 'border_radius', 0)));
        $padding = isset($_POST['ship_modal_padding'])
            ? min(64, max(0, absint($_POST['ship_modal_padding'])))
            : min(64, max(0, (int) $this->meta($post_id, 'padding', 20)));
        $max_width = isset($_POST['ship_modal_max_width'])
            ? min(1200, max(280, absint($_POST['ship_modal_max_width'])))
            : min(1200, max(280, (int) $this->meta($post_id, 'max_width', 620)));
        $scope = isset($_POST['ship_modal_scope'])
            ? sanitize_key(wp_unslash($_POST['ship_modal_scope']))
            : $this->meta($post_id, 'scope', 'front');
        $raw_target_ids = isset($_POST['ship_modal_target_ids']) && is_array($_POST['ship_modal_target_ids']) ? $_POST['ship_modal_target_ids'] : array();
        if ('hybrid' === $type) {
            // 必須・文字数による保存ブロックは行わない。
        }
        if ('text' === $type) {
            // 必須・文字数による保存ブロックは行わない。
        }

        $pages = array();
        if (isset($_POST['ship_modal_pages']) && is_array($_POST['ship_modal_pages'])) {
            foreach (array_values(wp_unslash($_POST['ship_modal_pages'])) as $index => $page) {
                if (! is_array($page)) {
                    continue;
                }
                $page_heading = isset($page['heading']) ? sanitize_text_field($page['heading']) : '';
                $page_body = isset($page['body']) ? wp_kses($page['body'], array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) : '';
                $page_buttons = $this->normalize_buttons(isset($page['buttons']) ? $page['buttons'] : array(), 2, 'ページ' . ((int) $index + 1) . 'のボタン', $errors);
                $has_page_content = $page_heading !== '' || trim(wp_strip_all_tags($page_body)) !== '' || ! empty($page['image_id']) || ! empty($page_buttons);
                if (! $has_page_content) {
                    continue;
                }
                $pages[] = array(
                    'image_id' => isset($page['image_id']) ? absint($page['image_id']) : 0,
                    'heading' => $page_heading,
                    'body' => $page_body,
                    'link_url' => isset($page['link_url']) ? esc_url_raw($page['link_url']) : '',
                    'link_new_tab' => ! empty($page['link_new_tab']) ? '1' : '0',
                    'buttons' => $page_buttons,
                );
            }
        }
        // ページ数による保存ブロックは行わない。

        if ($errors) {
            $form_key = 'ship_modal_form_' . get_current_user_id() . '_' . $post_id;
            $form_state = array(
                'type' => $type,
                'html' => $html,
                'heading' => $heading,
                'body' => $body,
                'image_id' => $image_id,
                'mobile_image_id' => $mobile_image_id,
                'image_alt' => $image_alt,
                'link_url' => isset($_POST['ship_modal_link_url']) ? esc_url_raw(wp_unslash($_POST['ship_modal_link_url'])) : $this->meta($post_id, 'link_url', ''),
                'buttons' => isset($_POST['ship_modal_buttons']) && is_array($_POST['ship_modal_buttons']) ? wp_unslash($_POST['ship_modal_buttons']) : $buttons,
                'pages' => isset($_POST['ship_modal_pages']) && is_array($_POST['ship_modal_pages']) ? wp_unslash($_POST['ship_modal_pages']) : $pages,
                'custom_css' => $custom_css,
            );
            if (! set_transient($form_key, $form_state, 60)) {
                update_post_meta($post_id, '_ship_modal_form_state_' . get_current_user_id(), $form_state);
            }
            $error_key = 'ship_modal_errors_' . get_current_user_id() . '_' . $post_id;
            if (! set_transient($error_key, $errors, 60)) {
                update_post_meta($post_id, '_ship_modal_errors_' . get_current_user_id(), $errors);
            }
            return;
        }

        delete_transient('ship_modal_form_' . get_current_user_id() . '_' . $post_id);
        delete_post_meta($post_id, '_ship_modal_form_state_' . get_current_user_id());
        delete_post_meta($post_id, '_ship_modal_errors_' . get_current_user_id());

        $schedule_warnings = array();
        $schedule_values = $this->normalize_schedule_values($post_id, $schedule_warnings);
        foreach (array('link_url', 'trigger_text', 'start_at', 'end_at') as $field) {
            if (isset($schedule_values[$field])) {
                update_post_meta($post_id, '_ship_modal_' . $field, $schedule_values[$field]);
                continue;
            }
            $value = isset($_POST['ship_modal_' . $field])
                ? wp_unslash($_POST['ship_modal_' . $field])
                : $this->meta($post_id, $field, '');
            $value = 'trigger_text' === $field
                ? $this->normalize_button_label($value)
                : sanitize_text_field($value);
            update_post_meta($post_id, '_ship_modal_' . $field, $value);
        }
        $schedule_warning_key = 'ship_modal_warnings_' . get_current_user_id() . '_' . $post_id;
        if ($schedule_warnings) {
            set_transient($schedule_warning_key, $schedule_warnings, 60);
        } else {
            delete_transient($schedule_warning_key);
        }
        // 画像専用モード中は、画面から隠している内容データを一切上書きしない。
        // 将来フラグをfalseへ戻した時に、既存のHTML等をそのまま再編集できるようにする。
        if (! $image_only_mode) {
            update_post_meta($post_id, '_ship_modal_html', wp_kses_post($html));
            update_post_meta($post_id, '_ship_modal_custom_css', $custom_css);
            update_post_meta($post_id, '_ship_modal_heading', $heading);
            update_post_meta($post_id, '_ship_modal_body', $body);
            update_post_meta($post_id, '_ship_modal_buttons', $buttons);
        }
        update_post_meta($post_id, '_ship_modal_image_id', $image_id);
        update_post_meta($post_id, '_ship_modal_image_id_mobile', $mobile_image_id);
        if (! $image_only_mode || isset($_POST['ship_modal_image_alt'])) {
            update_post_meta($post_id, '_ship_modal_image_alt', $image_alt);
        }
        $raw_link_new_tab = isset($_POST['ship_modal_link_new_tab']) ? wp_unslash($_POST['ship_modal_link_new_tab']) : null;
        if (is_array($raw_link_new_tab)) {
            $link_new_tab = in_array('1', array_map('strval', $raw_link_new_tab), true) ? '1' : '0';
        } elseif (null !== $raw_link_new_tab) {
            $link_new_tab = '1' === (string) $raw_link_new_tab ? '1' : '0';
        } else {
            $link_new_tab = $this->meta($post_id, 'link_new_tab', '0');
        }
        update_post_meta($post_id, '_ship_modal_link_new_tab', '1' === $link_new_tab ? '1' : '0');
        $trigger_bg_color = isset($_POST['ship_modal_trigger_bg_color'])
            ? sanitize_hex_color(wp_unslash($_POST['ship_modal_trigger_bg_color']))
            : sanitize_hex_color($this->meta($post_id, 'trigger_bg_color', '#0f766e'));
        $trigger_text_color = isset($_POST['ship_modal_trigger_text_color'])
            ? sanitize_hex_color(wp_unslash($_POST['ship_modal_trigger_text_color']))
            : sanitize_hex_color($this->meta($post_id, 'trigger_text_color', '#ffffff'));
        update_post_meta($post_id, '_ship_modal_trigger_bg_color', $trigger_bg_color ?: '#0f766e');
        update_post_meta($post_id, '_ship_modal_trigger_text_color', $trigger_text_color ?: '#ffffff');
        update_post_meta($post_id, '_ship_modal_delay', isset($_POST['ship_modal_delay']) ? min(120, max(0, absint($_POST['ship_modal_delay']))) : 2);
        update_post_meta($post_id, '_ship_modal_scroll_threshold', isset($_POST['ship_modal_scroll_threshold']) ? min(95, max(10, absint($_POST['ship_modal_scroll_threshold']))) : 50);
        update_post_meta($post_id, '_ship_modal_show_close', $show_close);
        update_post_meta($post_id, '_ship_modal_close_overlay', $close_overlay);
        if (! $image_only_mode) {
            update_post_meta($post_id, '_ship_modal_pages', $pages);
        }
        update_post_meta($post_id, '_ship_modal_border_radius', $border_radius);
        update_post_meta($post_id, '_ship_modal_padding', $padding);
        update_post_meta($post_id, '_ship_modal_max_width', $max_width);
        if (isset($_POST['ship_modal_target_ids']) || 'selected' === $scope) {
            $target_ids = array();
            $targetable_types = $this->targetable_post_types();
            foreach (array_unique(array_map('absint', wp_unslash($raw_target_ids))) as $target_id) {
                $target_post = $target_id ? get_post($target_id) : null;
                if ($target_post && 'publish' === $target_post->post_status && isset($targetable_types[$target_post->post_type])) {
                    $target_ids[] = $target_id;
                }
            }
            update_post_meta($post_id, '_ship_modal_target_ids', $target_ids);
        }
        $design_values = array('center', 'bottom', 'side');
        if ($this->is_fullscreen_enabled()) {
            $design_values[] = 'fullscreen';
        }
        $scope_values = array('front', 'selected', 'shortcode', 'all');
        if ($this->is_singular_scope_enabled()) {
            // 既存の並び順を維持しつつ、機能再開時に選択肢を戻す。
            $scope_values = array('front', 'singular', 'selected', 'shortcode', 'all');
        }
        $allowed = array(
            'content_type' => $allowed_types,
            'image_position' => array('top', 'left', 'right'),
            'design' => $design_values,
            'scope' => $scope_values,
            'trigger' => array('auto', 'scroll', 'exit_intent', 'manual'),
            'trigger_position' => array('left', 'center', 'right'),
            'frequency' => array('always', 'session', 'day', 'once'),
        );
        foreach ($allowed as $field => $values) {
            if ($image_only_mode && 'image_position' === $field) {
                continue;
            }
            if ($image_only_mode && 'content_type' === $field && 'image' !== $stored_type) {
                continue;
            }
            $default_value = reset($values);
            $current_value = $this->meta($post_id, $field, $default_value);
            $value = isset($_POST['ship_modal_' . $field])
                ? sanitize_key(wp_unslash($_POST['ship_modal_' . $field]))
                : $current_value;
            if (! in_array($value, $values, true)) {
                $locked_value = ('design' === $field && ! $this->is_fullscreen_enabled() && 'fullscreen' === $current_value)
                    || ('scope' === $field && ! $this->is_singular_scope_enabled() && 'singular' === $current_value);
                $value = $locked_value ? $current_value : (in_array($current_value, $values, true) ? $current_value : $default_value);
            }
            update_post_meta($post_id, '_ship_modal_' . $field, $value);
        }
        $this->ensure_counter_meta($post_id);
    }

    public function render_validation_notice()
    {
        if (empty($_GET['post'])) {
            return;
        }
        $key = 'ship_modal_errors_' . get_current_user_id() . '_' . absint($_GET['post']);
        $errors = get_transient($key);
        if (! is_array($errors)) {
            $errors = get_post_meta(absint($_GET['post']), '_ship_modal_errors_' . get_current_user_id(), true);
            if (is_array($errors)) {
                delete_post_meta(absint($_GET['post']), '_ship_modal_errors_' . get_current_user_id());
            }
        }
        if (is_array($errors) && $errors) {
            delete_transient($key);
            echo '<div class="notice notice-error"><p><strong>モーダルを保存できませんでした。</strong></p><ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        }
        $warning_key = 'ship_modal_warnings_' . get_current_user_id() . '_' . absint($_GET['post']);
        $warnings = get_transient($warning_key);
        if (! is_array($warnings) || ! $warnings) {
            return;
        }
        delete_transient($warning_key);
        echo '<div class="notice notice-warning"><p><strong>一部の入力を確認してください。</strong></p><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . esc_html($warning) . '</li>';
        }
        echo '</ul></div>';
    }

    public function enqueue_admin_assets($hook)
    {
        $screen = get_current_screen();
        if (! $screen) {
            return;
        }
        $is_settings_page = isset($_GET['page']) && 'ship-modal-settings' === sanitize_key(wp_unslash($_GET['page']));
        if ($is_settings_page) {
            wp_enqueue_style('ship-modal-admin', SHIP_MODAL_URL . 'assets/css/admin.css', array(), SHIP_MODAL_VERSION);
            return;
        }
        if ('ship_modal' !== $screen->post_type || ! in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('ship-modal-admin', SHIP_MODAL_URL . 'assets/js/admin.js', array('jquery', 'media-editor', 'media-views', 'media-upload'), SHIP_MODAL_VERSION, true);
        wp_localize_script('ship-modal-admin', 'ShipModalAdminConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'targetSearchNonce' => wp_create_nonce('ship_modal_target_search'),
            'postId' => isset($_GET['post']) ? absint($_GET['post']) : 0,
        ));
        wp_enqueue_style('ship-modal-admin', SHIP_MODAL_URL . 'assets/css/admin.css', array(), SHIP_MODAL_VERSION);
    }

    public function admin_columns($columns)
    {
        $columns['ship_modal_scope'] = '表示範囲';
        $columns['ship_modal_schedule'] = '期間';
        $columns['ship_modal_stats'] = '計測';
        return $columns;
    }

    public function render_admin_column($column, $post_id)
    {
        if ('ship_modal_scope' === $column) {
            $labels = array('front' => 'トップ', 'singular' => $this->is_singular_scope_enabled() ? '投稿・固定ページ（全て）' : '投稿・固定ページ（全て・新規選択停止）', 'selected' => '指定ページ', 'shortcode' => 'ショートコード', 'all' => '全ページ（注意）');
            $scope = $this->meta($post_id, 'scope', 'front');
            echo esc_html(isset($labels[$scope]) ? $labels[$scope] : $scope);
        } elseif ('ship_modal_schedule' === $column) {
            $start = $this->meta($post_id, 'start_at');
            $end = $this->meta($post_id, 'end_at');
            echo esc_html(($start ?: '即時') . ' 〜 ' . ($end ?: '無期限'));
        } elseif ('ship_modal_stats' === $column) {
            echo esc_html(number_format_i18n((int) get_post_meta($post_id, '_ship_modal_impressions', true)) . ' views / ' . number_format_i18n((int) get_post_meta($post_id, '_ship_modal_clicks', true)) . ' clicks');
        }
    }

    /**
     * 「更新してプレビュー」送信時は、保存後にプレビューURLへ遷移する。
     * 通常の更新・自動保存のリダイレクトには影響しない。
     */
    public function redirect_to_preview_after_save($location, $post_id)
    {
        $preview_after_save = isset($_POST['ship_modal_preview_after_save'])
            ? sanitize_text_field(wp_unslash($_POST['ship_modal_preview_after_save']))
            : '';
        if ('1' !== $preview_after_save) {
            return $location;
        }
        $post_id = isset($_POST['ship_modal_preview_post_id'])
            ? absint($_POST['ship_modal_preview_post_id'])
            : absint($post_id);
        if (! $post_id && isset($_POST['post_ID'])) {
            $post_id = absint($_POST['post_ID']);
        }
        if ('ship_modal' !== get_post_type($post_id) || ! $this->user_can_preview($post_id)) {
            return $location;
        }
        return $this->admin_action_url(
            'ship_modal_preview',
            $post_id,
            'ship_modal_preview_' . absint($post_id)
        );
    }

    public function preview()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        // HTML属性からコピーされたURLでは「&amp;post_id」として届くことがあるため吸収する。
        if (! $post_id && isset($_GET['amp;post_id'])) {
            $post_id = absint($_GET['amp;post_id']);
        }
        if (! $post_id && isset($_GET['post'])) {
            $post_id = absint($_GET['post']);
        }
        $post = $post_id ? get_post($post_id) : null;
        if (! $post || 'ship_modal' !== $post->post_type) {
            wp_die('モーダルが見つかりません。', 'Ship Modal', array('response' => 404));
        }
        if (! $this->user_can_preview($post_id)) {
            wp_die('プレビュー権限がありません。', 'Ship Modal', array('response' => 403));
        }
        if (! isset($_REQUEST['_wpnonce']) && isset($_REQUEST['amp;_wpnonce'])) {
            $_REQUEST['_wpnonce'] = sanitize_text_field(wp_unslash($_REQUEST['amp;_wpnonce']));
        }
        check_admin_referer('ship_modal_preview_' . $post_id);
        nocache_headers();
        $modal = $this->render_modal($post_id, false, true);
        if (! $modal) {
            wp_die('プレビューできる内容がありません。先に内容を保存してください。', 'Ship Modal', array('response' => 400));
        }
        $js_url = SHIP_MODAL_URL . 'assets/js/modal.js?ver=' . rawurlencode(SHIP_MODAL_VERSION);
        // 公開ページと同じテーマCSSを読み込み、プレビューだけ見た目が変わらないようにする。
        // wp_head() はテーマ固有の副作用（タイトルや外部スクリプトなど）も出力するため、
        // enqueue処理とスタイル出力だけを実行する。
        wp_enqueue_style('ship-modal', SHIP_MODAL_URL . 'assets/css/modal.css', array(), SHIP_MODAL_VERSION);
        do_action('wp_enqueue_scripts');
        ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html('モーダルプレビュー：' . get_the_title($post_id)); ?></title><?php wp_print_styles(); ?></head><body <?php body_class('ship-modal-preview-page'); ?>><?php echo $modal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><script src="<?php echo esc_url($js_url); ?>"></script></body></html><?php
        exit;
    }

    private function user_can_preview($post_id)
    {
        return $this->can_manage_modal() && current_user_can('edit_post', absint($post_id));
    }

    private function is_in_schedule($post_id)
    {
        $now = time();
        $start = $this->schedule_timestamp($this->meta($post_id, 'start_at'));
        $end = $this->schedule_timestamp($this->meta($post_id, 'end_at'));
        if ($start && $start > $now) {
            return false;
        }
        if ($end && $end < $now) {
            return false;
        }
        return true;
    }

    private function is_schedule_expired($post_id)
    {
        $end = $this->schedule_timestamp($this->meta($post_id, 'end_at'));
        return $end && $end < time();
    }

    private function schedule_timestamp($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $matches)) {
            return 0;
        }
        if (! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return 0;
        }
        if ((int) $matches[4] > 23 || (int) $matches[5] > 59 || (isset($matches[6]) && (int) $matches[6] > 59)) {
            return 0;
        }
        // WP4.8の手動gmt_offset設定でも正しく変換されるよう秒まで補完する。
        $local_datetime = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            (int) $matches[4],
            (int) $matches[5],
            isset($matches[6]) ? (int) $matches[6] : 0
        );
        $gmt_timestamp = get_gmt_from_date($local_datetime, 'U');
        return is_numeric($gmt_timestamp) ? (int) $gmt_timestamp : 0;
    }

    private function normalize_schedule_value($value, $fallback, $label, &$warnings)
    {
        $value = sanitize_text_field((string) $value);
        if ($value === '') {
            return '';
        }
        if ($this->schedule_timestamp($value)) {
            return $value;
        }
        $warnings[] = $label . 'の形式が正しくないため、保存前の値を維持しました。';
        return $fallback;
    }

    private function normalize_schedule_values($post_id, &$warnings)
    {
        $stored = array(
            'start_at' => sanitize_text_field((string) $this->meta($post_id, 'start_at', '')),
            'end_at' => sanitize_text_field((string) $this->meta($post_id, 'end_at', '')),
        );
        foreach ($stored as $field => $value) {
            if ($value !== '' && ! $this->schedule_timestamp($value)) {
                $stored[$field] = '';
            }
        }
        $values = array();
        foreach (array('start_at', 'end_at') as $field) {
            $label = 'start_at' === $field ? '開始日時' : '終了日時';
            $raw = isset($_POST['ship_modal_' . $field]) ? wp_unslash($_POST['ship_modal_' . $field]) : $stored[$field];
            $values[$field] = $this->normalize_schedule_value($raw, $stored[$field], $label, $warnings);
        }
        if ($values['start_at'] !== '' && $values['end_at'] !== '' && $this->schedule_timestamp($values['start_at']) > $this->schedule_timestamp($values['end_at'])) {
            $warnings[] = '開始日時が終了日時より後のため、保存前の期間を維持しました。';
            $values = $stored;
            if ($values['start_at'] !== '' && $values['end_at'] !== '' && $this->schedule_timestamp($values['start_at']) > $this->schedule_timestamp($values['end_at'])) {
                $values = array('start_at' => '', 'end_at' => '');
            }
        }
        return $values;
    }

    private function is_scope_visible($post_id)
    {
        $scope = $this->meta($post_id, 'scope', 'front');
        if ('shortcode' === $scope) {
            return false;
        }
        if ('front' === $scope && ! is_front_page()) {
            return false;
        }
        if ('singular' === $scope && ! is_singular()) {
            return false;
        }
        if ('selected' === $scope) {
            $target_ids = array_values(array_filter(array_map('absint', (array) $this->meta($post_id, 'target_ids', array()))));
            return is_singular() && in_array((int) get_queried_object_id(), $target_ids, true);
        }
        return true;
    }

    private function active_modal_ids()
    {
        if (is_array($this->active_modal_ids_cache)) {
            return $this->active_modal_ids_cache;
        }
        $query = new WP_Query(array(
            'post_type' => 'ship_modal',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'orderby' => 'menu_order date',
            'order' => 'DESC',
        ));
        // 開始前のモーダルもDOMへ出し、ページを開いたまま開始時刻を迎えた場合は
        // フロントJSで安全に起動する。終了済みかどうかはrender_modal()で除外する。
        $this->active_modal_ids_cache = array_map('absint', $query->posts);
        return $this->active_modal_ids_cache;
    }

    public function enqueue_front_assets()
    {
        // ショートコードがthe_content以外（テンプレート・ウィジェット等）から
        // 呼ばれてもCSSがwp_head後の遅延読込にならないよう、スタイルは先に登録する。
        wp_register_style('ship-modal', SHIP_MODAL_URL . 'assets/css/modal.css', array(), SHIP_MODAL_VERSION);
        $has_sitewide = false;
        foreach ($this->active_modal_ids() as $post_id) {
            if ($this->is_scope_visible($post_id) && ! $this->is_schedule_expired($post_id)) {
                $has_sitewide = true;
                break;
            }
        }
        $has_content_shortcode = false;
        if (is_singular()) {
            $queried_post = get_post(get_queried_object_id());
            $has_content_shortcode = $queried_post && has_shortcode($queried_post->post_content, 'ship_modal');
        }
        if (! $has_sitewide && ! $has_content_shortcode) {
            return;
        }
        wp_enqueue_style('ship-modal');
        wp_enqueue_script('ship-modal', SHIP_MODAL_URL . 'assets/js/modal.js', array(), SHIP_MODAL_VERSION, true);
        wp_localize_script('ship-modal', 'ShipModalConfig', $this->front_script_config());
    }

    private function front_script_config()
    {
        $settings = $this->ga4_settings();
        return array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'ga4MeasurementId' => $settings['measurement_id'],
            'ga4Transport' => $settings['transport'],
            // 初期表示時のCMP判定。ページ表示後の変更は、フロント側の
            // window.ShipModalConsent.setAnalyticsConsent()で反映する。
            // 未設定時は従来互換で送信を許可する。
            'ga4Enabled' => (bool) apply_filters('ship_modal_ga4_enabled', true),
        );
    }

    private function event_token_ttl()
    {
        $default = defined('DAY_IN_SECONDS') ? 7 * DAY_IN_SECONDS : 604800;
        $ttl = (int) apply_filters('ship_modal_event_token_ttl', $default);
        return max(300, min(30 * (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400), $ttl));
    }

    private function event_token($post_id)
    {
        // 公開HTMLへ出す値なので、投稿IDだけの固定トークンではなく発行時刻を署名する。
        $issued_at = time();
        $signature = hash_hmac('sha256', absint($post_id) . '|' . $issued_at, wp_salt('nonce'));
        return $issued_at . '.' . $signature;
    }

    private function verify_event_token($post_id, $token)
    {
        $token = is_string($token) ? trim($token) : '';
        if (preg_match('/^(\d{10})\.([a-f0-9]{64})$/i', $token, $matches)) {
            $issued_at = (int) $matches[1];
            $now = time();
            // サーバー間の時計ずれを少しだけ許容し、古いページの再送は期限切れにする。
            if ($issued_at > $now + 300 || $issued_at < $now - $this->event_token_ttl()) {
                return false;
            }
            $expected = hash_hmac('sha256', absint($post_id) . '|' . $issued_at, wp_salt('nonce'));
            return hash_equals($expected, strtolower($matches[2]));
        }

        // 固定値から生成する旧トークンは、キャッシュされたHTMLが残っていても
        // 受け付けない。デプロイ時にページ／CDNキャッシュを更新する前提で、
        // 発行時刻付きの署名トークンだけを有効にする。
        return false;
    }

    private function normalize_event_id($value)
    {
        $value = is_scalar($value) ? trim(sanitize_text_field((string) $value)) : '';
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,79}$/', $value) ? $value : '';
    }

    private function event_claim_key($post_id, $event, $event_id)
    {
        return 'ship_modal_event_' . substr(hash_hmac('sha256', absint($post_id) . '|' . $event . '|' . $event_id, wp_salt('auth')), 0, 40);
    }

    /**
     * イベントIDを専用テーブルの一意キーで短時間だけ予約する。
     * 戻り値: string=予約済み、false=重複、null=保存失敗。
     */
    private function claim_event_id($post_id, $event, $event_id)
    {
        if (! is_string($event_id) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,79}$/', $event_id)) {
            return null;
        }
        if (! $this->maybe_upgrade_event_claim_table()) {
            return null;
        }
        global $wpdb;

        $key = $this->event_claim_key($post_id, $event, $event_id);
        $ttl = max(300, min(2 * (defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600), $this->event_token_ttl()));
        // 高トラフィック時のテーブル肥大を防ぐ。削除は予約の成否に影響させない。
        if (function_exists('wp_rand') && 1 === wp_rand(1, 20)) {
            $this->cleanup_event_claims();
        }

        $claim_token = hash('sha256', $key . '|' . microtime(true) . '|' . wp_rand());
        $expires_at = gmdate('Y-m-d H:i:s', time() + $ttl);
        // expires_atが過去の行だけ同じ一意キーを原子的に再利用する。
        // VALUES()はWordPressが対応するMySQL 5.7/8.xで利用できる構文。
        $query = $wpdb->prepare(
            "INSERT INTO {$this->event_claim_table_name()} (claim_key, claim_token, modal_id, event_name, expires_at) VALUES (%s, %s, %d, %s, %s)
            ON DUPLICATE KEY UPDATE claim_token = IF(expires_at < UTC_TIMESTAMP(), VALUES(claim_token), claim_token), expires_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at)",
            $key,
            $claim_token,
            absint($post_id),
            sanitize_key($event),
            $expires_at
        );
        $result = $wpdb->query($query);
        if (false === $result || '' !== $wpdb->last_error) {
            return null;
        }
        $stored_token = $wpdb->get_var($wpdb->prepare(
            "SELECT claim_token FROM {$this->event_claim_table_name()} WHERE claim_key = %s LIMIT 1",
            $key
        ));
        if ('' !== $wpdb->last_error) {
            return null;
        }
        if (is_string($stored_token) && hash_equals($claim_token, $stored_token)) {
            return 'table|' . $key . '|' . $claim_token;
        }
        return false;
    }

    private function release_event_claim($key)
    {
        if (! is_string($key) || $key === '') {
            return;
        }
        if (strpos($key, 'table|') === 0) {
            $parts = explode('|', $key, 3);
            if (count($parts) === 3) {
                global $wpdb;
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$this->event_claim_table_name()} WHERE claim_key = %s AND claim_token = %s",
                    $parts[1],
                    $parts[2]
                ));
            }
        }
    }

    private function event_client_fingerprint()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr(trim((string) $_SERVER['HTTP_USER_AGENT']), 0, 200) : '';
        if ($ip === '' && $user_agent === '') {
            return '';
        }
        // 生のIPやUAは保存せず、サイト固有ソルトで一方向ハッシュ化する。
        return hash_hmac('sha256', $ip . '|' . $user_agent, wp_salt('nonce'));
    }

    private function event_is_rate_limited($post_id, $event)
    {
        $fingerprint = $this->event_client_fingerprint();
        if ($fingerprint === '') {
            return false;
        }
        $limit = (int) apply_filters('ship_modal_event_rate_limit', 60);
        $limit = max(10, min(600, $limit));
        $bucket = gmdate('YmdHi');
        $key = 'ship_modal_rate_' . substr(hash_hmac('sha256', absint($post_id) . '|' . $event . '|' . $fingerprint . '|' . $bucket, wp_salt('auth')), 0, 40);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, 120);
        return false;
    }

    private function render_image_content($image_id, $link_url, $alt, $new_tab = false, $mobile_image_id = 0)
    {
        $image_id = absint($image_id);
        $mobile_image_id = absint($mobile_image_id);
        if (! $image_id && $mobile_image_id) {
            // 基本画像が未設定でも、スマホ用画像を最低限のフォールバックとして表示する。
            $image_id = $mobile_image_id;
            $mobile_image_id = 0;
        }
        if (! $image_id) {
            return '';
        }
        // モーダルは初期状態がhiddenのため、画像本体は開く直前まで取得しない。
        // width/heightは残してレイアウトシフトを防ぎ、JSでdata属性をsrcへ戻す。
        $image = wp_get_attachment_image($image_id, 'full', false, array('class' => 'ship-modal__image', 'alt' => $alt, 'loading' => 'lazy', 'fetchpriority' => 'low'));
        if (! $image) {
            return '';
        }
        $image_url = wp_get_attachment_image_url($image_id, 'full');
        if ($image_url) {
            $placeholder = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
            $image = preg_replace(
                '/\ssrc=("|\')[^"\']*\1/i',
                ' src="' . esc_attr($placeholder) . '" data-ship-modal-src="' . esc_attr($image_url) . '"',
                $image,
                1
            );
            if (function_exists('wp_get_attachment_image_srcset')) {
                $srcset = wp_get_attachment_image_srcset($image_id, 'full');
                if ($srcset) {
                    $image = preg_replace(
                        '/\ssrcset=("|\')[^"\']*\1/i',
                        ' data-ship-modal-srcset="' . esc_attr($srcset) . '"',
                        $image,
                        1
                    );
                }
            }
            $image = preg_replace(
                '/\ssizes=("|\')[^"\']*\1/i',
                ' data-ship-modal-sizes="100vw"',
                $image,
                1
            );
            if (strpos($image, 'data-ship-modal-src=') === false) {
                $image = preg_replace('/<img\s/i', '<img data-ship-modal-src="' . esc_attr($image_url) . '" ', $image, 1);
            }
        }
        if ($mobile_image_id && $mobile_image_id !== $image_id) {
            $mobile_srcset = function_exists('wp_get_attachment_image_srcset')
                ? wp_get_attachment_image_srcset($mobile_image_id, 'full')
                : '';
            if (! $mobile_srcset) {
                $mobile_srcset = wp_get_attachment_image_url($mobile_image_id, 'full');
            }
            if ($mobile_srcset) {
                $image = '<picture><source media="(max-width: 767px)" data-ship-modal-srcset="' . esc_attr($mobile_srcset) . '">' . $image . '</picture>';
            }
        }
        $link_url = esc_url($link_url);
        $target = $new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';
        return $link_url ? '<a class="ship-modal__link" data-ship-modal-action="image" href="' . $link_url . '"' . $target . '>' . $image . '</a>' : $image;
    }

    private function render_button_markup($buttons)
    {
        if (! is_array($buttons) || ! $buttons) {
            return '';
        }
        $markup = '';
        $button_count = 0;
        foreach ($buttons as $button) {
            if (! is_array($button) || ! isset($button['label']) || trim(wp_strip_all_tags((string) $button['label'])) === '') {
                continue;
            }
            $action = isset($button['action']) && 'close' === $button['action'] ? 'close' : 'link';
            if ('link' === $action && empty($button['url'])) {
                continue;
            }
            $style = isset($button['style']) && 'secondary' === $button['style'] ? 'secondary' : 'primary';
            $target = ! empty($button['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
            $label = wp_kses($button['label'], array('br' => array()));
            $label_attr = esc_attr(wp_strip_all_tags($label));
            if ('close' === $action) {
                $markup .= '<button type="button" class="ship-modal__button ship-modal__button--' . esc_attr($style) . '" data-ship-modal-action="close" data-ship-modal-label="' . $label_attr . '" data-ship-modal-close>' . $label . '</button>';
            } else {
                $markup .= '<a class="ship-modal__button ship-modal__button--' . esc_attr($style) . '" data-ship-modal-action="button" data-ship-modal-label="' . $label_attr . '" href="' . esc_url($button['url']) . '"' . $target . '>' . $label . '</a>';
            }
            $button_count++;
        }
        return $markup ? '<div class="ship-modal__buttons ship-modal__buttons--count-' . absint($button_count) . '">' . $markup . '</div>' : '';
    }

    private function page_has_copy($page)
    {
        if (! is_array($page)) {
            return false;
        }
        $heading = isset($page['heading']) ? trim(wp_strip_all_tags((string) $page['heading'])) : '';
        $body = isset($page['body']) ? $page['body'] : (isset($page['html']) ? $page['html'] : '');
        if ($heading !== '' || trim(wp_strip_all_tags((string) $body)) !== '') {
            return true;
        }
        if (! empty($page['buttons']) && is_array($page['buttons'])) {
            foreach ($page['buttons'] as $button) {
                if (is_array($button) && trim(wp_strip_all_tags(isset($button['label']) ? (string) $button['label'] : '')) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    private function render_page_content($page, $title, $index)
    {
        $page = is_array($page) ? $page : array();
        $image = $this->render_image_content(isset($page['image_id']) ? $page['image_id'] : 0, isset($page['link_url']) ? $page['link_url'] : '', $title . ' ' . ((int) $index + 1) . 'ページ目', ! empty($page['link_new_tab']));
        $heading = isset($page['heading']) ? $page['heading'] : '';
        $body = isset($page['body']) ? $page['body'] : (isset($page['html']) ? wp_strip_all_tags($page['html']) : '');
        $buttons = isset($page['buttons']) ? $page['buttons'] : array();
        $copy = ($heading !== '' ? '<h3>' . esc_html($heading) . '</h3>' : '') . ($body !== '' ? '<p>' . wp_kses($body, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) . '</p>' : '') . $this->render_button_markup($buttons);
        $markup = $image ? '<div class="ship-modal__page-media">' . $image . '</div>' : '';
        if ($copy !== '') {
            $markup .= '<div class="ship-modal__page-html">' . $copy . '</div>';
        }
        return $markup;
    }

    private function render_modal($post_id, $shortcode = false, $preview = false)
    {
        $post_id = absint($post_id);
        if (! $post_id || (! $preview && isset($this->rendered_modal_ids[$post_id]))) {
            return '';
        }
        if (! $preview && $this->is_schedule_expired($post_id)) {
            return '';
        }
        $type = $this->meta($post_id, 'content_type', 'html');
        $design = $this->meta($post_id, 'design', 'center');
        $trigger = $this->meta($post_id, 'trigger', 'auto');
        $frequency = $this->meta($post_id, 'frequency', 'session');
        $delay = max(0, (int) $this->meta($post_id, 'delay', 2));
        if ($preview) {
            $trigger = 'auto';
            $frequency = 'always';
            $delay = 0;
        }
        $scroll_threshold = min(95, max(10, (int) $this->meta($post_id, 'scroll_threshold', 50)));
        $show_close = '1' === $this->meta($post_id, 'show_close', '1');
        $close_overlay = '1' === $this->meta($post_id, 'close_overlay', '1');
        $show_backdrop = '1' === $this->meta($post_id, 'show_backdrop', '1');
        if (! $show_close && ! $close_overlay) {
            $show_close = true;
        }
        $schedule_start = $this->schedule_timestamp($this->meta($post_id, 'start_at'));
        $schedule_end = $this->schedule_timestamp($this->meta($post_id, 'end_at'));
        $title = get_the_title($post_id);
        $image_alt = $this->meta($post_id, 'image_alt', '');
        $image_alt = $image_alt !== '' ? $image_alt : $title;
        $mobile_image_id = absint($this->meta($post_id, 'image_id_mobile', 0));
        $image_position = $this->meta($post_id, 'image_position', 'top');
        $heading = $this->meta($post_id, 'heading');
        $body = $this->meta($post_id, 'body');
        $buttons = $this->meta($post_id, 'buttons', array());
        $custom_css = $this->sanitize_custom_css($this->meta($post_id, 'custom_css', ''));
        $border_radius = min(48, max(0, (int) $this->meta($post_id, 'border_radius', 0)));
        $padding = min(64, max(0, (int) $this->meta($post_id, 'padding', 20)));
        $max_width = min(1200, max(280, (int) $this->meta($post_id, 'max_width', 620)));
        $theme_colors = $this->theme_colors($post_id);
        $modal_style = '--ship-modal-radius:' . $border_radius . 'px;--ship-modal-padding:' . $padding . 'px;--ship-modal-max-width:' . $max_width . 'px;'
            . '--ship-modal-surface:' . $theme_colors['surface'] . ';--ship-modal-accent:' . $theme_colors['accent'] . ';'
            . '--ship-modal-text:' . $theme_colors['text'] . ';--ship-modal-border:#e2e8f0;'
            . '--ship-modal-secondary:#f1f5f9;'
            . '--ship-modal-close-bg:' . $theme_colors['accent'] . ';--ship-modal-close-text:' . $theme_colors['surface'] . ';'
            . '--ship-modal-overlay:' . ($show_backdrop ? 'rgba(15,23,42,.45)' : 'transparent') . ';';
        $modal_id = 'ship-modal-' . absint($post_id) . '-' . wp_rand(100, 999);
        $content = '';
        $content_class = '';
        if ('image' === $type) {
            $content = $this->render_image_content($this->meta($post_id, 'image_id'), $this->meta($post_id, 'link_url'), $image_alt, '1' === $this->meta($post_id, 'link_new_tab', '0'), $mobile_image_id);
            $content_class = ' ship-modal__content--flush';
        } elseif ('hybrid' === $type) {
            $image = $this->render_image_content($this->meta($post_id, 'image_id'), $this->meta($post_id, 'link_url'), $image_alt, '1' === $this->meta($post_id, 'link_new_tab', '0'), $mobile_image_id);
            $body = $body !== '' ? $body : wp_strip_all_tags($this->meta($post_id, 'html'));
            $copy = ($heading !== '' ? '<h2>' . esc_html($heading) . '</h2>' : '') . ($body !== '' ? '<p>' . wp_kses($body, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) . '</p>' : '') . $this->render_button_markup($buttons);
            if ($image !== '' && $copy !== '') {
                $content = '<div class="ship-modal__hybrid ship-modal__hybrid--' . esc_attr($image_position) . '"><div class="ship-modal__hybrid-media">' . $image . '</div><div class="ship-modal__hybrid-html">' . $copy . '</div></div>';
            } elseif ($image !== '') {
                $content = $image;
                $content_class = ' ship-modal__content--flush';
            } elseif ($copy !== '') {
                $content = '<div class="ship-modal__text">' . $copy . '</div>';
            }
        } elseif ('text' === $type) {
            $copy = ($heading !== '' ? '<h2>' . esc_html($heading) . '</h2>' : '') . ($body !== '' ? '<p>' . wp_kses($body, array('strong' => array(), 'br' => array(), 'a' => array('href' => true, 'target' => true, 'rel' => true))) . '</p>' : '') . $this->render_button_markup($buttons);
            if ($copy !== '') {
                $content = '<div class="ship-modal__text">' . $copy . '</div>';
            }
        } elseif ('pager' === $type) {
            $pages = $this->meta($post_id, 'pages', array());
            if (is_array($pages)) {
                $pages = array_values($pages);
            } else {
                $pages = array();
            }
            $pages = array_filter($pages, function ($page) {
                return is_array($page) && (! empty($page['image_id']) || $this->page_has_copy($page));
            });
            $pages = array_values($pages);
            if ($pages) {
                $page_markup = '';
                $pager_has_copy = false;
                $page_count = count($pages);
                foreach ($pages as $index => $page) {
                    if ($this->page_has_copy($page)) {
                        $pager_has_copy = true;
                    }
                    $page_markup .= '<section id="' . esc_attr($modal_id . '-page-' . $index) . '" class="ship-modal__page' . (0 === $index ? ' is-active' : '') . '" data-ship-modal-page-panel="' . esc_attr($index) . '" role="group" aria-label="' . esc_attr(((int) $index + 1) . ' / ' . $page_count . 'ページ') . '"' . (0 === $index ? '' : ' hidden') . ' aria-hidden="' . (0 === $index ? 'false' : 'true') . '">' . $this->render_page_content($page, $title, $index) . '</section>';
                }
                $controls = '<nav class="ship-modal__pager" aria-label="モーダルページ切り替え"><button type="button" class="ship-modal__pager-arrow" data-ship-modal-page-prev aria-label="前のページ" disabled>前へ</button><div class="ship-modal__pager-dots">';
                foreach ($pages as $index => $page) {
                    $controls .= '<button type="button" class="ship-modal__pager-dot' . (0 === $index ? ' is-active' : '') . '" data-ship-modal-page="' . esc_attr($index) . '" aria-controls="' . esc_attr($modal_id . '-page-' . $index) . '" aria-label="' . esc_attr(((int) $index + 1) . 'ページ目') . '"' . (0 === $index ? ' aria-current="true"' : '') . '>' . esc_html((string) ((int) $index + 1)) . '</button>';
                }
                $controls .= '</div><span class="screen-reader-text" data-ship-modal-page-status aria-live="polite">1 / ' . esc_html($page_count) . 'ページ</span><button type="button" class="ship-modal__pager-arrow" data-ship-modal-page-next aria-label="次のページ"' . ($page_count < 2 ? ' disabled' : '') . '>次へ</button></nav>';
                $content = '<div class="ship-modal__pages" data-ship-modal-page-count="' . esc_attr($page_count) . '">' . $page_markup . $controls . '</div>';
                $content_class = ' ship-modal__content--pager' . ($pager_has_copy ? '' : ' ship-modal__content--pager-image-only');
            }
        } else {
            $content = wp_kses_post($this->meta($post_id, 'html'));
        }
        if ($content === '') {
            return '';
        }
        if (! $preview) {
            $this->rendered_modal_ids[$post_id] = true;
        }
        // 画像専用運用では自動生成の見出しを出さず、dialogのaria-labelだけで名前を付ける。
        // HTML等を再開するフラグへ戻した場合は、従来どおりスクリーンリーダー用h2を出力する。
        $show_modal_title_heading = ! $this->is_image_only_mode();
        ob_start();
        if ('manual' === $trigger) {
            $button_text = $this->meta($post_id, 'trigger_text', 'キャンペーン詳細を見る');
            $trigger_bg_color = sanitize_hex_color($this->meta($post_id, 'trigger_bg_color', '#0f766e')) ?: '#0f766e';
            $trigger_text_color = sanitize_hex_color($this->meta($post_id, 'trigger_text_color', '#ffffff')) ?: '#ffffff';
            $trigger_position = $this->meta($post_id, 'trigger_position', 'right');
            if (! in_array($trigger_position, array('left', 'center', 'right'), true)) {
                $trigger_position = 'right';
            }
            $trigger_class = $shortcode ? 'ship-modal-trigger ship-modal-trigger--inline-' . $trigger_position : 'ship-modal-trigger ship-modal-trigger--floating ship-modal-trigger--floating-' . $trigger_position;
            $trigger_style = '--ship-modal-trigger-bg:' . $trigger_bg_color . ';--ship-modal-trigger-color:' . $trigger_text_color . ';';
            echo '<button type="button" class="' . esc_attr($trigger_class) . '" style="' . esc_attr($trigger_style) . '" data-ship-modal-target="' . esc_attr($modal_id) . '" aria-haspopup="dialog" aria-controls="' . esc_attr($modal_id) . '" aria-expanded="false" hidden>' . wp_kses($button_text, array('br' => array())) . '</button>';
        }
        ?>
        <?php if ($custom_css !== '') : ?><style id="ship-modal-custom-css-<?php echo absint($post_id); ?>"><?php echo $custom_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style><?php endif; ?>
        <div id="<?php echo esc_attr($modal_id); ?>" class="ship-modal ship-modal--<?php echo esc_attr($design); ?> ship-modal--id-<?php echo absint($post_id); ?>" style="<?php echo esc_attr($modal_style); ?>" data-post-id="<?php echo absint($post_id); ?>" data-event-token="<?php echo esc_attr($this->event_token($post_id)); ?>" data-modal-title="<?php echo esc_attr($title); ?>" data-content-type="<?php echo esc_attr($type); ?>" data-design="<?php echo esc_attr($design); ?>" data-trigger="<?php echo esc_attr($trigger); ?>" data-frequency="<?php echo esc_attr($frequency); ?>" data-delay="<?php echo esc_attr($delay); ?>" data-scroll-threshold="<?php echo esc_attr($scroll_threshold); ?>" data-schedule-start="<?php echo esc_attr($schedule_start ? $schedule_start * 1000 : 0); ?>" data-schedule-end="<?php echo esc_attr($schedule_end ? $schedule_end * 1000 : 0); ?>" data-auto-open="<?php echo 'auto' === $trigger ? '1' : '0'; ?>" data-close-overlay="<?php echo $close_overlay ? '1' : '0'; ?>" data-preview="<?php echo $preview ? '1' : '0'; ?>" role="dialog" aria-modal="true" <?php if ($show_modal_title_heading && $title !== '') : ?>aria-labelledby="<?php echo esc_attr($modal_id); ?>-title"<?php else : ?>aria-label="<?php echo esc_attr($title !== '' ? $title : 'モーダル'); ?>"<?php endif; ?> hidden>
            <div class="ship-modal__backdrop" data-ship-modal-close></div>
            <div class="ship-modal__dialog" role="document" tabindex="-1">
                <?php if ($show_modal_title_heading && $title !== '') : ?><h2 id="<?php echo esc_attr($modal_id); ?>-title" class="screen-reader-text"><?php echo esc_html($title); ?></h2><?php endif; ?>
                <?php if ($show_close) : ?><button type="button" class="ship-modal__close" aria-label="閉じる" data-ship-modal-close><span aria-hidden="true">×</span></button><?php endif; ?>
                <div class="ship-modal__content<?php echo esc_attr($content_class); ?>"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function render_sitewide_modals()
    {
        foreach ($this->active_modal_ids() as $post_id) {
            if ($this->is_scope_visible($post_id)) {
                echo $this->render_modal($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
    }

    public function shortcode($atts)
    {
        $atts = shortcode_atts(array('id' => 0), $atts, 'ship_modal');
        $post_id = absint($atts['id']);
        if (! $post_id || 'ship_modal' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id)) {
            return '';
        }
        wp_enqueue_style('ship-modal', SHIP_MODAL_URL . 'assets/css/modal.css', array(), SHIP_MODAL_VERSION);
        wp_enqueue_script('ship-modal', SHIP_MODAL_URL . 'assets/js/modal.js', array(), SHIP_MODAL_VERSION, true);
        wp_localize_script('ship-modal', 'ShipModalConfig', $this->front_script_config());
        return $this->render_modal($post_id, true);
    }

    private function increment_counter_meta($post_id, $meta_key)
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
            absint($post_id),
            $meta_key
        ));
        if (0 === $updated) {
            if (! add_post_meta($post_id, $meta_key, 1, true)) {
                $updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
                    absint($post_id),
                    $meta_key
                ));
                if (! is_int($updated) || $updated < 1) {
                    return false;
                }
            }
        } elseif (false === $updated) {
            return false;
        }
        return true;
    }

    public function record_event()
    {
        $post_id = isset($_POST['modal_id']) ? absint($_POST['modal_id']) : 0;
        $event = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
        if (! $post_id || 'ship_modal' !== get_post_type($post_id) || 'publish' !== get_post_status($post_id) || ! in_array($event, array('impression', 'click', 'close', 'page_view'), true)) {
            wp_send_json_error(array('message' => 'invalid request'), 400);
        }
        $event_id = isset($_POST['event_id']) ? $this->normalize_event_id(wp_unslash($_POST['event_id'])) : '';
        if ('' === $event_id) {
            wp_send_json_error(array('message' => 'invalid event_id'), 400);
        }
        $submitted_token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $valid_token = $submitted_token !== '' && $this->verify_event_token($post_id, $submitted_token);
        $legacy_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        $valid_legacy_nonce = $legacy_nonce !== '' && wp_verify_nonce($legacy_nonce, 'ship_modal_event');
        if (! $valid_token && ! $valid_legacy_nonce) {
            wp_send_json_error(array('message' => 'invalid token'), 403);
        }
        if (! $this->is_in_schedule($post_id)) {
            wp_send_json_success(array('recorded' => false, 'reason' => 'outside_schedule'));
        }
        if ($this->event_is_rate_limited($post_id, $event)) {
            wp_send_json_success(array('recorded' => false, 'reason' => 'rate_limited'));
        }
        $event_claim = $this->claim_event_id($post_id, $event, $event_id);
        if (false === $event_claim) {
            // sendBeacon/fetchの再送や、同じリクエストのリトライは一度だけ集計する。
            wp_send_json_success(array('recorded' => false, 'reason' => 'duplicate'));
        }
        if (null === $event_claim) {
            // 重複排除を確保できない場合は、完全性を優先して集計を行わない。
            wp_send_json_error(array('message' => 'event deduplication is unavailable'), 503);
        }
        $keys = array(
            'impression' => '_ship_modal_impressions',
            'click' => '_ship_modal_clicks',
            'close' => '_ship_modal_closes',
            'page_view' => '_ship_modal_page_views',
        );
        $key = $keys[$event];

        // 集計画面・CSV用の日別データも同時に保存する。既存のメタ集計は互換性のため残す。
        global $wpdb;
        if (! $this->maybe_upgrade_stats_table()) {
            $this->release_event_claim($event_claim);
            wp_send_json_error(array('message' => 'stats table is unavailable'), 500);
        }
        $daily_query = $wpdb->prepare(
            "INSERT INTO {$this->stats_table_name()} (modal_id, stat_date, event_name, event_count) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE event_count = event_count + 1",
            $post_id,
            current_time('Y-m-d'),
            $event
        );
        $daily_recorded = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (false === $wpdb->query('START TRANSACTION')) {
                $this->release_event_claim($event_claim);
                wp_send_json_error(array('message' => 'could not start transaction'), 500);
            }
            if (false !== $wpdb->query($daily_query)) {
                $daily_recorded = true;
                break;
            }
            $wpdb->query('ROLLBACK');
            if (0 === $attempt) {
                delete_option('ship_modal_stats_db_version');
                delete_transient('ship_modal_stats_schema_checked');
                if (! $this->maybe_upgrade_stats_table()) {
                    $this->release_event_claim($event_claim);
                    wp_send_json_error(array('message' => 'stats table repair failed'), 500);
                }
            }
        }
        if (! $daily_recorded) {
            $this->release_event_claim($event_claim);
            wp_send_json_error(array('message' => 'could not record event'), 500);
        }
        if (! $this->increment_counter_meta($post_id, $key)) {
            $wpdb->query('ROLLBACK');
            $this->release_event_claim($event_claim);
            wp_send_json_error(array('message' => 'could not update event total'), 500);
        }
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            $this->release_event_claim($event_claim);
            wp_send_json_error(array('message' => 'could not commit event'), 500);
        }
        wp_cache_delete(absint($post_id), 'post_meta');
        wp_send_json_success();
    }

    public function export_stats()
    {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (! $this->can_manage_modal() || ! $post_id || 'ship_modal' !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id)) {
            wp_die('モーダルを操作する権限がありません。', 'Ship Modal', array('response' => 403));
        }
        check_admin_referer('ship_modal_export_stats_' . $post_id);

        $from = isset($_GET['from']) ? $this->stats_date(sanitize_text_field(wp_unslash($_GET['from'])), '') : '';
        $to = isset($_GET['to']) ? $this->stats_date(sanitize_text_field(wp_unslash($_GET['to'])), '') : '';
        if ($from && $to && $from > $to) {
            $temporary = $from;
            $from = $to;
            $to = $temporary;
        }
        $rows = $this->get_daily_stats($post_id, $from, $to);
        if ($this->stats_db_error !== '') {
            wp_die('日別集計を読み込めないため、CSVを出力できませんでした。データベースを確認してください。', 'Ship Modal', array('response' => 500));
        }
        $labels = $this->stats_event_labels();
        // 現行の画像専用モードではページャーを使用しないため、CSVにも出力しない。
        // 将来モードを戻した場合は既存のページャー集計をそのまま出力できるよう、保存データは保持する。
        if ($this->is_image_only_mode()) {
            unset($labels['page_view']);
        }
        $title = get_the_title($post_id);
        $period_totals = array_fill_keys(array_keys($labels), 0);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ship-modal-' . $post_id . '-stats-' . current_time('Ymd-His') . '.csv"');
        $output = fopen('php://output', 'w');
        if (false === $output) {
            wp_die('CSVを作成できませんでした。', 'Ship Modal', array('response' => 500));
        }
        fwrite($output, "\xEF\xBB\xBF");
        $this->write_csv_row($output, array('モーダルID', 'タイトル', '日付', 'イベント', '件数'));
        foreach ($rows as $row) {
            // 画像専用モードで保持している過去のページャー行もCSVへは出さない。
            if (! isset($labels[$row->event_name])) {
                continue;
            }
            $event_label = $labels[$row->event_name];
            $count = (int) $row->event_count;
            if (isset($period_totals[$row->event_name])) {
                $period_totals[$row->event_name] += $count;
            }
            $this->write_csv_row($output, array($post_id, $title, $row->stat_date, $event_label, $count));
        }
        if ($from === '' && $to === '') {
            foreach ($this->counter_meta_keys() as $event => $meta_key) {
                if (! isset($period_totals[$event])) {
                    continue;
                }
                $legacy_count = max(0, (int) get_post_meta($post_id, $meta_key, true) - $period_totals[$event]);
                if ($legacy_count > 0) {
                    $this->write_csv_row($output, array($post_id, $title, '日別導入前', $labels[$event], $legacy_count));
                    $period_totals[$event] += $legacy_count;
                }
            }
        }
        foreach ($period_totals as $event => $count) {
            $this->write_csv_row($output, array($post_id, $title, '期間合計', isset($labels[$event]) ? $labels[$event] : $event, $count));
        }
        fclose($output);
        exit;
    }

    public function reset_stats()
    {
        if (! isset($_SERVER['REQUEST_METHOD']) || 'POST' !== strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))) {
            wp_die('不正なリクエストです。', 'Ship Modal', array('response' => 405));
        }
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (! $this->can_manage_modal() || ! $post_id || 'ship_modal' !== get_post_type($post_id) || ! current_user_can('edit_post', $post_id)) {
            wp_die('モーダルを操作する権限がありません。', 'Ship Modal', array('response' => 403));
        }
        check_admin_referer('ship_modal_reset_stats_' . $post_id);

        global $wpdb;
        if (! $this->maybe_upgrade_stats_table()) {
            wp_die('日別集計テーブルを準備できませんでした。', 'Ship Modal', array('response' => 500));
        }
        if (! $this->ensure_counter_meta($post_id)) {
            wp_die('累計データを初期化できませんでした。', 'Ship Modal', array('response' => 500));
        }
        $daily_deleted = false;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (false === $wpdb->query('START TRANSACTION')) {
                wp_die('計測リセットを開始できませんでした。', 'Ship Modal', array('response' => 500));
            }
            if (false !== $wpdb->delete($this->stats_table_name(), array('modal_id' => $post_id), array('%d'))) {
                $daily_deleted = true;
                break;
            }
            $wpdb->query('ROLLBACK');
            if (0 === $attempt) {
                delete_option('ship_modal_stats_db_version');
                delete_transient('ship_modal_stats_schema_checked');
                if (! $this->maybe_upgrade_stats_table()) {
                    wp_die('日別集計テーブルを修復できませんでした。', 'Ship Modal', array('response' => 500));
                }
            }
        }
        if (! $daily_deleted) {
            wp_die('計測データを削除できませんでした。', 'Ship Modal', array('response' => 500));
        }
        $counter_keys = array_values($this->counter_meta_keys());
        $placeholders = implode(',', array_fill(0, count($counter_keys), '%s'));
        $parameters = array_merge(array(absint($post_id)), $counter_keys);
        $meta_result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = '0' WHERE post_id = %d AND meta_key IN ({$placeholders})",
            $parameters
        ));
        if (false === $meta_result || false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            wp_die('累計データをリセットできませんでした。', 'Ship Modal', array('response' => 500));
        }
        wp_cache_delete(absint($post_id), 'post_meta');

        $location = add_query_arg(
            array('post' => $post_id, 'action' => 'edit', 'ship_modal_stats_reset' => '1'),
            admin_url('post.php')
        );
        wp_safe_redirect($location);
        exit;
    }

    private function write_csv_row($output, $cells)
    {
        $safe_cells = array_map(function ($value) {
            $value = (string) $value;
            if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value)) {
                $value = "'" . $value;
            }
            return $value;
        }, $cells);
        return fputcsv($output, $safe_cells, ',', '"', '');
    }

    public function delete_modal_stats($post_id)
    {
        if ('ship_modal' !== get_post_type($post_id)) {
            return;
        }
        global $wpdb;
        if ('1.1' === get_option('ship_modal_stats_db_version')) {
            $wpdb->delete($this->stats_table_name(), array('modal_id' => absint($post_id)), array('%d'));
        }
        $claims_table = $this->event_claim_table_name();
        $claims_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($claims_table)));
        if ($claims_exists === $claims_table) {
            $wpdb->delete($claims_table, array('modal_id' => absint($post_id)), array('%d'));
        }
    }

    /**
     * Ship Modalを複製した直後に、累計・日別の計測を引き継がないようにする。
     *
     * Yoast Duplicate Postは投稿メタをコピーした後に
     * duplicate_post_after_duplicatedを実行するため、ここで新しい投稿IDだけを
     * 対象に初期化する。保存データの形式を将来戻せるよう、メタキー自体は残して0にする。
     *
     * @param int     $new_post_id 複製先の投稿ID。
     * @param WP_Post $source_post 複製元の投稿。
     * @param string  $status      複製先のステータス。
     * @param string  $post_type  複製元の投稿タイプ。
     * @return void
     */
    public function reset_duplicated_modal_stats($new_post_id, $source_post = null, $status = '', $post_type = '')
    {
        $new_post_id = absint($new_post_id);
        if (! $new_post_id || 'ship_modal' !== get_post_type($new_post_id)) {
            return;
        }

        foreach ($this->counter_meta_keys() as $meta_key) {
            update_post_meta($new_post_id, $meta_key, 0);
        }

        // 通常は日別テーブルの行は複製されないが、万一同じIDで作成されていた場合も除去する。
        $this->delete_modal_stats($new_post_id);
        wp_cache_delete($new_post_id, 'post_meta');
    }

    public function render_stats_reset_notice()
    {
        if (! $this->can_manage_modal() || empty($_GET['ship_modal_stats_reset']) || '1' !== sanitize_text_field(wp_unslash($_GET['ship_modal_stats_reset']))) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>このモーダルの計測データをリセットしました。</p></div>';
    }
}

register_activation_hook(SHIP_MODAL_FILE, array('Ship_Modal', 'activate'));
register_deactivation_hook(SHIP_MODAL_FILE, array('Ship_Modal', 'deactivate'));
