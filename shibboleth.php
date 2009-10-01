<?php
/*
 Plugin Name: Shibboleth
 Plugin URI: http://wordpress.org/extend/plugins/shibboleth
 Description: Easily externalize user authentication to a <a href="http://shibboleth.internet2.edu">Shibboleth</a> Service Provider
 Author: Will Norris
 Author URI: http://willnorris.com/
 Version: 1.3-dev
 License: Apache 2 (http://www.apache.org/licenses/LICENSE-2.0.html)
 */

define ( 'SHIBBOLETH_PLUGIN_REVISION', preg_replace( '/\$Rev: (.+) \$/', '\\1',
	'$Rev$') ); // this needs to be on a separate line so that svn:keywords can work its magic


// run activation function if new revision of plugin
$shibboleth_plugin_revision = shibboleth_get_option('shibboleth_plugin_revision');
if ($shibboleth_plugin_revision === false || SHIBBOLETH_PLUGIN_REVISION != $shibboleth_plugin_revision) {
	add_action('admin_init', 'shibboleth_activate_plugin');
}


/**
 * Activate the plugin.  This registers default values for all of the 
 * Shibboleth options and attempts to add the appropriate mod_rewrite rules to 
 * WordPress's .htaccess file.
 */
function shibboleth_activate_plugin() {
	if (function_exists('switch_to_blog')) switch_to_blog($GLOBALS['current_site']->blog_id);

	shibboleth_add_option('shibboleth_login_url', get_option('home') . '/Shibboleth.sso/Login');
	shibboleth_add_option('shibboleth_default_login', true);
	shibboleth_add_option('shibboleth_logout_url', get_option('home') . '/Shibboleth.sso/Logout');

	$headers = array(
		'username' => 'eppn',
		'first_name' => 'givenName',
		'last_name' => 'sn',
		'nickname' => 'eppn',
		'display_name' => 'displayName',
		'email' => 'mail',
	);
	shibboleth_add_option('shibboleth_headers', $headers);

	$roles = array(
		'administrator' => array(
			'header' => 'entitlement',
			'value' => 'urn:mace:example.edu:entitlement:wordpress:admin',
		),
		'author' => array(
			'header' => 'affiliation',
			'value' => 'faculty',
		),
		'default' => 'subscriber',
	);
	shibboleth_add_option('shibboleth_roles', $roles);

	shibboleth_add_option('shibboleth_update_users', true);
	shibboleth_add_option('shibboleth_update_roles', true);

	shibboleth_insert_htaccess();

	shibboleth_update_option('shibboleth_plugin_revision', SHIBBOLETH_PLUGIN_REVISION);

	if (function_exists('restore_current_blog')) restore_current_blog();
}
register_activation_hook('shibboleth/shibboleth.php', 'shibboleth_activate_plugin');


/**
 * Cleanup certain plugins options on deactivation.
 */
function shibboleth_deactivate_plugin() {
	shibboleth_remove_htaccess();
}
register_deactivation_hook('shibboleth/shibboleth.php', 'shibboleth_deactivate_plugin');


/**
 * Load Shibboleth admin hooks only on admin page loads.  admin_init is 
 * actually called *after* admin_menu, so we have to hook in to the 'init' 
 * action for this.
 */
function shibboleth_admin_hooks() {
	if ( defined('WP_ADMIN') && WP_ADMIN === true ) {
		require_once dirname(__FILE__) . '/options-admin.php';
		require_once dirname(__FILE__) . '/options-user.php';
	}
}
add_action('init', 'shibboleth_admin_hooks');


/**
 * Use the 'authenticate' filter if it is available (WordPress >= 2.8).
 * Otherwise, hook into 'init'.
 */
if (has_filter('authenticate')) {
	add_filter('authenticate', 'shibboleth_authenticate', 10, 3);
} else {
	add_action('init', 'shibboleth_wp_login');
}


/**
 * Authenticate the user.
 */
