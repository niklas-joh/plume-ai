import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import UsageSharingDetails from '../../shared/UsageSharingDetails';
import {
	recordClientEvent,
	recordUpgradeClick,
	withUtm,
} from '../../shared/telemetry';

const PROVIDERS = [
	{
		id: 'openai',
		name: 'OpenAI',
		keyUrl: 'https://platform.openai.com/api-keys',
		docsUrl: 'https://platform.openai.com/docs/quickstart',
		keyLabel: __( 'Get API key ↗', 'plume' ),
		// Key prefixes such as "sk-…" are not translatable, so they stay outside the msgid.
		placeholder: `sk-…  ${ __( 'Paste your OpenAI API key', 'plume' ) }`,
	},
	{
		id: 'claude',
		name: 'Claude',
		keyUrl: 'https://console.anthropic.com/settings/keys',
		docsUrl: 'https://docs.anthropic.com/en/api/getting-started',
		keyLabel: __( 'Get API key ↗', 'plume' ),
		placeholder: `sk-ant-…  ${ __( 'Paste your Anthropic API key', 'plume' ) }`,
	},
	{
		id: 'gemini',
		name: 'Gemini',
		keyUrl: 'https://aistudio.google.com/apikey',
		docsUrl: 'https://ai.google.dev/gemini-api/docs/quickstart',
		keyLabel: __( 'Get API key ↗', 'plume' ),
		placeholder: `AI…  ${ __( 'Paste your Gemini API key', 'plume' ) }`,
	},
];

const IMAGE_PROVIDERS = [
	{
		id: 'openai',
		name: 'OpenAI (DALL·E)',
		docsUrl: 'https://platform.openai.com/docs/guides/images',
		docsLabel: sprintf(
			/* translators: %s: image model name, e.g. "DALL·E". */
			__( '%s docs ↗', 'plume' ),
			'DALL·E'
		),
		placeholder: `sk-…  ${ __( 'Paste your OpenAI API key', 'plume' ) }`,
	},
	{
		id: 'gemini',
		name: 'Gemini (Imagen 3)',
		docsUrl: 'https://ai.google.dev/gemini-api/docs/image-generation',
		docsLabel: sprintf(
			/* translators: %s: image model name, e.g. "DALL·E". */
			__( '%s docs ↗', 'plume' ),
			'Imagen'
		),
		placeholder: `AI…  ${ __( 'Paste your Gemini API key', 'plume' ) }`,
		// DEV NOTE: Verify Imagen 3 endpoint before shipping.
		// Confirm if imagen-3.0-generate-* uses generativelanguage.googleapis.com
		// (same as text) or requires Vertex AI / a separate endpoint.
	},
];

// ── Usage sharing consent ─────────────────────────────────────────────────────

/**
 * Opt-in card for anonymous usage sharing, shown once at the top of step 1.
 *
 * The two choices are styled alike and neither is pre-selected; "Get started"
 * is never blocked by it. After a choice the buttons are replaced by a status
 * message that is announced and receives focus.
 *
 * @param {Object}   props
 * @param {Function} props.onChoose Called with `'yes'` or `'no'`.
 * @return {ReactElement}
 */
function ConsentCard( { onChoose } ) {
	const [ chosen, setChosen ] = useState( false );
	const statusRef = useRef( null );

	useEffect( () => {
		if ( chosen && statusRef.current ) {
			statusRef.current.focus();
		}
	}, [ chosen ] );

	const choose = ( value ) => {
		setChosen( true );
		onChoose( value );
	};

	return (
		<div className="plume-ob-consent">
			<div className="plume-ob-choice__title">
				{ __( 'Help improve Plume', 'plume' ) }
			</div>
			<p className="plume-ob-choice__desc">
				{ __(
					'Share anonymous usage statistics so we can see where setup or AI requests fail. Nothing is shared unless you say yes.',
					'plume'
				) }
			</p>
			<UsageSharingDetails />
			<div role="status" ref={ statusRef } tabIndex={ -1 }>
				{ chosen &&
					__(
						'Thanks. You can change this in Settings → Features.',
						'plume'
					) }
			</div>
			{ ! chosen && (
				<div className="plume-ob-consent__actions">
					<button
						type="button"
						className="plume-dash-btn"
						onClick={ () => choose( 'yes' ) }
					>
						{ __( 'Share anonymous usage', 'plume' ) }
					</button>
					<button
						type="button"
						className="plume-dash-btn"
						onClick={ () => choose( 'no' ) }
					>
						{ __( 'No thanks', 'plume' ) }
					</button>
				</div>
			) }
		</div>
	);
}

