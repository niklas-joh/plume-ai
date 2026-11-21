import { useId } from '@wordpress/element';
import { BODY_PATH, FACET_PATH, MONO_PATH } from './plume-logo-paths';
import './page-header.css';

/**
 * The Plume feather mark as an inline SVG.
 *
 * Decorative: it is hidden from assistive technology because the screen name
 * (the page's h1) already names the product area. Its size comes from the
 * `--plume-logo-size` token in CSS, not from a prop, so every screen matches.
 *
 * The colour variant uses two gradients; each instance gets its own ids
 * (via `useId`) so several logos on one page never reference each other's
 * gradients. The `mono` variant is a flat single-colour silhouette that takes
 * `currentColor`: no gradients, no shading, for black-and-white contexts.
 *
 * @param {Object}  props
 * @param {boolean} [props.mono]      Render the flat single-colour silhouette.
 * @param {string}  [props.className] Extra class names for the <svg>.
 * @return {ReactElement} The logo.
 * @example
 * <PlumeLogo />
 * <PlumeLogo mono className="my-icon" />
 */
export default function PlumeLogo( { mono = false, className = '' } ) {
	// useId returns values like ":r1:"; colons are not safe in url(#…).
	const uid = useId().replace( /[^a-zA-Z0-9_-]/g, '' );
	const bodyId = `plume-logo-a-${ uid }`;
	const facetId = `plume-logo-b-${ uid }`;
	const classes = [ 'plume-logo', className ].filter( Boolean ).join( ' ' );

	if ( mono ) {
		return (
			<svg
				className={ classes }
				viewBox="0 0 866 866"
				fill="currentColor"
				aria-hidden="true"
				focusable="false"
			>
				<path fillRule="evenodd" d={ MONO_PATH } />
			</svg>
		);
	}

	return (
		<svg
			className={ classes }
			viewBox="0 0 866 866"
			fill="none"
			aria-hidden="true"
			focusable="false"
		>
			<defs>
				<linearGradient
					id={ bodyId }
					gradientUnits="userSpaceOnUse"
					x1="190"
					y1="780"
					x2="640"
					y2="90"
				>
					<stop offset="0" stopColor="#0c3f67" />
					<stop offset=".45" stopColor="#14608a" />
					<stop offset="1" stopColor="#2796ad" />
				</linearGradient>
				<linearGradient
					id={ facetId }
					gradientUnits="userSpaceOnUse"
					x1="650"
					y1="90"
					x2="420"
					y2="560"
				>
					<stop offset="0" stopColor="#2dd4de" />
					<stop offset=".5" stopColor="#29a3b9" />
					<stop offset=".85" stopColor="#1f86a6" stopOpacity=".55" />
					<stop offset="1" stopColor="#1b6e92" stopOpacity="0" />
				</linearGradient>
			</defs>
			<path
				fill={ `url(#${ bodyId })` }
				fillRule="evenodd"
				d={ BODY_PATH }
			/>
			<path fill={ `url(#${ facetId })` } d={ FACET_PATH } />
		</svg>
	);
}