function shibboleth_authenticate($user, $username, $password) {
	if (array_key_exists('loggedout', $_REQUEST) || array_key_exists('wp-submit', $_POST)) return $user;

	if ($_SERVER['Shib-Session-ID'] || $_SERVER['HTTP_SHIB_IDENTITY_PROVIDER']) {
		return shibboleth_authenticate_user();
	} else {
		shibboleth_start_login();
	}
}


/**
 * Process requests to wp-login.php.
 */
function shibboleth_wp_login() {
	if ($GLOBALS['pagenow'] != 'wp-login.php') return;

	switch ($_REQUEST['action']) {
		case 'shibboleth':
			if ($_SERVER['Shib-Session-ID'] || $_SERVER['HTTP_SHIB_IDENTITY_PROVIDER']) {
				shibboleth_finish_login();
			} else {
				shibboleth_start_login();
			}
			break;

		case 'login':
		case '':
			add_action('login_form', 'shibboleth_login_form');
			break;

		case 'lostpassword':
			if (shibboleth_get_option('shibboleth_password_reset_url')) {
				if (array_key_exists('user_login', $_REQUEST)) {
					$user = get_userdatabylogin($_REQUEST['user_login']);
					if (!$user) $user = get_user_by_email($_REQUEST['user_login']);

					if ($user && get_usermeta($user->ID, 'shibboleth_account')) {
						wp_redirect(shibboleth_get_option('shibboleth_password_reset_url'));
						exit;
					}
				}
			}
			break;

		default:
			break;
	}
}


function shibboleth_site_url($url, $path, $scheme) {
	if ($path != 'wp-login.php' || $scheme == 'login_post') return $url;

	// static boolean to track if we should process the URL.  This prevents 
	// a recursive loop between this function and shibboleth_login_url()
	static $skip = true;
	if ($skip = (!$skip)) return $url;

	$redirect_to = false;

	if (shibboleth_get_option('shibboleth_default_login')) {
		$url_parts = parse_url($url);
		if (array_key_exists('query', $url_parts)) {
			$query_args = parse_str($url_parts['query']);
			if (array_key_exists('redirect_to', $query_args)) {
				$redirect_to = $query_args['redirect_to'];
			}
		}

		$url = shibboleth_login_url($redirect_to);
	}

	
	return $url;
}
add_filter('site_url', 'shibboleth_site_url', 10, 3);


/**
 * After logging out of WordPress, log user out of Shibboleth.
 */
function shibboleth_logout() {
	$logout_url = shibboleth_get_option('shibboleth_logout_url');
	wp_redirect($logout_url);
	exit;
}
add_action('wp_logout', 'shibboleth_logout', 20);


/**
 * Initiate Shibboleth Login by redirecting user to the Shibboleth login URL.
 */
function shibboleth_start_login() {
	do_action('shibboleth_start_login');
	$login_url = shibboleth_login_url();
	wp_redirect($login_url);
	exit;
}


/**
 * Generate the URL to initiate Shibboleth login.
 *
 * @return the URL to direct the user to in order to initiate Shibboleth login
 * @uses apply_filters() Calls 'shibboleth_login_url' before returning Shibboleth login URL
 */
function shibboleth_login_url($redirect = false) {

	// WordPress MU
	if (function_exists('switch_to_blog')) {
		switch_to_blog($GLOBALS['current_site']->blog_id);
		$target = site_url('wp-login.php');
		restore_current_blog();
	} else {
		$target = site_url('wp-login.php');
	}

	if (!empty($redirect)) {
		$target = add_query_arg('redirect_to', urlencode($redirect), $target);
	}
	$target = add_query_arg('action', 'shibboleth', $target);

	$login_url = shibboleth_get_option('shibboleth_login_url');
	$login_url = add_query_arg('target', urlencode($target), $login_url);

	$login_url = apply_filters('shibboleth_login_url', $login_url);
	return $login_url;
}


/**
 * Authenticate the user based on the current Shibboleth headers.
 *
 * If the data available does not map to a WordPress role (based on the
 * configured role-mapping), the user will not be allowed to login.
 *
 * If this is the first time we've seen this user (based on the username
 * attribute), a new account will be created.
 *
 * Known users will have their profile data updated based on the Shibboleth
 * data present if the plugin is configured to do so.
 *
 * @return WP_User|WP_Error authenticated user or error if unable to authenticate
 */
