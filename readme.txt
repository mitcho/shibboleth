=== Shibboleth ===
Contributors: wnorris 
Tags: shibboleth, authentication, login, saml
Requires at least: 2.6
Tested up to: 2.7.1
Stable tag: 0.1

Allows WordPress to externalize user authentication and account creation to a
Shibboleth Service Provider.

== Description ==

Allows WordPress to externalize user authentication and account creation to a
[Shibboleth][] Service Provider.

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

**For single-user WordPress**

Upload the `shibboleth` folder to your WordPress plugins folder (probably
/wp-content/plugins), and activate it through the WordPress admin panel.
Configure it from the Shibboleth settings page.

**For WordPress MU**

Shibboleth works equally well with WordPress MU using either vhosts or folders
for blogs.  Upload the `shibboleth` folder to your mu-plugins folder (probably
/wp-content/mu-plugins).  No need to activate it, just configure it from the
Shibboleth settings page.

[properly installed]: https://spaces.internet2.edu/display/SHIB2/Installation

== Frequently Asked Questions ==

= What is Shibboleth? =

<http://shibboleth.internet2.edu/>

== Screenshots ==

1. Specify which Shibboleth headers map to user profile fields
2. Assign users into WordPress roles based on arbitrary data provided by Shibboleth

== Changelog ==

= version 0.8 =
 - lots of fixes for WordPress MU

= version 0.1 =
 - initial public release

