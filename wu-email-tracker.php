<?php
/**
 * Plugin Name: WU Email Tracker
 * Plugin URI:  https://wulk.cc
 * Description: WordPress 郵件追蹤與管理系統 - 支援額度管理、Resend API、SMTP、Discord 通知
 * Version:     1.0.0
 * Author:      Wumetax
 * Author URI:  https://wulk.cc
 * License:     GPL-2.0+
 * Text Domain: wu-email-tracker
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ===== 常數定義 =====

define( 'WU_EMAIL_TRACKER_VERSION', '1.0.0' );
define( 'WU_EMAIL_TRACKER_DB_VERSION', '1.1' );
define( 'WU_EMAIL_TRACKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WU_EMAIL_TRACKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ===== 啟用 / 停用 Hook =====

register_activation_hook( __FILE__, 'wu_email_tracker_install' );
register_deactivation_hook( __FILE__, 'wu_email_tracker_deactivate' );

function wu_email_tracker_deactivate() {
    // 停用時不刪除資料,保留紀錄
}

// ===== Debug 日誌功能 =====

function wu_debug_log( $message ) {
    if ( ! get_option( 'wu_email_debug_enabled', 0 ) ) {
        return;
    }
    $log_file  = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
    $timestamp = date( 'Y-m-d H:i:s' );
    file_put_contents( $log_file, "[{$timestamp}] {$message}\n", FILE_APPEND );
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
        error_log( '[WU Email Tracker] ' . $message );
    }
}

// ===== 資料表安裝 / 更新 =====

function wu_email_tracker_install() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'wu_email_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        to_email varchar(255) NOT NULL,
        subject text NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'sent',
        error_message text,
        sent_time datetime DEFAULT CURRENT_TIMESTAMP,
        send_method varchar(50) DEFAULT 'default',
        PRIMARY KEY  (id),
        KEY sent_time (sent_time),
        KEY status (status)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE \'{$table_name}\'" );
    if ( $table_exists ) {
        wu_email_tracker_check_columns( $table_name );
        update_option( 'wu_email_tracker_db_version', WU_EMAIL_TRACKER_DB_VERSION );
    }
}

function wu_email_tracker_check_columns( $table_name ) {
    global $wpdb;
    $columns          = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}" );
    $existing_columns = array_column( (array) $columns, 'Field' );

    if ( ! in_array( 'send_method', $existing_columns ) ) {
        $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN send_method varchar(50) DEFAULT 'default' AFTER sent_time" );
    }
}

add_action( 'admin_init', 'wu_email_tracker_check_table' );
add_action( 'plugins_loaded', 'wu_email_tracker_check_table_version' );

function wu_email_tracker_check_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wu_email_logs';
    if ( $wpdb->get_var( "SHOW TABLES LIKE \'{$table_name}\'" ) !== $table_name ) {
        wu_email_tracker_install();
    }
}

function wu_email_tracker_check_table_version() {
    $current_version = get_option( 'wu_email_tracker_db_version', '0' );
    if ( version_compare( $current_version, WU_EMAIL_TRACKER_DB_VERSION, '<' ) ) {
        wu_email_tracker_install();
    }
}

// ===== 預設選項初始化 =====

add_action( 'admin_init', function () {
    add_option( 'wu_email_tracker_enabled', 1 );
    add_option( 'wu_email_daily_limit', 20 );
    add_option( 'wu_email_monthly_limit', 600 );
    add_option( 'wu_email_discord_webhook', '' );
    add_option( 'wu_email_send_method', 'default' );
    add_option( 'wu_email_resend_api_key', '' );
    add_option( 'wu_email_resend_from_email', '' );
    add_option( 'wu_email_resend_from_name', get_bloginfo( 'name' ) );
    add_option( 'wu_email_smtp_host', '' );
    add_option( 'wu_email_smtp_port', '587' );
    add_option( 'wu_email_smtp_encryption', 'tls' );
    add_option( 'wu_email_smtp_username', '' );
    add_option( 'wu_email_smtp_password', '' );
    add_option( 'wu_email_smtp_from_email', '' );
    add_option( 'wu_email_smtp_from_name', get_bloginfo( 'name' ) );
    add_option( 'wu_email_debug_enabled', 0 );
} );

global $wu_resend_email_sent;
$wu_resend_email_sent = false;

// ===== 選單註冊 (獨立頂層選單) =====

add_action( 'admin_menu', function () {
    add_menu_page(
        'WU Email Tracker',
        'WU 郵件追蹤',
        'manage_options',
        'wu-email-tracker',
        'wu_email_tracker_settings_page',
        'dashicons-email-alt2',
        30
    );
} );

// ===== Dashboard Widget =====

add_action( 'wp_dashboard_setup', function () {
    if ( ! get_option( 'wu_email_tracker_enabled', 1 ) ) {
        return;
    }
    wp_add_dashboard_widget(
        'wu_email_tracker_dashboard',
        '郵件發送追蹤管理',
        'wu_render_email_tracker_dashboard',
        null, null, 'normal', 'high'
    );
} );

function wu_render_email_tracker_dashboard() {
    global $wpdb;
    $table              = $wpdb->prefix . 'wu_email_logs';
    $send_method_option = get_option( 'wu_email_send_method', 'default' );

    switch ( $send_method_option ) {
        case 'resend':
            $send_method = 'Resend API';
            $send_status = '已啟用';
            $send_color  = '#46b450';
            break;
        case 'smtp':
            $send_method = '自訂 SMTP';
            $send_status = '已啟用';
            $send_color  = '#46b450';
            break;
        default:
            $smtp_info   = wu_detect_smtp_plugin();
            $send_method = $smtp_info['plugin_name'];
            $send_status = $smtp_info['enabled'] ? '正常運作' : '未啟用';
            $send_color  = $smtp_info['enabled'] ? '#46b450' : '#dc3232';
            break;
    }

    $today_start     = date( 'Y-m-d 00:00:00' );
    $month_start     = date( 'Y-m-01 00:00:00' );
    $today_count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $today_start ) );
    $today_blocked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'blocked'", $today_start ) );
    $month_count     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $month_start ) );
    $month_blocked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'blocked'", $month_start ) );
    $daily_limit     = (int) get_option( 'wu_email_daily_limit', 20 );
    $monthly_limit   = (int) get_option( 'wu_email_monthly_limit', 600 );
    $daily_pct       = $daily_limit > 0 ? round( ( $today_count / $daily_limit ) * 100, 1 ) : 0;
    $monthly_pct     = $monthly_limit > 0 ? round( ( $month_count / $monthly_limit ) * 100, 1 ) : 0;
    $daily_status    = wu_get_quota_status( $daily_pct );
    $monthly_status  = wu_get_quota_status( $monthly_pct );
    $is_blocked      = ( $today_count >= $daily_limit ) || ( $month_count >= $monthly_limit );
    $daily_reset     = date( 'Y-m-d H:i:s', strtotime( 'tomorrow 00:00:00' ) );
    $monthly_reset   = date( 'Y-m-d H:i:s', strtotime( 'first day of next month 00:00:00' ) );
    ?>
    <div class="wu-dashboard-container">
        <div class="wu-section">
            <h3 class="wu-section-title">
                發信狀態
                <span class="wu-monitoring-badge" style="background:<?php echo $send_color; ?>;"><?php echo $send_status; ?></span>
            </h3>
            <table class="wu-info-table">
                <tbody>
                    <tr>
                        <th>發信方式</th>
                        <td><span class="wu-status-indicator" style="color:<?php echo $send_color; ?>;"><?php echo esc_html( $send_method ); ?></span></td>
                        <td class="wu-info-meta">
                            <?php
                            if ( $send_method_option === 'resend' ) {
                                echo '使用 Resend API 服務發送郵件';
                            } elseif ( $send_method_option === 'smtp' ) {
                                echo '使用自訂 SMTP 伺服器發送郵件';
                            } else {
                                echo esc_html( $smtp_info['description'] );
                            }
                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="wu-section">
            <h3 class="wu-section-title">發信額度總覽</h3>
            <div style="margin-bottom:25px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:13px;font-weight:600;color:#1d2327;">今日發送</span>
                    <span style="font-size:13px;color:#50575e;">
                        <strong style="color:<?php echo $daily_status['color']; ?>;"><?php echo $today_count; ?></strong> / <?php echo $daily_limit; ?> 封
                        <?php if ( $today_blocked > 0 ) : ?><span style="color:#dc3232;margin-left:10px;">(被阻擋: <?php echo $today_blocked; ?>)</span><?php endif; ?>
                    </span>
                </div>
                <div class="wu-disk-bar"><div class="wu-disk-bar-fill" style="width:<?php echo min( $daily_pct, 100 ); ?>%;background:<?php echo $daily_status['color']; ?>;"></div></div>
                <div style="margin-top:8px;font-size:12px;color:<?php echo $daily_status['color']; ?>;font-weight:600;"><?php echo $daily_pct; ?>% - <?php echo $daily_status['text']; ?></div>
                <div style="margin-top:5px;font-size:11px;color:#666;">重設時間: <?php echo $daily_reset; ?></div>
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <span style="font-size:13px;font-weight:600;color:#1d2327;">本月發送</span>
                    <span style="font-size:13px;color:#50575e;">
                        <strong style="color:<?php echo $monthly_status['color']; ?>;"><?php echo $month_count; ?></strong> / <?php echo $monthly_limit; ?> 封
                        <?php if ( $month_blocked > 0 ) : ?><span style="color:#dc3232;margin-left:10px;">(被阻擋: <?php echo $month_blocked; ?>)</span><?php endif; ?>
                    </span>
                </div>
                <div class="wu-disk-bar"><div class="wu-disk-bar-fill" style="width:<?php echo min( $monthly_pct, 100 ); ?>%;background:<?php echo $monthly_status['color']; ?>;"></div></div>
                <div style="margin-top:8px;font-size:12px;color:<?php echo $monthly_status['color']; ?>;font-weight:600;"><?php echo $monthly_pct; ?>% - <?php echo $monthly_status['text']; ?></div>
                <div style="margin-top:5px;font-size:11px;color:#666;">重設時間: <?php echo $monthly_reset; ?></div>
            </div>
            <?php if ( $is_blocked ) : ?>
            <div class="wu-notice wu-notice-error" style="margin-top:20px;">
                <strong>發信額度已達上限</strong><br>
                <?php if ( $today_count >= $daily_limit ) : ?>今日發信已達到每日限制 (<?php echo $daily_limit; ?> 封),系統已自動阻擋新郵件發送。<br><?php endif; ?>
                <?php if ( $month_count >= $monthly_limit ) : ?>本月發信已達到每月限制 (<?php echo $monthly_limit; ?> 封),系統已自動阻擋新郵件發送。<br><?php endif; ?>
            </div>
            <?php elseif ( $daily_pct >= 80 || $monthly_pct >= 80 ) : ?>
            <div class="wu-notice" style="margin-top:20px;background:#fcf3cf;border-color:#996800;">
                <strong>額度即將達到上限</strong><br>請注意發信數量,避免超過額度限制。
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ===== 輔助函式 =====

function wu_detect_smtp_plugin() {
    $info = array( 'enabled' => true, 'plugin_name' => '系統預設', 'description' => '使用 WordPress 預設郵件功能' );
    if ( defined( 'FLUENTMAIL' ) ) {
        $info['plugin_name'] = 'FluentSMTP'; $info['description'] = '已安裝並啟用 FluentSMTP 外掛';
    } elseif ( class_exists( 'WPMailSMTP\Options' ) ) {
        $info['plugin_name'] = 'WP Mail SMTP'; $info['description'] = '已安裝並啟用 WP Mail SMTP 外掛';
    } elseif ( defined( 'EASY_WP_SMTP_VERSION' ) ) {
        $info['plugin_name'] = 'Easy WP SMTP'; $info['description'] = '已安裝並啟用 Easy WP SMTP 外掛';
    } elseif ( class_exists( 'PostmanOptions' ) ) {
        $info['plugin_name'] = 'Post SMTP'; $info['description'] = '已安裝並啟用 Post SMTP 外掛';
    }
    return $info;
}

function wu_get_quota_status( $percentage ) {
    if ( $percentage < 70 ) return array( 'text' => '額度充足', 'color' => '#46b450' );
    if ( $percentage < 90 ) return array( 'text' => '接近上限', 'color' => '#f0b849' );
    return array( 'text' => '達到上限', 'color' => '#dc3232' );
}

// ===== Resend API =====

function wu_send_email_via_resend( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $api_key    = get_option( 'wu_email_resend_api_key', '' );
    $from_email = get_option( 'wu_email_resend_from_email', '' );
    $from_name  = get_option( 'wu_email_resend_from_name', get_bloginfo( 'name' ) );

    if ( empty( $api_key ) || empty( $from_email ) ) {
        return array( 'success' => false, 'error' => 'API Key 或發件者 Email 未設定' );
    }

    $to_email    = is_array( $to ) ? $to[0] : $to;
    $html_message = wpautop( $message );
    $email_data  = array(
        'from'    => "{$from_name} <{$from_email}>",
        'to'      => array( $to_email ),
        'subject' => $subject,
        'html'    => $html_message,
    );

    $response = wp_remote_post( 'https://api.resend.com/emails', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ),
        'body'    => json_encode( $email_data ),
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        return array( 'success' => false, 'error' => $response->get_error_message() );
    }

    $body        = json_decode( wp_remote_retrieve_body( $response ), true );
    $status_code = wp_remote_retrieve_response_code( $response );

    if ( $status_code === 200 && isset( $body['id'] ) ) {
        return array( 'success' => true, 'id' => $body['id'] );
    }

    $error_msg = isset( $body['message'] ) ? $body['message'] : 'Unknown error';
    if ( isset( $body['name'] ) ) $error_msg = $body['name'] . ': ' . $error_msg;
    return array( 'success' => false, 'error' => $error_msg );
}

// ===== SMTP 設定 =====

add_action( 'phpmailer_init', 'wu_configure_smtp', 999 );

function wu_configure_smtp( $phpmailer ) {
    if ( get_option( 'wu_email_send_method', 'default' ) !== 'smtp' ) return;

    $smtp_host       = get_option( 'wu_email_smtp_host', '' );
    $smtp_port       = get_option( 'wu_email_smtp_port', '587' );
    $smtp_encryption = get_option( 'wu_email_smtp_encryption', 'tls' );
    $smtp_username   = get_option( 'wu_email_smtp_username', '' );
    $smtp_password   = get_option( 'wu_email_smtp_password', '' );
    $smtp_from_email = get_option( 'wu_email_smtp_from_email', '' );
    $smtp_from_name  = get_option( 'wu_email_smtp_from_name', get_bloginfo( 'name' ) );

    if ( empty( $smtp_host ) ) return;

    $phpmailer->isSMTP();
    $phpmailer->Host       = $smtp_host;
    $phpmailer->Port       = $smtp_port;
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Username   = $smtp_username;
    $phpmailer->Password   = $smtp_password;
    $phpmailer->SMTPSecure = $smtp_encryption;
    $phpmailer->From       = $smtp_from_email;
    $phpmailer->FromName   = $smtp_from_name;
}

// ===== 核心攔截邏輯 =====

add_filter( 'wp_mail', 'wu_intercept_email', 1 );

function wu_intercept_email( $args ) {
    wu_debug_log( '=== wp_mail filter TRIGGERED ===' );

    if ( ! get_option( 'wu_email_tracker_enabled', 1 ) ) {
        wu_debug_log( 'Tracker DISABLED, skipping.' );
        return $args;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wu_email_logs';

    if ( ! $wpdb->get_var( "SHOW TABLES LIKE \'{$table}\'" ) ) {
        wu_email_tracker_install();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE \'{$table}\'" ) ) {
            wu_debug_log( 'CRITICAL: Cannot create table, skipping tracking.' );
            return $args;
        }
    }

    $to_email      = is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'];
    $subject       = isset( $args['subject'] ) ? $args['subject'] : '(無主旨)';
    $today_start   = date( 'Y-m-d 00:00:00' );
    $month_start   = date( 'Y-m-01 00:00:00' );
    $today_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $today_start ) );
    $month_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $month_start ) );
    $daily_limit   = (int) get_option( 'wu_email_daily_limit', 20 );
    $monthly_limit = (int) get_option( 'wu_email_monthly_limit', 600 );

    wu_debug_log( "Quota: Today {$today_count}/{$daily_limit}, Month {$month_count}/{$monthly_limit}" );

    if ( $today_count >= $daily_limit || $month_count >= $monthly_limit ) {
        wu_debug_log( 'QUOTA EXCEEDED - blocking email.' );
        $wpdb->insert( $table, array(
            'to_email'      => $to_email,
            'subject'       => $subject,
            'status'        => 'blocked',
            'error_message' => '超過每日或每月發信額度限制',
            'send_method'   => get_option( 'wu_email_send_method', 'default' ),
            'sent_time'     => current_time( 'mysql' ),
        ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
        wu_send_quota_alert( 'blocked', $to_email, $subject );
        $args['to'] = '';
        return $args;
    }

    $send_method = get_option( 'wu_email_send_method', 'default' );

    if ( $send_method === 'resend' ) {
        $resend_result = wu_send_email_via_resend(
            $args['to'], $args['subject'], $args['message'],
            isset( $args['headers'] ) ? $args['headers'] : '',
            isset( $args['attachments'] ) ? $args['attachments'] : array()
        );

        if ( $resend_result['success'] ) {
            $wpdb->insert( $table, array(
                'to_email'      => $to_email,
                'subject'       => $subject,
                'status'        => 'sent',
                'send_method'   => 'resend_api',
                'error_message' => 'Email ID: ' . $resend_result['id'],
                'sent_time'     => current_time( 'mysql' ),
            ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
            global $wu_resend_email_sent;
            $wu_resend_email_sent = true;
        } else {
            $wpdb->insert( $table, array(
                'to_email'      => $to_email,
                'subject'       => $subject,
                'status'        => 'failed',
                'error_message' => 'Resend API: ' . $resend_result['error'],
                'send_method'   => 'resend_api',
                'sent_time'     => current_time( 'mysql' ),
            ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
        }
        wu_check_quota_warning();
        $args['to'] = '';
        return $args;
    }

    $method_label = ( $send_method === 'smtp' ) ? 'smtp' : 'default';
    $wpdb->insert( $table, array(
        'to_email'    => $to_email,
        'subject'     => $subject,
        'status'      => 'sent',
        'send_method' => $method_label,
        'sent_time'   => current_time( 'mysql' ),
    ), array( '%s', '%s', '%s', '%s', '%s' ) );

    wu_check_quota_warning();
    return $args;
}

function wu_check_quota_warning() {
    global $wpdb;
    $table         = $wpdb->prefix . 'wu_email_logs';
    $today_start   = date( 'Y-m-d 00:00:00' );
    $month_start   = date( 'Y-m-01 00:00:00' );
    $today_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $today_start ) );
    $month_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $month_start ) );
    $daily_limit   = (int) get_option( 'wu_email_daily_limit', 20 );
    $monthly_limit = (int) get_option( 'wu_email_monthly_limit', 600 );
    $daily_pct     = $daily_limit > 0 ? ( $today_count / $daily_limit ) * 100 : 0;
    $monthly_pct   = $monthly_limit > 0 ? ( $month_count / $monthly_limit ) * 100 : 0;

    if ( $daily_pct >= 80 || $monthly_pct >= 80 ) {
        $transient_key = 'wu_email_quota_warning_' . date( 'Y-m-d' );
        if ( ! get_transient( $transient_key ) ) {
            wu_send_quota_alert( 'warning' );
            set_transient( $transient_key, 1, DAY_IN_SECONDS );
        }
    }
}

function wu_send_quota_alert( $type, $to_email = '', $subject = '' ) {
    $webhook_url = get_option( 'wu_email_discord_webhook', '' );
    if ( empty( $webhook_url ) ) return;

    global $wpdb;
    $table         = $wpdb->prefix . 'wu_email_logs';
    $today_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", date( 'Y-m-d 00:00:00' ) ) );
    $month_count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", date( 'Y-m-01 00:00:00' ) ) );
    $daily_limit   = (int) get_option( 'wu_email_daily_limit', 20 );
    $monthly_limit = (int) get_option( 'wu_email_monthly_limit', 600 );

    if ( $type === 'blocked' ) {
        $title  = '🚫 郵件發送已被阻擋';
        $color  = 15158332;
        $fields = array(
            array( 'name' => '網站', 'value' => home_url(), 'inline' => false ),
            array( 'name' => '收件者', 'value' => $to_email, 'inline' => true ),
            array( 'name' => '主旨', 'value' => $subject, 'inline' => false ),
            array( 'name' => '今日發送', 'value' => "{$today_count} / {$daily_limit} 封", 'inline' => true ),
            array( 'name' => '本月發送', 'value' => "{$month_count} / {$monthly_limit} 封", 'inline' => true ),
        );
    } else {
        $title  = '⚠️ 郵件額度警告';
        $color  = 16760576;
        $fields = array(
            array( 'name' => '網站', 'value' => home_url(), 'inline' => false ),
            array( 'name' => '今日發送', 'value' => "{$today_count} / {$daily_limit} 封", 'inline' => true ),
            array( 'name' => '本月發送', 'value' => "{$month_count} / {$monthly_limit} 封", 'inline' => true ),
        );
    }

    wp_remote_post( $webhook_url, array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => json_encode( array( 'embeds' => array( array(
            'title'     => $title,
            'color'     => $color,
            'fields'    => $fields,
            'timestamp' => current_time( 'c' ),
        ) ) ) ),
        'timeout' => 15,
    ) );
}

// ===== 密碼驗證 =====

function wu_email_verify_password( $input_password ) {
    $stored = get_option( 'wu_email_admin_password', 'A4h8A*73q$Ao*X' );
    return ( $input_password === $stored );
}

// ===== AJAX 測試發信 =====

add_action( 'wp_ajax_wu_test_email', 'wu_test_email_handler' );

function wu_test_email_handler() {
    check_ajax_referer( 'wu_email_test', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => '權限不足' ) );

    $test_email = sanitize_email( $_POST['test_email'] ?? '' );
    if ( empty( $test_email ) ) wp_send_json_error( array( 'message' => '請輸入測試郵件地址' ) );

    global $wu_resend_email_sent;
    $wu_resend_email_sent = false;

    $result      = wp_mail( $test_email, '測試郵件 - WU Email Tracker', '這是一封測試郵件,用於確認郵件追蹤系統是否正常運作。\n\n發送時間: ' . current_time( 'Y-m-d H:i:s' ) . '\n網站: ' . home_url() );
    $send_method = get_option( 'wu_email_send_method', 'default' );

    if ( $send_method === 'resend' ) {
        $wu_resend_email_sent
            ? wp_send_json_success( array( 'message' => '測試郵件已透過 Resend API 發送!請重新整理頁面查看記錄。' ) )
            : wp_send_json_error( array( 'message' => '測試郵件發送失敗!請檢查 Resend API 設定。' ) );
    } else {
        $result
            ? wp_send_json_success( array( 'message' => '測試郵件已發送!請重新整理頁面查看記錄。' ) )
            : wp_send_json_error( array( 'message' => '測試郵件發送失敗!請檢查 SMTP 或郵件設定。' ) );
    }
}

// ===== 設定頁面 =====

function wu_email_tracker_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( '權限不足' );

    $password_verified = false;

    if ( isset( $_POST['wu_email_password_verify'] ) ) {
        check_admin_referer( 'wu_email_password' );
        if ( wu_email_verify_password( $_POST['admin_password'] ?? '' ) ) {
            $password_verified = true;
            set_transient( 'wu_email_password_verified_' . get_current_user_id(), 1, 1800 );
        } else {
            echo '<div class="notice notice-error is-dismissible"><p><strong>密碼錯誤!</strong> 請輸入正確的管理密碼。</p></div>';
        }
    }

    if ( get_transient( 'wu_email_password_verified_' . get_current_user_id() ) ) {
        $password_verified = true;
    }

    if ( ! $password_verified ) {
        ?>
        <div class="wrap">
            <h1>WU 郵件追蹤管理</h1>
            <div style="max-width:500px;margin:50px auto;background:#fff;padding:40px;border:1px solid #ddd;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                <h2 style="text-align:center;margin-top:0;color:#1d2327;">需要管理密碼</h2>
                <p style="text-align:center;color:#646970;margin-bottom:30px;">此頁面需要管理密碼才能存取</p>
                <form method="post">
                    <?php wp_nonce_field( 'wu_email_password' ); ?>
                    <div style="margin-bottom:20px;">
                        <label for="admin_password" style="display:block;font-weight:600;margin-bottom:8px;">管理密碼</label>
                        <input type="password" id="admin_password" name="admin_password" class="regular-text" required style="width:100%;padding:10px;font-size:16px;" autofocus>
                    </div>
                    <?php submit_button( '驗證並繼續', 'primary large', 'wu_email_password_verify', true, array( 'style' => 'width:100%;' ) ); ?>
                </form>
                <p style="text-align:center;margin-top:20px;font-size:12px;color:#999;">密碼驗證後 30 分鐘內有效</p>
            </div>
        </div>
        <?php
        return;
    }

    // 下載 Debug 日誌
    if ( isset( $_GET['wu_download_log'] ) ) {
        $log_file = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
        if ( file_exists( $log_file ) ) {
            header( 'Content-Type: text/plain' );
            header( 'Content-Disposition: attachment; filename="wu-email-tracker-debug-' . date( 'Y-m-d-His' ) . '.log"' );
            readfile( $log_file );
            exit;
        }
    }

    // 清除 Debug 日誌
    if ( isset( $_POST['wu_email_clear_log'] ) ) {
        check_admin_referer( 'wu_email_clear_log' );
        $log_file = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
        if ( file_exists( $log_file ) ) unlink( $log_file );
        echo '<div class="notice notice-success is-dismissible"><p><strong>Debug 日誌已清除</strong></p></div>';
    }

    // 儲存設定
    if ( isset( $_POST['wu_email_save'] ) ) {
        check_admin_referer( 'wu_email_settings' );
        update_option( 'wu_email_tracker_enabled', isset( $_POST['enabled'] ) ? 1 : 0 );
        update_option( 'wu_email_daily_limit', intval( $_POST['daily_limit'] ?? 20 ) );
        update_option( 'wu_email_monthly_limit', intval( $_POST['monthly_limit'] ?? 600 ) );
        update_option( 'wu_email_discord_webhook', esc_url_raw( $_POST['discord_webhook'] ?? '' ) );
        update_option( 'wu_email_send_method', sanitize_text_field( $_POST['send_method'] ?? 'default' ) );
        update_option( 'wu_email_debug_enabled', isset( $_POST['debug_enabled'] ) ? 1 : 0 );
        update_option( 'wu_email_resend_api_key', sanitize_text_field( $_POST['resend_api_key'] ?? '' ) );
        update_option( 'wu_email_resend_from_email', sanitize_email( $_POST['resend_from_email'] ?? '' ) );
        update_option( 'wu_email_resend_from_name', sanitize_text_field( $_POST['resend_from_name'] ?? '' ) );
        update_option( 'wu_email_smtp_host', sanitize_text_field( $_POST['smtp_host'] ?? '' ) );
        update_option( 'wu_email_smtp_port', sanitize_text_field( $_POST['smtp_port'] ?? '587' ) );
        update_option( 'wu_email_smtp_encryption', sanitize_text_field( $_POST['smtp_encryption'] ?? 'tls' ) );
        update_option( 'wu_email_smtp_username', sanitize_text_field( $_POST['smtp_username'] ?? '' ) );
        update_option( 'wu_email_smtp_password', sanitize_text_field( $_POST['smtp_password'] ?? '' ) );
        update_option( 'wu_email_smtp_from_email', sanitize_email( $_POST['smtp_from_email'] ?? '' ) );
        update_option( 'wu_email_smtp_from_name', sanitize_text_field( $_POST['smtp_from_name'] ?? '' ) );
        if ( ! empty( $_POST['admin_password_new'] ) ) {
            update_option( 'wu_email_admin_password', sanitize_text_field( $_POST['admin_password_new'] ) );
        }
        echo '<div class="notice notice-success is-dismissible"><p><strong>設定已儲存</strong></p></div>';
    }

    // 清除發信紀錄
    if ( isset( $_POST['wu_email_clear'] ) ) {
        check_admin_referer( 'wu_email_clear' );
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}wu_email_logs" );
        echo '<div class="notice notice-success is-dismissible"><p><strong>發信紀錄已清除</strong></p></div>';
    }

    // 登出
    if ( isset( $_POST['wu_email_logout'] ) ) {
        check_admin_referer( 'wu_email_logout' );
        delete_transient( 'wu_email_password_verified_' . get_current_user_id() );
        wp_redirect( admin_url( 'admin.php?page=wu-email-tracker' ) );
        exit;
    }

    // 讀取選項
    $enabled         = get_option( 'wu_email_tracker_enabled', 1 );
    $daily_limit     = get_option( 'wu_email_daily_limit', 20 );
    $monthly_limit   = get_option( 'wu_email_monthly_limit', 600 );
    $discord_webhook = get_option( 'wu_email_discord_webhook', '' );
    $send_method     = get_option( 'wu_email_send_method', 'default' );
    $debug_enabled   = get_option( 'wu_email_debug_enabled', 0 );
    $resend_api_key  = get_option( 'wu_email_resend_api_key', '' );
    $resend_from_email = get_option( 'wu_email_resend_from_email', '' );
    $resend_from_name  = get_option( 'wu_email_resend_from_name', get_bloginfo( 'name' ) );
    $smtp_host       = get_option( 'wu_email_smtp_host', '' );
    $smtp_port       = get_option( 'wu_email_smtp_port', '587' );
    $smtp_encryption = get_option( 'wu_email_smtp_encryption', 'tls' );
    $smtp_username   = get_option( 'wu_email_smtp_username', '' );
    $smtp_password   = get_option( 'wu_email_smtp_password', '' );
    $smtp_from_email = get_option( 'wu_email_smtp_from_email', '' );
    $smtp_from_name  = get_option( 'wu_email_smtp_from_name', get_bloginfo( 'name' ) );

    global $wpdb;
    $table       = $wpdb->prefix . 'wu_email_logs';
    $today_start = date( 'Y-m-d 00:00:00' );
    $month_start = date( 'Y-m-01 00:00:00' );
    $today_sent     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $today_start ) );
    $today_blocked  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'blocked'", $today_start ) );
    $month_sent     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'sent'", $month_start ) );
    $month_blocked  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE sent_time >= %s AND status = 'blocked'", $month_start ) );
    $total_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

    switch ( $send_method ) {
        case 'resend': $current_method_label = 'Resend API'; break;
        case 'smtp':   $current_method_label = '自訂 SMTP'; break;
        default:
            $smtp_info = wu_detect_smtp_plugin();
            $current_method_label = $smtp_info['plugin_name'];
    }

    $log_file    = WP_CONTENT_DIR . '/wu-email-tracker-debug.log';
    $log_exists  = file_exists( $log_file );
    $log_size_kb = $log_exists ? round( filesize( $log_file ) / 1024, 2 ) : 0;
    $db_version  = get_option( 'wu_email_tracker_db_version', 'none' );
    ?>

    <div class="wrap">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h1>WU 郵件追蹤管理 <span style="font-size:14px;color:#666;font-weight:normal;">(v<?php echo WU_EMAIL_TRACKER_VERSION; ?>)</span></h1>
            <form method="post" style="margin:0;">
                <?php wp_nonce_field( 'wu_email_logout' ); ?>
                <?php submit_button( '登出管理', 'secondary small', 'wu_email_logout', false ); ?>
            </form>
        </div>

        <!-- 當前狀態 -->
        <div style="background:#fff;padding:20px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #0073aa;">
            <h2 style="margin-top:0;">當前發信狀態</h2>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px;">
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #2271b1;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">發信方式</div>
                    <div style="font-size:16px;font-weight:700;color:#1d2327;"><?php echo esc_html( $current_method_label ); ?></div>
                </div>
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #46b450;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">今日發送</div>
                    <div style="font-size:22px;font-weight:700;"><?php echo $today_sent; ?> <span style="font-size:14px;color:#666;">/ <?php echo $daily_limit; ?></span></div>
                    <?php if ( $today_blocked > 0 ) : ?><div style="font-size:11px;color:#dc3232;margin-top:5px;">被阻擋: <?php echo $today_blocked; ?> 封</div><?php endif; ?>
                </div>
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #f0b849;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">本月發送</div>
                    <div style="font-size:22px;font-weight:700;"><?php echo $month_sent; ?> <span style="font-size:14px;color:#666;">/ <?php echo $monthly_limit; ?></span></div>
                    <?php if ( $month_blocked > 0 ) : ?><div style="font-size:11px;color:#dc3232;margin-top:5px;">被阻擋: <?php echo $month_blocked; ?> 封</div><?php endif; ?>
                </div>
                <div style="padding:15px;background:#f9f9f9;border-left:3px solid #0073aa;">
                    <div style="font-size:11px;color:#666;margin-bottom:5px;">總發送數</div>
                    <div style="font-size:22px;font-weight:700;color:#0073aa;"><?php echo number_format( $total_count ); ?></div>
                </div>
            </div>
            <p style="margin:0;font-size:12px;color:#666;">DB 版本: <?php echo esc_html( $db_version ); ?> | 外掛版本: <?php echo WU_EMAIL_TRACKER_VERSION; ?></p>
        </div>

        <!-- 測試發信 -->
        <div style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #00a32a;">
            <h2 style="color:#00a32a;">測試發信功能</h2>
            <p>發送一封測試郵件以確認追蹤系統是否正常運作。</p>
            <table class="form-table">
                <tr>
                    <th><label for="test_email">測試郵件地址</label></th>
                    <td>
                        <input type="email" id="test_email" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text">
                        <p class="description">將發送測試郵件到此地址 (當前發信方式: <?php echo esc_html( $current_method_label ); ?>)</p>
                    </td>
                </tr>
            </table>
            <button type="button" id="wu_test_email_btn" class="button button-secondary">發送測試郵件</button>
            <div id="wu_test_email_result" style="margin-top:15px;"></div>
        </div>

        <!-- 設定表單 -->
        <form method="post" style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;">
            <?php wp_nonce_field( 'wu_email_settings' ); ?>
            <h2>基本設定</h2>
            <table class="form-table">
                <tr>
                    <th>啟用郵件追蹤</th>
                    <td>
                        <label><input type="checkbox" name="enabled" value="1" <?php checked( 1, $enabled ); ?>> <strong>啟用郵件追蹤與額度管理</strong></label>
                        <p class="description">關閉後將不再追蹤郵件發送,也不會進行額度限制</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="daily_limit">每日發信額度</label></th>
                    <td>
                        <input type="number" id="daily_limit" name="daily_limit" value="<?php echo esc_attr( $daily_limit ); ?>" class="regular-text" min="1">
                        <p class="description">設定每日最多可發送的郵件數量 (預設: 20 封)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="monthly_limit">每月發信額度</label></th>
                    <td>
                        <input type="number" id="monthly_limit" name="monthly_limit" value="<?php echo esc_attr( $monthly_limit ); ?>" class="regular-text" min="1">
                        <p class="description">設定每月最多可發送的郵件數量 (預設: 600 封)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="discord_webhook">Discord Webhook (選填)</label></th>
                    <td>
                        <input type="url" id="discord_webhook" name="discord_webhook" value="<?php echo esc_attr( $discord_webhook ); ?>" class="large-text" placeholder="https://discord.com/api/webhooks/...">
                        <p class="description">當郵件被阻擋或接近額度時,發送 Discord 通知</p>
                    </td>
                </tr>
                <tr>
                    <th>Debug 日誌</th>
                    <td>
                        <label><input type="checkbox" name="debug_enabled" value="1" <?php checked( 1, $debug_enabled ); ?>> <strong>啟用 Debug 日誌記錄</strong></label>
                        <p class="description">啟用後會記錄詳細的郵件追蹤日誌 (預設: 關閉)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="admin_password_new">更改管理密碼 (選填)</label></th>
                    <td>
                        <input type="password" id="admin_password_new" name="admin_password_new" class="regular-text" placeholder="留空表示不更改">
                        <p class="description">設定新的管理密碼,留空則保持現有密碼不變</p>
                    </td>
                </tr>
            </table>

            <h2>發信方式設定</h2>
            <table class="form-table">
                <tr>
                    <th>選擇發信方式</th>
                    <td>
                        <label style="display:block;margin-bottom:10px;"><input type="radio" name="send_method" value="default" <?php checked( 'default', $send_method ); ?>> <strong>使用系統預設或外掛設定</strong></label>
                        <label style="display:block;margin-bottom:10px;"><input type="radio" name="send_method" value="resend" <?php checked( 'resend', $send_method ); ?>> <strong>使用 Resend API</strong></label>
                        <label style="display:block;"><input type="radio" name="send_method" value="smtp" <?php checked( 'smtp', $send_method ); ?>> <strong>使用自訂 SMTP</strong></label>
                    </td>
                </tr>
            </table>

            <!-- Resend API 設定 -->
            <div class="wu-method-section" id="resend_section" style="display:none;">
                <h3>Resend API 設定</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="resend_api_key">Resend API Key</label></th>
                        <td>
                            <input type="text" id="resend_api_key" name="resend_api_key" value="<?php echo esc_attr( $resend_api_key ); ?>" class="large-text" placeholder="re_...">
                            <p class="description">請至 <a href="https://resend.com/api-keys" target="_blank">Resend Dashboard</a> 取得 API Key</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_email">發件者 Email</label></th>
                        <td>
                            <input type="email" id="resend_from_email" name="resend_from_email" value="<?php echo esc_attr( $resend_from_email ); ?>" class="regular-text" placeholder="you@yourdomain.com">
                            <p class="description">必須是已在 Resend 驗證的網域</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="resend_from_name">發件者名稱</label></th>
                        <td>
                            <input type="text" id="resend_from_name" name="resend_from_name" value="<?php echo esc_attr( $resend_from_name ); ?>" class="regular-text">
                        </td>
                    </tr>
                </table>
            </div>

            <!-- SMTP 設定 -->
            <div class="wu-method-section" id="smtp_section" style="display:none;">
                <h3>SMTP 伺服器設定</h3>
                <table class="form-table">
                    <tr>
                        <th><label for="smtp_host">SMTP 主機</label></th>
                        <td><input type="text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr( $smtp_host ); ?>" class="regular-text" placeholder="smtp.example.com"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_port">SMTP 埠號</label></th>
                        <td><input type="text" id="smtp_port" name="smtp_port" value="<?php echo esc_attr( $smtp_port ); ?>" class="small-text" placeholder="587">
                        <p class="description">通常為 587 (TLS) 或 465 (SSL)</p></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_encryption">加密方式</label></th>
                        <td>
                            <select id="smtp_encryption" name="smtp_encryption">
                                <option value="tls" <?php selected( 'tls', $smtp_encryption ); ?>>TLS</option>
                                <option value="ssl" <?php selected( 'ssl', $smtp_encryption ); ?>>SSL</option>
                                <option value="none" <?php selected( 'none', $smtp_encryption ); ?>>無加密</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="smtp_username">SMTP 帳號</label></th>
                        <td><input type="text" id="smtp_username" name="smtp_username" value="<?php echo esc_attr( $smtp_username ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_password">SMTP 密碼</label></th>
                        <td><input type="password" id="smtp_password" name="smtp_password" value="<?php echo esc_attr( $smtp_password ); ?>" class="regular-text" placeholder="••••••••"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_from_email">發件者 Email</label></th>
                        <td><input type="email" id="smtp_from_email" name="smtp_from_email" value="<?php echo esc_attr( $smtp_from_email ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="smtp_from_name">發件者名稱</label></th>
                        <td><input type="text" id="smtp_from_name" name="smtp_from_name" value="<?php echo esc_attr( $smtp_from_name ); ?>" class="regular-text"></td>
                    </tr>
                </table>
            </div>

            <?php submit_button( '儲存設定', 'primary large', 'wu_email_save' ); ?>
        </form>

        <?php if ( $debug_enabled && $log_exists ) : ?>
        <!-- Debug 日誌 -->
        <div style="background:#fff3cd;padding:20px;border:1px solid #ffc107;margin-top:20px;border-left:4px solid #ff9800;">
            <h2 style="margin-top:0;color:#856404;">🐛 Debug 日誌</h2>
            <p><strong>日誌大小:</strong> <?php echo $log_size_kb; ?> KB</p>
            <div style="margin-top:15px;">
                <a href="<?php echo admin_url( 'admin.php?page=wu-email-tracker&wu_download_log=1' ); ?>" class="button button-secondary">📥 下載日誌</a>
                <form method="post" style="display:inline-block;margin-left:10px;" onsubmit="return confirm('確定要清除 Debug 日誌嗎?');">
                    <?php wp_nonce_field( 'wu_email_clear_log' ); ?>
                    <?php submit_button( '🗑️ 清除日誌', 'secondary', 'wu_email_clear_log', false ); ?>
                </form>
            </div>
            <div style="margin-top:20px;background:#fff;padding:15px;border:1px solid #ddd;max-height:300px;overflow-y:auto;">
                <h4 style="margin-top:0;">最近 50 行日誌:</h4>
                <pre style="font-size:11px;line-height:1.4;margin:0;"><?php
                $lines        = file( $log_file );
                $recent_lines = array_slice( $lines, - 50 );
                echo esc_html( implode( '', $recent_lines ) );
                ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <!-- 清除紀錄 -->
        <form method="post" style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;border-left:4px solid #dc3232;" onsubmit="return confirm('確定要清除所有發信紀錄嗎?此操作無法復原!');">
            <?php wp_nonce_field( 'wu_email_clear' ); ?>
            <h2 style="color:#dc3232;">危險操作</h2>
            <p>清除所有發信紀錄。統計數字將會歸零。</p>
            <?php submit_button( '清除所有發信紀錄', 'delete', 'wu_email_clear', false ); ?>
        </form>

        <!-- 發信詳細紀錄 -->
        <div style="background:#fff;padding:25px;border:1px solid #ddd;margin-top:20px;">
            <h2>發信紀錄詳細 (最近 50 筆)</h2>
            <?php
            $all_emails = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sent_time DESC LIMIT 50" );
            if ( ! empty( $all_emails ) ) :
            ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:180px;">發送時間</th>
                        <th style="width:220px;">收件者</th>
                        <th>主旨</th>
                        <th style="width:100px;">發送方式</th>
                        <th style="width:80px;">狀態</th>
                        <th style="width:200px;">備註</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $all_emails as $email ) : ?>
                    <tr>
                        <td><?php echo esc_html( $email->sent_time ); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html( $email->to_email ); ?></code></td>
                        <td><?php echo esc_html( $email->subject ); ?></td>
                        <td>
                            <?php
                            $method_map = array( 'resend_api' => '<span style="color:#2271b1;">Resend API</span>', 'smtp' => '<span style="color:#00a32a;">SMTP</span>' );
                            echo isset( $method_map[ $email->send_method ] ) ? $method_map[ $email->send_method ] : '<span style="color:#646970;">預設</span>';
                            ?>
                        </td>
                        <td>
                            <?php
                            $status_map = array(
                                'sent'    => '<span style="display:inline-block;padding:3px 8px;background:#d7f0dd;color:#1d8a3f;font-size:11px;font-weight:600;border-radius:3px;">成功</span>',
                                'blocked' => '<span style="display:inline-block;padding:3px 8px;background:#fcf3cf;color:#996800;font-size:11px;font-weight:600;border-radius:3px;">已阻擋</span>',
                            );
                            echo isset( $status_map[ $email->status ] ) ? $status_map[ $email->status ] : '<span style="display:inline-block;padding:3px 8px;background:#fcdbdb;color:#b32d2e;font-size:11px;font-weight:600;border-radius:3px;">失敗</span>';
                            ?>
                        </td>
                        <td><small style="color:#666;"><?php echo esc_html( $email->error_message ); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p style="color:#666;padding:40px;text-align:center;">目前尚無發信紀錄<br><small>請使用上方「測試發信功能」確認系統是否正常運作</small></p>
            <?php endif; ?>
        </div>

        <!-- 系統說明 -->
        <div class="notice notice-info" style="padding:15px;margin-top:20px;">
            <p style="margin:0;"><strong>系統說明</strong></p>
            <ul style="margin:8px 0 0 20px;line-height:1.8;">
                <li><strong>追蹤:</strong> 記錄所有透過 WordPress 發送的郵件</li>
                <li><strong>發信方式:</strong> 支援 Default / Resend API / SMTP 三種</li>
                <li><strong>額度管理:</strong> 可設定每日/每月發信額度,超過自動阻擋</li>
                <li><strong>通知:</strong> 支援 Discord Webhook 通知 (阻擋/警告)</li>
                <li><strong>自動清理:</strong> 自動清除 90 天前的舊紀錄</li>
                <li><strong>Debug:</strong> 預設關閉,需要時可在設定中啟用</li>
                <li><strong>密碼:</strong> 可在設定表單中隨時更改管理密碼</li>
            </ul>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function toggleMethodSections() {
            $('.wu-method-section').hide();
            var method = $('input[name="send_method"]:checked').val();
            if (method === 'resend') $('#resend_section').show();
            else if (method === 'smtp') $('#smtp_section').show();
        }
        toggleMethodSections();
        $('input[name="send_method"]').on('change', toggleMethodSections);

        $('#wu_test_email_btn').on('click', function() {
            var $btn = $(this), $result = $('#wu_test_email_result'), testEmail = $('#test_email').val();
            if (!testEmail) { $result.html('<div class="notice notice-error inline"><p>請輸入測試郵件地址</p></div>'); return; }
            $btn.prop('disabled', true).text('發送中...');
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'wu_test_email', nonce: '<?php echo wp_create_nonce( 'wu_email_test' ); ?>', test_email: testEmail },
                success: function(r) {
                    if (r.success) {
                        $result.html('<div class="notice notice-success inline"><p>' + r.data.message + '</p></div>');
                        setTimeout(function(){ location.reload(); }, 2000);
                    } else {
                        $result.html('<div class="notice notice-error inline"><p>' + r.data.message + '</p></div>');
                    }
                },
                error: function() { $result.html('<div class="notice notice-error inline"><p>發送失敗,請稍後再試</p></div>'); },
                complete: function() { $btn.prop('disabled', false).text('發送測試郵件'); }
            });
        });
    });
    </script>

    <style>
    .wu-method-section { background:#f9f9f9; padding:20px; margin-top:20px; border-left:4px solid #2271b1; }
    .notice.inline { display:inline-block; margin:0; padding:10px 15px; }
    </style>
    <?php
}

// ===== 自動清理舊紀錄 =====

add_action( 'wp_scheduled_delete', 'wu_email_tracker_clean_old_records' );

function wu_email_tracker_clean_old_records() {
    global $wpdb;
    $table   = $wpdb->prefix . 'wu_email_logs';
    $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sent_time < %s", date( 'Y-m-d H:i:s', strtotime( '-90 days' ) ) ) );
    if ( $deleted ) wu_debug_log( "Auto cleanup: Deleted {$deleted} old records (>90 days)" );
}

// ===== Dashboard 樣式 =====

add_action( 'admin_head', function () {
    if ( ! get_option( 'wu_email_tracker_enabled', 1 ) ) return;
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'dashboard' ) {
        echo '<style>
        #wu_email_tracker_dashboard{width:100%!important;grid-column:1/-1!important}
        #wu_email_tracker_dashboard .inside{padding:0!important;margin:0!important}
        .wu-dashboard-container{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell,"Helvetica Neue",sans-serif}
        .wu-section{background:#fff;border:1px solid #ddd;padding:20px;margin-bottom:15px}
        .wu-section-title{margin:0 0 15px 0;font-size:16px;font-weight:600;color:#1d2327;display:flex;align-items:center;justify-content:space-between}
        .wu-monitoring-badge{font-size:11px;color:#fff;padding:4px 10px;border-radius:3px;font-weight:500}
        .wu-info-table{width:100%;border-collapse:collapse}
        .wu-info-table th{width:140px;padding:12px 15px;text-align:left;font-weight:600;color:#1d2327;background:#f6f7f7;border-top:1px solid #ddd;font-size:13px}
        .wu-info-table td{padding:12px 15px;border-top:1px solid #ddd;font-size:13px;color:#1d2327}
        .wu-info-meta{color:#646970!important;font-size:12px!important}
        .wu-status-indicator{font-weight:600}
        .wu-disk-bar{height:12px;background:#f0f0f1;border-radius:6px;overflow:hidden;margin-bottom:10px}
        .wu-disk-bar-fill{height:100%;transition:width .3s ease;border-radius:6px}
        .wu-notice{padding:15px;border-left:4px solid;margin-top:15px;background:#fff}
        .wu-notice-error{border-color:#d63638;background:#fcf0f1}
        </style>';
    }
} );
