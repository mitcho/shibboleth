<?php

// include regular Shibboleth plugin file
require_once dirname(__FILE__) . '/shibboleth/shibboleth.php';


function shibboleth_muplugins_loaded() {
	add_filter('shibboleth_plugin_path', create_function('$p', 'return WPMU_PLUGIN_URL . "/shibboleth";') );
}
add_action('muplugins_loaded', 'shibboleth_muplugins_loaded');

?>
