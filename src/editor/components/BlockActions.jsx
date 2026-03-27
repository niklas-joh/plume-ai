import { useSelect } from '@wordpress/data';
import { FilePenLine, Minimize2, Wand2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';

const ACTIONS = [
	{
		slug: 'rewrite',
		label: 'Rewrite block',
		icon: FilePenLine,
		prompt: 'Rewrite the following content to improve clarity and style:\n\n',
	},
	{
		slug: 'shorten',
		label: 'Make it shorter',
		icon: Minimize2,
		prompt: 'Shorten the following content while preserving the key message:\n\n',
	},
	{
		slug: 'elaborate',
		label: 'Elaborate',
		icon: Wand2,
		prompt: 'Expand on the following content with more detail:\n\n',
	},
];

export default function BlockActions( { convId, onResult } ) {
	const selectedBlock = useSelect( ( select ) =>
		select( 'core/block-editor' ).getSelectedBlock()
	);

	const blockText =
		selectedBlock?.attributes?.content ??
		selectedBlock?.attributes?.value ??
		'';

	async function runAction( action ) {
		if ( ! blockText ) {
			return;
		}
		try {
			const cid =
				convId ||
				(
					await apiFetch( {
						path: '/wp-ai-mind/v1/conversations',
						method: 'POST',
						data: { title: action.label },
					} )
				).id;
			const res = await apiFetch( {
				path: `/wp-ai-mind/v1/conversations/${ cid }/messages`,
				method: 'POST',
				data: { content: action.prompt + blockText },
			} );
			onResult?.( res.content, selectedBlock?.clientId );
		} catch ( e ) {
			// Block action failed — silently ignore.
		}
	}

	if ( ! blockText ) {
		return (
			<p
				style={ {
					color: 'var(--color-text-muted)',
					fontSize: 'var(--text-sm)',
				} }
			>
				Select a text block to use AI actions.
			</p>
		);
	}

	return (
		<div className="wpaim-block-actions">
			{ ACTIONS.map( ( action ) => (
				<button
					key={ action.slug }
					className="wpaim-block-actions__btn"
					onClick={ () => runAction( action ) }
				>
					<action.icon
						size={ 14 }
						strokeWidth={ 1.5 }
						style={ { marginRight: 'var(--space-2)' } }
					/>
					{ action.label }
				</button>
			) ) }
		</div>
	);
}
