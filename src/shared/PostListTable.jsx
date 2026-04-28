/* global navigator */
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import DOMPurify from 'dompurify';

const PER_PAGE = 20;

/**
 * Shared data table with tab filtering, search, and pagination.
 *
 * Fetches all published posts and pages on mount (all pages, not just the
 * first REST page) and merges them into a single sorted list. Consumer
 * provides tab definitions, an expandable work area component, and optional
 * extra column definitions.
 *
 * @param {Object}   props
 * @param {Array}    props.tabs      Tab definitions: `[{ id, label, filter }]` where `filter` is a predicate on a post object.
 * @param {Function} props.WorkArea  Component rendered in the expanded work-area row; receives `{ post, onClose, onUpdate }`.
 * @param {Array}    [props.columns] Optional extra column definitions: `[{ label, render, width? }]`.
 * @return {ReactElement}
 *
 * @example
 * <PostListTable tabs={ SEO_TABS } WorkArea={ SeoWorkArea } columns={ SEO_COLUMNS } />
 */
export default function PostListTable( { tabs, WorkArea, columns = [] } ) {
	const [ posts, setPosts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ activeTab, setActiveTab ] = useState( tabs[ 0 ]?.id ?? 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ expandedId, setExpandedId ] = useState( null );

	useEffect( () => {
		const fetchAll = async () => {
			try {
				// Fetch all pages for a given post-type base path.
				// context=edit is required so WordPress includes fields
				// registered with schema context ['edit'], such as
				// wpaim_seo_status. Without it the default 'view' context
				// strips those fields and they arrive as undefined.
				const fetchAllPages = async ( base ) => {
					const firstResponse = await apiFetch( {
						path: `${ base }?per_page=100&_embed=1&context=edit&page=1`,
						parse: false,
					} );
					const firstData = await firstResponse.json();
					const totalPages = parseInt(
						firstResponse.headers.get( 'X-WP-TotalPages' ) ?? '1',
						10
					);

					if ( totalPages <= 1 ) {
						return firstData;
					}

					const remainingRequests = [];
					for ( let p = 2; p <= totalPages; p++ ) {
						remainingRequests.push(
							apiFetch( {
								path: `${ base }?per_page=100&_embed=1&context=edit&page=${ p }`,
								parse: false,
							} ).then( ( r ) => r.json() )
						);
					}
					const remainingData =
						await Promise.all( remainingRequests );
					return [ firstData, ...remainingData ].flat();
				};

				const [ allPosts, allPages ] = await Promise.all( [
					fetchAllPages( '/wp/v2/posts' ),
					fetchAllPages( '/wp/v2/pages' ),
				] );

				const merged = [ ...allPosts, ...allPages ].sort(
					( a, b ) => new Date( b.modified ) - new Date( a.modified )
				);
				setPosts( merged );
			} catch ( e ) {
				setError( e.message ?? 'Failed to load posts.' );
			} finally {
				setLoading( false );
			}
		};
		fetchAll();
	}, [] );

	const handlePostUpdate = ( updatedFields ) => {
		setPosts( ( prev ) =>
			prev.map( ( p ) =>
				p.id === updatedFields.id ? { ...p, ...updatedFields } : p
			)
		);
	};

	const activeFilter =
		tabs.find( ( t ) => t.id === activeTab )?.filter ?? ( () => true );
	const filtered = posts
		.filter( activeFilter )
		.filter( ( p ) =>
			p.title.rendered.toLowerCase().includes( search.toLowerCase() )
		);

	const totalPages = Math.ceil( filtered.length / PER_PAGE );
	const visible = filtered.slice( ( page - 1 ) * PER_PAGE, page * PER_PAGE );

	const handleExpand = ( id ) => {
		setExpandedId( ( prev ) => ( prev === id ? null : id ) );
	};

	const handleTabChange = ( id ) => {
		setActiveTab( id );
		setPage( 1 );
		setExpandedId( null );
	};

	const handleSearch = ( e ) => {
		setSearch( e.target.value );
		setPage( 1 );
		setExpandedId( null );
	};

	if ( loading ) {
		return <div className="wpaim-list-loading">Loading posts…</div>;
	}
	if ( error ) {
		return <div className="wpaim-list-error">Error: { error }</div>;
	}

	return (
		<div className="wpaim-post-list">
			<div className="wpaim-list-toolbar">
				<div className="wpaim-list-tabs">
					{ tabs.map( ( tab ) => (
						<button
							key={ tab.id }
							className={ `wpaim-tab${
								activeTab === tab.id ? ' is-active' : ''
							}` }
							onClick={ () => handleTabChange( tab.id ) }
						>
							{ tab.label }
						</button>
					) ) }
				</div>
				<input
					type="search"
					className="wpaim-list-search"
					placeholder="Search posts…"
					value={ search }
					onChange={ handleSearch }
				/>
			</div>

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Title</th>
						<th style={ { width: 70 } }>Type</th>
						{ columns.map( ( col ) => (
							<th
								key={ col.label }
								style={ col.width ? { width: col.width } : {} }
							>
								{ col.label }
							</th>
						) ) }
						<th style={ { width: 90 } }>Updated</th>
						<th style={ { width: 120 } }></th>
					</tr>
				</thead>
				<tbody>
					{ visible.length === 0 && (
						<tr>
							<td
								colSpan={ 4 + columns.length }
								style={ {
									textAlign: 'center',
									color: 'var(--color-text-muted)',
									padding: '20px',
								} }
							>
								No posts found.
							</td>
						</tr>
					) }
					{ visible.map( ( post ) => (
						<PostRow
							key={ post.id }
							post={ post }
							columns={ columns }
							expanded={ expandedId === post.id }
							onExpand={ () => handleExpand( post.id ) }
							onClose={ () => setExpandedId( null ) }
							onUpdate={ handlePostUpdate }
							WorkArea={ WorkArea }
						/>
					) ) }
				</tbody>
			</table>

			{ totalPages > 1 && (
				<div className="wpaim-list-pagination">
					<span className="wpaim-list-count">
						Showing { visible.length } of { filtered.length } posts
						and pages
					</span>
					<div className="wpaim-list-page-btns">
						<button
							className="button"
							onClick={ () => setPage( ( p ) => p - 1 ) }
							disabled={ page === 1 }
						>
							← Prev
						</button>
						<button
							className="button"
							onClick={ () => setPage( ( p ) => p + 1 ) }
							disabled={ page === totalPages }
						>
							Next →
						</button>
					</div>
				</div>
			) }
		</div>
	);
}

