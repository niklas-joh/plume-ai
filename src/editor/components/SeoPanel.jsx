import { useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CheckCircle2, XCircle, AlertCircle } from 'lucide-react';

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
		<div key={ label } className="plume-seo-panel__item">
			<Icon size={ 14 } style={ { color } } />
			<span>
				{ label }
				{ tip && (
					<span className="plume-seo-panel__item-tip">
						{ ' — ' }
						{ tip }
					</span>
				) }
			</span>
		</div>
	);
}

/**
 * Live SEO checklist panel in the Block Editor sidebar.
 *
 * Available to every tier — purely client-side analysis (word count, title
 * length, excerpt presence) with no Worker call, so there is no credit cost
 * and no tier gate. Reads title, content, and excerpt live from
 * `core/editor` state so scores update as the user types without requiring
 * a save.
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
		<div className="plume-seo-panel">
			<div className="plume-seo-panel__score-row">
				<span
					className="plume-seo-panel__score"
					style={ { background: scoreColor } }
				>
					{ sprintf(
						/* translators: %d: number of SEO checks passed, out of three. */
						__( '%d/3', 'plume' ),
						score
					) }
				</span>
				<span className="plume-seo-panel__word-count">
					{ sprintf(
						/* translators: %d: number of words in the post. */
						_n( '%d word', '%d words', wordCount, 'plume' ),
						wordCount
					) }
				</span>
			</div>
			{ scoreItem(
				__( 'Title length 30–60 chars', 'plume' ),
				hasTitle,
				sprintf(
					/* translators: %d: number of characters in the post title. */
					_n( '%d char', '%d chars', title.length, 'plume' ),
					title.length
				)
			) }
			{ scoreItem( __( 'Excerpt present', 'plume' ), hasExcerpt ) }
			{ scoreItem(
				__( '300+ words', 'plume' ),
				goodLength,
				sprintf(
					/* translators: %d: number of words in the post. */
					_n( '%d word', '%d words', wordCount, 'plume' ),
					wordCount
				)
			) }
		</div>
	);
}
