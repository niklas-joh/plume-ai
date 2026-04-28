import { useMemo } from '@wordpress/element';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

marked.setOptions( { breaks: true, gfm: true } );

/**
 * Renders a Markdown string as sanitised HTML.
 *
 * Parsing is memoised on `content` to avoid re-running marked + DOMPurify
 * on every parent re-render.
 *
 * @param {Object} props
 * @param {string} props.content   Raw Markdown string to render.
 * @param {string} [props.className] Optional CSS class applied to the wrapper div.
 * @return {ReactElement}
 *
 * @example
 * <MarkdownContent content={ message.content } className="wpaim-bubble__markdown" />
 */
export default function MarkdownContent( { content, className } ) {
	const html = useMemo(
		() => DOMPurify.sanitize( marked.parse( content || '' ) ),
		[ content ]
	);
	return (
		<div
			className={ className }
			dangerouslySetInnerHTML={ { __html: html } }
		/>
	);
}
