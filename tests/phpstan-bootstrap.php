<?php
// PHPStan bootstrap — defines constants WP normally sets at runtime.
// Guard each constant: the phpstan-wordpress extension may pre-define some of them.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', __DIR__ . '/../wp-content' );
// Static placeholder — semantic-release owns the real version; no code should branch on this value.
defined( 'WP_AI_MIND_VERSION' ) || define( 'WP_AI_MIND_VERSION', '1.0.0' );
defined( 'WP_AI_MIND_FILE' ) || define( 'WP_AI_MIND_FILE', __DIR__ . '/../wp-ai-mind.php' );
defined( 'WP_AI_MIND_DIR' ) || define( 'WP_AI_MIND_DIR', __DIR__ . '/../' );
defined( 'WP_AI_MIND_URL' ) || define( 'WP_AI_MIND_URL', 'https://example.com/wp-content/plugins/wp-ai-mind/' );
defined( 'WP_AI_MIND_BASENAME' ) || define( 'WP_AI_MIND_BASENAME', 'wp-ai-mind/wp-ai-mind.php' );
defined( 'WP_AI_MIND_HTTP_TIMEOUT' ) || define( 'WP_AI_MIND_HTTP_TIMEOUT', 60 );
