<?php
/**
 * Plugin Name: Ship Modal
 * Description: HTML・画像バナー・期間指定・表示頻度・計測に対応したモーダル管理プラグイン。
 * Version: 1.8.2
 * Requires at least: 4.8
 * Requires PHP: 7.4
 * Author: Ship Inc.
 * License: GPL-2.0-or-later
 * Text Domain: ship-modal
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SHIP_MODAL_VERSION', '1.8.2');
define('SHIP_MODAL_FILE', __FILE__);
define('SHIP_MODAL_DIR', plugin_dir_path(__FILE__));
define('SHIP_MODAL_URL', plugin_dir_url(__FILE__));
define('SHIP_MODAL_REPOSITORY', 'https://github.com/ship-git-admin/ship-modal');

// 現行リリースは画像専用UIで運用する。wp-config.phpでfalseを先に定義するか、
// ship_modal_image_only_modeフィルターでfalseを返すと、HTML等と詳細レイアウト設定の入力UIを再開できる。
if (! defined('SHIP_MODAL_IMAGE_ONLY_MODE')) {
    define('SHIP_MODAL_IMAGE_ONLY_MODE', true);
}

require_once SHIP_MODAL_DIR . 'includes/class-ship-modal.php';

Ship_Modal::instance();

// GitHubのmainブランチを参照する自動アップデータ。
if (file_exists(SHIP_MODAL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php')) {
    require_once SHIP_MODAL_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

    $ship_modal_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        SHIP_MODAL_REPOSITORY,
        SHIP_MODAL_FILE,
        'ship-modal'
    );
    $ship_modal_update_checker->setBranch('main');
    // main/masterはPUC既定で古いRelease・tagが優先されるため、
    // このプラグインでは常にmainブランチのVersionヘッダーを正とする。
    $ship_modal_update_checker->addFilter('vcs_update_detection_strategies', function ($strategies) {
        return isset($strategies['branch']) ? array('branch' => $strategies['branch']) : $strategies;
    });
}
