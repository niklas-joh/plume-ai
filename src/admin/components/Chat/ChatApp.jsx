import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Plus, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import ConversationHistory from '../Sidebar/ConversationHistory';
import MessageList from './MessageList';
import Composer from './Composer';
import QuickActions from '../RightPanel/QuickActions';
import ModelSelector from '../RightPanel/ModelSelector';
import apiFetch from '@wordpress/api-fetch';
import { LAUNCH_ACTIONS } from './actions';

const NEW_CONVERSATION_TITLE = __( 'New conversation', 'wp-ai-mind' );

const LAUNCH_SUGGESTIONS = [
	{
		...LAUNCH_ACTIONS[ 0 ],
		label: __( 'Summarise this post', 'wp-ai-mind' ),
	},
	{
		...LAUNCH_ACTIONS[ 1 ],
		label: __( 'Improve readability', 'wp-ai-mind' ),
	},
	{ ...LAUNCH_ACTIONS[ 2 ], label: __( 'Write a post', 'wp-ai-mind' ) },
];

/**
 * Root chat application: conversation list, message thread, and composer.
 *
 * Manages the full chat session including conversation CRUD, provider/model
 * selection, and the context-post attachment flow.
 *
 * @return {ReactElement}
 */
export default function ChatApp() {
	const { isPro } = window.wpAiMindData || {};

	const [ conversations, setConversations ] = useState( [] );
	const [ activeConvId, setActiveConvId ] = useState( null );
	const [ messages, setMessages ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ selectedProvider, setSelectedProvider ] = useState( '' );
	const [ selectedModel, setSelectedModel ] = useState( '' );
	const [ providers, setProviders ] = useState( [] );
	const [ isSidebarCollapsed, setIsSidebarCollapsed ] = useState(
		() => window.localStorage.getItem( 'wpaim-sidebar-collapsed' ) === '1'
	);
	const [ attachedPost, setAttachedPost ] = useState( null );
	const [ pendingQuickAction, setPendingQuickAction ] = useState( null );
	const [ forcePickerOpen, setForcePickerOpen ] = useState( false );
	const [ deletingIds, setDeletingIds ] = useState( new Set() );
	const [ deleteErrors, setDeleteErrors ] = useState( {} );
	const skipLoadRef = useRef( false );
	// Tracks which conversation IDs have already had a title PATCH dispatched,
	// preventing a second send if the user types quickly before state settles.
	const titlePatchedConvsRef = useRef( new Set() );

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
				const storedDefault = window.wpAiMindData?.defaultProvider;
				const match = data.find( ( p ) => p.slug === storedDefault );
				setSelectedProvider( ( match ?? data[ 0 ] ).slug );
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
			data: { title: NEW_CONVERSATION_TITLE },
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
		setDeleteErrors( ( prev ) => {
			const next = { ...prev };
			delete next[ convId ];
			return next;
		} );
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
				setDeleteErrors( ( prev ) => ( {
					...prev,
					[ convId ]: __(
						'Failed to delete. Please try again.',
						'wp-ai-mind'
					),
				} ) );
			}
		} finally {
			setDeletingIds( ( prev ) => {
				const next = new Set( prev );
				next.delete( convId );
				return next;
			} );
		}
	}

	async function sendMessage( content, contextPostId = null ) {
		// Resolve conversation ID — create one if none active.
		let convId = activeConvId;
		// Track whether an inline conversation was just created so needsTitleUpdate is set correctly.
		let inlineCreated = false;

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
			inlineCreated = true;
		}

		// Capture whether this conversation still needs a title update.
		// Inline-created conversations always start with NEW_CONVERSATION_TITLE and need
		// a PATCH after the assistant replies. The ref guard prevents a second PATCH if
		// the user sends again before state settles.
		const needsTitleUpdate =
			! titlePatchedConvsRef.current.has( convId ) &&
			( inlineCreated ||
				conversations.find( ( c ) => c.id === convId )?.title ===
					NEW_CONVERSATION_TITLE );

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
					context_post_id: contextPostId ?? attachedPost?.id ?? 0,
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
			if ( needsTitleUpdate ) {
				const rawTitle = content.slice( 0, 60 );
				// Avoid cutting mid-word; fall back to hard slice if no word boundary found.
				const newTitle = rawTitle.replace( /\s+\S*$/, '' ) || rawTitle;
				if ( newTitle.trim() ) {
					apiFetch( {
						path: `/wp-ai-mind/v1/conversations/${ convId }`,
						method: 'PATCH',
						data: { title: newTitle },
					} )
						.then( () => {
							titlePatchedConvsRef.current.add( convId );
							setConversations( ( prev ) =>
								prev.map( ( c ) =>
									c.id === convId
										? { ...c, title: newTitle }
										: c
								)
							);
						} )
						.catch( ( err ) =>
							// eslint-disable-next-line no-console
							console.warn(
								'[wp-ai-mind] title update failed',
								err
							)
						);
				}
			}
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

	function handleAttach( post ) {
		setAttachedPost( post );
		setForcePickerOpen( false );
		if ( pendingQuickAction ) {
			const toSend = pendingQuickAction;
			setPendingQuickAction( null );
			sendMessage( toSend, post.id );
		}
	}

	function guardedQuickAction( prompt ) {
		setPendingQuickAction( prompt );
		setForcePickerOpen( true );
	}

	// Called by suggestion chips (prompt, requiresPost).
	function handleQuickAction( prompt, requiresPost = false ) {
		if ( requiresPost && ! attachedPost ) {
			guardedQuickAction( prompt );
			return;
		}
		sendMessage( prompt );
	}

	// Called by QuickActions when a post-required action fires but no post is attached.
	function requestPostAttach( prompt ) {
		guardedQuickAction( prompt );
	}

	function handlePickerClose() {
		setForcePickerOpen( false );
		setPendingQuickAction( null );
	}

	const toggleLabel = isSidebarCollapsed
		? __( 'Expand sidebar', 'wp-ai-mind' )
		: __( 'Collapse sidebar', 'wp-ai-mind' );

	return (
		<div
			className={ `wpaim-shell${
				isSidebarCollapsed ? ' wpaim-shell--sidebar-collapsed' : ''
			}` }
		>
			<aside className="wpaim-sidebar">
				<div className="wpaim-sidebar__header">
					{ ! isSidebarCollapsed && (
						<span className="wpaim-sidebar__title">
							{ __( 'Conversations', 'wp-ai-mind' ) }
						</span>
					) }
					{ ! isSidebarCollapsed && (
						<button
							className="wpaim-btn wpaim-btn--ghost wpaim-btn--icon"
							onClick={ newConversation }
							title={ NEW_CONVERSATION_TITLE }
							aria-label={ NEW_CONVERSATION_TITLE }
							type="button"
						>
							<Plus size={ 14 } strokeWidth={ 1.5 } />
						</button>
					) }
					<button
						className="wpaim-btn wpaim-btn--ghost wpaim-btn--icon wpaim-sidebar__toggle"
						onClick={ () =>
							setIsSidebarCollapsed( ( prev ) => {
								const next = ! prev;
								window.localStorage.setItem(
									'wpaim-sidebar-collapsed',
									next ? '1' : '0'
								);
								return next;
							} )
						}
						title={ toggleLabel }
						aria-label={ toggleLabel }
						aria-expanded={ ! isSidebarCollapsed }
						type="button"
					>
						{ isSidebarCollapsed ? (
							<PanelLeftOpen size={ 14 } strokeWidth={ 1.5 } />
						) : (
							<PanelLeftClose size={ 14 } strokeWidth={ 1.5 } />
						) }
					</button>
				</div>
				<ConversationHistory
					conversations={ conversations }
					activeId={ activeConvId }
					onSelect={ setActiveConvId }
					onDelete={ deleteConversation }
					deletingIds={ deletingIds }
					deleteErrors={ deleteErrors }
				/>
			</aside>

			<main className="wpaim-main">
				{ messages.length === 0 && ! isLoading ? (
					<CenteredLaunch
						suggestions={ LAUNCH_SUGGESTIONS }
						onSend={ handleQuickAction }
						isLoading={ isLoading }
						attachedPost={ attachedPost }
						onAttach={ handleAttach }
						onDetach={ () => setAttachedPost( null ) }
						forcePickerOpen={ forcePickerOpen }
						onPickerClose={ handlePickerClose }
					/>
				) : (
					<>
						<MessageList
							messages={ messages }
							isLoading={ isLoading }
						/>
						<Composer
							onSend={ sendMessage }
							isLoading={ isLoading }
							attachedPost={ attachedPost }
							onAttach={ handleAttach }
							onDetach={ () => setAttachedPost( null ) }
							forcePickerOpen={ forcePickerOpen }
							onPickerClose={ handlePickerClose }
						/>
					</>
				) }
			</main>

			<aside className="wpaim-right-panel">
				<ModelSelector
					providers={ providers }
					selectedProvider={ selectedProvider }
					selectedModel={ selectedModel }
					onProviderChange={ setSelectedProvider }
					onModelChange={ setSelectedModel }
					isPro={ isPro }
				/>
				<QuickActions
					onAction={ sendMessage }
					isPro={ isPro }
					attachedPost={ attachedPost }
					onRequestAttach={ requestPostAttach }
				/>
			</aside>
		</div>
	);
}

