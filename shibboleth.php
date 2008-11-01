<?php
/*
 Plugin Name: Shibboleth
 Plugin URI: http://wordpress.org/extend/plugins/shibboleth
 Description: Easily externalize user authentication to a <a href="http://shibboleth.internet2.edu">Shibboleth</a> Service Provider
 Author: Will Norris
 Author URI: http://will.norris.name/
 Version: trunk
 License: Apache 2 (http://www.apache.org/licenses/LICENSE-2.0.html)
 */

register_activation_hook('shibboleth/shibboleth.php', 'shibboleth_activate_plugin');
add_action('init', 'shibboleth_wp_login');
add_action('admin_menu', 'shibboleth_admin_panels');
add_action('profile_personal_options', 'shibboleth_profile_personal_options');
add_action('personal_options_update', 'shibboleth_personal_options_update');


/**
 * Activate the plugin.
 */
function shibboleth_activate_plugin() {
	add_option('shibboleth_login_url', get_option('home') . '/Shibboleth.sso/Login');
	add_option('shibboleth_logout_url', get_option('home') . '/Shibboleth.sso/Logout');

	$headers = array(
		'username' => 'eppn',
		'first_name' => 'givenName',
		'last_name' => 'sn',
		'display_name' => 'displayName',
		'email' => 'mail',
	);
	add_option('shibboleth_headers', $headers);

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
	add_option('shibboleth_roles', $roles);

	add_option('shibboleth_update_users', true);
	add_option('shibboleth_update_roles', true);

	shibboleth_insert_htaccess();
}


/**
 * Process requests to wp-login.php.
 */
function shibboleth_wp_login() {
	global $pagenow;
	if ($pagenow != 'wp-login.php') return;

	$shib_headers = get_option('shibboleth_headers');

	switch ($_REQUEST['action']) {
		case 'logout':
			wp_logout();

			$logout_url = get_option('shibboleth_logout_url');
			wp_redirect($logout_url);
			exit;

			break;

		case 'local_login':
			add_action('login_form', 'shibboleth_login_form');
			break;

		default:
			if ($_SERVER['Shib-Session-ID']) {
				shibboleth_finish_login();
			} else {
				shibboleth_start_login();
			}
			break;
	}
}


/**
 * Initiate Shibboleth Login.
 */
function shibboleth_start_login() {
	$login_url = shibboleth_login_url();
	wp_redirect($login_url);
	exit;
}


/**
 * Generate the URL to initiate Shibboleth login.
 *
 * @return the URL to direct the user to in order to initiate Shibboleth login
 */
function shibboleth_login_url() {
	$target = site_url('wp-login.php');
	$target = add_query_arg('redirect_to', urlencode($_REQUEST['redirect_to']), $target);
	$target = add_query_arg('action', 'login', $target);

	$login_url = get_option('shibboleth_login_url');
	$login_url = add_query_arg('target', urlencode($target), $login_url);

	return $login_url;
}


/**
 * Finish logging a user in based on the Shibboleth headers present.  
 *
 * If the data available does not map to a WordPress role (based on the 
 * configured role-mapping), the user will not be allowed to login.  
 *
 * If this is the first time we've seen this user (based on the username 
 * attribute), a new account will be created. 
 *
 * Known users will have their profile data updated based on the Shibboleth 
 * data present if the plugin is configured to do so.
 */
