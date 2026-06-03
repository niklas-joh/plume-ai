import { __, sprintf } from '@wordpress/i18n';

/**
 * Compact usage widget embedded in the main Dashboard page.
 *
 * Displays a tier badge, a percentage-based quota indicator, and a progress
 * bar. Visible only when the `usage` prop is truthy (i.e. the current user
 * has the `manage_options` capability, as gated by PHP before localisation).
 *
 * @param {Object}       props
 * @param {Object|null}  props.usage  Usage summary from `NJ_Usage_Tracker::get_usage()`:
 *                                    `{ tier, used, limit, remaining, can_use }`.
 *                                    When null or falsy the component renders nothing.
 * @return {ReactElement|null}
 *
 * @example
 * <UsageWidget usage={ data.usage ?? null } />
 */
export default function UsageWidget( { usage } ) {
	if ( ! usage ) {
		return null;
	}

	const { tier, used, limit, can_use: canUse } = usage;

	const hasLimit = limit !== null && limit !== undefined;
	const usedPct = hasLimit
		? Math.min( 100, Math.round( ( used / limit ) * 100 ) )
		: 0;

	return (
		<div className="wpaim-usage-widget">
			<div className="wpaim-usage-widget__header">
				<span className="wpaim-dash-section-title">
					{ __( 'Usage', 'stilus' ) }
				</span>
				<span className="wpaim-usage-widget__tier-badge">
					{
						/* translators: %s: the user's current subscription tier slug */
						sprintf( __( 'Tier: %s', 'stilus' ), tier )
					}
				</span>
			</div>

			<div className="wpaim-usage-widget__value-row">
				<span
					className={ `wpaim-usage-widget__value${
						canUse
							? ''
							: ' wpaim-usage-widget__value--limit-reached'
					}` }
				>
					{ hasLimit
						? usedPct + '%'
						: __( 'Unlimited', 'stilus' ) }
				</span>
				{ hasLimit && (
					<span className="wpaim-usage-widget__sub-label">
						{ __( 'of quota used', 'stilus' ) }
					</span>
				) }
			</div>

			{ hasLimit && (
				<div className="wpaim-usage-widget__token-count">
					{ sprintf(
						/* translators: 1: used tokens, 2: limit tokens */
						__( '%1$s / %2$s tokens', 'stilus' ),
						used.toLocaleString(),
						limit.toLocaleString()
					) }
				</div>
			) }

			{ hasLimit && (
				<div className="wpaim-usage-widget__bar-track">
					<div
						className={ `wpaim-usage-widget__bar-fill${
							canUse
								? ''
								: ' wpaim-usage-widget__bar-fill--limit-reached'
						}` }
						style={ { width: `${ usedPct }%` } }
					/>
				</div>
			) }

			{ hasLimit && ! canUse && (
				<p className="wpaim-usage-widget__limit-message">
					{ __(
						'Monthly limit reached. Upgrade your plan to continue.',
						'stilus'
					) }
				</p>
			) }
		</div>
	);
}
