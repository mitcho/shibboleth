<?php
/*
 Plugin Name: Shibboleth
 Plugin URI: http://wordpress.org/extend/plugins/shibboleth
 Description: Easily externalize user authentication to a <a href="http://shibboleth.internet2.edu">Shibboleth</a> Service Provider
 Author: Will Norris
 Author URI: http://willnorris.com/
 Version: 0.1
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
	global $action;
	if ($action == 'local_login' || array_key_exists('loggedout', $_REQUEST) || array_key_exists('wp-submit', $_POST)) return $user;

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
		case 'local_login':
			add_action('login_form', 'shibboleth_login_form');
			break;

		case 'login':
		case '':
			if (array_key_exists('checkemail', $_REQUEST)) return;
			if (array_key_exists('wp-submit', $_POST)) break;

			if ($_SERVER['Shib-Session-ID'] || $_SERVER['HTTP_SHIB_IDENTITY_PROVIDER']) {
				shibboleth_finish_login();
			} else {
				shibboleth_start_login();
			}
			break;

		case 'logout':
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
function shibboleth_login_url() {

	$target = site_url('wp-login.php');

	// WordPress MU
	if (function_exists('switch_to_blog')) {
		switch_to_blog($GLOBALS['current_site']->blog_id);
		$target = site_url('wp-login.php');
		restore_current_blog();
	}

	$target = add_query_arg('redirect_to', urlencode($_REQUEST['redirect_to']), $target);
	$target = add_query_arg('action', 'login', $target);

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
 * users can style this however they choose.
 */
function shibboleth_login_form() {
	$login_url = shibboleth_login_url();
	echo '<p id="shibboleth_login"><a href="' . $login_url . '">' . __('Login with Shibboleth', 'shibboleth') . '</a></p>';
}


/**
 * For WordPress accounts that were created by Shibboleth, limit what profile
 * attributes they can modify.
 */
