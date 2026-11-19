import PlumeLogo from './PlumeLogo';
import './page-header.css';

/**
 * Shared page header for the Plume admin screens: logo, brand name, the
 * screen's one <h1>, an optional one-line description and optional actions.
 *
 * One component so title size, logo and spacing cannot drift between
 * screens (#1037). The brand text and logo sit outside the <h1>, so the
 * heading a screen reader announces is only the screen name.
 *
 * Placement: it renders inside the screen's mount <div>, which WordPress
 * prints inside `#wpbody-content`. Core admin notices are printed before the
 * mount, so they appear above this header.
 *
 * @param {Object}    props
 * @param {string}    props.title         Screen name, already translated. The screen's only <h1>.
 * @param {string}    [props.description] One line, already translated.
 * @param {ReactNode} [props.actions]     Controls aligned to the end; they wrap under the title on a phone.
 * @param {boolean}   [props.compact]     Smaller logo; the description is hidden on phones but shown from 783px, for dense app screens.
 * @return {ReactElement} The header.
 * @example
 * <PageHeader
 *   title={ __( 'SEO', 'plume' ) }
 *   description={ __( 'Generate meta titles and alt text.', 'plume' ) }
 *   actions={ <Button variant="secondary">…</Button> }
 * />
 */
export default function PageHeader( {
	title,
	description,
	actions,
	compact = false,
} ) {
	const classes = [
		'plume-page-header',
		compact ? 'plume-page-header--compact' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<header className={ classes }>
			<PlumeLogo />
			<div className="plume-page-header__text">
				{ /* Product name, deliberately not translated; outside the h1. */ }
				<span className="plume-page-header__brand">Plume</span>
				<h1 className="plume-page-header__title">{ title }</h1>
				{ description && (
					<p className="plume-page-header__description">
						{ description }
					</p>
				) }
			</div>
			{ actions && (
				<div className="plume-page-header__actions">{ actions }</div>
			) }
		</header>
	);
}
