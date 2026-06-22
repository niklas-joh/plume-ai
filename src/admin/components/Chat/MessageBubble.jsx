import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Cpu } from 'lucide-react';
import MarkdownContent from '../../../shared/MarkdownContent';
import PlanCard from './PlanCard';

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
 * AI messages are rendered as sanitised Markdown; user messages are plain text.
 * Error messages receive an additional error modifier class. When an AI message
 * includes a pending plan, a PlanCard approval widget is rendered below the text.
 *
 * @param {Object}  props
 * @param {Object}  props.message              Message object from the conversation history.
 * @param {string}   props.message.role           Either 'user' or 'assistant'.
 * @param {string}   props.message.content        Message text (Markdown for AI, plain text for user).
 * @param {string}   [props.message.model]        Model slug displayed in the meta line for AI messages.
 * @param {number}   [props.message.tokens]       Token count displayed in the meta line for AI messages.
 * @param {boolean}  [props.message.isError]      When true, applies the error modifier class.
 * @param {Object}   [props.message.pending_plan] Pending plan data; when present, renders a PlanCard.
 * @param {string[]} [props.message.tools_used]   Tool names called; shown as passive indicator chips.
 * @return {ReactElement}
 */
export default function MessageBubble( { message } ) {
	const isAI = message.role === 'assistant';
	const [ plan, setPlan ] = useState( message.pending_plan ?? null );

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
			{ isAI && message.model && (
				<div className="plume-bubble__meta">
					<Cpu size={ 10 } strokeWidth={ 1.5 } />
					<span>{ message.model }</span>
					{ message.tokens && <span>{ message.tokens } tokens</span> }
				</div>
			) }
			{ isAI && plan && plan.plan_type !== 'update' && (
				<PlanCard plan={ plan } onDismiss={ () => setPlan( null ) } />
			) }
		</div>
	);
}
