<?php
/*
Plugin Name: WPU Contact Forms Salesforce
Plugin URI: https://github.com/WordPressUtilities/wpucontactforms_salesforce
Update URI: https://github.com/WordPressUtilities/wpucontactforms_salesforce
Description: Link WPUContactForms results to Salesforce.
Version: 0.3.4
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
    private $plugin_version = '0.3.4';
    private $plugin_settings = array(
        'id' => 'wpucontactforms_salesforce',
        'name' => 'WPU Contact Forms Salesforce'
    );
    private $success_codes = array(
        200,
        201,
        204
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
            'enable_salesforce_sync' => array(
                'section' => 'config',
                'type' => 'select',
                'datas' => array(
                    __('No', 'wpucontactforms_salesforce'),
                    __('Yes', 'wpucontactforms_salesforce')
                ),
                'label' => __('Enable sync', 'wpucontactforms_salesforce')
            ),
            'main_endpoint' => array(
                'section' => 'config',
                'default_value' => 'Contact',
                'label' => __('Main endpoint', 'wpucontactforms_salesforce')
            ),
            'secondary_endpoint' => array(
                'section' => 'config',
                'default_value' => 'Contact',
                'label' => __('Secondary endpoint', 'wpucontactforms_salesforce'),
                'help' => __('If a duplicate is found on this endpoint, this duplicate could be updated there instead of the main endpoint.', 'wpucontactforms_salesforce')
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

        /* Settings Hooks */
        $this->plugin_version = apply_filters('wpucontactforms_salesforce__plugin_version', $this->plugin_version);
        $this->salesforce_url = apply_filters('wpucontactforms_salesforce__salesforce_url', $this->salesforce_url);

        /* Watch WPU Contact Forms */
        add_action('wpucontactforms_submit_contactform', array(&$this,
            'wpucontactforms_submit_contactform'
        ), 10, 2);

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

    /**
     * Renders the main content of the plugin page.
     */
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
            $opt = $this->settings_obj->get_settings();

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

            /* TOKEN */
            if (!defined('WPUCONTACTFORMS_SALESFORCE_HAS_LOGIN_MESSAGE')) {
                echo wpautop(__('It looks like you are already logged-in.', 'wpucontactforms_salesforce'));
            }
            echo '<p>';
            echo submit_button(__('Refresh token', 'wpucontactforms_salesforce'), 'primary', 'refresh_token', false);
            echo ' ' . __('or', 'wpucontactforms_salesforce') . ' ';
            echo '<a class="button" href="' . esc_url($login_url) . '">' . __('Try to log again', 'wpucontactforms_salesforce') . '</a>';
            echo '</p>';

            /* TESTS */
            echo '<hr />';
            echo '<h2>' . __('Tests', 'wpucontactforms_salesforce') . '</h2>';

            if ($opt['issued_at']) {
                echo wpautop(sprintf(__('Token was refreshed %s ago.', 'wpucontactforms_salesforce'), human_time_diff($opt['issued_at'] / 1000)));
            }

            $endpoint_name = strtolower($opt['main_endpoint']);
            $fields = get_option('wpucontactforms_salesforce_fields');
            $label_fields = __('Load available fields', 'wpucontactforms_salesforce');
            if (is_array($fields)) {
                $label_fields = __('Update available fields', 'wpucontactforms_salesforce');
            }

            echo '<p>';
            echo submit_button(__('Test your token', 'wpucontactforms_salesforce'), 'primary', 'test_token', false);
            echo ' ';
            echo submit_button(sprintf(__('Create a demo %s', 'wpucontactforms_salesforce'), esc_attr($endpoint_name)), 'primary', 'demo_contact', false);
            echo ' ';
            echo submit_button($label_fields, 'primary', 'refresh_fields', false);
            echo '</p>';

            /* FIELDS */
            if (is_array($fields)) {
                echo '<hr />';
                echo '<h2>' . __('Available fields', 'wpucontactforms_salesforce') . '</h2>';
                echo '<details>';
                echo $this->page_content__main__display_fields($fields);
                echo '</details>';
            }

        } else {
            echo wpautop(__('You need to link this plugin to your account.', 'wpucontactforms_salesforce'));
            echo wpautop('<a class="button button-primary" href="' . esc_url($login_url) . '">' . __('Click here to login', 'wpucontactforms_salesforce') . '</a>');
        }

    }

    /**
     * Generates and returns the HTML content for displaying fields in a table format.
     *
     * @param array $fields The array of fields to be displayed.
     * @return string The generated HTML content.
     */
    public function page_content__main__display_fields($fields) {
        $html = '';
        $html .= '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead>';
        $html .= '<tr>';

        foreach ($fields['keys'] as $key) {
            $html .= '<th>' . $key . '</th>';
        }
        $html .= '</tr>';
        $html .= '</thead>';

        $html .= '<tbody>';
        foreach ($fields['lines'] as $field) {
            $html .= '<tr>';
            $extra = '';
            if (isset($field['extra'])) {
                $extra .= '<details><summary>' . __('Values', 'wpucontactforms_salesforce') . '</summary>';
                $extra .= '<ul>';
                foreach ($field['extra'] as $extra_value) {
                    $extra .= '<li contenteditable>' . $extra_value . '</li>';
                }
                $extra .= '</ul>';
                $extra .= '</details>';
                unset($field['extra']);
            }
            foreach ($field as $field_key => $field_value) {
                $html .= '<td>';
                $html .= '<span contenteditable>' . $field_value . '</span>';
                if ($field_key == 'type' && $extra) {
                    $html .= $extra;
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
        $html .= '</table>';
        return $html;
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
                'Company' => 'WordPressCompany',
                'FirstName' => 'WordPressFirstName',
                'LastName' => 'WordPressLastName',
                'Email' => 'wordpress@' . $domain,
                'Description' => 'Update : ' . time()
            ));

            if ($user_id && $this->test_token()) {
                $opt = $this->settings_obj->get_settings();
                $success_str = __('A "%s" is <a href="%s">available here</a>.', 'wpucontactforms_salesforce');
                $success_url = $opt['instance_url'] . '/lightning/r/' . esc_attr($opt['main_endpoint']) . '/' . $user_id . '/view';
                $this->set_message('demo_contact_success', sprintf($success_str, $opt['main_endpoint'], $success_url), 'updated');
            } else {
                $this->set_message('demo_contact_error', __('Contact could not be created.', 'wpucontactforms_salesforce'), 'error');
            }

        }

        if (isset($_POST['refresh_fields'])) {
            $this->page_action__main__refresh_fields();
        }
    }

    /**
     * Refreshes the available fields for the main endpoint in the Salesforce integration.
     * @return void
     */
    function page_action__main__refresh_fields() {
        $opt = $this->settings_obj->get_settings();
        $fields = $this->call_salesforce('sobjects/' . esc_attr($opt['main_endpoint']) . '/describe', array(), array('method' => 'GET'));
        if (is_array($fields) && isset($fields['fields'])) {
            usort($fields['fields'], function ($a, $b) {
                return strcmp(strtolower($a['name']), strtolower($b['name']));
            });

            $keys = array('name', 'label', 'type');

            $final_fields = array(
                'keys' => array('name', 'label', 'type'),
                'lines' => array()
            );

            foreach ($fields['fields'] as $field) {
                $line = array();
                foreach ($keys as $key) {
                    $line[$key] = $field[$key];
                    if ($key == 'type' && $field[$key] == 'picklist' && isset($field['picklistValues']) && $field['picklistValues']) {
                        $line['extra'] = array();
                        foreach ($field['picklistValues'] as $picklistValue) {
                            $line['extra'][] = $picklistValue['value'];
                        }
                    }
                }
                $final_fields['lines'][] = $line;
            }
            update_option('wpucontactforms_salesforce_fields', $final_fields, false);
            $this->set_message('refresh_fields_success', __('Available fields have been refreshed.', 'wpucontactforms_salesforce'), 'updated');
        } else {
            $this->set_message('refresh_fields_error', __('Available fields could not be refreshed.', 'wpucontactforms_salesforce'), 'error');
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
    public function refresh_token() {
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
    public function test_token() {
        $opt = $this->settings_obj->get_settings();
        if (!$opt['main_endpoint']) {
            return false;
        }

        $response = $this->call_salesforce('sobjects/' . esc_attr($opt['main_endpoint']) . '/describe', array(), array('method' => 'HEAD'));
        return (is_array($response) && isset($response['code']) && in_array($response['code'], $this->success_codes));
    }

    /* ----------------------------------------------------------
      Submit helper
    ---------------------------------------------------------- */

    function wpucontactforms_submit_contactform($form) {
        $opt = $this->settings_obj->get_settings();
        if (!$opt['enable_salesforce_sync']) {
            return;
        }
        $values = array();
        $note_content = '';
        foreach ($form->contact_fields as $field) {
            if (isset($field['api_content'])) {
                $note_content .= "\n\n" . $field['label'] . ":\n" . $field['value'];
            }
            if (!isset($field['api_field_name']) || !$field['api_field_name']) {
                continue;
            }
            $values[$field['api_field_name']] = html_entity_decode($field['value']);
        }

        $note_content .= "\n\n" . __('Values', 'wpucontactforms_salesforce') . ":\n" . json_encode($values, JSON_PRETTY_PRINT);

        $values = apply_filters('wpucontactforms_salesforce__submit_contactform__values', $values, $form);

        $user_id = $this->create_or_update_contact($values, array(
            'task_create' => false,
            'note_create' => true,
            'note_title' => '[' . get_bloginfo('name') . '] ' . $form->options['name'],
            'note_content' => trim($note_content)
        ));

        do_action('wpucontactforms_salesforce__after_submit_contactform', $user_id, $values, $form);

    }

    /* ----------------------------------------------------------
      Global call helper
    ---------------------------------------------------------- */

    public function call_salesforce($request_uri, $fields = array(), $args = array()) {
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

        $args = apply_filters('wpucontactforms_salesforce__call_salesforce__args', $args, $opt, $fields, $request_uri);
        $fields = apply_filters('wpucontactforms_salesforce__call_salesforce__fields', $fields, $opt, $request_uri, $args);
        $call_url = apply_filters('wpucontactforms_salesforce__call_salesforce__url', $call_url, $opt, $fields, $request_uri, $args);

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

        $request_args = apply_filters('wpucontactforms_salesforce__call_salesforce__request_args', $request_args, $opt, $fields, $request_uri, $args);
        $request = wp_remote_request($call_url, $request_args);
        $req_body = wp_remote_retrieve_body($request);

        do_action('wpucontactforms_salesforce__call_salesforce__after', $request, $opt, $fields, $request_uri, $args);

        if (isset($request['response']) && !in_array($request['response']['code'], $this->success_codes)) {
            error_log('WPUCONTACTFORMS_SALESFORCE ERROR : ' . $args['method'] . ' - ' . $request_uri . ' - ' . $request['body'] . ' - ' . json_encode($request['response']));
        }

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

    public function create_or_update_contact($fields, $args = array()) {
        $opt = $this->settings_obj->get_settings();

        if (!isset($fields['Email'])) {
            return false;
        }

        $default_args = array(
            'task_create' => true,
            'task_title' => 'Website Form',
            'task_content' => json_encode($fields, JSON_PRETTY_PRINT),
            'note_create' => true,
            'note_title' => 'Website Form',
            'note_content' => json_encode($fields, JSON_PRETTY_PRINT)
        );
        if (!is_array($args)) {
            $args = array();
        }
        $args = array_merge($default_args, $args);

        $args = apply_filters('wpucontactforms_salesforce__create_or_update_contact__args', $args);
        $fields = apply_filters('wpucontactforms_salesforce__create_or_update_contact__fields', $fields);

        /* TEST IF CONTACT EXISTS */
        $user_id = $this->search_contact($fields['Email']);
        $user = false;
        if ($user_id) {
            /* Update */
            $user = $this->update_contact($user_id, $fields);
        } else {
            /* Create */
            $user = $this->call_salesforce('sobjects/' . esc_attr($opt['main_endpoint']), $fields, array('method' => 'POST'));
            if (!is_array($user)) {
                $user = json_decode($user, 1);
            }

            if (is_array($user) && isset($user[0]) && isset($user[0]['errorCode']) && $user[0]['errorCode'] == 'DUPLICATES_DETECTED') {
                $duplicate = $user[0]['duplicateResult']['matchResults'][0];
                if (($duplicate['entityType'] == $opt['main_endpoint'] || $duplicate['entityType'] == $opt['secondary_endpoint']) && count($duplicate['matchRecords']) && $duplicate['matchRecords'][0]['matchConfidence'] > 90) {
                    $user_id = $duplicate['matchRecords'][0]['record']['Id'];
                    $user = $this->update_contact($user_id, $fields, array(
                        'main_endpoint' => $duplicate['entityType']
                    ));
                }
            }
        }

        if (is_array($user)) {
            if (isset($user['Id'])) {
                $user_id = $user['Id'];
            }
            if (isset($user['id'])) {
                $user_id = $user['id'];
            }
        }

        if (!$user_id) {
            return false;
        }

        /* Add a note to the user */
        if ($args['note_create']) {
            $this->create_note($user_id, $args['note_title'], $args['note_content']);
        }

        /* Add a task to the user */
        if ($args['task_create']) {
            $this->create_task($user_id, $args['task_title'], $args['task_content']);
        }

        return $user_id;
    }

    function update_contact($user_id, $fields, $args = array()) {
        if (!is_array($args)) {
            $args = array();
        }
        $opt = $this->settings_obj->get_settings();
        $main_endpoint = isset($args['main_endpoint']) ? $args['main_endpoint'] : $opt['main_endpoint'];
        $fields = apply_filters('wpucontactforms_salesforce__update_contact__fields', $fields, $user_id, $main_endpoint, $args);
        if (isset($fields['Email'])) {
            unset($fields['Email']);
        }
        $user = $this->call_salesforce('sobjects/' . esc_attr($main_endpoint) . '/' . $user_id, $fields, array('method' => 'PATCH'));

    }

    public function search_contact($emails) {
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

    /* ----------------------------------------------------------
      Notes
    ---------------------------------------------------------- */

    public function create_note($parent_id, $title, $content) {
        return $this->call_salesforce('sobjects/Note/', array(
            "ParentId" => $parent_id,
            "Title" => $title,
            "Body" => $content
        ), array(
            'method' => 'POST'
        ));
    }

    /* ----------------------------------------------------------
      Tasks
    ---------------------------------------------------------- */

    public function create_task($parent_id, $title, $content) {
        return $this->call_salesforce('sobjects/Task/', array(
            "WhoId" => $parent_id,
            "Subject" => $title,
            "Description" => $content
        ), array(
            'method' => 'POST'
        ));
    }

}

$WPUContactFormsSalesForce = new WPUContactFormsSalesForce();