function shibboleth_authenticate_user() {
	$shib_headers = shibboleth_get_option('shibboleth_headers');

	// ensure user is authorized to login
	$user_role = shibboleth_get_user_role();
	if (empty($user_role)) {
		return new WP_Error('no_access', __('You do not have sufficient access.'));
	}

	$username = $_SERVER[$shib_headers['username']];
	$user = new WP_User($username);

	if ($user->ID) {
		if (!get_usermeta($user->ID, 'shibboleth_account')) {
			// TODO: what happens if non-shibboleth account by this name already exists?
			//return new WP_Error('invalid_username', __('Account already exists by this name.'));
		}
	}

	// create account if new user
	if (!$user->ID) {
		$user = shibboleth_create_new_user($username);
	}

	if (!$user->ID) {
		$error_message = 'Unable to create account based on data provided.';
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$error_message .= '<!-- ' . print_r($_SERVER, true) . ' -->';
		}
		return new WP_Error('missing_data', $error_message);
	}

	// update user data
	update_usermeta($user->ID, 'shibboleth_account', true);
	if (shibboleth_get_option('shibboleth_update_users')) shibboleth_update_user_data($user->ID);
	if (shibboleth_get_option('shibboleth_update_roles')) $user->set_role($user_role);

	return $user;
}


/**
 * Finish logging a user in based on the Shibboleth headers present.
 *
 * This function is only used if the 'authenticate' filter is not present.  
 * This filter was added in WordPress 2.8, and will take care of everything 
 * shibboleth_finish_login is doing.
 *
 * @uses apply_filters() Calls 'login_redirect' before redirecting the user
 */
function shibboleth_finish_login() {
	$user = shibboleth_authenticate_user();

	if (is_wp_error($user)) {
		wp_die($user->get_error_message());
	}

	// log user in
	set_current_user($user->ID);
	wp_set_auth_cookie($user->ID, $remember);
	do_action('wp_login', $user->user_login);

	// redirect user to wherever they were going
	$request_redirect = (isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '');
	$redirect_to = ($request_redirect ? $request_redirect : admin_url());
	$redirect_to = apply_filters('login_redirect', $redirect_to, $request_redirect, $user);
	if ( !$user->has_cap('edit_posts') && ( empty($redirect_to) || $redirect_to == 'wp-admin/' ) )  {
		$redirect_to = admin_url('profile.php');
	}
	wp_safe_redirect($redirect_to);
	exit();
}


/**
 * Create a new WordPress user account, and mark it as a Shibboleth account.
 *
 * @param string $user_login login name for the new user
 * @return object WP_User object for newly created user
 */
function shibboleth_create_new_user($user_login) {
	if (empty($user_login)) return null;

	// create account and flag as a shibboleth acount
	require_once( ABSPATH . WPINC . '/registration.php');
	$user_id = wp_insert_user(array('user_login'=>$user_login));
	$user = new WP_User($user_id);
	update_usermeta($user->ID, 'shibboleth_account', true);

	// always update user data and role on account creation
	shibboleth_update_user_data($user->ID);
	$user_role = shibboleth_get_user_role();
	$user->set_role($user_role);

	return $user;
}


/**
 * Get the role the current user should have.  This is determined by the role
 * mapping configured for the plugin, and the Shibboleth headers present at the
 * time of login.
 *
 * @return string the role the current user should have
 * @uses apply_filters() Calls 'shibboleth_roles' after retrieving shibboleth_roles array
 * @uses apply_filters() Calls 'shibboleth_user_role' before returning final user role
 */
