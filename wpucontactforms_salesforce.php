<?php
/*
Plugin Name: WPU Contact Forms Salesforce
Plugin URI: https://github.com/WordPressUtilities/wpucontactforms_salesforce
Update URI: https://github.com/WordPressUtilities/wpucontactforms_salesforce
Description: Link WPUContactForms results to Salesforce.
Version: 0.1.0
Author: darklg
Author URI: https://darklg.me/
Text Domain: wpucontactforms_salesforce
Domain Path: /lang
Requires at least: 6.2
Requires PHP: 8.0
Network: Optional
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) {
    exit();
}

class WPUContactFormsSalesForce {
    private $plugin_version = '0.1.0';
    private $plugin_settings = array(
        'id' => 'wpucontactforms_salesforce',
        'name' => 'WPU Contact Forms Salesforce'
    );
    private $salesforce_url = 'https://login.salesforce.com/services/oauth2/';
    private $basetoolbox;
    private $basecron;
    private $messages;
    private $adminpages;
    private $settings;
    private $settings_obj;
    private $settings_details;
    private $plugin_description;

    public function __construct() {
        add_action('plugins_loaded', array(&$this, 'plugins_loaded'));
    }

    public function plugins_loaded() {
        # TRANSLATION
        if (!load_plugin_textdomain('wpucontactforms_salesforce', false, dirname(plugin_basename(__FILE__)) . '/lang/')) {
            load_muplugin_textdomain('wpucontactforms_salesforce', dirname(plugin_basename(__FILE__)) . '/lang/');
        }
        $this->plugin_description = __('Link WPUContactForms results to Salesforce.', 'wpucontactforms_salesforce');
        # TOOLBOX
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpucontactforms_salesforce\WPUBaseToolbox(array(
            'need_form_js' => false
        ));
        # CUSTOM PAGE
        $admin_pages = array(
            'main' => array(
                'icon_url' => 'dashicons-admin-generic',
                'menu_name' => $this->plugin_settings['name'],
                'name' => $this->plugin_settings['name'],
                'settings_link' => true,
                'settings_name' => __('Settings'),
                'function_content' => array(&$this,
                    'page_content__main'
                ),
                'function_action' => array(&$this,
                    'page_action__main'
                )
            ),
            'settings' => array(
                'name' => 'Settings',
                'parent' => 'main',
                'has_form' => false,
                'function_content' => array(&$this,
                    'page_content__settings'
                )
            )
        );
        $pages_options = array(
            'id' => $this->plugin_settings['id'],
            'level' => 'manage_options',
            'basename' => plugin_basename(__FILE__)
        );
        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->adminpages = new \wpucontactforms_salesforce\WPUBaseAdminPage();
        $this->adminpages->init($pages_options, $admin_pages);

        # SETTINGS
        $this->settings_details = array(
            # Admin page
            'create_page' => false,
            'plugin_basename' => plugin_basename(__FILE__),
            # Default
            'plugin_name' => $this->plugin_settings['name'],
            'plugin_id' => $this->plugin_settings['id'],
            'option_id' => $this->plugin_settings['id'] . '_options',
            'sections' => array(
                'keys' => array(
                    'name' => __('Keys', 'wpucontactforms_salesforce')
                ),
                'tokens' => array(
                    'name' => __('Tokens', 'wpucontactforms_salesforce')
                )
            )
        );
        $this->settings = array(
            'client_key' => array(
                'section' => 'keys',
                'label' => __('Client Key', 'wpucontactforms_salesforce')
            ),
            'secret_key' => array(
                'section' => 'keys',
                'label' => __('Secret Key', 'wpucontactforms_salesforce')
            ),
            'access_token' => array(
                'section' => 'tokens',
                'readonly' => true,
                'label' => __('Access Token', 'wpucontactforms_salesforce')
            ),
            'refresh_token' => array(
                'section' => 'tokens',
                'readonly' => true,
                'label' => __('Refresh Token', 'wpucontactforms_salesforce')
            ),
            'instance_url' => array(
                'section' => 'tokens',
                'readonly' => true,
                'label' => __('Instance URL', 'wpucontactforms_salesforce')
            ),
            'token_type' => array(
                'section' => 'tokens',
                'readonly' => true,
                'label' => __('Token Type', 'wpucontactforms_salesforce')
            ),
            'issued_at' => array(
                'section' => 'tokens',
                'readonly' => true,
                'label' => __('Issued at', 'wpucontactforms_salesforce')
            )
        );
        require_once __DIR__ . '/inc/WPUBaseSettings/WPUBaseSettings.php';
        $this->settings_obj = new \wpucontactforms_salesforce\WPUBaseSettings($this->settings_details, $this->settings);

        # CRONS
        /* Include hooks */
        require_once __DIR__ . '/inc/WPUBaseCron/WPUBaseCron.php';
        $this->basecron = new \wpucontactforms_salesforce\WPUBaseCron(array(
            'pluginname' => $this->plugin_settings['name'],
            'cronhook' => 'wpucontactforms_salesforce__cron_hook',
            'croninterval' => 3600
        ));
        /* Callback when hook is triggered by the cron */
        add_action('wpucontactforms_salesforce__cron_hook', array(&$this,
            'wpucontactforms_salesforce__cron_hook'
        ), 10);

        # MESSAGES
        if (is_admin()) {
            require_once __DIR__ . '/inc/WPUBaseMessages/WPUBaseMessages.php';
            $this->messages = new \wpucontactforms_salesforce\WPUBaseMessages($this->plugin_settings['id']);
        }
    }

    /* ----------------------------------------------------------
      Cron
    ---------------------------------------------------------- */

    public function wpucontactforms_salesforce__cron_hook() {
        $this->refresh_token();
    }

    /* ----------------------------------------------------------
      Messages
    ---------------------------------------------------------- */

    public function set_message($id, $message, $group = '') {
        if (!$this->messages) {
            error_log($id . ' - ' . $message);
            return;
        }
        $this->messages->set_message($id, $message, $group);
    }

    /* ----------------------------------------------------------
      Admin pages
    ---------------------------------------------------------- */

    public function page_content__main() {
        $opt = $this->settings_obj->get_settings();

        /* Invalid settings */
        if (!$opt['client_key'] || !$opt['secret_key']) {
            echo wpautop(sprintf(__('Please add the correct keys in <a href="%s">Settings</a>', 'wpucontactforms_salesforce'), $this->adminpages->get_page_url('settings')));
            return;
        }

        /* Return from oauth */
        if (isset($_GET['code']) && $opt['client_key'] && $opt['secret_key']) {

            $query_url = $this->salesforce_url . 'token?' . http_build_query(array(
                'grant_type' => 'authorization_code',
                'client_secret' => $opt['secret_key'],
                'client_id' => $opt['client_key'],
                'code' => $_GET['code'],
                'redirect_uri' => str_replace('http:', 'https:', $this->adminpages->get_page_url('main'))
            ));

            $data = json_decode(wp_remote_retrieve_body(wp_remote_get($query_url)), 1);

            /* Handle errors */
            if (!$data) {
                echo wpautop(__('Error: invalid ids', 'wpucontactforms_salesforce'));
                return;
            }
            if (isset($data['error'])) {
                if (isset($data['error_description'])) {
                    echo wpautop(sprintf(__('Error: %s - %s', 'wpucontactforms_salesforce'), $data['error'], $data['error_description']));
                } else {
                    echo wpautop(__('Error: invalid ids', 'wpucontactforms_salesforce'));
                }
                return;
            }

            /* Save token */
            $this->save_token_from_data($data);
            if (isset($data['access_token'], $data['refresh_token'])) {
                echo wpautop(__('You are successfully connected', 'wpucontactforms_salesforce'));
            } else {
                echo wpautop(__('Error: Token could not be saved', 'wpucontactforms_salesforce'));
            }

            return;
        }

        /* Build Login */
        $login_url = $this->salesforce_url . 'authorize?' . http_build_query(array(
            'response_type' => 'code',
            'client_id' => $opt['client_key'],
            'redirect_uri' => str_replace('http:', 'https:', $this->adminpages->get_page_url('main'))
        ));

        echo wpautop('<a href="' . esc_url($login_url) . '">' . __('Login', 'wpucontactforms_salesforce') . '</a>');

        if ($opt['access_token'] && $opt['refresh_token']) {
            echo wpautop(__('It looks like you are already connected.', 'wpucontactforms_salesforce'));
            echo submit_button(__('Refresh token', 'wpucontactforms_salesforce'), 'primary', 'refresh_token');

        }

    }

    public function page_action__main() {
        if (isset($_POST['refresh_token'])) {
            if ($this->refresh_token()) {
                $this->set_message('refresh_token_success', __('Token was successfully refreshed.', 'wpucontactforms_salesforce'), 'updated');
            } else {
                $this->set_message('refresh_token_error', __('Token could not be refreshed.', 'wpucontactforms_salesforce'), 'error');
            }
        }

    }

    public function page_content__settings() {
        settings_errors();
        echo '<form action="' . admin_url('options.php') . '" method="post">';
        settings_fields($this->settings_details['option_id']);
        do_settings_sections($this->settings_details['plugin_id']);
        echo submit_button(__('Save Changes', 'wpuimporttwitter'));
        echo '</form>';
    }

    /* ----------------------------------------------------------
      Token
    ---------------------------------------------------------- */

    public function save_token_from_data($data) {
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['error'])) {
            error_log('WPUCONTACTFORMS_SALESFORCE ERROR : ' . json_encode($data));
        }

        /* Save token */
        if (isset($data['access_token'])) {
            $this->settings_obj->update_setting('access_token', $data['access_token']);
        }
        if (isset($data['refresh_token'])) {
            $this->settings_obj->update_setting('refresh_token', $data['refresh_token']);
        }
        if (isset($data['instance_url'])) {
            $this->settings_obj->update_setting('instance_url', $data['instance_url']);
        }
        if (isset($data['token_type'])) {
            $this->settings_obj->update_setting('token_type', $data['token_type']);
        }
        if (isset($data['issued_at'])) {
            $this->settings_obj->update_setting('issued_at', $data['issued_at']);
        }

        return true;
    }

    /* ----------------------------------------------------------
      Refresh token
    ---------------------------------------------------------- */

    function refresh_token() {
        $opt = $this->settings_obj->get_settings();
        if (!$opt['access_token'] || !$opt['refresh_token']) {
            return false;
        }

        $refresh_url_args = array(
            'grant_type' => 'refresh_token',
            'client_secret' => $opt['secret_key'],
            'client_id' => $opt['client_key'],
            'refresh_token' => $opt['refresh_token']
        );

        $refresh_url = $this->salesforce_url . "token?" . http_build_query($refresh_url_args);
        $data = json_decode(wp_remote_retrieve_body(wp_remote_post($refresh_url)), 1);
        if ($data) {
            return $this->save_token_from_data($data);
        }

        return false;
    }

}

$WPUContactFormsSalesForce = new WPUContactFormsSalesForce();
