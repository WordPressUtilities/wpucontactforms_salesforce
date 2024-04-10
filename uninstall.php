<?php
defined('ABSPATH') || die;
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/* Delete options */
$options = array(
    'wpucontactforms_salesforce_options',
    'wpucontactforms_salesforce_wpucontactforms_salesforce_version'
);
foreach ($options as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}
