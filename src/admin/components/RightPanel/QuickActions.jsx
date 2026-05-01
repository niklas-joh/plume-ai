import { Button } from '@wordpress/components';
import { FREE_ACTIONS, PRO_ACTIONS } from '../Chat/actions';

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
						key={ action.id }
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