// ── Step 1 ────────────────────────────────────────────────────────────────────

/**
 * First onboarding step — choose between Plugin API, own API key, or Pro.
 *
 * @param {Object}   props
 * @param {string}   props.selection   Currently selected connection type: `'plugin'`, `'own_key'`, or `'pro'`.
 * @param {Function} props.onSelect    Called with the new selection string when a choice card is clicked.
 * @param {Function} props.onContinue  Called when the user clicks "Get started".
 * @param {Function} props.onSkip      Called when the user skips setup entirely.
 * @param {Function} props.onUpgrade   Called when the user follows the Pro upgrade link.
 * @param {string}   props.upgradeUrl  URL for the Pro upgrade page (used when `selection === 'pro'`).
 * @param {?Object}  props.consentCard Usage-sharing consent card to show above the choices, or null.
 * @return {ReactElement}
 */
function Step1( {
	selection,
	onSelect,
	onContinue,
	onSkip,
	onUpgrade,
	upgradeUrl,
	consentCard = null,
} ) {
	const choices = [
		{
			id: 'plugin',
			title: __( 'Use Plugin API', 'plume' ),
			badge: __( 'Free', 'plume' ),
			desc: __(
				'Start immediately. Built-in access with usage limits. No API key needed. Registers your site to run AI models.',
				'plume'
			),
		},
		{
			id: 'own_key',
			title: __( 'Use my own API key', 'plume' ),
			badge: null,
			desc: __(
				'Connect OpenAI, Claude, or Gemini directly. Unlimited usage.',
				'plume'
			),
		},
		{
			id: 'pro',
			title: __( 'Upgrade to Pro', 'plume' ),
			badge: 'Pro',
			desc: __(
				'Full access, priority support, and advanced features.',
				'plume'
			),
		},
	];

	const isPro = selection === 'pro';

	return (
		<>
			<div className="plume-ob-header">
				<div className="plume-ob-pips">
					<div className="plume-ob-pip plume-ob-pip--active" />
					{ /* Second pip only shown on own-key path — hidden here */ }
				</div>
				<div className="plume-ob-title">
					{ __( 'Welcome to Plume - Write and Design', 'plume' ) }
				</div>
				<div className="plume-ob-sub">
					{ __(
						'How would you like to connect? You can change this anytime in Settings.',
						'plume'
					) }
				</div>
			</div>

			<div className="plume-ob-body">
				{ consentCard }
				{ choices.map( ( c ) => (
					<div
						key={ c.id }
						className={ `plume-ob-choice${
							selection === c.id
								? ' plume-ob-choice--selected'
								: ''
						}` }
						role="button"
						tabIndex={ 0 }
						onClick={ () => onSelect( c.id ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								onSelect( c.id );
							}
						} }
					>
						<div className="plume-ob-radio">
							<div className="plume-ob-radio__dot" />
						</div>
						<div>
							<div className="plume-ob-choice__title">
								{ c.title }
								{ c.badge && (
									<span
										className="plume-pro-badge"
										style={ { marginLeft: 6 } }
									>
										{ c.badge }
									</span>
								) }
							</div>
							<div className="plume-ob-choice__desc">
								{ c.desc }
							</div>
						</div>
					</div>
				) ) }
			</div>

			<div className="plume-ob-footer">
				<button className="plume-ob-skip" onClick={ onSkip }>
					{ __( 'Skip setup', 'plume' ) }
				</button>
				{ isPro ? (
					<a
						href={ withUtm( upgradeUrl, 'onboarding' ) }
						className="plume-dash-btn plume-dash-btn--primary"
						target="_blank"
						rel="nofollow noreferrer"
						onClick={ onUpgrade }
					>
						{ __( 'Go to Upgrade ↗', 'plume' ) }
					</a>
				) : (
					<button
						className="plume-dash-btn plume-dash-btn--primary"
						onClick={ onContinue }
					>
						{ __( 'Get started →', 'plume' ) }
					</button>
				) }
			</div>
		</>
	);
}

