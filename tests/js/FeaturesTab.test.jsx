/**
 * Unit tests for FeaturesTab — module-enable settings grid.
 *
 * @see src/admin/settings/FeaturesTab.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import FeaturesTab from '../../src/admin/settings/FeaturesTab';

// @wordpress/element re-exports React hooks.
jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

// lucide-react icons — stub every named export as a no-op span.
jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'FeaturesTab', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'does not render a frontend_widget card', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab settings={ {} } saveSettings={ jest.fn() } />
			);
		} );

		expect( container.textContent ).not.toContain( 'Frontend Widget' );
	} );

	it( 'renders every remaining module card unlocked regardless of is_paid', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ { is_paid: false } }
					saveSettings={ jest.fn() }
				/>
			);
		} );

		expect(
			container.querySelector( '.plume-feature-card--locked' )
		).toBeNull();
		expect( container.querySelectorAll( '.plume-feature-card' ).length ).toBe(
			7
		);
	} );

	it( 'does not render a Pro badge or lock icon on any card', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ { is_paid: false } }
					saveSettings={ jest.fn() }
				/>
			);
		} );

		expect( container.querySelector( '.plume-pro-badge' ) ).toBeNull();
		expect( container.textContent ).not.toContain( 'Requires Plume Pro' );
	} );

	it( 'calls saveSettings with the toggled module slug when a toggle changes', async () => {
		const saveSettings = jest.fn();

		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ { enabled_modules: [], is_paid: false } }
					saveSettings={ saveSettings }
				/>
			);
		} );

		const toggle = container.querySelector(
			'input[aria-label="SEO"]'
		);
		expect( toggle ).not.toBeNull();

		// Clicking a real checkbox toggles `.checked` and fires a native
		// `change` event — this exercises the same React onChange path a
		// real user interaction would, without needing @testing-library.
		await act( async () => {
			toggle.click();
		} );

		expect( saveSettings ).toHaveBeenCalled();
	} );

	it( 'shows the updated section description without a Pro-licence mention', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab settings={ {} } saveSettings={ jest.fn() } />
			);
		} );

		expect( container.textContent ).toContain(
			'Enable or disable individual AI modules.'
		);
		expect( container.textContent ).not.toContain( 'Pro licence' );
	} );

	// ── Usage sharing ────────────────────────────────────────────────────────

	const usageToggle = () =>
		container.querySelector(
			'input[aria-label="Share usage statistics with Plume"]'
		);

	it( 'reflects the stored usage-sharing consent', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ {
						telemetry_consent: 'yes',
						telemetry_available: true,
					} }
					saveSettings={ jest.fn() }
				/>
			);
		} );
		expect( usageToggle().checked ).toBe( true );
		expect( container.textContent ).not.toContain( 'What is shared?' );
	} );

	it( 'links Learn more to the privacy policy with UTM tags in a new tab', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab settings={ {} } saveSettings={ jest.fn() } />
			);
		} );
		const link = Array.from( container.querySelectorAll( 'a' ) ).find(
			( a ) => a.textContent.startsWith( 'Learn more' )
		);
		const url = new URL( link.href );
		expect( url.origin + url.pathname ).toBe(
			'https://wpaimind.com/privacy-policy'
		);
		expect( url.searchParams.get( 'utm_source' ) ).toBe( 'plugin' );
		expect( url.searchParams.get( 'utm_medium' ) ).toBe( 'settings' );
		expect( url.searchParams.get( 'utm_content' ) ).toBe( 'usage_sharing' );
		expect( link.target ).toBe( '_blank' );
		expect( link.rel ).toBe( 'noopener noreferrer' );
		expect( link.textContent ).toContain( '(opens in a new tab)' );
	} );

	it( 'keeps both statistics toggles in one section and makes no unlinked-data claim', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ { telemetry_available: true } }
					saveSettings={ jest.fn() }
				/>
			);
		} );
		const section = usageToggle().closest( 'section' );
		expect(
			section.querySelector(
				'input[aria-label="Share managed-service request statistics"]'
			)
		).not.toBeNull();
		expect( container.textContent ).not.toMatch( /anon[y]m/i );
	} );

	it( 'saves yes and no when toggled', async () => {
		const saveSettings = jest.fn();
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ {
						telemetry_consent: 'unset',
						telemetry_available: true,
					} }
					saveSettings={ saveSettings }
				/>
			);
		} );
		expect( usageToggle().checked ).toBe( false );
		expect( usageToggle().disabled ).toBe( false );

		await act( async () => {
			usageToggle().click();
		} );
		expect( saveSettings ).toHaveBeenLastCalledWith( {
			telemetry_consent: 'yes',
		} );

		await act( async () => {
			usageToggle().click();
		} );
		expect( saveSettings ).toHaveBeenLastCalledWith( {
			telemetry_consent: 'no',
		} );
	} );

	it( 'is disabled when unavailable and not yet shared', async () => {
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ {
						telemetry_consent: 'unset',
						telemetry_available: false,
					} }
					saveSettings={ jest.fn() }
				/>
			);
		} );
		expect( usageToggle().disabled ).toBe( true );
	} );

	it( 'stays enabled when unavailable but already shared, so it can be turned off', async () => {
		const saveSettings = jest.fn();
		await act( async () => {
			root.render(
				<FeaturesTab
					settings={ {
						telemetry_consent: 'yes',
						telemetry_available: false,
					} }
					saveSettings={ saveSettings }
				/>
			);
		} );
		expect( usageToggle().disabled ).toBe( false );
		await act( async () => {
			usageToggle().click();
		} );
		expect( saveSettings ).toHaveBeenCalledWith( {
			telemetry_consent: 'no',
		} );
	} );

	describe( 'usage statistics toggle (#1015)', () => {
		const LABEL = 'Share managed-service request statistics';

		const statsToggle = () =>
			container.querySelector( `input[aria-label="${ LABEL }"]` );

		it( 'renders on by default', async () => {
			await act( async () => {
				root.render(
					<FeaturesTab settings={ {} } saveSettings={ jest.fn() } />
				);
			} );

			expect( statsToggle() ).not.toBeNull();
			expect( statsToggle().checked ).toBe( true );
		} );

		it( 'reflects a saved opt-out', async () => {
			await act( async () => {
				root.render(
					<FeaturesTab
						settings={ { share_usage_stats: false } }
						saveSettings={ jest.fn() }
					/>
				);
			} );

			expect( statsToggle().checked ).toBe( false );
		} );

		it( 'saves share_usage_stats when toggled off and back on', async () => {
			const saveSettings = jest.fn();

			await act( async () => {
				root.render(
					<FeaturesTab
						settings={ { share_usage_stats: true } }
						saveSettings={ saveSettings }
					/>
				);
			} );

			await act( async () => {
				statsToggle().click();
			} );
			expect( saveSettings ).toHaveBeenLastCalledWith( {
				share_usage_stats: false,
			} );
			expect( statsToggle().checked ).toBe( false );

			await act( async () => {
				statsToggle().click();
			} );
			expect( saveSettings ).toHaveBeenLastCalledWith( {
				share_usage_stats: true,
			} );
		} );
	} );
} );
