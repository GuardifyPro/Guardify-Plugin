<?php

if (!isset($_GET['apikey']) || empty($_GET['apikey'])) {
    http_response_code(401);
    echo json_encode(['error' => 'API key required']);
    exit;
}

// Load WordPress
$wp_load_path = dirname(__FILE__) . '/wp-load.php';
$wp_loaded = false;

if (!file_exists($wp_load_path)) {
    $current_dir = dirname(__FILE__);
    for ($i = 0; $i < 15; $i++) {
        $wp_load_path = $current_dir . '/wp-load.php';
        if (file_exists($wp_load_path)) {
            require_once($wp_load_path);
            $wp_loaded = true;
            break;
        }
        $current_dir = dirname($current_dir);
    }
} else {
    require_once($wp_load_path);
    $wp_loaded = true;
}

if (!function_exists('get_option')) {
    http_response_code(500);
    echo json_encode([
        'error' => 'WordPress not found',
        'debug_info' => [
            'current_file' => __FILE__,
            'current_dir' => dirname(__FILE__),
            'wp_load_attempted' => $wp_load_path ?? 'Not set',
            'wp_loaded' => $wp_loaded ?? false,
            'function_exists_get_option' => function_exists('get_option')
        ]
    ]);
    exit;
}

// Validate API key
$api_key = sanitize_text_field($_GET['apikey']);
$stored_api_key = get_option('orderguard_api_key', '');

if ($api_key !== $stored_api_key) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        getSettings();
        break;
    case 'POST':
        updateSettings();
        break;
    case 'PUT':
        updateSpecificSettings();
        break;
    case 'DELETE':
        resetSettings();
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
}

