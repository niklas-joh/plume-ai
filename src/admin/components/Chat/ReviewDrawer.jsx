import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	X,
	GripVertical,
	Loader2,
	MessageSquare,
	ChevronDown,
	ChevronUp,
} from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import { computeDiff } from '../../utils/computeDiff';
import { storageGet, storageSet } from '../../utils/storage';
import DiffView from './DiffView';

const DRAWER_WIDTH_KEY = 'plume_drawer_width';
const MIN_WIDTH = 280;
const MAX_WIDTH = 560;
const DEFAULT_WIDTH = 360;

/**
 * Slide-in review drawer for AI-proposed post update plans.
 *
 * Shows a paragraph-level diff of the current post content vs the proposed
 * content, lets the user annotate specific passages, and supports requesting
 * a revision round-trip through the existing chat messages endpoint.
 *
 * State machine: reviewing -> commenting -> loading -> revised
 *
 * @param {Object}   props
 * @param {Object}   props.plan               Pending plan object (`plan_type === 'update'`).
 * @param {string}   props.plan.id            Plan ID used for the execute endpoint.
 * @param {number}   props.plan.post_id       Post being updated; content is fetched on mount.
 * @param {string}   props.plan.new_content   AI-proposed content string.
 * @param {number}   props.convId             Active conversation ID for the revision request.
 * @param {string}   props.selectedProvider   Provider slug the user selected in chat; forwarded so the revision uses the same model.
 * @param {string}   props.selectedModel      Model slug the user selected in chat; forwarded with the revision request.
 * @param {Function} props.onApply            Called after a successful plan execution.
 * @param {Function} props.onClose            Called when the drawer is dismissed.
 * @param {Function} props.onMessagesRefresh  Called after a revision round-trip so ChatApp reloads history.
 * @return {ReactElement}
 */
