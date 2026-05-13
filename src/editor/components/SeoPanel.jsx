import { useSelect } from '@wordpress/data';
import { CheckCircle2, XCircle, AlertCircle, Lock } from 'lucide-react';

const { isPro } = window.wpAiMindData || {};

function scoreItem( label, pass, tip ) {
	let Icon;
	if ( pass === true ) {
		Icon = CheckCircle2;
	} else if ( pass === false ) {
		Icon = XCircle;
	} else {
		Icon = AlertCircle;
	}
	let color;
	if ( pass === true ) {
		color = 'var(--color-success, #22c55e)';
	} else if ( pass === false ) {
		color = 'var(--color-error, #ef4444)';
	} else {
		color = 'var(--color-warning, #f59e0b)';
	}

	return (
		<div key={ label } className="wpaim-seo-panel__item">
			<Icon size={ 14 } style={ { color } } />
			<span>
				{ label }
				{ tip && (
					<span className="wpaim-seo-panel__item-tip">
						{ ' — ' }
						{ tip }
					</span>
				) }
			</span>
		</div>
	);
}

/**
 * Live SEO checklist panel in the Block Editor sidebar (Pro only).
 *
 * Reads title, content, and excerpt live from `core/editor` state so scores
 * update as the user types without requiring a save. Renders a locked
 * placeholder for free-tier users.
 *
 * @return {ReactElement}
 */
export default function SeoPanel() {
	const { title, content, excerpt } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			title: editor.getEditedPostAttribute( 'title' ) ?? '',
			content: editor.getEditedPostAttribute( 'content' ) ?? '',
			excerpt: editor.getEditedPostAttribute( 'excerpt' ) ?? '',
		};
	} );

	if ( ! isPro ) {
		return (
			<div className="wpaim-seo-panel wpaim-seo-panel--locked">
				<Lock size={ 20 } />
				<p className="wpaim-seo-panel__locked-text">
					SEO analysis requires Stilus Pro.
				</p>
			</div>
		);
	}

	const wordCount = content
		.replace( /<[^>]+>/g, '' )
		.trim()
		.split( /\s+/ )
		.filter( Boolean ).length;
	const hasTitle = title.length >= 30 && title.length <= 60;
	const hasExcerpt = excerpt.length > 0;
	const goodLength = wordCount >= 300;

	const score = [ hasTitle, hasExcerpt, goodLength ].filter( Boolean ).length;
	let scoreColor;
	if ( score === 3 ) {
		scoreColor = 'var(--color-success, #22c55e)';
	} else if ( score >= 1 ) {
		scoreColor = 'var(--color-warning, #f59e0b)';
	} else {
		scoreColor = 'var(--color-error, #ef4444)';
	}

	return (
		<div className="wpaim-seo-panel">
			<div className="wpaim-seo-panel__score-row">
				<span
					className="wpaim-seo-panel__score"
					style={ { background: scoreColor } }
				>
					{ score }/3
				</span>
				<span className="wpaim-seo-panel__word-count">
					{ wordCount } words
				</span>
			</div>
			{ scoreItem(
				'Title length 30–60 chars',
				hasTitle,
				`${ title.length } chars`
			) }
			{ scoreItem( 'Excerpt present', hasExcerpt ) }
			{ scoreItem( '300+ words', goodLength, `${ wordCount } words` ) }
		</div>
	);
}
