import { useState, useRef, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Send, Loader2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import MarkdownContent from '../../shared/MarkdownContent';
import OutOfCreditsNotice from '../../shared/OutOfCreditsNotice';
import { getErrorMessage } from '../../shared/errors';
import { isOutOfCreditsError } from '../../shared/credits';
import { DEFAULT_WEBSITE_URL } from '../../shared/constants';

/**
 * Compact chat interface embedded in the Block Editor plugin sidebar.
 *
 * Creates a conversation lazily on the first message, associating it with the
 * current post via `post_id`. The conversation ID is kept in local state so
 * subsequent messages append to the same thread without a second POST to
 * /conversations.
 *
 * A failed request is shown in the thread (or as the out-of-credits notice),
 * so the author is never left looking at their own message with no answer.
 *
 * @param {Object}      props
 * @param {number|null} props.postId  Current post ID from `core/editor`; passed as `post_id` when creating a conversation and as `context_post_id` on every message to enable post-aware AI responses.
 * @return {ReactElement}
 */
export default function MiniChat( { postId } ) {
	const [ messages, setMessages ] = useState( [] );
	const [ input, setInput ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ convId, setConvId ] = useState( null );
	const endRef = useRef( null );

	useEffect( () => {
		endRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [ messages ] );

	async function send() {
		if ( ! input.trim() || isLoading ) {
			return;
		}
		const text = input.trim();
		setInput( '' );
		setMessages( ( prev ) => [ ...prev, { role: 'user', content: text } ] );
		setIsLoading( true );

		try {
			// Create conversation on first message.
			let cid = convId;
			if ( ! cid ) {
				const conv = await apiFetch( {
					path: '/plume/v1/conversations',
					method: 'POST',
					data: { title: text.slice( 0, 60 ), post_id: postId },
				} );
				cid = conv.id;
				setConvId( cid ); // capture new ID — stale closure fix
			}
			const res = await apiFetch( {
				path: `/plume/v1/conversations/${ cid }/messages`,
				method: 'POST',
				data: { content: text, context_post_id: postId ?? 0 },
			} );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: res.content },
			] );
		} catch ( e ) {
			setMessages( ( prev ) => [
				...prev,
				{
					role: 'assistant',
					isError: true,
					outOfCredits: isOutOfCreditsError( e ),
					content: getErrorMessage(
						e,
						__( 'Something went wrong. Please try again.', 'plume' )
					),
				},
			] );
		} finally {
			setIsLoading( false );
		}
	}

	function renderMessage( m, i ) {
		if ( m.outOfCredits ) {
			const { websiteUrl = DEFAULT_WEBSITE_URL, settingsUrl = '' } =
				window.plumeData ?? {};
			return (
				<OutOfCreditsNotice
					key={ i }
					websiteUrl={ websiteUrl }
					settingsUrl={ settingsUrl }
				/>
			);
		}
		if ( m.isError ) {
			return (
				<div
					key={ i }
					role="alert"
					className="plume-editor-mini-chat__bubble plume-editor-mini-chat__bubble--ai plume-editor-mini-chat__bubble--error"
				>
					{ m.content }
				</div>
			);
		}
		return (
			<div
				key={ i }
				className={ `plume-editor-mini-chat__bubble plume-editor-mini-chat__bubble--${
					m.role === 'user' ? 'user' : 'ai'
				}` }
			>
				{ m.role === 'assistant' ? (
					<MarkdownContent content={ m.content } />
				) : (
					m.content
				) }
			</div>
		);
	}

	return (
		<div className="plume-editor-mini-chat">
			<div className="plume-editor-mini-chat__messages">
				{ messages.length === 0 && (
					<p className="plume-editor-mini-chat__empty">
						{ __( 'Ask anything about this post…', 'plume' ) }
					</p>
				) }
				{ messages.map( renderMessage ) }
				{ isLoading && (
					<div className="plume-editor-mini-chat__bubble plume-editor-mini-chat__bubble--ai">
						<Loader2 size={ 14 } className="plume-spin" />
					</div>
				) }
				<div ref={ endRef } />
			</div>
			<div className="plume-editor-mini-chat__composer">
				<input
					className="plume-editor-mini-chat__input"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ ( e ) =>
						e.key === 'Enter' &&
						! e.shiftKey &&
						( e.preventDefault(), send() )
					}
					placeholder={ __( 'Ask AI…', 'plume' ) }
					aria-label={ __( 'Message to the AI', 'plume' ) }
					disabled={ isLoading }
				/>
				<button
					type="button"
					className="plume-editor-mini-chat__send"
					onClick={ send }
					disabled={ isLoading || ! input.trim() }
					aria-label={ __( 'Send', 'plume' ) }
				>
					{ isLoading ? (
						<Loader2 size={ 14 } className="plume-spin" />
					) : (
						<Send size={ 14 } />
					) }
				</button>
			</div>
		</div>
	);
}