function shibboleth_finish_login() {
	$shib_headers = get_option('shibboleth_headers');

	// ensure user is authorized to login
	$user_role = shibboleth_get_user_role();
	if (empty($user_role)) {
		wp_die('you do not have sufficient access.');
	}

	$username = $_SERVER[$shib_headers['username']];
	$user = new WP_User($username);

	if ($user->ID) {
		if (!get_usermeta($user->ID, 'shibboleth_account')) {
			//wp_die('account already exists by this name');
		}
	}

	// create account if new user
	if (!$user->ID) {
		$user = shibboleth_create_new_user($username);
	}

	if (!$user->ID) {
		wp_die('unable to create account based on data provided');
	}

	// update user data
	update_usermeta($user->ID, 'shibboleth_account', true);
	if (get_option('shibboleth_update_users')) shibboleth_update_user_data($user->ID);
	if (get_option('shibboleth_update_roles')) $user->set_role($user_role);

	// log user in
	set_current_user($user->ID);
	wp_set_auth_cookie($user->ID, $remember);
	do_action('wp_login', $user->user_login);

	// redirect user to whever they were going
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
 * return string the role the current user should have
 */
function shibboleth_get_user_role() {
	global $wp_roles;
	if (!$wp_roles) $wp_roles = new WP_Roles();

	$shib_roles = get_option('shibboleth_roles');
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

	return $user_role;
}


/**
 * Update the user data for the specified user based on the current Shibboleth headers.
 *
 * @param int $user_id ID of the user to update
 */
function shibboleth_update_user_data($user_id) {
	require_once( ABSPATH . WPINC . '/registration.php');

	$shib_headers = get_option('shibboleth_headers');

	$user_data = array(
		'ID' => $user_id,
		'user_login' => $_SERVER[$shib_headers['username']],
		'user_nicename' => $_SERVER[$shib_headers['username']],
		'first_name' => $_SERVER[$shib_headers['first_name']],
		'last_name' => $_SERVER[$shib_headers['last_name']],
		'display_name' => $_SERVER[$shib_headers['display_name']],
		'user_email' => $_SERVER[$shib_headers['email']],
	);

	wp_update_user($user_data);
}


/**
 * Add a "Login with Shibboleth" link to the WordPress login form.
 */
function shibboleth_login_form() {
	$login_url = shibboleth_login_url();
	echo '<p><a href="' . $login_url . '">Login with Shibboleth</a></p>';
}


/**
 * For WordPress accounts that were created by Shibboleth, limit what profile 
 * attributes they can modify.
 */
function shibboleth_profile_personal_options() {
	$user = wp_get_current_user();
	if (get_usermeta($user->ID, 'shibboleth_account')) {
		add_filter('show_password_fields', create_function('$v', 'return false;'));

		if (get_option('shibboleth_update_users')) {
			echo '
			<script type="text/javascript">
				var cannot_change = " This field cannot be changed from WordPress.";
				jQuery(function() {
					jQuery("#first_name,#last_name,#nickname,#display_name,#email")
						.attr("disabled", true).after(cannot_change);
				});
			</script>';
		}
	}
}

/**
 * Ensure profile data isn't updated by the user
 */
function shibboleth_personal_options_update() {
	if (get_option('shibboleth_update_users')) {
		add_filter('pre_user_first_name', 'shibboleth_pre_first_name');
		add_filter('pre_user_last_name', 'shibboleth_pre_last_name');
		add_filter('pre_user_nickname', 'shibboleth_pre_nickname');
		add_filter('pre_user_display_name', 'shibboleth_pre_display_name');
		add_filter('pre_user_email', 'shibboleth_pre_email');
	}
}

function shibboleth_pre_first_name($name) {
	global $current_user;
	return $current_user->first_name;
}
function shibboleth_pre_last_name($name) {
	global $current_user;
	return $current_user->last_name;
}
function shibboleth_pre_nickname($name) {
	global $current_user;
	return $current_user->nickname;
}
function shibboleth_pre_display_name($name) {
	global $current_user;
	return $current_user->display_name;
}
function shibboleth_pre_email($email) {
	global $current_user;
	return $current_user->user_email;
}


/**
 * Setup admin menus for Shibboleth options.
 *
 * @action: admin_menu
 **/
function shibboleth_admin_panels() {
	// global options page
	$hookname = add_options_page(__('Shibboleth options', 'shibboleth'), __('Shibboleth', 'shibboleth'), 8, 'shibboleth-options', 'shibboleth_options_page' );
}


/**
 * WordPress options page to configure the Shibboleth plugin.
 */
function shibboleth_options_page() {
	global $wp_roles;

	if (isset($_POST['submit'])) {
		check_admin_referer('shibboleth_update_options');

		$shib_headers = get_option('shibboleth_headers');
		if (!is_array($shib_headers)) $shib_headers = array();
		$shib_headers = array_merge($shib_headers, $_POST['headers']);
		update_option('shibboleth_headers', $shib_headers);

		$shib_roles = get_option('shibboleth_roles');
		if (!is_array($shib_roles)) $shib_roles = array();
		$shib_roles = array_merge($shib_roles, $_POST['shibboleth_roles']);
		update_option('shibboleth_roles', $shib_roles);

		update_option('shibboleth_login_url', $_POST['login_url']);
		update_option('shibboleth_logout_url', $_POST['logout_url']);
		update_option('shibboleth_update_users', $_POST['update_users']);
		update_option('shibboleth_update_roles', $_POST['update_roles']);
	}

	$shib_headers = get_option('shibboleth_headers');
	$shib_roles = get_option('shibboleth_roles');

	echo '
	<style type="text/css">
		.form-table input[type="text"] {
			width: 300px;
		}
	</style>

	<div class="wrap">
		<form method="post">

			<h2>' . __('Shibboleth Options', 'shibboleth') . '</h2>

			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="login_url">' .  __('Session Initiator URL') . '</label</th>
					<td><input type="text" id="login_url" name="login_url" value="' . get_option('shibboleth_login_url') . '" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="logout_url">' .  __('Logout URL') . '</label</th>
					<td><input type="text" id="logout_url" name="logout_url" value="' . get_option('shibboleth_logout_url') . '" /></td>
				</tr>
			</table>

			<br class="clear" />

			<h3>' . __('User Profile Data', 'shibboleth') . '</h3>

			<p>Define the Shibboleth headers which should be mapped to each 
			user profile attribute.  These header names are configured in 
			<code>attribute-map.xml</code> (for Shibboleth 2.x) or 
			<code>AAP.xml</code> (for Shibboleth 1.x).</p>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">
				<tr valign="top">
					<th scope="row"><label for="username">' .  __('Username') . '</label</th>
					<td><input type="text" id="username" name="headers[username]" value="'.$shib_headers['username'].'" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="first_name">' . __('First name') . '</label</th>
					<td><input type="text" id="first_name" name="headers[first_name]" value="'.$shib_headers['first_name'].'" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="last_name">' . __('Last name') . '</label</th>
					<td><input type="text" id="last_name" name="headers[last_name]" value="'.$shib_headers['last_name'].'" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="display_name">' . __('Display name') . '</label</th>
					<td><input type="text" id="display_name" name="headers[display_name]" value="'.$shib_headers['display_name'].'" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="email">' . __('Email Address') . '</label</th>
					<td><input type="text" id="email" name="headers[email]" value="'.$shib_headers['email'].'" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="update_users">' .  __('Update User Data') . '</label</th>
					<td>
						<input type="checkbox" id="update_users" name="update_users" '.(get_option('shibboleth_update_users') ? ' checked="checked"' : '').' />
						<label for="update_users">Use Shibboleth data to update user profile data each time the user logs in.  This will prevent users from being 
						able to manually update these fields.</label>
					  	<p>(Shibboleth data is always used to populate the user profile during account creation.)</p>

					</td>
				</tr>
			</table>

			<br class="clear" />

			<h3>' . __('User Role Mappings', 'shibboleth') . '</h3>

			<p>Users can be placed into one of WordPress\'s internal roles 
			based on any attribute.  For example, you could define a special 
			eduPersonEntitlement value that designates the user as a WordPress 
			Administrator.  Or you could automatically place all users with an 
			eduPersonAffiliation of "faculty" in the Author role.</p>

			<p><strong>Current Limitations:</strong> While WordPress supports 
			users having multiple roles, the Shibboleth plugin will only place 
			the user in the highest ranking role.  Only a single header/value 
			pair is supported for each user role.  This may be expanded in the 
			future to support multiple header/value pairs or regular expression 
			values.</p>

			<style type="text/css">
				#role_mappings { padding: 0; }
				#role_mappings thead th { padding: 5px 10px; }
				#role_mappings td, #role_mappings th { border-bottom: 0px; }
			</style>

			<table class="form-table optiontable editform" cellspacing="2" cellpadding="5" width="100%">

				<tr>
					<th scope="row">Role Mappings</th>
					<td id="role_mappings">
						<table id="">
						<thead>
							<tr>
								<th></th>
								<th scope="column">Header Name</th>
								<th scope="column">Header Value</th>
							</tr>
						</thead>
						<tbody>';

					foreach ($wp_roles->role_names as $key => $name) {
						echo'
						<tr valign="top">
							<th scope="row">'.$name.'</th>
							<td><input type="text" id="role_'.$key.'_header" name="shibboleth_roles['.$key.'][header]" value="' . @$shib_roles[$key]['header'] . '" /></td>
							<td><input type="text" id="role_'.$key.'_value" name="shibboleth_roles['.$key.'][value]" value="' . @$shib_roles[$key]['value'] . '" /></td>
						</tr>';
					}

					echo '
						</tbody>
						</table>
					</td>
				</tr>

				<tr>
					<th scope="row">Default Role</th>
					<td>
						<p>If a user does not map into any of the roles above, 
						they will be placed into the default role.  If there is 
						no default role, the user will not be able to 
						login with Shibboleth.</p>

						<select id="default_role" name="shibboleth_roles[default]">
						<option value="">(none)</option>';

			foreach ($wp_roles->role_names as $key => $name) {
				echo '
						<option value="'.$key.'"'. ($shib_roles['default'] == $key ? ' selected="selected"' : '') . '>'.$name.'</option>';
			}

			echo'
					</select></td>
				</tr>

				<tr>
					<th scope="row">Update User Roles</th>
					<td>
						<input type="checkbox" id="update_roles" name="update_roles" '.(get_option('shibboleth_update_roles') ? ' checked="checked"' : '').' />
						<label for="update_roles">Use Shibboleth data to update user role mappings each time the user logs in.  This 
						will prevent you from setting user roles manually within WordPress.</label>
					  	<p>(Shibboleth data is always used to populate the initial user role during account creation.)</p>
					</td>
				</tr>
			</table>


			' . wp_nonce_field('shibboleth_update_options', '_wpnonce', true, false) . '
			<p class="submit"><input type="submit" name="submit" value="' . __('Update Options') . ' &raquo;" /></p>
		</form>
	</div>
';

}


/**
 * Insert Shibboleth directives into .htaccess file.
 */
function shibboleth_insert_htaccess() {
	if (got_mod_rewrite()) {
		$htaccess = get_home_path() . '.htaccess';
		$rules = array('AuthType Shibboleth', 'Require Shibboleth');
		$result = insert_with_markers($htaccess, 'Shibboleth', $rules);
		if ($result) {
			error_log( 'yes' );
		} else {
			error_log( 'no' );
		}
	}

}

?>
