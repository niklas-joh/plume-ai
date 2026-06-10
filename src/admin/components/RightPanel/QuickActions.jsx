import { Button } from '@wordpress/components';
import { FREE_ACTIONS, PRO_ACTIONS } from '../Chat/actions';

/**
 * Pre-defined prompt shortcuts displayed in the right panel.
 *
 * Free users see a limited set of actions; Pro users see additional prompts.
 * Actions with requiresPost=true call onRequestAttach instead of onAction when
 * no post is currently attached, so the user is prompted to select one first.
 *
 * @param {Object}      props
 * @param {Function}    props.onAction        Called with (prompt) when an action fires without a post requirement.
 * @param {boolean}     props.isPro           When true, the full Pro action set is displayed.
 * @param {Object|null} props.attachedPost    Currently attached post, or null.
 * @param {Function}    props.onRequestAttach Called with the pending prompt when a post is required but missing.
 * @return {ReactElement}
 */
export default function QuickActions( {
	onAction,
	isPro,
	attachedPost,
	onRequestAttach,
} ) {
	const actions = isPro ? [ ...FREE_ACTIONS, ...PRO_ACTIONS ] : FREE_ACTIONS;

	function handleClick( action ) {
		if ( action.requiresPost && ! attachedPost ) {
			onRequestAttach( action.prompt );
		} else {
			onAction( action.prompt );
		}
	}

	return (
		<div className="plume-panel-section">
			<div className="plume-panel-label">Quick actions</div>
			<div className="plume-quick-actions">
				{ actions.map( ( action ) => (
					<Button
						key={ action.id }
						variant="tertiary"
						className="plume-quick-action"
						onClick={ () => handleClick( action ) }
					>
						<action.icon size={ 12 } strokeWidth={ 1.5 } />
						<span>{ action.label }</span>
					</Button>
				) ) }
				{ ! isPro && (
					<div className="plume-pro-teaser">
						<span>More actions with Pro</span>
					</div>
				) }
			</div>
		</div>
	);
}
