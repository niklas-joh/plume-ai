<?php
/**
 * Site key: the normalised site address used to link usage statistics.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP mirror of siteKeyFromUrl() in plume-proxy/src/metrics.ts, which is the reference.
 *
 * The managed service keys plume_metrics.index1 on siteKeyFromUrl( site_url ), where
 * site_url is the home_url() sent to /register. Heartbeats carry the same key so the
 * two datasets can be joined per site. Both implementations are run against the shared
 * fixture plume-proxy/test/fixtures/site-key-cases.json (SiteKeyTest and vitest), so
 * any drift fails a test.
 *
 * The key is `host[/path]`: host lower-cased, IDN hosts in punycode, one leading `www.`
 * stripped; scheme, port, credentials, query, fragment and trailing slashes dropped;
 * the path percent-encoded like a WHATWG URL path; at most 96 bytes.
 *
 * Known limits, none of which home_url() produces in practice: dot segments in the
 * path are not resolved, IPv6 and percent-encoded hosts return '', and IPv4 shorthand
 * (for example `0x7f.1`) is not normalised.
 *
 * @since NEXT_VERSION
 */
final class SiteKey {

	/**
	 * Byte limit of an Analytics Engine index, as in the Worker.
	 *
	 * @since NEXT_VERSION
	 */
	public const MAX_BYTES = 96;

	/**
	 * Normalise a site URL to its site key.
	 *
	 * @since NEXT_VERSION
	 * @param string $url Site URL.
	 * @return string The key, or '' when the URL is not a usable http(s) URL.
	 */
	public static function from_url( string $url ): string {
		// WHATWG drops tabs and newlines anywhere, trims C0 controls and spaces at both
		// ends, and reads a backslash as a slash in http(s) URLs. PHP's parser would
		// count a backslash as part of the host, so it is converted before parsing.
		$url = str_replace( [ "\t", "\n", "\r", '\\' ], [ '', '', '', '/' ], $url );
		$url = trim( $url, "\x00..\x20" );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = self::host( (string) $parts['host'] );
		if ( '' === $host ) {
			return '';
		}

		$path = rtrim( self::encode_path( (string) ( $parts['path'] ?? '' ) ), '/' );

		// Host and encoded path are pure ASCII here, so a byte cut never splits a
		// character and matches the Worker's truncateBytes().
		return substr( $host . $path, 0, self::MAX_BYTES );
	}

	/**
	 * The site key of this site, from home_url().
	 *
	 * The address comes from home_url(), the same URL SiteRegistration sends to /register. Plugins
	 * that filter it (for example to add a language path) change both in the same way, so they
	 * stay consistent.
	 *
	 * @since NEXT_VERSION
	 * @return string The key, or ''.
	 */
	public static function current(): string {
		return self::from_url( (string) home_url() );
	}

	/**
	 * Whether a value is a site key in its canonical form (the Worker runs the same check).
	 *
	 * Two rare keys fail this round trip and are therefore never sent, so the site does
	 * not join: a host that still starts with `www.` after one was stripped (for example
	 * `www.www.example.com`), and a key cut at the byte limit right after a slash.
	 *
	 * @since NEXT_VERSION
	 * @param string $key Candidate key.
	 * @return bool
	 */
	public static function is_valid( string $key ): bool {
		return '' !== $key && strlen( $key ) <= self::MAX_BYTES && self::from_url( 'https://' . $key ) === $key;
	}

	/**
	 * Normalise a host: lower case, punycode for IDN, one `www.` stripped.
	 *
	 * @param string $host Raw host from wp_parse_url().
	 * @return string Host, or '' when it cannot be normalised like the Worker does.
	 */
	private static function host( string $host ): string {
		// Characters WHATWG forbids in a domain (after percent-decoding, which is not
		// mirrored here), plus brackets so IPv6 literals are refused rather than mismatched.
		if ( '' === $host || 1 === preg_match( '/[\x00-\x20\x7F#%\/:<>?@\[\\\\\]^|]/', $host ) ) {
			return '';
		}
		if ( 1 === preg_match( '/[\x80-\xFF]/', $host ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return '';
			}
			$ascii = idn_to_ascii( $host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $ascii ) {
				return '';
			}
			$host = $ascii;
		}
		$host = strtolower( $host );
		if ( str_starts_with( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	/**
	 * Percent-encode a path with the WHATWG path percent-encode set.
	 *
	 * That set is C0 controls, space, `"`, `#`, `<`, `>`, `?`, backtick, `{`, `}`, DEL
	 * and every non-ASCII byte. Existing `%XX` escapes and lone `%` signs are kept as
	 * they are, as WHATWG does.
	 *
	 * @param string $path Raw path.
	 * @return string Encoded path.
	 */
	private static function encode_path( string $path ): string {
		return (string) preg_replace_callback(
			'/[\x00-\x20"#<>?`{}\x7F-\xFF]/',
			static fn( array $m ): string => '%' . strtoupper( bin2hex( $m[0] ) ),
			$path
		);
	}
}