// ── Step 2 ────────────────────────────────────────────────────────────────────

/**
 * Second onboarding step — select text and image AI providers and enter API keys.
 *
 * Supports inline key validation via the /test-key REST endpoint. The image
 * provider section is optional and collapsible. A separate key input is only
 * shown when the chosen image provider differs from the text provider.
 *
 * @param {Object}   props
 * @param {Function} props.onBack     Called when the user navigates back to step 1.
 * @param {Function} props.onFinish   Called with `{ provider, apiKeys, imageProvider }` on completion.
 * @param {string}   props.nonce      WordPress REST nonce for authenticated requests.
 * @param {string}   props.restUrl    Base URL for the plugin's REST API.
 * @return {ReactElement}
 */
function Step2( { onBack, onFinish, nonce, restUrl } ) {
	const [ selectedProvider, setSelectedProvider ] = useState( null );
	const [ apiKeys, setApiKeys ] = useState( {} );
	const [ imageProvider, setImageProvider ] = useState( null );
	const [ imageOpen, setImageOpen ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ testStatus, setTestStatus ] = useState( {} );

	const imageNeedsSeparateKey =
		imageProvider && imageProvider !== selectedProvider;

	const handleTestKey = useCallback(
		async ( provider ) => {
			setTestStatus( ( prev ) => ( {
				...prev,
				[ provider ]: 'testing',
			} ) );
			try {
				const res = await window.fetch( `${ restUrl }/test-key`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( {
						provider,
						api_key: apiKeys[ provider ],
					} ),
				} );
				const data = await res.json();
				setTestStatus( ( prev ) => ( {
					...prev,
					[ provider ]: data.success
						? 'valid'
						: `error:${
								data.message ?? __( 'Invalid key', 'plume' )
							}`,
				} ) );
			} catch {
				setTestStatus( ( prev ) => ( {
					...prev,
					[ provider ]: `error:${ __( 'Network error', 'plume' ) }`,
				} ) );
			}
		},
		[ apiKeys, nonce, restUrl ]
	);

	const handleFinish = useCallback( async () => {
		setSaving( true );
		await onFinish( {
			provider: selectedProvider,
			apiKeys,
			imageProvider,
		} );
		setSaving( false );
	}, [ onFinish, selectedProvider, apiKeys, imageProvider ] );

	return (
		<>
			<div className="plume-ob-header">
				<div className="plume-ob-pips">
					<div className="plume-ob-pip plume-ob-pip--done" />
					<div className="plume-ob-pip plume-ob-pip--active" />
				</div>
				<div className="plume-ob-title">
					{ __( 'Choose your AI providers', 'plume' ) }
				</div>
				<div className="plume-ob-sub">
					{ __(
						'Pick a text provider and paste your key. Image generation is optional.',
						'plume'
					) }
				</div>
			</div>

			<div className="plume-ob-body">
				<div className="plume-ob-section-label">
					{ __( 'Text model', 'plume' ) }
				</div>

				<div className="plume-ob-providers">
					{ PROVIDERS.map( ( p ) => (
						<div
							key={ p.id }
							className={ `plume-ob-provider${
								selectedProvider === p.id
									? ' plume-ob-provider--selected'
									: ''
							}` }
							role="button"
							tabIndex={ 0 }
							onClick={ () => setSelectedProvider( p.id ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									e.preventDefault();
									setSelectedProvider( p.id );
								}
							} }
						>
							<div className="plume-ob-provider__check">
								&#x2713;
							</div>
							<div className="plume-ob-provider__name">
								{ p.name }
							</div>
							{ p.docsUrl && (
								<a
									href={ p.docsUrl }
									className="plume-ob-provider__link"
									target="_blank"
									rel="nofollow noreferrer"
									onClick={ ( e ) => e.stopPropagation() }
								>
									{ __( 'Setup guide ↗', 'plume' ) }
								</a>
							) }
							<a
								href={ p.keyUrl }
								className="plume-ob-provider__link"
								target="_blank"
								rel="nofollow noreferrer"
								onClick={ ( e ) => e.stopPropagation() }
							>
								{ p.keyLabel }
							</a>
						</div>
					) ) }
				</div>

				{ selectedProvider && (
					<>
						<div className="plume-ob-key-row">
							<input
								className="plume-ob-key-input"
								type="password"
								value={ apiKeys[ selectedProvider ] ?? '' }
								onChange={ ( e ) => {
									setApiKeys( ( prev ) => ( {
										...prev,
										[ selectedProvider ]: e.target.value,
									} ) );
									setTestStatus( ( prev ) => ( {
										...prev,
										[ selectedProvider ]: null,
									} ) );
								} }
								placeholder={
									PROVIDERS.find(
										( p ) => p.id === selectedProvider
									)?.placeholder ??
									__( 'Paste your API key', 'plume' )
								}
								autoComplete="off"
							/>
							<button
								type="button"
								className="plume-ob-test-btn"
								disabled={
									! apiKeys[ selectedProvider ] ||
									testStatus[ selectedProvider ] === 'testing'
								}
								onClick={ () =>
									handleTestKey( selectedProvider )
								}
							>
								{ testStatus[ selectedProvider ] === 'testing'
									? '…'
									: __( 'Test key', 'plume' ) }
							</button>
						</div>
						{ testStatus[ selectedProvider ] === 'valid' && (
							<span className="plume-ob-key-status plume-ob-key-status--valid">
								{ __( '✓ Valid', 'plume' ) }
							</span>
						) }
						{ testStatus[ selectedProvider ]?.startsWith(
							'error:'
						) && (
							<span className="plume-ob-key-status plume-ob-key-status--error">
								&#x2717;{ ' ' }
								{ testStatus[ selectedProvider ].slice( 6 ) }
							</span>
						) }
					</>
				) }

				{ /* Image provider — optional, collapsible */ }
				<div className="plume-ob-optional">
					<button
						type="button"
						className="plume-ob-optional__toggle"
						onClick={ () => setImageOpen( ( o ) => ! o ) }
					>
						<span className="plume-ob-optional__label">
							{ __( 'Image model', 'plume' ) }
						</span>
						<span className="plume-ob-optional__tag">
							{ __( 'Optional', 'plume' ) }
						</span>
						<span
							className={ `plume-ob-optional__chevron${
								imageOpen
									? ' plume-ob-optional__chevron--open'
									: ''
							}` }
						>
							&#x25be;
						</span>
					</button>

					{ imageOpen && (
						<div className="plume-ob-optional__body">
							<p className="plume-ob-optional__desc">
								{ __(
									'Add an image generation provider. Can be configured later in Settings.',
									'plume'
								) }
							</p>

							<div className="plume-ob-providers">
								{ IMAGE_PROVIDERS.map( ( p ) => (
									<div
										key={ p.id }
										className={ `plume-ob-provider${
											imageProvider === p.id
												? ' plume-ob-provider--selected'
												: ''
										}` }
										role="button"
										tabIndex={ 0 }
										onClick={ () =>
											setImageProvider( ( prev ) =>
												prev === p.id ? null : p.id
											)
										}
										onKeyDown={ ( e ) => {
											if (
												e.key === 'Enter' ||
												e.key === ' '
											) {
												e.preventDefault();
												setImageProvider( ( prev ) =>
													prev === p.id ? null : p.id
												);
											}
										} }
									>
										<div className="plume-ob-provider__check">
											&#x2713;
										</div>
										<div className="plume-ob-provider__name">
											{ p.name }
										</div>
										<a
											href={ p.docsUrl }
											className="plume-ob-provider__link"
											target="_blank"
											rel="nofollow noreferrer"
											onClick={ ( e ) =>
												e.stopPropagation()
											}
										>
											{ p.docsLabel }
										</a>
									</div>
								) ) }
								<div
									className="plume-ob-provider"
									aria-hidden="true"
									style={ {
										opacity: 0.4,
										cursor: 'default',
										borderStyle: 'dashed',
									} }
								>
									<div
										className="plume-ob-provider__name"
										style={ {
											color: 'var(--color-text-muted)',
											fontSize: '0.625rem',
										} }
									>
										{ __( 'More coming', 'plume' ) }
									</div>
								</div>
							</div>

							{ imageNeedsSeparateKey && (
								<>
									<div className="plume-ob-key-row">
										<input
											className="plume-ob-key-input"
											type="password"
											value={
												apiKeys[ imageProvider ] ?? ''
											}
											onChange={ ( e ) => {
												setApiKeys( ( prev ) => ( {
													...prev,
													[ imageProvider ]:
														e.target.value,
												} ) );
												setTestStatus( ( prev ) => ( {
													...prev,
													[ imageProvider ]: null,
												} ) );
											} }
											placeholder={
												IMAGE_PROVIDERS.find(
													( p ) =>
														p.id === imageProvider
												)?.placeholder ??
												__(
													'Paste your image API key',
													'plume'
												)
											}
											autoComplete="off"
										/>
										<button
											type="button"
											className="plume-ob-test-btn"
											disabled={
												! apiKeys[ imageProvider ] ||
												testStatus[ imageProvider ] ===
													'testing'
											}
											onClick={ () =>
												handleTestKey( imageProvider )
											}
										>
											{ testStatus[ imageProvider ] ===
											'testing'
												? '…'
												: __( 'Test key', 'plume' ) }
										</button>
									</div>
									{ testStatus[ imageProvider ] ===
										'valid' && (
										<span className="plume-ob-key-status plume-ob-key-status--valid">
											{ __( '✓ Valid', 'plume' ) }
										</span>
									) }
									{ testStatus[ imageProvider ]?.startsWith(
										'error:'
									) && (
										<span className="plume-ob-key-status plume-ob-key-status--error">
											&#x2717;{ ' ' }
											{ testStatus[ imageProvider ].slice(
												6
											) }
										</span>
									) }
								</>
							) }
						</div>
					) }
				</div>
			</div>

			<div className="plume-ob-footer">
				<button className="plume-ob-skip" onClick={ onBack }>
					{ __( '← Back', 'plume' ) }
				</button>
				<button
					className="plume-dash-btn plume-dash-btn--primary"
					onClick={ handleFinish }
					disabled={ saving }
				>
					{ saving
						? __( 'Saving…', 'plume' )
						: __( 'Finish setup →', 'plume' ) }
				</button>
			</div>
		</>
	);
}

