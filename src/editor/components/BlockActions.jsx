import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { FilePenLine, Minimize2, Wand2, Loader2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import { getErrorMessage } from '../../shared/errors';

// The reply replaces the block's text as-is, so the model must return the new
// text and nothing else: an introduction ("Here is a rewritten version:") or
// Markdown syntax would end up in the post.
const OUTPUT_RULE =
	'Return only the new text: no introduction, no explanation, no surrounding quotation marks and no Markdown.';

const ACTIONS = [
	{
		slug: 'rewrite',
		label: __( 'Rewrite block', 'plume' ),
		done: __( 'Block rewritten.', 'plume' ),
		icon: FilePenLine,
		prompt: `Rewrite the following content to improve clarity and style. ${ OUTPUT_RULE }\n\n`,
	},
	{
		slug: 'shorten',
		label: __( 'Make it shorter', 'plume' ),
		done: __( 'Block shortened.', 'plume' ),
		icon: Minimize2,
		prompt: `Shorten the following content while preserving the key message. ${ OUTPUT_RULE }\n\n`,
	},
	{
		slug: 'elaborate',
		label: __( 'Elaborate', 'plume' ),
		done: __( 'Block expanded.', 'plume' ),
		icon: Wand2,
		prompt: `Expand on the following content with more detail. ${ OUTPUT_RULE }\n\n`,
	},
];

/**
 * AI action buttons (rewrite, shorten, elaborate) for the currently selected block.
 *
 * An action reads the selected block's text, asks the AI for a replacement and
 * writes it back to the same block. Because the reply replaces the author's
 * words outright, every step is made visible: the running action shows a
 * spinner and blocks further clicks, a failure is reported as an editor notice,
 * and a success shows a snackbar whose Undo puts the previous text back.
 *
 * Undo restores the captured text on the captured block rather than calling the
 * editor's global undo, which would revert whatever the author did last and
 * that may no longer be this action.
 *
 * Each action runs in its own throwaway conversation, deleted afterwards so
 * block actions do not fill the Chat history with one-line conversations.
 *
 * @return {ReactElement}
 */
export default function BlockActions() {
	const selectedBlock = useSelect( ( select ) =>
		select( 'core/block-editor' ).getSelectedBlock()
	);
	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );
	const [ busySlug, setBusySlug ] = useState( null );

	// Most text blocks keep their text in `content`; a few (quote-like blocks)
	// use `value`. The reply must go back to the attribute it was read from.
	const attributes = selectedBlock?.attributes ?? {};
	let attribute = null;
	if ( attributes.content !== undefined && attributes.content !== null ) {
		attribute = 'content';
	} else if ( attributes.value !== undefined && attributes.value !== null ) {
		attribute = 'value';
	}
	// `content` is a RichTextData object on current WordPress; String() gives its HTML.
	const blockText = attribute ? String( attributes[ attribute ] ) : '';

	async function runAction( action ) {
		if ( busySlug !== null || ! blockText ) {
			return;
		}
		// Captured now: the selection may move while the request is in flight.
		const { clientId } = selectedBlock;
		const previous = attributes[ attribute ];
		let conversationId = null;

		setBusySlug( action.slug );
		try {
			const conversation = await apiFetch( {
				path: '/plume/v1/conversations',
				method: 'POST',
				data: { title: action.label },
			} );
			conversationId = conversation.id;

			const res = await apiFetch( {
				path: `/plume/v1/conversations/${ conversationId }/messages`,
				method: 'POST',
				data: { content: action.prompt + blockText },
			} );
			if ( ! res?.content ) {
				throw new Error(
					__( 'The AI returned no text. Please try again.', 'plume' )
				);
			}

			updateBlockAttributes( clientId, { [ attribute ]: res.content } );
			createSuccessNotice( action.done, {
				type: 'snackbar',
				actions: [
					{
						label: __( 'Undo', 'plume' ),
						onClick: () =>
							updateBlockAttributes( clientId, {
								[ attribute ]: previous,
							} ),
					},
				],
			} );
		} catch ( e ) {
			createErrorNotice(
				getErrorMessage(
					e,
					__( 'The AI action failed. Please try again.', 'plume' )
				)
			);
		} finally {
			setBusySlug( null );
			if ( conversationId ) {
				// Best effort: a failed clean-up only leaves a stray conversation.
				apiFetch( {
					path: `/plume/v1/conversations/${ conversationId }`,
					method: 'DELETE',
				} ).catch( () => {} );
			}
		}
	}

	if ( ! blockText ) {
		return (
			<p className="plume-block-actions__empty">
				{ __( 'Select a text block to use AI actions.', 'plume' ) }
			</p>
		);
	}

	const isBusy = busySlug !== null;

	return (
		<div className="plume-block-actions">
			{ ACTIONS.map( ( action ) => {
				const isRunning = busySlug === action.slug;
				const Icon = isRunning ? Loader2 : action.icon;
				return (
					<button
						key={ action.slug }
						type="button"
						className={ `plume-block-actions__btn${
							isBusy ? ' is-disabled' : ''
						}` }
						// aria-disabled, not disabled: a disabled button drops
						// keyboard focus the moment the action starts.
						aria-disabled={ isBusy }
						aria-busy={ isRunning }
						onClick={ () => runAction( action ) }
					>
						<Icon
							size={ 14 }
							strokeWidth={ 1.5 }
							className={ isRunning ? 'plume-spin' : undefined }
						/>
						{ isRunning ? __( 'Working…', 'plume' ) : action.label }
					</button>
				);
			} ) }
		</div>
	);
}
