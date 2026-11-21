<?php
/**
 * Single owner of the site-wide voice option and its stored shapes.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Voice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the `plume_site_voice` option.
 *
 * The canonical shape is an associative array (tone, style, language, audience,
 * avoid, extra); the Settings textarea edits `extra`. Earlier versions stored the
 * Settings text as a plain string, so every reader goes through normalise(), and
 * saving converts a legacy string to the array shape. Stored data is never
 * rewritten on read.
 *
 * Covers the SITE option only; the per-user `plume_voice` meta is separate.
 *
 * @since 2.2.0
 */
final class SiteVoice {

	/**
	 * Site voice option name.
	 *
	 * @since 2.2.0
	 */
	public const OPTION = 'plume_site_voice';

	/**
	 * Keys of the canonical array shape.
	 *
	 * @since 2.2.0
	 */
	public const KEYS = [ 'tone', 'style', 'language', 'audience', 'avoid', 'extra' ];

	/**
	 * Convert any stored value into the canonical array shape.
	 *
	 * A non-empty string becomes `[ 'extra' => $string ]`. For arrays only known
	 * keys with scalar values are kept (cast to string), so nested values cannot
	 * trigger "Array to string conversion" downstream. Anything else is empty.
	 *
	 * @since 2.2.0
	 * @param mixed $raw Value read from the option.
	 * @return array<string,string>
	 */
	public static function normalise( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			return '' !== $raw ? [ 'extra' => $raw ] : [];
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$voice = [];
		foreach ( self::KEYS as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$voice[ $key ] = (string) $raw[ $key ];
			}
		}

		return $voice;
	}

	/**
	 * Get the site voice in canonical shape.
	 *
	 * @since 2.2.0
	 * @return array<string,string>
	 */
	public static function get(): array {
		return self::normalise( get_option( self::OPTION, [] ) );
	}

	/**
	 * Get the free text edited in Settings (the `extra` key).
	 *
	 * @since 2.2.0
	 * @return string Empty string when unset.
	 */
	public static function get_free_text(): string {
		return self::get()['extra'] ?? '';
	}

	/**
	 * Save the Settings free text into `extra`, keeping every other stored key.
	 *
	 * Deletes the option when nothing non-empty remains.
	 *
	 * @since 2.2.0
	 * @param string $text Free text from the Settings screen.
	 * @return void
	 */
	public static function save_free_text( string $text ): void {
		$raw = get_option( self::OPTION, [] );

		// A legacy string or junk value starts afresh; an array keeps every key.
		// Keys outside self::KEYS are deliberately preserved here for data-loss
		// safety, even though normalise()/get() never read them back (write-only).
		$voice = is_array( $raw ) ? $raw : [];

		$voice['extra'] = sanitize_textarea_field( $text );

		if ( [] === array_filter( $voice, static fn( $value ): bool => is_scalar( $value ) && '' !== trim( (string) $value ) ) ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $voice );
	}
}
