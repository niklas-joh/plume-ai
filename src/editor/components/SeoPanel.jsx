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
			<Icon size={ 14 } style={ { color, flexShrink: 0 } } />
			<span>
				{ label }
				{ tip && (
					<span
						style={ {
							color: 'var(--color-text-muted)',
							fontSize: 'var(--text-xs)',
						} }
					>
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
			<div
				className="wpaim-seo-panel"
				style={ { textAlign: 'center', padding: 'var(--space-4)' } }
			>
				<Lock
					size={ 20 }
					style={ { color: 'var(--color-text-muted)' } }
				/>
				<p
					style={ {
						color: 'var(--color-text-muted)',
						fontSize: 'var(--text-sm)',
						marginTop: 'var(--space-2)',
					} }
				>
					SEO analysis requires WP AI Mind Pro.
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
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: 'var(--space-2)',
					marginBottom: 'var(--space-3)',
				} }
			>
				<span
					className="wpaim-seo-panel__score"
					style={ {
						background: scoreColor,
						color: '#fff',
						padding: '2px 8px',
						fontWeight: 600,
					} }
				>
					{ score }/3
				</span>
				<span
					style={ {
						fontSize: 'var(--text-sm)',
						color: 'var(--color-text-muted)',
					} }
				>
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
