<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/documentation/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
if (isset($_ENV['DATABASE'])) {
  define( 'DB_NAME', $_ENV['DATABASE'] );
}

/** Database username */
if (isset($_ENV['USERNAME'])) {
  define( 'DB_USER', $_ENV['USERNAME'] );
}

/** Database password */
if (isset($_ENV['PASSWORD'])) {
  define( 'DB_PASSWORD', $_ENV['PASSWORD'] );
}

/** Database hostname */
if (isset($_ENV['HOST'])) {
  define( 'DB_HOST', $_ENV['HOST'] );
}

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
if (isset($_ENV['DB_COLLATE'])) {
  define( 'DB_COLLATE', $_ENV['DB_COLLATE'] );
}
else {
  if (isset($_ENV['HOST']) && str_contains($_ENV['HOST'], 'tidbcloud.com')) {
    define ( 'DB_COLLATE', 'utf8mb4_general_ci');
  }
  else {
    define( 'DB_COLLATE', '' );
  }
}

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'Y;SF~k[L(aR~6E[9MHo|FsSm*As7gq{+>Dv8(B=Flra<.u/Ckx[](&-zhpP91J?_' );
define( 'SECURE_AUTH_KEY',  'o[!9y*@ZT&w0MC{kUD]ux,Gxw!L*7t+!+loO/e|Vm,X0QY8xf71$,.4Kyo{CyT>/' );
define( 'LOGGED_IN_KEY',    'G.gZ G&TFiS{ r*~?2[OgX,rSEAzX5``<,8pD/&%pY~;[}zju1&Zz7GKa0M[H?t1' );
define( 'NONCE_KEY',        '`c_e2j6eA9tuFsrnI#J(p$k.!2-=_H(xF+v`JCM!F8,jPsmdfEj8fvN>y=e{j^g@' );
define( 'AUTH_SALT',        'uo+A!z[EhC/>M_K{WWS@7g5g.W#9^L7<M$$!7Ei9k.`Z(!{<<sD&CFL^CFA~sLuN' );
define( 'SECURE_AUTH_SALT', 'hNMx|f=2Su_yW_)B?(<T7P(Nq] ##g81`wythJ-pl-8knn 6?Ks&(^ t:Q[*C_>(' );
define( 'LOGGED_IN_SALT',   'yS};55?L/RUPTq]a:Xu5KOPZX>;QvQrnMSGj[C0:p{;%Z~Lc2+b!_n@3xGX${%`H' );
define( 'NONCE_SALT',       't.A5`-HT=CXel*+Y@s9x`|G#dfT7J:%{Fk*dw7jXKC .Hx`1twOx.UeXTs0sBSDH' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = isset($_ENV['TABLE_PREFIX']) ? $_ENV['TABLE_PREFIX'] : 'xy_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/documentation/article/debugging-in-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */

if (!isset($_ENV['SKIP_MYSQL_SSL'])) {
  define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL );
}

$_SERVER['HTTPS'] = 'on';

// Inject the true host.
$headers = getallheaders();
if (isset($headers['injectHost'])) {
  $_SERVER['HTTP_HOST'] = $headers['injectHost'];
}

define('WP_SITEURL', 'https://' . $_SERVER['HTTP_HOST']);
define('WP_HOME', 'https://' . $_SERVER['HTTP_HOST']);

// Optional S3 credentials for file storage.
if (isset($_ENV['S3_KEY_ID']) && isset($_ENV['S3_ACCESS_KEY'])) {
	define( 'AS3CF_SETTINGS', serialize( array(
        'provider' => 'aws',
        'access-key-id' => $_ENV['S3_KEY_ID'],
        'secret-access-key' => $_ENV['S3_ACCESS_KEY'],
) ) );
}

// Disable file modification because the changes won't be persisted.
define('DISALLOW_FILE_EDIT', true );
define('DISALLOW_FILE_MODS', true );

// If using SQLite + S3 instead of MySQL/MariaDB.
if (isset($_ENV['SQLITE_S3_BUCKET'])) {
  define('DB_DIR', '/tmp');
  define('DB_FILE', 'wp-sqlite-s3.sqlite');

  // Auto-cron can cause db race conditions on these urls, don't bother with it.
  if (strpos($_SERVER['REQUEST_URI'], 'wp-admin') !== false || strpos($_SERVER['REQUEST_URI'], 'wp-login') !== false) {
    define('DISABLE_WP_CRON', true);
  }

  // Increase time between cron runs (2 hours) to reduce DB writes.
  define('WP_CRON_LOCK_TIMEOUT', 7200);

  // Limit revisions.
  define('WP_POST_REVISIONS', 3);
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