export default function ReviewDrawer( {
	plan,
	convId,
	selectedProvider,
	selectedModel,
	onApply,
	onClose,
	onMessagesRefresh,
} ) {
	const [ drawerState, setDrawerState ] = useState( 'reviewing' );
	const [ currentPlan, setCurrentPlan ] = useState( plan );
	const [ postContent, setPostContent ] = useState( null );
	const [ diffBlocks, setDiffBlocks ] = useState( [] );
	const [ comments, setComments ] = useState( [] );
	const [ generalNote, setGeneralNote ] = useState( '' );
	const [ unsavedBlockIds, setUnsavedBlockIds ] = useState( new Set() );
	const [ aiSummary, setAiSummary ] = useState( '' );
	const [ revision, setRevision ] = useState( 0 );
	const [ error, setError ] = useState( null );
	const [ aiResponseOpen, setAiResponseOpen ] = useState( true );
	const [ drawerWidth, setDrawerWidth ] = useState(
		() => parseInt( storageGet( DRAWER_WIDTH_KEY ), 10 ) || DEFAULT_WIDTH
	);

	const closeButtonRef = useRef( null );
	const drawerRef = useRef( null );
	const resizingRef = useRef( false );
	const resizeStartXRef = useRef( 0 );
	const resizeStartWidthRef = useRef( 0 );
	const commentIdRef = useRef( 0 );

	// Fetch current post content on mount — post_id is stable for the drawer lifetime.
	useEffect( () => {
		apiFetch( {
			path: `/wp/v2/posts/${ currentPlan.post_id }?context=edit`,
		} )
			.then( ( post ) => {
				setPostContent(
					post.content.raw ?? post.content.rendered ?? ''
				);
			} )
			.catch( () => {
				// If the post fetch fails, diff against empty string — drawer still usable.
				setPostContent( '' );
			} );
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Recompute diff whenever source content or plan content changes.
	useEffect( () => {
		if ( postContent !== null ) {
			setDiffBlocks(
				computeDiff( postContent, currentPlan.new_content ?? '' )
			);
		}
	}, [ postContent, currentPlan ] );

	// Focus close button on mount for accessibility.
	useEffect( () => {
		closeButtonRef.current?.focus();
	}, [] );

	// Transition state based on comments and feedback.
	// drawerState is intentionally excluded — we only want to react to content changes.
	useEffect( () => {
		if ( drawerState === 'loading' || drawerState === 'revised' ) {
			return;
		}
		const hasSavedComments = comments.some( ( c ) => c.saved );
		const hasFeedback = generalNote.trim().length > 0;
		if ( hasSavedComments || hasFeedback ) {
			setDrawerState( 'commenting' );
		} else {
			setDrawerState( 'reviewing' );
		}
	}, [ comments, generalNote ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// -----------------------------------------------------------------------
	// Resize handle
	// -----------------------------------------------------------------------

	function handleResizeMouseDown( e ) {
		e.preventDefault();
		resizingRef.current = true;
		resizeStartXRef.current = e.clientX;
		resizeStartWidthRef.current = drawerWidth;

		function onMouseMove( moveEvent ) {
			if ( ! resizingRef.current ) {
				return;
			}
			const delta = resizeStartXRef.current - moveEvent.clientX;
			const newWidth = Math.min(
				MAX_WIDTH,
				Math.max( MIN_WIDTH, resizeStartWidthRef.current + delta )
			);
			setDrawerWidth( newWidth );
		}

		function onMouseUp() {
			resizingRef.current = false;
			document.removeEventListener( 'mousemove', onMouseMove );
			document.removeEventListener( 'mouseup', onMouseUp );
			setDrawerWidth( ( w ) => {
				storageSet( DRAWER_WIDTH_KEY, String( w ) );
				return w;
			} );
		}

		document.addEventListener( 'mousemove', onMouseMove );
		document.addEventListener( 'mouseup', onMouseUp );
	}

	function handleResizeKeyDown( e ) {
		if ( e.key === 'ArrowLeft' ) {
			const next = Math.min( MAX_WIDTH, drawerWidth + 20 );
			setDrawerWidth( next );
			storageSet( DRAWER_WIDTH_KEY, String( next ) );
		}
		if ( e.key === 'ArrowRight' ) {
			const next = Math.max( MIN_WIDTH, drawerWidth - 20 );
			setDrawerWidth( next );
			storageSet( DRAWER_WIDTH_KEY, String( next ) );
		}
	}

	// -----------------------------------------------------------------------
	// Comment management
	// -----------------------------------------------------------------------

	const handleAddComment = useCallback( () => {
		// DiffView calls this after a selection — CommentThread handles the UI.
		// Ensure the state transition happens so the footer updates.
		setDrawerState( ( prev ) =>
			prev === 'reviewing' ? 'commenting' : prev
		);
	}, [] );

	const handleSaveComment = useCallback(
		( diffBlockId, selectedText, text ) => {
			setComments( ( prev ) => [
				...prev,
				{
					id: `c-${ ++commentIdRef.current }`,
					diffBlockId,
					selectedText,
					text,
					saved: true,
				},
			] );
		},
		[]
	);

	const handleDeleteComment = useCallback( ( commentId ) => {
		setComments( ( prev ) => prev.filter( ( c ) => c.id !== commentId ) );
	}, [] );

	const handleUnsavedChange = useCallback( ( diffBlockId, hasDirty ) => {
		setUnsavedBlockIds( ( prev ) => {
			const next = new Set( prev );
			if ( hasDirty ) {
				next.add( diffBlockId );
			} else {
				next.delete( diffBlockId );
			}
			return next;
		} );
	}, [] );

	// -----------------------------------------------------------------------
	// Cancel logic
	// -----------------------------------------------------------------------

	function handleClose() {
		const hasSavedComments = comments.some( ( c ) => c.saved );
		const isDirty = hasSavedComments || generalNote.trim().length > 0;
		if ( isDirty ) {
			// Native confirm is intentional for this destructive discard guard;
			// the drawer has no modal layer of its own.
			if (
				// eslint-disable-next-line no-alert
				! window.confirm(
					__( 'Discard your comments and cancel?', 'plume' )
				)
			) {
				return;
			}
		}
		onClose();
	}

	// -----------------------------------------------------------------------
	// Apply logic
	// -----------------------------------------------------------------------

	async function handleApply() {
		setError( null );
		try {
			await apiFetch( {
				path: `/plume/v1/plans/${ currentPlan.id }/execute`,
				method: 'POST',
			} );
			onApply();
			onClose();
		} catch ( err ) {
			setError(
				err?.message ??
					__( 'Something went wrong. Please try again.', 'plume' )
			);
		}
	}

	// -----------------------------------------------------------------------
	// Request revision
	// -----------------------------------------------------------------------

	async function handleRequestRevision() {
		if ( unsavedBlockIds.size > 0 ) {
			// CommentThread handles the inline warning via its own state.
			return;
		}

		const savedComments = comments.filter( ( c ) => c.saved );
		if ( savedComments.length === 0 && ! generalNote.trim() ) {
			return;
		}

		setDrawerState( 'loading' );
		setError( null );

		const parts = [];
		if ( savedComments.length > 0 ) {
			const commentList = savedComments
				.map( ( c ) =>
					c.selectedText
						? `- On "${ c.selectedText }": ${ c.text }`
						: `- ${ c.text }`
				)
				.join( '\n' );
			parts.push( `Inline feedback:\n${ commentList }` );
		}
		if ( generalNote.trim() ) {
			parts.push( `Overall: ${ generalNote.trim() }` );
		}

		const message = `Please revise your proposed changes.\n\n${ parts.join(
			'\n\n'
		) }`;

		try {
			const res = await apiFetch( {
				path: `/plume/v1/conversations/${ convId }/messages`,
				method: 'POST',
				data: {
					content: message,
					provider: selectedProvider,
					model: selectedModel,
					context_post_id: currentPlan.post_id ?? 0,
				},
			} );

			if ( ! res.pending_plan ) {
				throw new Error(
					__(
						'No revised plan was returned. Please try again.',
						'plume'
					)
				);
			}

			setCurrentPlan( res.pending_plan );
			setComments( [] );
			setGeneralNote( '' );
			setUnsavedBlockIds( new Set() );
			setAiSummary( res.content ?? '' );
			setRevision( ( r ) => r + 1 );
			setAiResponseOpen( true );
			setDrawerState( 'revised' );
			onMessagesRefresh();
		} catch ( err ) {
			setError(
				err?.message ??
					__(
						'Something went wrong. Your comments are saved — try again.',
						'plume'
					)
			);
			setDrawerState( 'commenting' );
		}
	}

	// -----------------------------------------------------------------------
	// Derived values
	// -----------------------------------------------------------------------

	const changeCount = diffBlocks.filter(
		( b ) => b.removedText || b.addedText
	).length;
	const savedCommentCount = comments.filter( ( c ) => c.saved ).length;
	const canRevise =
		( savedCommentCount > 0 || generalNote.trim().length > 0 ) &&
		unsavedBlockIds.size === 0;

	const reviewingSubtitle = sprintf(
		/* translators: %d: number of proposed changes */
		_n(
			'%d change — drag to select text and comment',
			'%d changes — drag to select text and comment',
			changeCount,
			'plume'
		),
		changeCount
	);

	const commentingSubtitle = sprintf(
		/* translators: %d: number of saved comments */
		_n(
			'%d comment — not yet submitted',
			'%d comments — not yet submitted',
			savedCommentCount,
			'plume'
		),
		savedCommentCount
	);

	const revisedSubtitle = sprintf(
		/* translators: 1: comment count phrase, 2: change count phrase */
		__( 'AI addressed your %1$s — %2$s', 'plume' ),
		sprintf(
			/* translators: %d: number of comments */
			_n( '%d comment', '%d comments', savedCommentCount, 'plume' ),
			savedCommentCount
		),
		sprintf(
			/* translators: %d: number of changes */
			_n( '%d change', '%d changes', changeCount, 'plume' ),
			changeCount
		)
	);

	const footerCommentCount = sprintf(
		/* translators: %d: number of saved comments */
		_n( '%d comment', '%d comments', savedCommentCount, 'plume' ),
		savedCommentCount
	);

	// -----------------------------------------------------------------------
	// Render
	// -----------------------------------------------------------------------

	return (
		<div
			ref={ drawerRef }
			className="plume-review-drawer"
			style={ { width: drawerWidth } }
			role="complementary"
			aria-label={ __( 'Review Update Drawer', 'plume' ) }
		>
			{ /* Resize handle — focusable separator follows the WAI-ARIA window
			   splitter pattern, which is keyboard-operable via onKeyDown. */ }
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="plume-review-drawer__resize"
				onMouseDown={ handleResizeMouseDown }
				onKeyDown={ handleResizeKeyDown }
				role="separator"
				aria-orientation="vertical"
				aria-valuenow={ drawerWidth }
				aria-valuemin={ MIN_WIDTH }
				aria-valuemax={ MAX_WIDTH }
				tabIndex={ 0 }
				title={ __( 'Drag to resize', 'plume' ) }
			>
				<GripVertical size={ 12 } />
			</div>

			{ /* Header */ }
			<div
				className={ `plume-review-drawer__header${
					drawerState === 'revised'
						? ' plume-review-drawer__header--revised'
						: ''
				}` }
			>
				<div className="plume-review-drawer__header-text">
					<p className="plume-review-drawer__title">
						{ __( 'Review Update', 'plume' ) }
						{ drawerState === 'revised' && (
							<span className="plume-review-drawer__revised-badge">
								{ __( 'Revised', 'plume' ) }
							</span>
						) }
					</p>
					{ drawerState === 'reviewing' && (
						<p className="plume-review-drawer__subtitle">
							{ reviewingSubtitle }
						</p>
					) }
					{ drawerState === 'commenting' && (
						<p className="plume-review-drawer__subtitle plume-review-drawer__subtitle--warning">
							{ commentingSubtitle }
						</p>
					) }
					{ drawerState === 'revised' && (
						<p className="plume-review-drawer__subtitle plume-review-drawer__subtitle--revised">
							{ revisedSubtitle }
						</p>
					) }
				</div>
				<button
					ref={ closeButtonRef }
					type="button"
					className="plume-review-drawer__close"
					onClick={ handleClose }
					aria-label={ __( 'Close drawer', 'plume' ) }
				>
					<X size={ 14 } />
				</button>
			</div>

			{ /* Error banner */ }
			{ error && (
				<div className="plume-review-drawer__error">
					<span>{ error }</span>
					{ drawerState === 'commenting' && (
						<button
							type="button"
							className="plume-btn plume-btn--ghost plume-btn--xs"
							onClick={ () => setError( null ) }
						>
							{ __( 'Dismiss', 'plume' ) }
						</button>
					) }
				</div>
			) }

			{ /* AI response strip (State 3) */ }
			{ drawerState === 'revised' && aiSummary && (
				<div
					className={ `plume-review-drawer__ai-strip${
						aiResponseOpen
							? ''
							: ' plume-review-drawer__ai-strip--collapsed'
					}` }
				>
					<div
						className="plume-review-drawer__ai-strip-header"
						onClick={ () => setAiResponseOpen( ( v ) => ! v ) }
						role="button"
						tabIndex={ 0 }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								setAiResponseOpen( ( v ) => ! v );
							}
						} }
						aria-expanded={ aiResponseOpen }
					>
						<MessageSquare size={ 12 } />
						<span style={ { flex: 1 } }>
							{ __( 'AI response', 'plume' ) }
						</span>
						{ aiResponseOpen ? (
							<ChevronUp size={ 12 } />
						) : (
							<ChevronDown size={ 12 } />
						) }
					</div>
					{ aiResponseOpen && (
						<div className="plume-review-drawer__ai-strip-body">
							{ aiSummary }
						</div>
					) }
				</div>
			) }

			{ /* Loading interstitial */ }
			{ drawerState === 'loading' ? (
				<div
					className="plume-review-drawer__loading"
					role="status"
					aria-live="polite"
				>
					<Loader2 size={ 24 } className="plume-spin" />
					<span>{ __( 'AI is revising…', 'plume' ) }</span>
				</div>
			) : (
				<>
					{ /* Scrollable diff body */ }
					<div className="plume-review-drawer__body">
						{ postContent === null ? (
							<div
								className="plume-review-drawer__loading"
								role="status"
								aria-live="polite"
							>
								<Loader2 size={ 20 } className="plume-spin" />
							</div>
						) : (
							<DiffView
								blocks={ diffBlocks }
								comments={ comments }
								onAddComment={ handleAddComment }
								onSaveComment={ handleSaveComment }
								onDeleteComment={ handleDeleteComment }
								onUnsavedChange={ handleUnsavedChange }
								drawerState={ drawerState }
							/>
						) }
					</div>

					{ /* General feedback */ }
					{ ( drawerState === 'commenting' ||
						drawerState === 'revised' ) && (
						<div className="plume-review-drawer__feedback">
							<label htmlFor={ `plume-feedback-${ revision }` }>
								{ __( 'Overall feedback', 'plume' ) }
							</label>
							<textarea
								id={ `plume-feedback-${ revision }` }
								value={ generalNote }
								onChange={ ( e ) =>
									setGeneralNote( e.target.value )
								}
								placeholder={ __(
									'Any overall notes for the AI…',
									'plume'
								) }
							/>
						</div>
					) }

					{ /* Footer */ }
					<div className="plume-review-drawer__footer">
						{ ( drawerState === 'reviewing' ||
							drawerState === 'commenting' ) && (
							<>
								{ drawerState === 'commenting' ? (
									<button
										type="button"
										className="plume-btn plume-btn--primary"
										onClick={ handleRequestRevision }
										disabled={ ! canRevise }
									>
										{ __( 'Request revision', 'plume' ) }
									</button>
								) : (
									<button
										type="button"
										className="plume-btn plume-btn--primary"
										onClick={ handleApply }
									>
										{ __( 'Apply update', 'plume' ) }
									</button>
								) }
								<button
									type="button"
									className="plume-btn plume-btn--ghost"
									onClick={ handleClose }
								>
									{ __( 'Cancel', 'plume' ) }
								</button>
								{ drawerState === 'commenting' && (
									<span className="plume-review-drawer__footer-count">
										{ footerCommentCount }
									</span>
								) }
							</>
						) }

						{ drawerState === 'revised' && (
							<>
								<button
									type="button"
									className="plume-btn plume-btn--success"
									onClick={ handleApply }
								>
									{ __( 'Apply update', 'plume' ) }
								</button>
								<button
									type="button"
									className="plume-btn plume-btn--ghost"
									onClick={ () =>
										setDrawerState( 'commenting' )
									}
								>
									{ __( 'Comment again', 'plume' ) }
								</button>
								<button
									type="button"
									className="plume-btn plume-btn--ghost"
									onClick={ handleClose }
								>
									{ __( 'Cancel', 'plume' ) }
								</button>
							</>
						) }
					</div>
				</>
			) }
		</div>
	);
}
