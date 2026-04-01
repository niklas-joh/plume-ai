import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import DOMPurify from 'dompurify';

const PER_PAGE = 20;

export default function PostListTable( { tabs, WorkArea, columns = [] } ) {
	const [ posts, setPosts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ activeTab, setActiveTab ] = useState( tabs[ 0 ]?.id ?? 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ expandedId, setExpandedId ] = useState( null );
	const [ truncated, setTruncated ] = useState( false );

	useEffect( () => {
		const fetchAll = async () => {
			try {
				const fetchEndpoint = async ( path ) => {
					const response = await apiFetch( { path, parse: false } );
					const data = await response.json();
					const total = parseInt(
						response.headers.get( 'X-WP-Total' ) ?? '0',
						10
					);
					return { data, total };
				};

				// context=edit is required so WordPress includes fields
				// registered with schema context ['edit'], such as
				// wpaim_seo_status. Without it the default 'view' context
				// strips those fields and they arrive as undefined.
				const [ postsRes, pagesRes ] = await Promise.all( [
					fetchEndpoint(
						'/wp/v2/posts?per_page=100&_embed=1&context=edit'
					),
					fetchEndpoint(
						'/wp/v2/pages?per_page=100&_embed=1&context=edit'
					),
				] );

				const merged = [ ...postsRes.data, ...pagesRes.data ].sort(
					( a, b ) => new Date( b.modified ) - new Date( a.modified )
				);
				setPosts( merged );

				const totalFetched =
					postsRes.data.length + pagesRes.data.length;
				const totalAvailable = postsRes.total + pagesRes.total;
				if ( totalAvailable > totalFetched ) {
					setTruncated( true );
				}
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
			{ truncated && (
				<div className="wpaim-list-notice">
					⚠ Showing the 100 most recent posts and pages. Your site
					has more content that is not listed here.
				</div>
			) }
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
	const updated = new Date( post.modified ).toLocaleDateString( 'en-GB', {
		day: 'numeric',
		month: 'short',
	} );

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
