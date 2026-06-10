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
		<div className="plume-usage-widget">
			<div className="plume-usage-widget__header">
				<span className="plume-dash-section-title">
					{ __( 'Usage', 'plume' ) }
				</span>
				<span className="plume-usage-widget__tier-badge">
					{
						/* translators: %s: the user's current subscription tier slug */
						sprintf( __( 'Tier: %s', 'plume' ), tier )
					}
				</span>
			</div>

			<div className="plume-usage-widget__value-row">
				<span
					className={ `plume-usage-widget__value${
						canUse
							? ''
							: ' plume-usage-widget__value--limit-reached'
					}` }
				>
					{ hasLimit ? usedPct + '%' : __( 'Unlimited', 'plume' ) }
				</span>
				{ hasLimit && (
					<span className="plume-usage-widget__sub-label">
						{ __( 'of quota used', 'plume' ) }
					</span>
				) }
			</div>

			{ hasLimit && (
				<div className="plume-usage-widget__token-count">
					{ sprintf(
						/* translators: 1: used tokens, 2: limit tokens */
						__( '%1$s / %2$s tokens', 'plume' ),
						used.toLocaleString(),
						limit.toLocaleString()
					) }
				</div>
			) }

			{ hasLimit && (
				<div className="plume-usage-widget__bar-track">
					<div
						className={ `plume-usage-widget__bar-fill${
							canUse
								? ''
								: ' plume-usage-widget__bar-fill--limit-reached'
						}` }
						style={ { width: `${ usedPct }%` } }
					/>
				</div>
			) }

			{ hasLimit && ! canUse && (
				<p className="plume-usage-widget__limit-message">
					{ __(
						'Monthly limit reached. Upgrade your plan to continue.',
						'plume'
					) }
				</p>
			) }
		</div>
	);
}
