<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */

define('DB_NAME', 'zdralice');


/** MySQL database username */
define('DB_USER', 'root');

/** MySQL database password */
define('DB_PASSWORD', '');

/** MySQL hostname */
define('DB_HOST', 'localhost');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');


define('WP_DEBUG', true);
/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'CP*!v%:SWzr},|tJm< .*)Do|mf=cdD<K4.Z%thm J^,kN8`?yfKp`I]cB-e0Qyk');
define('SECURE_AUTH_KEY',  '_pe;98@+7Krx2~p}k_oIF;[o`Udtx,)ox&OQ5F^i_wSHl:j>R3UVml)R8P0z-t3|');
define('LOGGED_IN_KEY',    '[R5!u:>#?1xcBWWe)g}w>GaH*@O2a:G Uy2U#vu5L*S$4VEUR}ujf5Y1JRx0f]29');
define('NONCE_KEY',        '+l7cZf>[9y)L2#b&4PEXV1M|gNf :o4z)k(GiVEz5::C19e<I[EqA4F&B/Wwb,(2');
define('AUTH_SALT',        '`bc*-yuJlz9I A+kB1iX{nQ}=@QPNY>k,7>g6kG*,ao]56 NBBC5/8+RI4Jb05=~');
define('SECURE_AUTH_SALT', '#Pz4Wwd6QHu5OyNPWhEDZi5lJKI#WY}_-Qo+yGWHPL3mx=h]Dl{.o#K#SXa50(5K');
define('LOGGED_IN_SALT',   'ET=J@kcv9^cFLYGPiH!-M^+tpj2=qH:lW@;~O.E5.*GPp9CJw;Y~?[6fmDbw.^S*');
define('NONCE_SALT',       '1`hRcZOb&r]9KOD7PsaJI<{W,cuF4(Q5yGBl<Rm5~i{&}@sf1(vpU$JXij#Fuh<l');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wp_zdralice';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define('WP_DEBUG', false);

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');