// ── Done screen ───────────────────────────────────────────────────────────────

/**
 * Final onboarding screen confirming that setup is complete.
 *
 * @param {Object}  props
 * @param {string}  props.apiTierLabel  Human-readable label describing the chosen API tier.
 * @param {Object}  props.urls          URL map with at least a `chat` key for the CTA link.
 * @param {boolean} props.registered    Whether the site is already registered with the proxy (proxy tiers only; irrelevant for BYOK).
 * @return {ReactElement}
 */
function DoneScreen( { apiTierLabel, urls, registered } ) {
	return (
		<>
			<div className="plume-ob-header">
				<div className="plume-ob-pips">
					<div className="plume-ob-pip plume-ob-pip--done" />
					<div className="plume-ob-pip plume-ob-pip--done" />
				</div>
				<div className="plume-ob-title">
					{ __( "You're all set", 'plume' ) }
				</div>
				<div className="plume-ob-sub">
					{ __(
						'Plume - Write and Design is ready to use.',
						'plume'
					) }
				</div>
			</div>

			<div className="plume-ob-body">
				<div className="plume-ob-success">
					<div className="plume-ob-success__title">
						{ __( 'Setup complete', 'plume' ) }
					</div>
					<div className="plume-ob-success__sub">
						{ apiTierLabel }
						<br />
						{ __( 'Change this anytime in Settings.', 'plume' ) }
					</div>
					{ false === registered && (
						<div className="plume-ob-success__sub">
							{ __(
								'Finishing setup — this can take a few seconds.',
								'plume'
							) }
						</div>
					) }
					<a href={ urls.chat } className="plume-ob-success__cta">
						{ __( 'Open Chat →', 'plume' ) }
					</a>
				</div>
			</div>

			<div
				className="plume-ob-footer"
				style={ {
					borderTop: 'none',
					paddingTop: 0,
					justifyContent: 'center',
				} }
			>
				<button
					type="button"
					className="plume-ob-skip"
					onClick={ () => window.location.reload() }
				>
					{ __( 'Go to Dashboard instead', 'plume' ) }
				</button>
			</div>
		</>
	);
}

