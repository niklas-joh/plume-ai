import { useState, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Cpu, Copy, Check, RotateCcw } from 'lucide-react';
import MarkdownContent from '../../../shared/MarkdownContent';
import PlanCard from './PlanCard';
import OutOfCreditsNotice from '../../../shared/OutOfCreditsNotice';
import { DEFAULT_WEBSITE_URL } from '../../../shared/constants';
import { parseRevisionMessage } from './revisionMessage';

const TOOL_LABELS = {
	get_recent_posts: __( 'Fetched recent posts', 'plume' ),
	get_post_content: __( 'Read post content', 'plume' ),
	search_posts: __( 'Searched posts', 'plume' ),
	get_pages: __( 'Fetched pages', 'plume' ),
	get_site_info: __( 'Read site info', 'plume' ),
	generate_seo_meta: __( 'Generated SEO data', 'plume' ),
};

/**
 * Renders a single chat message bubble.
 *
 * AI messages are rendered as sanitised Markdown; user messages are plain text,
 * except revision requests from the review drawer, which show each comment
 * beneath the text it refers to.
 * Error messages receive an additional error modifier class. When an AI message
 * includes a pending plan, a PlanCard approval widget is rendered below the text.
 *
 * @param {Object}  props
 * @param {Object}  props.message              Message object from the conversation history.
 * @param {string}   props.message.role           Either 'user' or 'assistant'.
 * @param {string}   props.message.content        Message text (Markdown for AI, plain text for user).
 * @param {string}   [props.message.model]        Model slug displayed in the meta line for AI messages.
 * @param {number}   [props.message.credits] Credit cost displayed in the meta line for AI messages.
 * @param {boolean}  [props.message.isError]      When true, applies the error modifier class.
 * @param {boolean}  [props.message.outOfCredits] When true, renders the out-of-credits notice instead of the error text.
 * @param {Object}   [props.message.pending_plan] Pending plan data; when present, renders a PlanCard.
 * @param {string[]} [props.message.tools_used]   Tool names called; shown as passive indicator chips.
 * @param {string}   [props.message.applyEditUrl] When present, renders a "View post" link after applying an update.
 * @param {Function} [props.onRetry]             When given on an error message, renders a "Try again" button that calls it.
 * @param {boolean}  [props.isLoading]           Disables "Try again" while a reply is on its way.
 * @param {Function} [props.onPlanDismiss]       Called with the plan id after the user dismisses its card.
 * @return {ReactElement}
 */
