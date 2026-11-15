import { __ } from '@wordpress/i18n';

/**
 * Footer navigation bar at the bottom of the dashboard page.
 *
 * @param {Object} props
 * @param {Object} props.urls         URL map with at least a `settings` key.
 * @param {string} props.runSetupUrl  URL that re-triggers the onboarding wizard.
 * @return {ReactElement}
 */
export default function PageFooter( { urls, runSetupUrl } ) {
	return (
		<div className="plume-dash-footer">
			<a href={ urls.settings } className="plume-dash-footer__link">
				{ __( 'Settings', 'plume' ) }
			</a>
			<div className="plume-dash-footer__sep" />
			<a href={ runSetupUrl } className="plume-dash-footer__link">
				{ __( 'Run setup again', 'plume' ) }
			</a>
			<div className="plume-dash-footer__sep" />
			<a
				href={ urls.docs }
				className="plume-dash-footer__link"
				target="_blank"
				rel="nofollow noreferrer"
			>
				{ __( 'Documentation ↗', 'plume' ) }
			</a>
			<div className="plume-dash-footer__sep" />
			<a
				href={ urls.support }
				className="plume-dash-footer__link"
				target="_blank"
				rel="nofollow noreferrer"
			>
				{ __( 'Support ↗', 'plume' ) }
			</a>
		</div>
	);
}
