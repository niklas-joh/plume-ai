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
		<div className="wpaim-dash-footer">
			<a href={ urls.settings } className="wpaim-dash-footer__link">
				Settings
			</a>
			<div className="wpaim-dash-footer__sep" />
			<a href={ runSetupUrl } className="wpaim-dash-footer__link">
				Run setup again
			</a>
			<div className="wpaim-dash-footer__sep" />
			<a
				href="https://wpaimind.com/docs"
				className="wpaim-dash-footer__link"
				target="_blank"
				rel="nofollow noreferrer"
			>
				Documentation &#x2197;
			</a>
			<div className="wpaim-dash-footer__sep" />
			<a
				href="https://wpaimind.com/support"
				className="wpaim-dash-footer__link"
				target="_blank"
				rel="nofollow noreferrer"
			>
				Support &#x2197;
			</a>
		</div>
	);
}