/**
 * Single row in the post list table, with an optional expandable work area.
 *
 * @param {Object}   props
 * @param {Object}   props.post      WordPress post object from the REST API.
 * @param {Array}    props.columns   Extra column definitions: `[{ label, render }]`.
 * @param {boolean}  props.expanded  Whether the work-area row is currently open.
 * @param {Function} props.onExpand  Called when the expand/collapse button is clicked.
 * @param {Function} props.onClose   Called to close the work-area row without expanding another.
 * @param {Function} props.onUpdate  Called with partial post fields after an in-row update.
 * @param {Function} props.WorkArea  Component to render in the expanded row.
 * @return {ReactElement}
 */
function PostRow( {
	post,
	columns,
	expanded,
	onExpand,
	onClose,
	onUpdate,
	WorkArea,
} ) {
	const colSpan = 4 + columns.length;
	const updated = new Date( post.modified ).toLocaleDateString(
		navigator.language,
		{
			day: 'numeric',
			month: 'short',
		}
	);

	return (
		<>
			<tr className={ expanded ? 'is-expanded' : '' }>
				<td
					dangerouslySetInnerHTML={ {
						__html: DOMPurify.sanitize( post.title.rendered ),
					} }
				/>
				<td>
					<span className="wpaim-type-badge">{ post.type }</span>
				</td>
				{ columns.map( ( col ) => (
					<td key={ col.label }>{ col.render( post ) }</td>
				) ) }
				<td>{ updated }</td>
				<td>
					<button
						className="button button-small"
						onClick={ onExpand }
					>
						{ expanded ? '▲ Close' : 'Generate ▼' }
					</button>
				</td>
			</tr>
			{ expanded && (
				<tr className="wpaim-work-row">
					<td colSpan={ colSpan }>
						<WorkArea
							post={ post }
							onClose={ onClose }
							onUpdate={ onUpdate }
						/>
					</td>
				</tr>
			) }
		</>
	);
}
