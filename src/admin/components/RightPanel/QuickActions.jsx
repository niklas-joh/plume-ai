import { Button } from '@wordpress/components';
import { FilePenLine, Search, Image } from 'lucide-react';

const FREE_ACTIONS = [
	{
		label: 'Summarise this post',
		prompt: 'Please summarise the current post in 2-3 sentences.',
		icon: FilePenLine,
	},
	{
		label: 'Improve readability',
		prompt: 'Review this content and suggest readability improvements.',
		icon: FilePenLine,
	},
];

const PRO_ACTIONS = [
	{
		label: 'Write a post',
		prompt: 'Help me write a new blog post. What topic should we start with?',
		icon: FilePenLine,
	},
	{
		label: 'Generate SEO title',
		prompt: 'Generate an optimised SEO title for this post.',
		icon: Search,
	},
	{
		label: 'Write meta description',
		prompt: 'Write a compelling 155-character meta description for this post.',
		icon: Search,
	},
	{
		label: 'Create featured image',
		prompt: 'Generate a featured image for this post.',
		icon: Image,
	},
];

/**
 * Pre-defined prompt shortcuts displayed in the right panel.
 *
 * Free users see a limited set of actions; Pro users see additional prompts.
 * Clicking a button fires the prompt directly via onAction.
 *
 * @param {Object}   props
 * @param {Function} props.onAction Called with the prompt string when an action button is clicked.
 * @param {boolean}  props.isPro    When true, the full Pro action set is displayed.
 * @return {ReactElement}
 */
export default function QuickActions( { onAction, isPro } ) {
	const actions = isPro ? [ ...FREE_ACTIONS, ...PRO_ACTIONS ] : FREE_ACTIONS;

	return (
		<div className="wpaim-panel-section">
			<div className="wpaim-panel-label">Quick actions</div>
			<div className="wpaim-quick-actions">
				{ actions.map( ( action ) => (
					<Button
						key={ action.label }
						variant="tertiary"
						className="wpaim-quick-action"
						onClick={ () => onAction( action.prompt ) }
					>
						<action.icon size={ 12 } strokeWidth={ 1.5 } />
						<span>{ action.label }</span>
					</Button>
				) ) }
				{ ! isPro && (
					<div className="wpaim-pro-teaser">
						<span>More actions with Pro</span>
					</div>
				) }
			</div>
		</div>
	);
}
