<?php
/**
 * Human-readable names for AI model ids.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns any model id a provider or the managed Worker may report into a name a
 * person can read.
 *
 * Chat replies record the id the provider itself returned, which can be a dated
 * snapshot (for example OpenAI's gpt-4.1-nano-2025-04-14) that no model list
 * carries. This class is the single PHP source of truth for display names: the
 * JavaScript never derives a name from an id, it renders the label sent with it.
 *
 * @since NEXT_VERSION
 */
final class ModelLabels {

	/**
	 * Longest model id considered; anything beyond this is cut before processing.
	 *
	 * @since NEXT_VERSION
	 */
	private const MAX_ID_LENGTH = 120;

	/**
	 * Longest label returned.
	 *
	 * @since NEXT_VERSION
	 */
	private const MAX_LABEL_LENGTH = 60;

	/**
	 * Return the display name for a model id.
	 *
	 * Resolution order: an exact match in the known model lists; the same lookup
	 * after removing a snapshot suffix (an 8-digit date, an ISO date, "-latest" or
	 * an Ollama ":tag"); then a humanised form of the id. The raw id is never
	 * returned for a slug-shaped id, and the result is always plain text.
	 *
	 * @since NEXT_VERSION
	 * @param string $model_id Model id as stored or reported, for example "claude-haiku-4-5-20251001".
	 * @return string Display name, or an empty string when no id was given.
	 */
	public static function for_id( string $model_id ): string {
		$model_id = trim( $model_id );
		if ( '' === $model_id ) {
			return '';
		}

		// The id may come from a provider response or a database row, so treat it as untrusted text.
		$clean = sanitize_text_field( mb_substr( $model_id, 0, self::MAX_ID_LENGTH ) );
		if ( '' === $clean ) {
			return self::neutral();
		}

		$known = self::known();
		if ( isset( $known[ $clean ] ) ) {
			return self::finish( $known[ $clean ], $clean );
		}

		$stripped = self::strip_snapshot( $clean );
		if ( isset( $known[ $stripped ] ) ) {
			return self::finish( $known[ $stripped ], $clean );
		}

		return self::finish( self::humanise( $stripped ), $clean );
	}

	/**
	 * Every model id with a curated label.
	 *
	 * Provider labels win over the managed ones, the same precedence as
	 * ManagedCatalogue::labelled_models().
	 *
	 * @since NEXT_VERSION
	 * @return array<string, string> Model id => label.
	 */
	private static function known(): array {
		return array_merge(
			ManagedDefaults::labels(),
			ClaudeProvider::MODELS,
			OpenAIProvider::MODELS,
			GeminiProvider::MODELS
		);
	}

	/**
	 * Remove an Ollama tag and a trailing date or "-latest" marker.
	 *
	 * Deliberately narrow: other trailing numbers (such as Gemini's "-001") can be
	 * part of a real model name, so they stay.
	 *
	 * @since NEXT_VERSION
	 * @param string $model_id Sanitised model id.
	 * @return string The id without its snapshot suffix.
	 */
	private static function strip_snapshot( string $model_id ): string {
		$model_id = (string) preg_replace( '/:[A-Za-z0-9._-]+$/', '', $model_id );
		return (string) preg_replace( '/-(?:\d{8}|\d{4}-\d{2}-\d{2}|latest)$/', '', $model_id );
	}

	/**
	 * Build a readable name from an unrecognised id.
	 *
	 * Splits on separators, joins runs of bare digits with a dot ("4-5" becomes
	 * "4.5") and capitalises each word, keeping "GPT" upper case.
	 *
	 * @since NEXT_VERSION
	 * @param string $model_id Sanitised model id without snapshot suffix.
	 * @return string Humanised name.
	 */
	private static function humanise( string $model_id ): string {
		$tokens = preg_split( '/[-_\/\s]+/', $model_id, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) || [] === $tokens ) {
			return $model_id;
		}

		$words = [];
		foreach ( $tokens as $token ) {
			$last = count( $words ) - 1;
			if ( $last >= 0 && ctype_digit( $token ) && ctype_digit( str_replace( '.', '', $words[ $last ] ) ) ) {
				$words[ $last ] .= '.' . $token;
				continue;
			}
			$words[] = $token;
		}

		$words = array_map(
			static fn( string $word ): string => 'gpt' === strtolower( $word ) ? 'GPT' : ucfirst( $word ),
			$words
		);

		// "GPT-4.1 Turbo", not "GPT 4.1 Turbo": the family name and version read as one word.
		if ( 'GPT' === $words[0] && isset( $words[1] ) && 1 === preg_match( '/^\d/', $words[1] ) ) {
			$words[0] .= '-' . $words[1];
			array_splice( $words, 1, 1 );
		}

		return implode( ' ', $words );
	}

	/**
	 * Final guard: plain text, length-capped, and never the raw slug.
	 *
	 * @since NEXT_VERSION
	 * @param string $label Candidate label.
	 * @param string $raw   The sanitised id the label was built from.
	 * @return string Safe label.
	 */
	private static function finish( string $label, string $raw ): string {
		$label = trim( sanitize_text_field( $label ) );
		if ( mb_strlen( $label ) > self::MAX_LABEL_LENGTH ) {
			$label = rtrim( mb_substr( $label, 0, self::MAX_LABEL_LENGTH - 1 ) ) . '…';
		}

		// A label identical to a slug-shaped id (for example "1234") tells the reader nothing.
		if ( '' === $label || ( $label === $raw && 1 === preg_match( '/^[a-z0-9._:\/-]+$/', $raw ) ) ) {
			return self::neutral();
		}

		return $label;
	}

	/**
	 * Neutral name used when an id cannot be turned into anything readable.
	 *
	 * @since NEXT_VERSION
	 * @return string Translated label.
	 */
	private static function neutral(): string {
		return __( 'AI model', 'plume' );
	}
}