function getSettings() {
    $settings = [
        'timestamp' => current_time('mysql'),
        'domain' => $_SERVER['HTTP_HOST'],
        'plugin_version' => defined('ORDERGUARD_VERSION') ? ORDERGUARD_VERSION : 'Unknown',
        
        'phone_validation' => [
            'enabled' => get_option('orderguard_phone_validation_enabled', '1'),
            'message' => get_option('orderguard_phone_validation_message', '')
        ],
        
        'otp_verification' => [
            'enabled' => get_option('orderguard_enable_otp_verification', '0'),
            'verification_type' => get_option('orderguard_verification_type', 'standard')
        ],
        
        'smart_order_filter' => [
            'enabled' => get_option('orderguard_smart_filter_enabled', '0'),
            'dp_threshold' => get_option('orderguard_smart_filter_dp_threshold', '70'),
            'action' => get_option('orderguard_smart_filter_action', 'block'),
            'error_message' => get_option('orderguard_smart_filter_error_message', ''),
            'skip_new_customers' => get_option('orderguard_smart_filter_skip_new', '0')
        ],
        
        'vpn_block' => [
            'enabled' => get_option('orderguard_vpn_block_enabled', '0'),
            'title' => get_option('orderguard_vpn_block_title', ''),
            'message' => get_option('orderguard_vpn_block_message', ''),
            'warning' => get_option('orderguard_vpn_block_warning', '')
        ],
        
        'fraud_detection' => [
            'enabled' => get_option('orderguard_fraud_detection_enabled', '0'),
            'title' => get_option('orderguard_blocked_user_title', ''),
            'message' => get_option('orderguard_blocked_user_message', ''),
            'warning' => get_option('orderguard_blocked_user_warning', ''),
            'support_number' => get_option('orderguard_fraud_detection_support_number', ''),
            'auto_block' => [
                'enabled' => get_option('orderguard_auto_block_enabled', '0'),
                'order_limit' => get_option('orderguard_auto_block_order_limit', '3'),
                'time_limit' => get_option('orderguard_auto_block_time_limit', '24'),
                'reason' => get_option('orderguard_auto_block_reason', '')
            ]
        ],
        
        'incomplete_orders' => [
            'enabled' => get_option('orderguard_incomplete_orders_enabled', '0'),
            'retention_days' => get_option('orderguard_incomplete_orders_retention', '30'),
            'notification_frequency' => get_option('orderguard_incomplete_orders_notification', 'daily'),
            'cooldown' => [
                'enabled' => get_option('orderguard_incomplete_orders_cooldown_enabled', '1'),
                'minutes' => get_option('orderguard_incomplete_orders_cooldown', '30')
            ]
        ],
        
        'repeat_order_blocker' => [
            'enabled' => get_option('orderguard_repeat_order_blocker_enabled', '0'),
            'time_limit_hours' => get_option('orderguard_repeat_order_time_limit', '24'),
            'error_message' => get_option('orderguard_repeat_order_error_message', ''),
            'support_number' => get_option('orderguard_repeat_order_support_number', '')
        ],
        
        'phone_history' => [
            'enabled' => get_option('orderguard_phone_history_enabled', '0'),
            'autoload_enabled' => get_option('orderguard_phone_history_autoload', '1')
        ],
        
        'courier_report' => [
            'display_delivery_details' => get_option('display_delivery_details_in_admin', '1'),
            'show_report_column' => get_option('orderguard_show_report_column', '1'),
            'max_orders_to_load' => get_option('orderguard_max_orders_to_load', '25')
        ],
        
        'notifications' => [
            'enabled_statuses' => get_option('orderguard_notification_statuses', []),
            'status_messages' => get_option('orderguard_status_messages', [])
        ]
    ];
    
    echo json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function updateSettings() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $updated = [];
    
    foreach ($data as $section => $settings) {
        switch ($section) {
            case 'phone_validation':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_phone_validation_enabled', $settings['enabled']);
                    $updated['phone_validation']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['message'])) {
                    update_option('orderguard_phone_validation_message', sanitize_text_field($settings['message']));
                    $updated['phone_validation']['message'] = $settings['message'];
                }
                break;
                
            case 'smart_order_filter':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_smart_filter_enabled', $settings['enabled']);
                    $updated['smart_order_filter']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['dp_threshold'])) {
                    update_option('orderguard_smart_filter_dp_threshold', intval($settings['dp_threshold']));
                    $updated['smart_order_filter']['dp_threshold'] = $settings['dp_threshold'];
                }
                if (isset($settings['action'])) {
                    update_option('orderguard_smart_filter_action', sanitize_text_field($settings['action']));
                    $updated['smart_order_filter']['action'] = $settings['action'];
                }
                if (isset($settings['error_message'])) {
                    update_option('orderguard_smart_filter_error_message', sanitize_textarea_field($settings['error_message']));
                    $updated['smart_order_filter']['error_message'] = $settings['error_message'];
                }
                break;
                
            case 'fraud_detection':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_fraud_detection_enabled', $settings['enabled']);
                    $updated['fraud_detection']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['auto_block']['enabled'])) {
                    update_option('orderguard_auto_block_enabled', $settings['auto_block']['enabled']);
                    $updated['fraud_detection']['auto_block']['enabled'] = $settings['auto_block']['enabled'];
                }
                break;
                
            case 'vpn_block':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_vpn_block_enabled', $settings['enabled']);
                    $updated['vpn_block']['enabled'] = $settings['enabled'];
                }
                break;
                
            case 'repeat_order_blocker':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_repeat_order_blocker_enabled', $settings['enabled']);
                    $updated['repeat_order_blocker']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['time_limit_hours'])) {
                    update_option('orderguard_repeat_order_time_limit', intval($settings['time_limit_hours']));
                    $updated['repeat_order_blocker']['time_limit_hours'] = $settings['time_limit_hours'];
                }
                break;
                
            case 'courier_report':
                if (isset($settings['display_delivery_details'])) {
                    update_option('display_delivery_details_in_admin', $settings['display_delivery_details']);
                    $updated['courier_report']['display_delivery_details'] = $settings['display_delivery_details'];
                }
                if (isset($settings['show_report_column'])) {
                    update_option('orderguard_show_report_column', $settings['show_report_column']);
                    $updated['courier_report']['show_report_column'] = $settings['show_report_column'];
                }
                if (isset($settings['max_orders_to_load'])) {
                    update_option('orderguard_max_orders_to_load', intval($settings['max_orders_to_load']));
                    $updated['courier_report']['max_orders_to_load'] = $settings['max_orders_to_load'];
                }
                break;
                
            case 'phone_history':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_phone_history_enabled', $settings['enabled']);
                    $updated['phone_history']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['autoload_enabled'])) {
                    update_option('orderguard_phone_history_autoload', $settings['autoload_enabled']);
                    $updated['phone_history']['autoload_enabled'] = $settings['autoload_enabled'];
                }
                break;
                
            case 'otp_verification':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_enable_otp_verification', $settings['enabled']);
                    $updated['otp_verification']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['verification_type'])) {
                    update_option('orderguard_verification_type', sanitize_text_field($settings['verification_type']));
                    $updated['otp_verification']['verification_type'] = $settings['verification_type'];
                }
                break;
                
            case 'incomplete_orders':
                if (isset($settings['enabled'])) {
                    update_option('orderguard_incomplete_orders_enabled', $settings['enabled']);
                    $updated['incomplete_orders']['enabled'] = $settings['enabled'];
                }
                if (isset($settings['retention_days'])) {
                    update_option('orderguard_incomplete_orders_retention', intval($settings['retention_days']));
                    $updated['incomplete_orders']['retention_days'] = $settings['retention_days'];
                }
                if (isset($settings['notification_frequency'])) {
                    update_option('orderguard_incomplete_orders_notification', sanitize_text_field($settings['notification_frequency']));
                    $updated['incomplete_orders']['notification_frequency'] = $settings['notification_frequency'];
                }
                if (isset($settings['cooldown'])) {
                    if (isset($settings['cooldown']['enabled'])) {
                        update_option('orderguard_incomplete_orders_cooldown_enabled', $settings['cooldown']['enabled']);
                        $updated['incomplete_orders']['cooldown']['enabled'] = $settings['cooldown']['enabled'];
                    }
                    if (isset($settings['cooldown']['minutes'])) {
                        update_option('orderguard_incomplete_orders_cooldown', intval($settings['cooldown']['minutes']));
                        $updated['incomplete_orders']['cooldown']['minutes'] = $settings['cooldown']['minutes'];
                    }
                }
                break;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Settings updated',
        'updated' => $updated,
        'timestamp' => current_time('mysql')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function updateSpecificSettings() {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $updated = [];
    
    foreach ($data as $option_name => $value) {
        if (strpos($option_name, 'orderguard_') === 0 || $option_name === 'display_delivery_details_in_admin') {
            update_option($option_name, $value);
            $updated[$option_name] = $value;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Specific settings updated',
        'updated' => $updated,
        'timestamp' => current_time('mysql')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function resetSettings() {
    $defaults = [
        'orderguard_phone_validation_enabled' => '1',
        'orderguard_smart_filter_enabled' => '0',
        'orderguard_fraud_detection_enabled' => '0',
        'orderguard_vpn_block_enabled' => '0',
        'orderguard_repeat_order_blocker_enabled' => '0',
        'orderguard_incomplete_orders_enabled' => '0',
        'orderguard_phone_history_enabled' => '0',
        'orderguard_enable_otp_verification' => '0',
        'display_delivery_details_in_admin' => '1',
        'orderguard_show_report_column' => '1',
        'orderguard_max_orders_to_load' => '25'
    ];
    
    foreach ($defaults as $option => $value) {
        update_option($option, $value);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Settings reset to defaults',
        'timestamp' => current_time('mysql')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
