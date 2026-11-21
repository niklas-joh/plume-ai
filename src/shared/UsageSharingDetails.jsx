import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Disclosure listing, in plain words, what anonymous usage sharing sends and
 * what it never sends.
 *
 * Shared by the onboarding consent card and the Settings > Features section so
 * both describe the data identically. Keep it in step with the event allowlist
 * (plume-proxy/src/event-schema.json) and the readme's External services entry.
 *
 * @param {Object} [props]
 * @param {string} [props.className] Extra class for the wrapper.
 * @return {ReactElement}
 *
 * @example
 * <UsageSharingDetails />
 */
export default function UsageSharingDetails( { className = '' } = {} ) {
	const [ open, setOpen ] = useState( false );
	const panelId = `plume-usage-details-${ useId().replace( /:/g, '' ) }`;

	return (
		<div className={ `plume-usage-details ${ className }`.trim() }>
			<button
				type="button"
				className="button-link plume-usage-details__toggle"
				aria-expanded={ open }
				aria-controls={ panelId }
				onClick={ () => setOpen( ( o ) => ! o ) }
			>
				{ open
					? __( 'Hide what is shared', 'plume' )
					: __( 'What is shared?', 'plume' ) }
			</button>
			<div
				id={ panelId }
				className="plume-usage-details__panel"
				hidden={ ! open }
			>
				<p>
					{ __(
						'A random install ID (not derived from your site), sent about once an hour in the background, with:',
						'plume'
					) }
				</p>
				<ul className="plume-usage-details__list">
					<li>
						{ __(
							'plugin, WordPress and PHP versions, site language and plan',
							'plume'
						) }
					</li>
					<li>
						{ __(
							'which AI provider types are set up (never your keys)',
							'plume'
						) }
					</li>
					<li>
						{ __(
							'which setup steps were viewed or completed, and clicks on upgrade links',
							'plume'
						) }
					</li>
					<li>
						{ __( 'a daily check-in, and deactivation', 'plume' ) }
					</li>
					<li>
						{ __(
							'for each AI request: the feature, provider, a known model name, whether it used the managed service, your own key or Ollama, success or a fixed error category, response time, retry count, and token or image counts when you use your own key or Ollama',
							'plume'
						) }
					</li>
				</ul>
				<p>
					{ __(
						'Never sent: prompts, content, post titles, API keys, user names, email addresses, your site address or error messages. Times are rounded to the hour, and these statistics are not linked to the managed service’s records.',
						'plume'
					) }
				</p>
				<p>
					{ __(
						'IP addresses are not stored with the statistics. Cloudflare processes them to deliver requests and limit abuse, and they may appear in its request logs, which are kept for up to 7 days.',
						'plume'
					) }
				</p>
			</div>
		</div>
	);
}