// ── Root page ─────────────────────────────────────────────────────────────────

/**
 * Multi-step onboarding wizard rendered in place of the dashboard on first visit.
 *
 * Manages a three-state machine: `step1` → `step2` (own-key path only) → `done`.
 * Each transition persists progress to the /onboarding REST endpoint so the
 * wizard does not re-appear after completion.
 *
 * Usage sharing: when it is available and the site owner has not chosen yet,
 * step 1 shows a consent card. The choice is saved through POST /settings
 * (not /onboarding, which would also register the site). Step events are sent
 * only once the local consent is `'yes'`, and each waits for the consent save.
 *
 * @param {Object}  props
 * @param {string}  props.nonce                     WordPress REST nonce for authenticated requests.
 * @param {string}  props.restUrl                   Base URL for the plugin's REST API.
 * @param {Object}  props.urls                      URL map passed through to child steps (e.g. `upgrade`, `chat`).
 * @param {string}  [props.telemetryConsent]        Stored usage-sharing choice: `'unset'`, `'yes'` or `'no'`.
 * @param {boolean} [props.telemetryAvailable]      Whether usage sharing can be turned on on this site.
 * @return {ReactElement}
 */
export default function OnboardingPage( {
	nonce,
	restUrl,
	urls,
	telemetryConsent = 'unset',
	telemetryAvailable = false,
} ) {
	const [ step, setStep ] = useState( 'step1' ); // 'step1' | 'step2' | 'done'
	// Decided once, so the card (and its status message) stays after a choice.
	const [ showConsentCard ] = useState(
		telemetryAvailable && telemetryConsent === 'unset'
	);
	const consentSaved = useRef( Promise.resolve() );
	// A ref, not state, so a click in the same frame as the choice already sees it.
	const consentRef = useRef( telemetryConsent );
	const [ connection, setConnection ] = useState( 'plugin' ); // 'plugin' | 'own_key' | 'pro'
	const [ apiTierLabel, setApiTierLabel ] = useState(
		__( 'Using Plugin API (free tier).', 'plume' )
	);
	const [ registered, setRegistered ] = useState( true );

	const postOnboarding = useCallback(
		async ( body ) => {
			const res = await window.fetch( `${ restUrl }/onboarding`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( body ),
			} );
			try {
				return await res.json();
			} catch {
				return {};
			}
		},
		[ nonce, restUrl ]
	);

	const handleConsent = useCallback(
		( value ) => {
			consentRef.current = value;
			consentSaved.current = window
				.fetch( `${ restUrl }/settings`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( {
						telemetry_consent: value,
						telemetry_source: 'onboarding',
					} ),
				} )
				.catch( () => {} );
		},
		[ nonce, restUrl ]
	);

	const recordStep = useCallback( ( stepName, action ) => {
		if ( consentRef.current !== 'yes' ) {
			return;
		}
		consentSaved.current.then( () =>
			recordClientEvent( 'onboarding_step', {
				step: stepName,
				action,
			} )
		);
	}, [] );

	useEffect( () => {
		if ( step === 'step2' ) {
			recordStep( 'providers', 'viewed' );
		} else if ( step === 'done' ) {
			recordStep( 'done', 'viewed' );
		}
		// Only a step change should record a view, not a later consent change.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step ] );

	// Each completion waits for a pending consent save, so a fast click cannot reach
	// /onboarding (which records onboarding_done) before consent is stored.
	const handleSkip = useCallback( async () => {
		await consentSaved.current;
		await postOnboarding( { seen: true, route: 'skipped' } );
		window.location.reload();
	}, [ postOnboarding ] );

	const handleUpgrade = useCallback( async () => {
		await consentSaved.current;
		recordUpgradeClick( 'onboarding', 'dashboard' );
		await postOnboarding( { seen: true, route: 'upgrade' } );
		window.location.reload();
	}, [ postOnboarding ] );

	const handleStep1Continue = useCallback( async () => {
		recordStep( 'welcome', 'completed' );
		await consentSaved.current;
		if ( connection === 'plugin' ) {
			const result = await postOnboarding( {
				seen: true,
				route: 'managed',
			} );
			setApiTierLabel( __( 'Using Plugin API (free tier).', 'plume' ) );
			setRegistered( result.registered !== false );
			setStep( 'done' );
		} else if ( connection === 'own_key' ) {
			setStep( 'step2' );
		}
		// 'pro' path handled by the upgrade link directly in Step1
	}, [ connection, postOnboarding, recordStep ] );

	const handleStep2Finish = useCallback(
		async ( { provider, apiKeys, imageProvider } ) => {
			recordStep( 'providers', 'completed' );
			const result = await postOnboarding( {
				seen: true,
				route: 'own_key',
				provider,
				api_keys: apiKeys,
				image_provider: imageProvider,
			} );
			setApiTierLabel(
				sprintf(
					/* translators: %s: AI provider name, e.g. "Claude". */
					__( 'Using your own %s API key.', 'plume' ),
					provider
						? provider.charAt( 0 ).toUpperCase() +
								provider.slice( 1 )
						: ''
				)
			);
			setRegistered( result.registered !== false );
			setStep( 'done' );
		},
		[ postOnboarding, recordStep ]
	);

	return (
		<div className="plume-ob-page">
			<div className="plume-ob-page__inner">
				{ step === 'step1' && (
					<Step1
						selection={ connection }
						onSelect={ setConnection }
						onContinue={ handleStep1Continue }
						onSkip={ handleSkip }
						onUpgrade={ handleUpgrade }
						upgradeUrl={ urls.upgrade }
						consentCard={
							showConsentCard ? (
								<ConsentCard onChoose={ handleConsent } />
							) : null
						}
					/>
				) }
				{ step === 'step2' && (
					<Step2
						onBack={ () => setStep( 'step1' ) }
						onFinish={ handleStep2Finish }
						nonce={ nonce }
						restUrl={ restUrl }
					/>
				) }
				{ step === 'done' && (
					<DoneScreen
						apiTierLabel={ apiTierLabel }
						urls={ urls }
						registered={ registered }
					/>
				) }
			</div>
		</div>
	);
}
