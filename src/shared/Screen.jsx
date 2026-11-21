import './page-header.css';

/**
 * Layout wrapper that gives a screen its header and one shared content width.
 *
 * `page` (default) is a centred column capped at `--plume-screen-max`, used by
 * SEO, Images, Generator, Usage and Settings. `app` is a full-height flex
 * column for app-like screens whose body must fill the viewport.
 *
 * Uses its own `.plume-screen` class, not `.plume-page`: PHP mount points
 * already carry `.plume-page`, and a global rule on it would squeeze Chat.
 *
 * @param {Object}       props
 * @param {ReactElement} props.header       Usually a <PageHeader />.
 * @param {'page'|'app'} [props.layout]     Layout mode. Defaults to `page`.
 * @param {string}       [props.className]  Extra class names for the wrapper.
 * @param {ReactNode}    props.children     Screen body.
 * @return {ReactElement} The screen.
 * @example
 * <Screen header={ <PageHeader title={ __( 'Usage', 'plume' ) } /> }>
 *   …
 * </Screen>
 */
export default function Screen( {
	header,
	layout = 'page',
	className = '',
	children,
} ) {
	const classes = [ 'plume-screen', `plume-screen--${ layout }`, className ]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div className={ classes }>
			{ header }
			<div className="plume-screen__body">{ children }</div>
		</div>
	);
}