function shibboleth_get_user_role() {
	global $wp_roles;
	if (!$wp_roles) $wp_roles = new WP_Roles();

	$shib_roles = apply_filters('shibboleth_roles', shibboleth_get_option('shibboleth_roles'));
	$user_role = $shib_roles['default'];

	foreach ($wp_roles->role_names as $key => $name) {
		$role_header = $shib_roles[$key]['header'];
		$role_value = $shib_roles[$key]['value'];

		if (empty($role_header) || empty($role_value)) continue;

		$values = split(';', $_SERVER[$role_header]);
		if (in_array($role_value, $values)) {
			$user_role = $key;
			break;
		}
	}

	$user_role = apply_filters('shibboleth_user_role', $user_role);

	return $user_role;
}


/**
 * Update the user data for the specified user based on the current Shibboleth headers.
 *
 * @param int $user_id ID of the user to update
 * @uses apply_filters() Calls 'shibboleth_user_*' before setting user attributes, 
 *       where '*' is one of: login, nicename, first_name, last_name, nickname, 
 *       display_name, email
 */
function shibboleth_update_user_data($user_id) {
	require_once( ABSPATH . WPINC . '/registration.php');

	$shib_headers = shibboleth_get_option('shibboleth_headers');

	$user_data = array(
		'ID' => $user_id,
		'user_login' => apply_filters('shibboleth_user_login', $_SERVER[$shib_headers['username']]),
		'user_nicename' => apply_filters('shibboleth_user_nicename', $_SERVER[$shib_headers['username']]),
		'first_name' => apply_filters('shibboleth_user_first_name', $_SERVER[$shib_headers['first_name']]),
		'last_name' => apply_filters('shibboleth_user_last_name', $_SERVER[$shib_headers['last_name']]),
		'nickname' => apply_filters('shibboleth_user_nickname', $_SERVER[$shib_headers['nickname']]),
		'display_name' => apply_filters('shibboleth_user_display_name', $_SERVER[$shib_headers['display_name']]),
		'user_email' => apply_filters('shibboleth_user_email', $_SERVER[$shib_headers['email']]),
	);

	wp_update_user($user_data);
}


/**
 * Add a "Login with Shibboleth" link to the WordPress login form.  This link 
 * will be wrapped in a <p> with an id value of "shibboleth_login" so that 
 * deployers can style this however they choose.
 */
function shibboleth_login_form() {
	$login_url = shibboleth_login_url();
	echo '<p id="shibboleth_login"><a href="' . $login_url . '">' . __('Login with Shibboleth', 'shibboleth') . '</a></p>';
}


/**
 * Insert directives into .htaccess file to enable Shibboleth Lazy Sessions.
 */
function shibboleth_insert_htaccess() {
	if (got_mod_rewrite()) {
		$htaccess = get_home_path() . '.htaccess';
		$rules = array('AuthType Shibboleth', 'Require Shibboleth');
		insert_with_markers($htaccess, 'Shibboleth', $rules);
	}
}


/**
 * Remove directives from .htaccess file to enable Shibboleth Lazy Sessions.
 */
function shibboleth_remove_htaccess() {
	if (got_mod_rewrite()) {
		$htaccess = get_home_path() . '.htaccess';
		insert_with_markers($htaccess, 'Shibboleth', array());
	}
}


/* Custom option functions to correctly use WPMU *_site_option functions when available. */
function shibboleth_get_option($key, $default = false ) {
	return function_exists('get_site_option') ? get_site_option($key, $default) : get_option($key, $default);
}
function shibboleth_add_option($key, $value, $autoload = 'yes') {
	if (function_exists('add_site_option')) {
		// WordPress MU's add_site_option() is totally broken, in that it simply calls site_update_option()
		// if a value exists instead of leaving it alone like add_option() does.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare("SELECT meta_value FROM $wpdb->sitemeta WHERE meta_key = %s AND site_id = %d", $key, $wpdb->siteid) );
		if ($row !== null) return false;

		return add_site_option($key, $value);
	} else {
		return add_option($key, $value, '', $autoload);
	}
}
function shibboleth_update_option($key, $value) {
	return function_exists('update_site_option') ? update_site_option($key, $value) : update_option($key, $value);
}
function shibboleth_delete_option($key) {
	return function_exists('delete_site_option') ? delete_site_option($key) : delete_option($key);
}

?>
