/**
 * Build and parse the "request revision" chat message.
 *
 * The wire format is sent to the AI and persisted as plain text, so the
 * labels below are deliberately untranslated and must not change: stored
 * history is parsed with them.
 */

export const REVISION_PREFIX = 'Please revise your proposed changes.';
const INLINE_LABEL = 'Inline feedback:';
const OVERALL_LABEL = 'Overall: ';

/**
 * Build the revision-request message sent to the AI.
 *
 * The caller is responsible for passing only saved comments.
 *
 * @param {Array<{selectedText?: string, text: string}>} comments    Saved comments.
 * @param {string}                                       generalNote Overall note (may be empty).
 * @return {string} Message content.
 */
export function buildRevisionMessage( comments, generalNote ) {
	const parts = [];
	if ( comments.length > 0 ) {
		const list = comments
			.map( ( c ) =>
				c.selectedText
					? `- On "${ c.selectedText }": ${ c.text }`
					: `- ${ c.text }`
			)
			.join( '\n' );
		parts.push( `${ INLINE_LABEL }\n${ list }` );
	}
	const note = ( generalNote ?? '' ).trim();
	if ( note ) {
		parts.push( `${ OVERALL_LABEL }${ note }` );
	}
	return `${ REVISION_PREFIX }\n\n${ parts.join( '\n\n' ) }`;
}

/**
 * Parse a stored revision-request message for display.
 *
 * The format is lossy: an anchor containing `": ` or a comment with a line
 * starting `- ` cannot be told apart from the delimiters. The first `": `
 * after the opening quote ends the anchor; messages that cannot be parsed
 * unambiguously return null so the caller falls back to plain text.
 *
 * @param {string} content Stored message content.
 * @return {?{comments: Array<{anchor: ?string, text: string}>, note: ?string}} Parsed message, or null.
 */
export function parseRevisionMessage( content ) {
	const head = `${ REVISION_PREFIX }\n\n`;
	if ( typeof content !== 'string' || ! content.startsWith( head ) ) {
		return null;
	}
	let body = content.slice( head.length );
	let note = null;

	const noteAt = body.lastIndexOf( `\n\n${ OVERALL_LABEL }` );
	if ( noteAt !== -1 ) {
		note = body.slice( noteAt + 2 + OVERALL_LABEL.length ).trim();
		body = body.slice( 0, noteAt );
	} else if ( body.startsWith( OVERALL_LABEL ) ) {
		note = body.slice( OVERALL_LABEL.length ).trim();
		body = '';
	}

	const comments = [];
	if ( body !== '' ) {
		if ( ! body.startsWith( `${ INLINE_LABEL }\n- ` ) ) {
			return null;
		}
		const items = body.slice( INLINE_LABEL.length + 1 ).split( '\n- ' );
		items[ 0 ] = items[ 0 ].slice( 2 );
		for ( const item of items ) {
			const m = item.match( /^On "(.*?)": ([\s\S]*)$/ );
			comments.push(
				m
					? { anchor: m[ 1 ], text: m[ 2 ] }
					: { anchor: null, text: item }
			);
		}
	}

	if ( comments.length === 0 && ! note ) {
		return null;
	}
	return { comments, note: note || null };
}
