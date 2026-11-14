import { CircleSlash } from 'lucide-react';
import { __ } from '@wordpress/i18n';
import './out-of-credits.css';

/**
 * Banner shown when a Worker request fails with a credit-exhaustion error.
 *
 * Says "Get more credits" rather than "Upgrade to Pro" because BYOK sites
 * structurally never reach this state (they call their own API key
 * directly, bypassing the Worker's credit ledger entirely) — both Free and
 * Pro Managed tiers have a real monthly ceiling and can land here, so the
 * copy and CTA must make sense for an already-paying Pro Managed user too,
 * not just imply "upgrade from Free."
 *
 * The own-key link is the way forward that costs nothing, so it is offered
 * whenever the viewer can actually open Settings. `settingsUrl` is empty for
 * users without `manage_options`, and the link is then left out rather than
 * sending them to a "not allowed" page.
 *
 * @param {Object} props
 * @param {string} props.websiteUrl    Base marketing-site URL (no trailing slash) used to build the pricing link.
 * @param {string} [props.settingsUrl] Plume settings URL; when non-empty, adds the "use your own API key" link.
 * @return {ReactElement}
 *
 * @example
 * <OutOfCreditsNotice
 *     websiteUrl="https://wpaimind.com"
 *     settingsUrl={ window.plumeData?.settingsUrl }
 * />
 */
export default function OutOfCreditsNotice( { websiteUrl, settingsUrl = '' } ) {
	return (
		<div className="plume-out-of-credits-banner">
			<CircleSlash size={ 18 } />
			<span className="plume-out-of-credits-banner__text">
				{ __(
					'You’ve used all your credits for this billing period.',
					'plume'
				) }
			</span>
			<span className="plume-out-of-credits-banner__actions">
				{ settingsUrl && (
					<a href={ settingsUrl }>
						{ __( 'Use your own API key (free)', 'plume' ) }
					</a>
				) }
				<a
					href={ `${ websiteUrl }/pricing` }
					className="button button-primary"
				>
					{ __( 'Get more credits →', 'plume' ) }
				</a>
			</span>
		</div>
	);
}