function shibboleth_profile_personal_options() {
	$user = wp_get_current_user();
	if (get_usermeta($user->ID, 'shibboleth_account')) {
		add_filter('show_password_fields', create_function('$v', 'return false;'));

		if (shibboleth_get_option('shibboleth_update_users')) {
			echo '
			<script type="text/javascript">
				jQuery(function() {
					jQuery("#first_name,#last_name,#nickname,#display_name,#email").attr("disabled", true);
					jQuery("h3:contains(\'Name\')").after("<div class=\"updated fade\"><p>' 
						. __('These fields cannot be changed from WordPress.', 'shibboleth') . '<p></div>");
				});
			</script>';
		}
	}
}


/**
 * For WordPress accounts that were created by Shibboleth, warn the admin of 
 * Shibboleth managed attributes.
 */
function shibboleth_edit_user_profile() {
	global $user_id;

	if (get_usermeta($user_id, 'shibboleth_account')) {
		$shibboleth_fields = array();

		if (shibboleth_get_option('shibboleth_update_users')) {
			$shibboleth_fields = array_merge($shibboleth_fields, 
				array('user_login', 'first_name', 'last_name', 'nickname', 'display_name', 'email'));
		}

		if (shibboleth_get_option('shibboleth_update_roles')) {
			$shibboleth_fields = array_merge($shibboleth_fields, array('role'));
		}

		if (!empty($shibboleth_fields)) {
			$selectors = array();

			foreach($shibboleth_fields as $field) {
				$selectors[] = 'label[for=\'' . $field . '\']';
			}

			echo '
			<script type="text/javascript">
				jQuery(function() {
					jQuery("' . implode(',', $selectors) . '").before("<span style=\"color: #F00; font-weight: bold;\">*</span> ");
					jQuery("h3:contains(\'Name\')")
						.after("<div class=\"updated fade\"><p><span style=\"color: #F00; font-weight: bold;\">*</span> ' 
							. __('Starred fields are managed by Shibboleth and should not be changed from WordPress.', 'shibboleth') . '</p></div>");
				});
			</script>';
		}
	}
}


/**
 * Add change password link to the user profile for Shibboleth users.
 */
function shibboleth_show_user_profile() {
	$user = wp_get_current_user();
	if (get_usermeta($user->ID, 'shibboleth_account')) {
		if (shibboleth_get_option('shibboleth_password_change_url')) {
?>
	<table class="form-table">
		<tr>
			<th>Change Password</th>
			<td><a href="<?php echo shibboleth_get_option('shibboleth_password_change_url'); 
				?>" target="_blank"><?php _e('Change your password', 'shibboleth'); ?></a></td>
		</tr>
	</table>
<?php
		}
	}
}


/**
 * Ensure profile data isn't updated by the user.  This only applies to 
 * accounts that were provisioned through Shibboleth, and only if the option
 * to manage user attributes exclusively from Shibboleth is enabled.
 */
function shibboleth_personal_options_update() {
	$user = wp_get_current_user();

	if (get_usermeta($user->ID, 'shibboleth_account') && shibboleth_get_option('shibboleth_update_users')) {
		add_filter('pre_user_first_name', 
			create_function('$n', 'return $GLOBALS["current_user"]->first_name;'));

		add_filter('pre_user_last_name', 
			create_function('$n', 'return $GLOBALS["current_user"]->last_name;'));

		add_filter('pre_user_nickname', 
			create_function('$n', 'return $GLOBALS["current_user"]->nickname;'));

		add_filter('pre_user_display_name', 
			create_function('$n', 'return $GLOBALS["current_user"]->display_name;'));

		add_filter('pre_user_email', 
			create_function('$e', 'return $GLOBALS["current_user"]->user_email;'));
	}
}


/**
 * Setup admin menus for Shibboleth options.
 *
 * @action: admin_menu
 **/
function shibboleth_admin_panels() {
	// global options page
	if (isset($GLOBALS['wpmu_version'])) {
		$hookname = add_submenu_page('wpmu-admin.php', __('Shibboleth Options', 'shibboleth'), 'Shibboleth', 8, 'shibboleth-options', 'shibboleth_options_page' );
	} else {
		$hookname = add_options_page(__('Shibboleth options', 'shibboleth'), 'Shibboleth', 8, 'shibboleth-options', 'shibboleth_options_page' );
	}

	add_action('profile_personal_options', 'shibboleth_profile_personal_options');
	add_action('personal_options_update', 'shibboleth_personal_options_update');
	add_action('show_user_profile', 'shibboleth_show_user_profile');
	add_action('edit_user_profile', 'shibboleth_edit_user_profile');
}
add_action('admin_menu', 'shibboleth_admin_panels');


/**
 * WordPress options page to configure the Shibboleth plugin.
 *
 * @uses apply_filters() Calls 'shibboleth_plugin_path'
 */
function shibboleth_options_page() {
	global $wp_roles;

	if (isset($_POST['submit'])) {
		check_admin_referer('shibboleth_update_options');

		$shib_headers = (array) shibboleth_get_option('shibboleth_headers');
		$shib_headers = array_merge($shib_headers, $_POST['headers']);
		shibboleth_update_option('shibboleth_headers', $shib_headers);

		$shib_roles = (array) shibboleth_get_option('shibboleth_roles');
		$shib_roles = array_merge($shib_roles, $_POST['shibboleth_roles']);
		shibboleth_update_option('shibboleth_roles', $shib_roles);

		shibboleth_update_option('shibboleth_login_url', $_POST['login_url']);
		shibboleth_update_option('shibboleth_logout_url', $_POST['logout_url']);
		shibboleth_update_option('shibboleth_password_change_url', $_POST['password_change_url']);
		shibboleth_update_option('shibboleth_password_reset_url', $_POST['password_reset_url']);

		shibboleth_update_option('shibboleth_update_users', (boolean) $_POST['update_users']);
		shibboleth_update_option('shibboleth_update_roles', (boolean) $_POST['update_roles']);
	}

	$shib_headers = shibboleth_get_option('shibboleth_headers');
	$shib_roles = shibboleth_get_option('shibboleth_roles');

	$shibboleth_plugin_path = apply_filters('shibboleth_plugin_path', plugins_url('shibboleth'));

	screen_icon('shibboleth');

?>
	<style type="text/css">
		#icon-shibboleth { background: url("<?php echo $shibboleth_plugin_path . '/icon.png' ?>") no-repeat; height: 36px width: 36px; }
	</style>

	<div class="wrap">
		<form method="post">

			<h2><?php _e('Shibboleth Options', 'shibboleth') ?></h2>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="login_url"><?php _e('Session Initiator URL', 'shibboleth') ?></label</th>
					<td>
						<input type="text" id="login_url" name="login_url" value="<?php echo shibboleth_get_option('shibboleth_login_url') ?>" size="50" /><br />
						<?php _e('This URL is constructed from values found in your main Shibboleth'
							. ' SP configuration file: your site hostname, the Sessions handlerURL,'
							. ' and the SessionInitiator Location.', 'shibboleth'); ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="logout_url"><?php _e('Logout URL', 'shibboleth') ?></label</th>
					<td>
						<input type="text" id="logout_url" name="logout_url" value="<?php echo shibboleth_get_option('shibboleth_logout_url') ?>" size="50" /><br />
						<?php _e('This URL is constructed from values found in your main Shibboleth'
							. ' SP configuration file: your site hostname, the Sessions handlerURL,'
							. ' and the LogoutInitiator Location (also known as the'
							. ' SingleLogoutService Location in Shibboleth 1.3).', 'shibboleth'); ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="password_change_url"><?php _e('Password Change URL', 'shibboleth') ?></label</th>
					<td>
						<input type="text" id="password_change_url" name="password_change_url" value="<?php echo shibboleth_get_option('shibboleth_password_change_url') ?>" size="50" /><br />
						<?php _e('If this option is set, Shibboleth users will see a "change password" link on their profile page directing them to this URL.', 'shibboleth') ?>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="password_reset_url"><?php _e('Password Reset URL', 'shibboleth') ?></label</th>
					<td>
						<input type="text" id="password_reset_url" name="password_reset_url" value="<?php echo shibboleth_get_option('shibboleth_password_reset_url') ?>" size="50" /><br />
						<?php _e('If this option is set, Shibboleth users who try to reset their forgotten password using WordPress will be redirected to this URL.', 'shibboleth') ?>
					</td>
				</tr>
			</table>

			<br class="clear" />

			<h3><?php _e('User Profile Data', 'shibboleth') ?></h3>

			<p><?php _e('Define the Shibboleth headers which should be mapped to each user profile attribute.  These'
				. ' header names are configured in <code>attribute-map.xml</code> (for Shibboleth 2.x) or'
				. ' <code>AAP.xml</code> (for Shibboleth 1.x).', 'shibboleth') ?></p>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
				<tr valign="top">
					<th scope="row"><label for="username"><?php _e('Username') ?></label</th>
					<td><input type="text" id="username" name="headers[username]" value="<?php echo $shib_headers['username'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="first_name"><?php _e('First name') ?></label</th>
					<td><input type="text" id="first_name" name="headers[first_name]" value="<?php echo $shib_headers['first_name'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="last_name"><?php _e('Last name') ?></label</th>
					<td><input type="text" id="last_name" name="headers[last_name]" value="<?php echo $shib_headers['last_name'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="nickname"><?php _e('Nickname') ?></label</th>
					<td><input type="text" id="nickname" name="headers[nickname]" value="<?php echo $shib_headers['nickname'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="display_name"><?php _e('Display name') ?></label</th>
					<td><input type="text" id="display_name" name="headers[display_name]" value="<?php echo $shib_headers['display_name'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="email"><?php _e('Email Address') ?></label</th>
					<td><input type="text" id="email" name="headers[email]" value="<?php echo $shib_headers['email'] ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="update_users"><?php _e('Update User Data', 'shibboleth') ?></label</th>
					<td>
						<input type="checkbox" id="update_users" name="update_users" <?php echo shibboleth_get_option('shibboleth_update_users') ? ' checked="checked"' : '' ?> />
						<label for="update_users"><?php _e('Use Shibboleth data to update user profile data each time the user logs in.', 'shibboleth'); ?></label>

						<p><?php _e('This will prevent users from being able to manually update these'
							. ' fields.  Note that Shibboleth data is always used to populate the user'
							. ' profile during account creation.', 'shibboleth'); ?></p>

					</td>
				</tr>
			</table>

			<br class="clear" />

			<h3><?php _e('User Role Mappings', 'shibboleth') ?></h3>

			<p><?php _e('Users can be placed into one of WordPress\'s internal roles based on any'
				. ' attribute.  For example, you could define a special eduPersonEntitlement value'
				. ' that designates the user as a WordPress Administrator.  Or you could automatically'
				. ' place all users with an eduPersonAffiliation of "faculty" in the Author role.', 'shibboleth'); ?></p>

			<p><?php _e('<strong>Current Limitations:</strong> While WordPress supports users having'
				. ' multiple roles, the Shibboleth plugin will only place the user in the highest ranking'
				. ' role.  Only a single header/value pair is supported for each user role.  This may be'
				. ' expanded in the future to support multiple header/value pairs or regular expression'
				. ' values.  In the meantime, you can use the <em>shibboleth_roles</em> and'
				. ' <em>shibboleth_user_role</em> WordPress filters to provide your own logic for assigning'
				. ' user roles.', 'shibboleth'); ?></p>

			<style type="text/css">
				#role_mappings { padding: 0; }
				#role_mappings thead th { padding: 5px 10px; }
				#role_mappings td, #role_mappings th { border-bottom: 0px; }
			</style>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">

				<tr>
					<th scope="row"><?php _e('Role Mappings', 'shibboleth') ?></th>
					<td id="role_mappings">
						<table id="">
						<col width="10%"></col>
						<col></col>
						<col></col>
						<thead>
							<tr>
								<th></th>
								<th scope="column"><?php _e('Header Name', 'shibboleth') ?></th>
								<th scope="column"><?php _e('Header Value', 'shibboleth') ?></th>
							</tr>
						</thead>
						<tbody>
<?php

					foreach ($wp_roles->role_names as $key => $name) {
						echo'
						<tr valign="top">
							<th scope="row">' . _c($name) . '</th>
							<td><input type="text" id="role_'.$key.'_header" name="shibboleth_roles['.$key.'][header]" value="' . @$shib_roles[$key]['header'] . '" style="width: 100%" /></td>
							<td><input type="text" id="role_'.$key.'_value" name="shibboleth_roles['.$key.'][value]" value="' . @$shib_roles[$key]['value'] . '" style="width: 100%" /></td>
						</tr>';
					}
?>

						</tbody>
						</table>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e('Default Role', 'shibboleth') ?></th>
					<td>
						<select id="default_role" name="shibboleth_roles[default]">
						<option value=""><?php _e('(none)') ?></option>
<?php
			foreach ($wp_roles->role_names as $key => $name) {
				echo '
						<option value="' . $key . '"' . ($shib_roles['default'] == $key ? ' selected="selected"' : '') . '>' . _c($name) . '</option>';
			}
?>
						</select>

						<p><?php _e('If a user does not map into any of the roles above, they will'
							. ' be placed into the default role.  If there is no default role, the'
							. ' user will not be able to login with Shibboleth.', 'shibboleth'); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="update_roles"><?php _e('Update User Roles', 'shibboleth') ?></label></th>
					<td>
						<input type="checkbox" id="update_roles" name="update_roles" <?php echo shibboleth_get_option('shibboleth_update_roles') ? ' checked="checked"' : '' ?> />
						<label for="update_roles"><?php _e('Use Shibboleth data to update user role mappings each time the user logs in.', 'shibboleth') ?></label>

						<p><?php _e('Be aware that if you use this option, you should <strong>not</strong> update user roles manually,'
						. ' since they will be overwritten from Shibboleth the next time the user logs in.  Note that Shibboleth data'
					   	. ' is always used to populate the initial user role during account creation.', 'shibboleth') ?></p>

					</td>
				</tr>
			</table>


			<?php wp_nonce_field('shibboleth_update_options') ?>
			<p class="submit"><input type="submit" name="submit" value="<?php _e('Update Options') ?>" /></p>
		</form>
	</div>

<?php
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
