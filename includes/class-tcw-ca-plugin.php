<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once TCW_CA_DIR . 'includes/class-tcw-ca-core.php';
require_once TCW_CA_DIR . 'includes/class-tcw-ca-admin.php';

class TCW_CA_Plugin {
    private static $instance = null;
    public $core;
    public $admin;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->core = new TCW_CA_Core();
        $this->admin = new TCW_CA_Admin($this->core);
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));
        add_action('tcw_ca_publish_next_scheduled', array($this->core, 'run_scheduled_publish'));
    }


    public function register_cron_schedules($schedules) {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display' => __('Once Monthly (30 days)', 'tcw-content-assistant'),
            );
        }
        return $schedules;
    }

    public static function activate() {
        $core = new TCW_CA_Core();
        $core->create_tables();
        $core->register_default_settings();
        $core->sync_schedule();
    }
}

