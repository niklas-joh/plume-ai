/**
 * Unit tests for PlanGate component.
 *
 * Covers the two rendering paths:
 *  - allowed=true  → children rendered directly, no wrapper or overlay
 *  - allowed=false → children wrapped in aria-hidden+inert div, overlay shown
 */

// Required for React 18 concurrent mode act() support in Jest.
global.IS_REACT_ACT_ENVIRONMENT = true;

import { act } from 'react';
import { createRoot } from 'react-dom/client';
import PlanGate from '../PlanGate';

jest.mock(
	'@wordpress/i18n',
	() => ( {
		__: ( str ) => str,
		sprintf: ( fmt, ...args ) =>
			args.reduce( ( s, a ) => s.replace( '%s', a ), fmt ),
	} ),
	{ virtual: true }
);

jest.mock( 'lucide-react', () => ( {
	Lock: () => null,
} ) );

describe( 'PlanGate', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		act( () => {
			root = createRoot( container );
		} );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		document.body.removeChild( container );
	} );

	it( 'renders children directly when allowed is true', () => {
		act( () => {
			root.render(
				<PlanGate
					allowed={ true }
					requiredPlan="Pro"
					upgradeUrl="/upgrade"
				>
					<span data-testid="child">Child content</span>
				</PlanGate>
			);
		} );

		expect( container.querySelector( '.wpaim-plan-gate' ) ).toBeNull();
		expect(
			container.querySelector( '.wpaim-plan-gate__overlay' )
		).toBeNull();
		expect(
			container.querySelector( '[data-testid="child"]' )
		).not.toBeNull();
	} );

	it( 'wraps children in aria-hidden inert div when allowed is false', () => {
		act( () => {
			root.render(
				<PlanGate
					allowed={ false }
					requiredPlan="Pro BYOK"
					upgradeUrl="/upgrade-url"
				>
					<span data-testid="locked">Locked content</span>
				</PlanGate>
			);
		} );

		const gate = container.querySelector( '.wpaim-plan-gate' );
		expect( gate ).not.toBeNull();

		const content = container.querySelector( '.wpaim-plan-gate__content' );
		expect( content ).not.toBeNull();
		expect( content.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( content.hasAttribute( 'inert' ) ).toBe( true );
	} );

	it( 'shows overlay with requiredPlan name when allowed is false', () => {
		act( () => {
			root.render(
				<PlanGate
					allowed={ false }
					requiredPlan="Pro BYOK"
					upgradeUrl="/upgrade-url"
				>
					<span>content</span>
				</PlanGate>
			);
		} );

		const overlay = container.querySelector( '.wpaim-plan-gate__overlay' );
		expect( overlay ).not.toBeNull();
		expect( overlay.textContent ).toContain( 'Pro BYOK' );
	} );

	it( 'upgrade link points to upgradeUrl when allowed is false', () => {
		act( () => {
			root.render(
				<PlanGate
					allowed={ false }
					requiredPlan="Pro"
					upgradeUrl="/my-upgrade-page"
				>
					<span>content</span>
				</PlanGate>
			);
		} );

		const link = container.querySelector( 'a.wpaim-btn--primary' );
		expect( link ).not.toBeNull();
		expect( link.getAttribute( 'href' ) ).toBe( '/my-upgrade-page' );
	} );
} );
