<?php
// PHPStan bootstrap — defines constants WP normally sets at runtime.
// Guard each constant: the phpstan-wordpress extension may pre-define some of them.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', __DIR__ . '/../wp-content' );
// Static placeholder — semantic-release owns the real version; no code should branch on this value.
defined( 'STILUS_VERSION' ) || define( 'STILUS_VERSION', '1.0.0' );
defined( 'STILUS_FILE' ) || define( 'STILUS_FILE', __DIR__ . '/../stilus.php' );
defined( 'STILUS_DIR' ) || define( 'STILUS_DIR', __DIR__ . '/../' );
defined( 'STILUS_URL' ) || define( 'STILUS_URL', 'https://example.com/wp-content/plugins/stilus/' );
defined( 'STILUS_BASENAME' ) || define( 'STILUS_BASENAME', 'stilus/stilus.php' );
defined( 'STILUS_HTTP_TIMEOUT' ) || define( 'STILUS_HTTP_TIMEOUT', 60 );
