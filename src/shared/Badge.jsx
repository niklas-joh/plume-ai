/**
 * Shared badge primitive rendering the `plume-badge` class-name convention.
 *
 * Centralises the `plume-badge plume-badge--{variant}` markup so domain badges
 * (SEO, images, post-status) delegate here instead of each repeating the
 * span/className construction — new variants are wired in one place.
 *
 * @param {Object}          props
 * @param {string}          props.variant   Modifier suffix, applied as `plume-badge--{variant}`.
 * @param {React.ReactNode} props.children  Badge label content.
 * @return {ReactElement}
 *
 * @example
 * <Badge variant="complete">Published</Badge>
 */
export default function Badge( { variant, children } ) {
	return (
		<span className={ `plume-badge plume-badge--${ variant }` }>
			{ children }
		</span>
	);
}
