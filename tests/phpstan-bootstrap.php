<?php
// PHPStan bootstrap — defines constants WP normally sets at runtime.
// Guard each constant: the phpstan-wordpress extension may pre-define some of them.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', __DIR__ . '/../wp-content' );
// Static placeholder — semantic-release owns the real version; no code should branch on this value.
defined( 'PLUME_VERSION' ) || define( 'PLUME_VERSION', '1.0.0' );
defined( 'PLUME_FILE' ) || define( 'PLUME_FILE', __DIR__ . '/../plume.php' );
defined( 'PLUME_DIR' ) || define( 'PLUME_DIR', __DIR__ . '/../' );
defined( 'PLUME_URL' ) || define( 'PLUME_URL', 'https://example.com/wp-content/plugins/plume/' );
defined( 'PLUME_BASENAME' ) || define( 'PLUME_BASENAME', 'plume/plume.php' );
defined( 'PLUME_HTTP_TIMEOUT' ) || define( 'PLUME_HTTP_TIMEOUT', 60 );
