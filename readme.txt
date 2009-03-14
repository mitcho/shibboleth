=== Shibboleth ===
Contributors: wnorris 
Tags: shibboleth, authentication, login, saml
Requires at least: 2.6
Tested up to: 2.7.1
Stable tag: 1.0

Allows WordPress to externalize user authentication and account creation to a
Shibboleth Service Provider.

== Description ==

This plugin is designed to support integrating your WordPress or WordPress MU
blog into your existing identity management infrastructure using a
[Shibboleth][] Service Provider.  

All WordPress login requests will be sent to your configured Shibboleth
Identity Provider or Discovery Service.  Upon successful authentication, a new
WordPress account will be automatically provisioned for the user if one does
not already exist.  User attributes (username, first name, last name, display
name, and email address) can be synchronized with your enterprise's system of
record each time the user logs into WordPress.  

Finally, the user's role within WordPress can be automatically set (and
continually updated) based on any attribute Shibboleth provides.  For example,
you may decide to give users with an eduPersonAffiliation value of 'faculty'
the WordPress role of 'editor', while the eduPersonAffiliation value of
'student' maps to the WordPress role 'contributor'.  Or you may choose to limit
access to WordPress altogether using a special eduPersonEntitlement value.

[Shibboleth]: http://shibboleth.internet2.edu/

== Installation ==

First and foremost, you must have the Shibboleth Service Provider [properly
installed][] and working.  If you don't have Shibboleth working yet, I assure
you that you won't get this plugin to work.  This plugin expects Shibboleth to
be configured to use "lazy sessions", so ensure that you have Shibboleth
configured with requireSession set to "false".  Upon activation, the plugin
will attempt to set the appropriate directives in WordPress's .htaccess file.
If it is unable to do so, you can add this manually:

    AuthType Shibboleth
    Require Shibboleth

= For single-user WordPress =

Upload the `shibboleth` folder to your WordPress plugins folder (probably
/wp-content/plugins), and activate it through the WordPress admin panel.
Configure it from the Shibboleth settings page.

= For WordPress MU =

Shibboleth works equally well with WordPress MU using either vhosts or folders
for blogs.  Upload the `shibboleth` folder to your mu-plugins folder (probably
/wp-content/mu-plugins).  Move the file `shibboleth-mu.php` from the
`shibboleth` folder up one directory so that it is in `mu-plugins` alongside
the `shibboleth` folder.  No need to activate it, just configure it from the
Shibboleth settings page.

[properly installed]: https://spaces.internet2.edu/display/SHIB2/Installation

== Frequently Asked Questions ==

= What is Shibboleth? =

<http://shibboleth.internet2.edu/>

= Can I still login using my local 'admin' account? =

Yes. Simply specify the login action `local_login`, like so:

	navigate to http://yoursite.com/wp-login.php?action=local_login

= I've screwed something up and can't get into my WordPress site at all =

You can forcibly disable the plugin by deleting or renaming the plugin folder.
This should allow you to then use the normal WordPress account recovery
mechanisms to get back into your site.

== Screenshots ==

1. Specify which Shibboleth headers map to user profile fields
2. Assign users into WordPress roles based on arbitrary data provided by Shibboleth

== Changelog ==

= version 1.0 =
 - now works properly with WordPress MU
 - move Shibboleth menu to Site Admin for WordPress MU (props: Chris Bland)
 - lots of code cleanup and documentation

= version 0.1 =
 - initial public release

