<?php
/*
Plugin Name: WPU Contact Forms Salesforce
Plugin URI: https://github.com/WordPressUtilities/wpucontactforms_salesforce
Update URI: https://github.com/WordPressUtilities/wpucontactforms_salesforce
Description: Link WPUContactForms results to Salesforce.
Version: 0.2.0
Author: Darklg
Author URI: https://github.com/darklg
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
    private $plugin_version = '0.2.0';
    private $plugin_settings = array(
        'id' => 'wpucontactforms_salesforce',
        'name' => 'WPU Contact Forms Salesforce'
    );
    private $salesforce_api_version = 'v60.0';
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
                'name' => __('Main', 'wpucontactforms_salesforce'),
                'settings_link' => true,
                'settings_name' => __('Settings', 'wpucontactforms_salesforce'),
                'function_content' => array(&$this,
                    'page_content__main'
                ),
                'function_action' => array(&$this,
                    'page_action__main'
                )
            ),
            'settings' => array(
                'name' => __('Settings', 'wpucontactforms_salesforce'),
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
                ),
                'config' => array(
                    'name' => __('Config', 'wpucontactforms_salesforce')
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
            ),
            'main_endpoint' => array(
                'section' => 'config',
                'default_value' => 'Contact',
                'label' => __('Main endpoint', 'wpucontactforms_salesforce')
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
                define('WPUCONTACTFORMS_SALESFORCE_HAS_LOGIN_MESSAGE', 1);
                echo wpautop(__('You are successfully logged-in', 'wpucontactforms_salesforce'));
            } else {
                echo wpautop(__('Error: Token could not be saved', 'wpucontactforms_salesforce'));
                return;
            }

        }

        /* Build Login */
        $login_url = $this->salesforce_url . 'authorize?' . http_build_query(array(
            'response_type' => 'code',
            'client_id' => $opt['client_key'],
            'redirect_uri' => str_replace('http:', 'https:', $this->adminpages->get_page_url('main'))
        ));

        if ($opt['access_token'] && $opt['refresh_token']) {
            if (!defined('WPUCONTACTFORMS_SALESFORCE_HAS_LOGIN_MESSAGE')) {
                echo wpautop(__('It looks like you are already logged-in.', 'wpucontactforms_salesforce'));
            }
            echo '<p>';
            echo submit_button(__('Refresh token', 'wpucontactforms_salesforce'), 'primary', 'refresh_token', false);
            echo ' ' . __('or', 'wpucontactforms_salesforce') . ' ';
            echo '<a class="button" href="' . esc_url($login_url) . '">' . __('Try to log again', 'wpucontactforms_salesforce') . '</a>';
            echo '</p>';
            echo '<hr />';
            echo '<h2>' . __('Tests', 'wpucontactforms_salesforce') . '</h2>';
            echo '<p>';
            echo submit_button(__('Test your token', 'wpucontactforms_salesforce'), 'primary', 'test_token', false);
            echo ' ';
            echo submit_button(__('Create a demo contact', 'wpucontactforms_salesforce'), 'primary', 'demo_contact', false);
            echo '</p>';
        } else {
            echo wpautop(__('You need to link this plugin to your account.', 'wpucontactforms_salesforce'));
            echo wpautop('<a class="button button-primary" href="' . esc_url($login_url) . '">' . __('Click here to login', 'wpucontactforms_salesforce') . '</a>');
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

        if (isset($_POST['test_token'])) {
            if ($this->test_token()) {
                $this->set_message('test_token_success', __('Token is successfully working.', 'wpucontactforms_salesforce'), 'updated');
            } else {
                $this->set_message('test_token_error', __('Token is not working anymore.', 'wpucontactforms_salesforce'), 'error');
            }
        }

        if (isset($_POST['demo_contact'])) {
            $urlparts = wp_parse_url(home_url());
            $domain = $urlparts['host'];

            $user_id = $this->create_or_update_contact(array(
                'FirstName' => 'WordPressFirstName',
                'LastName' => 'WordPressLastName',
                'Email' => 'wordpress@' . $domain,
                'Description' => 'Update : ' . time()
            ));

            if ($user_id) {
                if ($this->test_token()) {
                    $opt = $this->settings_obj->get_settings();
                    $success_str = __('A "%s" is <a href="%s">available here</a>.', 'wpucontactforms_salesforce');
                    $success_url = $opt['instance_url'] . '/lightning/r/' . esc_attr($opt['main_endpoint']) . '/' . $user_id . '/view';
                    $this->set_message('demo_contact_success', sprintf($success_str, $opt['main_endpoint'], $success_url), 'updated');
                } else {
                    $this->set_message('demo_contact_error', __('Contact could not be created.', 'wpucontactforms_salesforce'), 'error');
                }
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
      Token
    ---------------------------------------------------------- */

    /* Refresh token */
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
        $data = json_decode(wp_remote_retrieve_body(wp_remote_get($refresh_url)), 1);
        if ($data) {
            return $this->save_token_from_data($data);
        }

        return false;
    }

    /* Test token */
    function test_token() {
        $opt = $this->settings_obj->get_settings();
        if (!$opt['main_endpoint']) {
            return false;
        }

        $response = $this->call_salesforce('sobjects/' . esc_attr($opt['main_endpoint']) . '/describe', array(), array('method' => 'HEAD'));
        return (is_array($response) && isset($response['code']) && $response['code'] == 200);
    }

    /* ----------------------------------------------------------
      Calls
    ---------------------------------------------------------- */

    function call_salesforce($request_uri, $fields = array(), $args = array()) {
        $opt = $this->settings_obj->get_settings();
        if (!$opt['access_token'] || !$opt['instance_url']) {
            return false;
        }
        if (!is_array($args)) {
            $args = array();
        }
        if (!isset($args['method'])) {
            $args['method'] = 'POST';
        }

        $call_url = $opt['instance_url'] . '/services/data/' . $this->salesforce_api_version . '/' . $request_uri;
        $request_args = array(
            'method' => $args['method'],
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => $opt['token_type'] . ' ' . $opt['access_token']
            )
        );
        if ($fields) {
            $request_args['body'] = json_encode($fields);
        }
        $request = wp_remote_request($call_url, $request_args);
        $req_body = wp_remote_retrieve_body($request);
        if (in_array($args['method'], array('HEAD', 'PATCH')) && isset($request['response'])) {
            return $request['response'];
        }

        if (substr($req_body, 0, 1) == '{') {
            $req_body = json_decode($req_body, true);
        }

        return $req_body;

    }

    /* ----------------------------------------------------------
      Contact helpers
    ---------------------------------------------------------- */

    function create_or_update_contact($fields) {
        $opt = $this->settings_obj->get_settings();

        /* TEST IF CONTACT EXISTS */
        $user_id = $this->search_contact($fields['Email']);

        if ($user_id) {
            /* Update */
            unset($fields['Email']);
            $this->call_salesforce('sobjects/' . esc_attr($opt['main_endpoint']) . '/' . $user_id, $fields, array('method' => 'PATCH'));
        } else {
            /* Create */
            $user = $this->call_salesforce('sobjects/' . esc_attr($opt['main_endpoint']), $fields, array('method' => 'POST'));
            if (is_array($user) && isset($user['Id'])) {
                $user_id = $user['Id'];
            }
        }

        if (!$user_id) {
            return false;
        }

        /* Add a note to  */
        $this->call_salesforce('sobjects/Note/', array(
            "ParentId" => $user_id,
            "Title" => "Website Form",
            "Body" => json_encode($fields, JSON_PRETTY_PRINT)
        ), array('method' => 'POST'));

        return $user_id;
    }

    function search_contact($emails) {
        $opt = $this->settings_obj->get_settings();

        if (!is_array($emails)) {
            $emails = array($emails);
        }

        $query = "SELECT id FROM " . esc_attr($opt['main_endpoint']) . " WHERE Email IN ('" . implode("','", $emails) . "')";
        $req_url = "query/?q=" . urlencode($query);
        $req = $this->call_salesforce($req_url, array(), array('method' => 'GET'));

        if (!is_array($req) || !isset($req['totalSize'], $req['records']) || !$req['totalSize'] || !$req['records']) {
            return false;
        }

        return $req['records'][0]['Id'];

    }

}

$WPUContactFormsSalesForce = new WPUContactFormsSalesForce();
