<?php
// functions for managing Shibboleth user options through the WordPress administration panel

add_action('profile_personal_options', 'shibboleth_profile_personal_options');
add_action('personal_options_update', 'shibboleth_personal_options_update');
add_action('show_user_profile', 'shibboleth_show_user_profile');
add_action('edit_user_profile', 'shibboleth_edit_user_profile');


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
					jQuery("form#your-profile").submit(function() {
						jQuery("#first_name,#last_name,#nickname,#display_name,#email").attr("disabled", false);
					});
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

