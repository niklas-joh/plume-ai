import { useState, useEffect, useRef } from '@wordpress/element';
import { MessageSquare, Plus } from 'lucide-react';
import ConversationHistory from '../Sidebar/ConversationHistory';
import MessageList from './MessageList';
import Composer from './Composer';
import QuickActions from '../RightPanel/QuickActions';
import ModelSelector from '../RightPanel/ModelSelector';
import apiFetch from '@wordpress/api-fetch';

export default function ChatApp() {
	const { isPro } = window.wpAiMindData || {};

	const [ conversations, setConversations ] = useState( [] );
	const [ activeConvId, setActiveConvId ] = useState( null );
	const [ messages, setMessages ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ selectedProvider, setSelectedProvider ] = useState( '' );
	const [ selectedModel, setSelectedModel ] = useState( '' );
	const [ providers, setProviders ] = useState( [] );
	const [ attachedPost, setAttachedPost ] = useState( null );
	const [ deletingIds, setDeletingIds ] = useState( new Set() );
	const [ deleteError, setDeleteError ] = useState( null );
	const skipLoadRef = useRef( false );

	useEffect( () => {
		loadConversations();
		loadProviders();
	}, [] );

	useEffect( () => {
		if ( activeConvId ) {
			if ( skipLoadRef.current ) {
				skipLoadRef.current = false;
				return;
			}
			loadMessages( activeConvId );
		}
	}, [ activeConvId ] );

	async function loadConversations() {
		try {
			const data = await apiFetch( {
				path: '/wp-ai-mind/v1/conversations',
			} );
			setConversations( data );
		} catch ( e ) {
			// Conversations failed to load — list stays empty.
		}
	}

	async function loadProviders() {
		try {
			const data = await apiFetch( { path: '/wp-ai-mind/v1/providers' } );
			setProviders( data );
			if ( data.length > 0 ) {
				setSelectedProvider( data[ 0 ].slug );
			}
		} catch ( e ) {
			// Provider list is best-effort — don't crash if unavailable.
		}
	}

	async function loadMessages( convId ) {
		const data = await apiFetch( {
			path: `/wp-ai-mind/v1/conversations/${ convId }/messages`,
		} );
		setMessages( data );
	}

	async function newConversation() {
		const conv = await apiFetch( {
			path: '/wp-ai-mind/v1/conversations',
			method: 'POST',
			data: { title: 'New conversation' },
		} );
		setConversations( ( prev ) => [ conv, ...prev ] );
		setActiveConvId( conv.id );
		setMessages( [] );
	}

	function removeConversationFromState( convId ) {
		setConversations( ( prev ) => prev.filter( ( c ) => c.id !== convId ) );
		if ( activeConvId === convId ) {
			setActiveConvId( null );
			setMessages( [] );
		}
	}

	async function deleteConversation( convId ) {
		if ( deletingIds.has( convId ) ) {
			return;
		}
		setDeleteError( null );
		setDeletingIds( ( prev ) => new Set( [ ...prev, convId ] ) );
		try {
			await apiFetch( {
				path: `/wp-ai-mind/v1/conversations/${ convId }`,
				method: 'DELETE',
			} );
			removeConversationFromState( convId );
		} catch ( e ) {
			if ( e?.data?.status === 404 ) {
				// Already gone on the server — remove from list.
				removeConversationFromState( convId );
			} else {
				// eslint-disable-next-line no-console
				console.error( 'Failed to delete conversation:', e );
				setDeleteError(
					'Failed to delete conversation. Please try again.'
				);
			}
		} finally {
			setDeletingIds( ( prev ) => {
				const next = new Set( prev );
				next.delete( convId );
				return next;
			} );
		}
	}

	async function sendMessage( content ) {
		// Resolve conversation ID — create one if none active.
		let convId = activeConvId;
		if ( ! convId ) {
			const conv = await apiFetch( {
				path: '/wp-ai-mind/v1/conversations',
				method: 'POST',
				data: { title: content.slice( 0, 60 ) },
			} );
			setConversations( ( prev ) => [ conv, ...prev ] );
			skipLoadRef.current = true;
			setActiveConvId( conv.id );
			convId = conv.id; // capture new ID — do NOT use activeConvId (stale closure)
		}

		setMessages( ( prev ) => [ ...prev, { role: 'user', content } ] );
		setIsLoading( true );

		try {
			const res = await apiFetch( {
				path: `/wp-ai-mind/v1/conversations/${ convId }/messages`,
				method: 'POST',
				data: {
					content,
					provider: selectedProvider,
					model: selectedModel,
					context_post_id: attachedPost?.id ?? 0,
				},
			} );
			setMessages( ( prev ) => [
				...prev,
				{
					role: 'assistant',
					content: res.content,
					model: res.model,
					tokens: res.tokens,
				},
			] );
		} catch ( err ) {
			const errorText =
				err?.message ?? 'Something went wrong. Please try again.';
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: errorText, isError: true },
			] );
		} finally {
			setIsLoading( false );
		}
	}

	return (
		<div className="wpaim-shell">
			<aside className="wpaim-sidebar">
				<div className="wpaim-sidebar__header">
					<span className="wpaim-sidebar__title">Conversations</span>
					<button
						className="wpaim-btn wpaim-btn--ghost wpaim-btn--icon"
						onClick={ newConversation }
						title="New conversation"
						aria-label="New conversation"
						type="button"
					>
						<Plus size={ 14 } strokeWidth={ 1.5 } />
					</button>
				</div>
				{ deleteError && (
					<div className="wpaim-sidebar__error">{ deleteError }</div>
				) }
				<ConversationHistory
					conversations={ conversations }
					activeId={ activeConvId }
					onSelect={ setActiveConvId }
					onDelete={ deleteConversation }
					deletingIds={ deletingIds }
				/>
			</aside>

			<main className="wpaim-main">
				{ messages.length === 0 && ! isLoading ? (
					<EmptyState />
				) : (
					<MessageList
						messages={ messages }
						isLoading={ isLoading }
					/>
				) }
				<Composer
					onSend={ sendMessage }
					isLoading={ isLoading }
					attachedPost={ attachedPost }
					onAttach={ setAttachedPost }
					onDetach={ () => setAttachedPost( null ) }
				/>
			</main>

			<aside className="wpaim-right-panel">
				<ModelSelector
					providers={ providers }
					selectedProvider={ selectedProvider }
					selectedModel={ selectedModel }
					onProviderChange={ setSelectedProvider }
					onModelChange={ setSelectedModel }
				/>
				<QuickActions onAction={ sendMessage } isPro={ isPro } />
			</aside>
		</div>
	);
}

function EmptyState() {
	return (
		<div className="wpaim-empty">
			<MessageSquare
				size={ 32 }
				strokeWidth={ 1 }
				className="wpaim-empty__icon"
			/>
			<p className="wpaim-empty__title">
				What would you like to work on?
			</p>
			<p className="wpaim-empty__subtitle">
				Ask anything, or choose a quick action on the right.
			</p>
		</div>
	);
}
