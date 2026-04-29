import { Lock } from 'lucide-react';
import { __ } from '@wordpress/i18n';

/**
 * Wraps a settings section with a locked overlay when the current plan does
 * not include the required feature.
 *
 * When `allowed` is true the children are rendered as-is with zero overhead.
 * When `allowed` is false the children are visually dimmed and an overlay
 * communicates the required plan and provides an upgrade link.
 *
 * @param {Object}       props
 * @param {boolean}      props.allowed      True if the feature is available on this plan.
 * @param {string}       props.requiredPlan Human-readable plan name (e.g. 'Pro BYOK').
 * @param {string}       props.upgradeUrl   URL of the upgrade page.
 * @param {ReactElement} props.children     Section content to gate.
 * @returns {ReactElement}
 *
 * @example
 * <PlanGate allowed={features.own_api_key} requiredPlan="Pro BYOK" upgradeUrl={upgradeUrl}>
 *   <ApiKeySection />
 * </PlanGate>
 */
export default function PlanGate( { allowed, requiredPlan, upgradeUrl, children } ) {
	if ( allowed ) {
		return children;
	}

	return (
		<div className="wpaim-plan-gate">
			<div className="wpaim-plan-gate__content" aria-hidden="true">
				{ children }
			</div>
			<div className="wpaim-plan-gate__overlay" role="status">
				<Lock size={ 18 } aria-hidden="true" />
				<span className="wpaim-pro-badge">{ requiredPlan }</span>
				<p>
					{ __(
						'This feature is not available on your current plan.',
						'wp-ai-mind'
					) }
				</p>
				<a href={ upgradeUrl } className="wpaim-btn wpaim-btn--primary">
					{ __( 'Upgrade →', 'wp-ai-mind' ) }
				</a>
			</div>
		</div>
	);
}