export default function MessageBubble( {
	message,
	onRetry,
	isLoading = false,
	onPlanDismiss,
} ) {
	const isAI = message.role === 'assistant';
	const [ copied, setCopied ] = useState( false );
	const copiedTimerRef = useRef( null );
	useEffect( () => () => clearTimeout( copiedTimerRef.current ), [] );

	// navigator.clipboard only exists on secure origins; plain-HTTP admin sites
	// get no Copy button rather than one that throws.
	const canCopy =
		isAI &&
		! message.isError &&
		!! message.content &&
		typeof window.navigator?.clipboard?.writeText === 'function';

	async function copyReply() {
		try {
			// The raw Markdown, so pasting into the editor or a Markdown field
			// keeps headings, lists and emphasis.
			await window.navigator.clipboard.writeText( message.content );
			setCopied( true );
			clearTimeout( copiedTimerRef.current );
			copiedTimerRef.current = setTimeout(
				() => setCopied( false ),
				1500
			);
		} catch {
			// Permission denied or document not focused: nothing useful to show.
		}
	}
	const [ plan, setPlan ] = useState( message.pending_plan ?? null );

	// A plan can reach an already-rendered message: after a reload ChatApp
	// attaches the conversation's pending plan to its latest reply.
	const incomingPlanId = message.pending_plan?.id;
	useEffect( () => {
		if ( incomingPlanId ) {
			setPlan( message.pending_plan );
		}
		// Keyed on the id: a new object for the same plan must not undo a dismiss.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ incomingPlanId ] );

	function dismissPlan() {
		const planId = plan?.id;
		setPlan( null );
		if ( planId ) {
			onPlanDismiss?.( planId );
			// Best effort: without it the plan returns on reload until it expires.
			apiFetch( {
				path: `/plume/v1/plans/${ planId }`,
				method: 'DELETE',
			} ).catch( () => {} );
		}
	}
	const revision = isAI ? null : parseRevisionMessage( message.content );

	if ( message.outOfCredits ) {
		const { websiteUrl = DEFAULT_WEBSITE_URL, settingsUrl = '' } =
			window.plumeData ?? {};
		return (
			<div className="plume-bubble plume-bubble--ai">
				<OutOfCreditsNotice
					websiteUrl={ websiteUrl }
					settingsUrl={ settingsUrl }
				/>
			</div>
		);
	}

	return (
		<div
			className={ `plume-bubble plume-bubble--${ isAI ? 'ai' : 'user' }${
				message.isError ? ' plume-bubble--error' : ''
			}` }
		>
			<div className="plume-bubble__content">
				{ isAI ? (
					<MarkdownContent
						content={ message.content }
						className="plume-bubble__markdown"
					/>
				) : revision ? (
					<div className="plume-bubble__revision">
						<p className="plume-bubble__revision-title">
							{ __( 'Revision requested', 'plume' ) }
						</p>
						{ revision.comments.length > 0 && (
							<ul className="plume-bubble__revision-list">
								{ revision.comments.map( ( c ) => (
									<li
										key={ `${ c.anchor ?? '' }:${ c.text }` }
									>
										{ c.anchor && (
											<blockquote className="plume-bubble__revision-anchor">
												{ c.anchor }
											</blockquote>
										) }
										<span className="plume-bubble__revision-text">
											{ c.text }
										</span>
									</li>
								) ) }
							</ul>
						) }
						{ revision.note && (
							<p className="plume-bubble__revision-note">
								<strong>
									{ __( 'Overall note:', 'plume' ) }
								</strong>{ ' ' }
								{ revision.note }
							</p>
						) }
					</div>
				) : (
					<p>{ message.content }</p>
				) }
			</div>
			{ isAI && message.tools_used && message.tools_used.length > 0 && (
				<div className="plume-bubble__tools">
					{ message.tools_used.map( ( t ) => (
						<span key={ t } className="plume-tool-pill">
							{ TOOL_LABELS[ t ] ?? t }
						</span>
					) ) }
				</div>
			) }
			{ message.isError && onRetry && (
				<div className="plume-bubble__actions">
					<button
						type="button"
						className="plume-btn plume-btn--ghost plume-btn--sm"
						onClick={ onRetry }
						disabled={ isLoading }
					>
						<RotateCcw size={ 12 } strokeWidth={ 1.5 } />
						{ __( 'Try again', 'plume' ) }
					</button>
				</div>
			) }
			{ isAI && message.model && (
				<div className="plume-bubble__meta">
					<Cpu size={ 10 } strokeWidth={ 1.5 } />
					<span>{ message.model }</span>
					{ message.credits > 0 && (
						<span>
							{ message.credits } { __( 'credits', 'plume' ) }
						</span>
					) }
				</div>
			) }
			{ canCopy && (
				<div className="plume-bubble__actions">
					<button
						type="button"
						className="plume-btn plume-btn--ghost plume-btn--sm"
						onClick={ copyReply }
					>
						{ copied ? (
							<Check size={ 12 } strokeWidth={ 1.5 } />
						) : (
							<Copy size={ 12 } strokeWidth={ 1.5 } />
						) }
						<span>
							{ copied
								? __( 'Copied', 'plume' )
								: __( 'Copy', 'plume' ) }
						</span>
					</button>
				</div>
			) }
			{ isAI && plan && plan.plan_type !== 'update' && (
				<PlanCard plan={ plan } onDismiss={ dismissPlan } />
			) }
			{ isAI && message.applyEditUrl && (
				<div className="plume-bubble__apply-success">
					<a
						href={ message.applyEditUrl }
						className="button button-secondary button-small"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'View post', 'plume' ) }
					</a>
				</div>
			) }
		</div>
	);
}
