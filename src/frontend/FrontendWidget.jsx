import { useState, useRef, useEffect } from '@wordpress/element';
import { MessageSquare, X, Send, Loader2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import MarkdownContent from '../shared/MarkdownContent';

const { currentPostId, siteTitle } = window.wpAiMindData || {};

/**
 * Floating chat widget rendered on the public-facing site via a shortcode.
 *
 * Toggled open/closed with a fixed-position button. Conversations are created
 * lazily on the first message and associated with the current post ID when
 * available. Network errors show an inline error bubble rather than crashing.
 *
 * @return {ReactElement}
 */
export default function FrontendWidget() {
	const [ open, setOpen ] = useState( false );
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
			let cid = convId;
			if ( ! cid ) {
				const conv = await apiFetch( {
					path: '/wp-ai-mind/v1/conversations',
					method: 'POST',
					data: {
						title: text.slice( 0, 60 ),
						post_id: currentPostId,
					},
				} );
				cid = conv.id;
				setConvId( cid );
			}
			const res = await apiFetch( {
				path: `/wp-ai-mind/v1/conversations/${ cid }/messages`,
				method: 'POST',
				data: { content: text },
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
					content: 'Sorry, something went wrong. Please try again.',
				},
			] );
		} finally {
			setIsLoading( false );
		}
	}

	return (
		<div className="wpaim-widget">
			<div
				className={ `wpaim-widget__panel ${
					open ? '' : 'wpaim-widget__panel--hidden'
				}` }
			>
				<div className="wpaim-widget__header">
					<span
						style={ {
							fontWeight: 600,
							fontSize: 'var(--text-sm)',
						} }
					>
						{ siteTitle || 'AI Assistant' }
					</span>
					<button
						onClick={ () => setOpen( false ) }
						style={ {
							background: 'none',
							border: 'none',
							cursor: 'pointer',
							color: 'var(--color-text-muted)',
							display: 'flex',
							alignItems: 'center',
						} }
					>
						<X size={ 16 } />
					</button>
				</div>

				<div className="wpaim-widget__messages">
					{ messages.length === 0 && (
						<p
							style={ {
								color: 'var(--color-text-muted)',
								fontSize: 'var(--text-sm)',
								textAlign: 'center',
								marginTop: 'var(--space-6)',
							} }
						>
							Hi! How can I help you?
						</p>
					) }
					{ messages.map( ( m, i ) => (
						<div
							key={ i }
							className={ `wpaim-widget__bubble wpaim-widget__bubble--${
								m.role === 'user' ? 'user' : 'ai'
							}` }
						>
							{ m.role === 'assistant' ? (
								<MarkdownContent content={ m.content } />
							) : (
								m.content
							) }
						</div>
					) ) }
					{ isLoading && (
						<div className="wpaim-widget__bubble wpaim-widget__bubble--ai">
							<Loader2 size={ 14 } className="wpaim-spin" />
						</div>
					) }
					<div ref={ endRef } />
				</div>

				<div className="wpaim-widget__composer">
					<input
						className="wpaim-widget__input"
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
						onKeyDown={ ( e ) =>
							e.key === 'Enter' &&
							! e.shiftKey &&
							( e.preventDefault(), send() )
						}
						placeholder="Ask anything…"
						disabled={ isLoading }
					/>
					<button
						className="wpaim-widget__send"
						onClick={ send }
						disabled={ isLoading || ! input.trim() }
					>
						{ isLoading ? (
							<Loader2 size={ 14 } className="wpaim-spin" />
						) : (
							<Send size={ 14 } />
						) }
					</button>
				</div>
			</div>

			<button
				className="wpaim-widget__toggle"
				onClick={ () => setOpen( ( prev ) => ! prev ) }
				aria-label="Open AI chat"
			>
				{ open ? (
					<X size={ 22 } color="white" />
				) : (
					<MessageSquare size={ 22 } color="white" />
				) }
			</button>
		</div>
	);
}