/**
 * Centred launch view shown before any message has been sent.
 *
 * Vertically centres a heading, three suggestion chips, and the full composer
 * in the main column. Clicking a chip auto-submits that prompt.
 *
 * @param {Object}      props
 * @param {Array}       props.suggestions      Array of {label, prompt, requiresPost} objects.
 * @param {Function}    props.onSend           Forwarded to chip clicks: called with (prompt, requiresPost).
 * @param {boolean}     props.isLoading        Forwarded to Composer.
 * @param {Object|null} props.attachedPost     Forwarded to Composer.
 * @param {Function}    props.onAttach         Forwarded to Composer.
 * @param {Function}    props.onDetach         Forwarded to Composer.
 * @param {boolean}     props.forcePickerOpen  Forwarded to Composer to open the context picker externally.
 * @param {Function}    props.onPickerClose    Forwarded to Composer; called when the picker is dismissed.
 * @return {ReactElement}
 */
function CenteredLaunch( {
	suggestions,
	onSend,
	isLoading,
	attachedPost,
	onAttach,
	onDetach,
	forcePickerOpen,
	onPickerClose,
} ) {
	return (
		<div className="wpaim-launch">
			<div className="wpaim-launch__inner">
				<p className="wpaim-launch__title">
					{ __( 'How can I help you today?', 'wp-ai-mind' ) }
				</p>
				<div className="wpaim-launch__suggestions">
					{ suggestions.map( ( s ) => (
						<button
							key={ s.id }
							className="wpaim-suggestion-chip"
							type="button"
							onClick={ () => onSend( s.prompt, s.requiresPost ) }
						>
							{ s.label }
						</button>
					) ) }
				</div>
				<Composer
					onSend={ onSend }
					isLoading={ isLoading }
					attachedPost={ attachedPost }
					onAttach={ onAttach }
					onDetach={ onDetach }
					forcePickerOpen={ forcePickerOpen }
					onPickerClose={ onPickerClose }
					borderless
				/>
			</div>
		</div>
	);
}
