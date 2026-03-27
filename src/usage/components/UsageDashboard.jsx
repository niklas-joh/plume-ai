import { useState, useEffect } from '@wordpress/element';
import { BarChart2, Zap, DollarSign, Loader2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';

export default function UsageDashboard() {
	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/wp-ai-mind/v1/usage' } )
			.then( setData )
			.catch( ( e ) =>
				setError( e?.message || 'Failed to load usage data.' )
			)
			.finally( () => setIsLoading( false ) );
	}, [] );

	if ( isLoading ) {
		return (
			<div
				className="wpaim-usage"
				style={ { textAlign: 'center', paddingTop: 'var(--space-12)' } }
			>
				<Loader2
					size={ 32 }
					className="wpaim-spin"
					style={ { color: 'var(--wp-admin-theme-color)' } }
				/>
			</div>
		);
	}

	if ( error ) {
		return (
			<div className="wpaim-usage">
				<div className="wpaim-usage__empty">{ error }</div>
			</div>
		);
	}

	const { totals, breakdown, daily } = data;

	// Build SVG sparkline from daily data
	function buildSparkline( dailyRows ) {
		if ( ! dailyRows?.length ) {
			return null;
		}
		const values = dailyRows.map( ( r ) => parseFloat( r.cost ) || 0 );
		const max = Math.max( ...values, 0.001 );
		const w = 300,
			h = 50,
			pad = 4;
		const pts = values
			.map( ( v, i ) => {
				const x =
					pad +
					( i / Math.max( values.length - 1, 1 ) ) * ( w - pad * 2 );
				const y = h - pad - ( v / max ) * ( h - pad * 2 );
				return `${ x },${ y }`;
			} )
			.join( ' ' );
		return (
			<svg
				viewBox={ `0 0 ${ w } ${ h }` }
				className="wpaim-usage__sparkline"
				preserveAspectRatio="none"
			>
				<polyline
					points={ pts }
					fill="none"
					stroke="var(--wp-admin-theme-color)"
					strokeWidth="2"
					strokeLinejoin="round"
				/>
			</svg>
		);
	}

	return (
		<div className="wpaim-usage">
			<div className="wpaim-usage__header">
				<h1
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: 'var(--space-2)',
					} }
				>
					<BarChart2
						size={ 24 }
						style={ { color: 'var(--wp-admin-theme-color)' } }
					/>{ ' ' }
					Usage &amp; Cost
				</h1>
				<span className="wpaim-usage__period">Last 30 days</span>
			</div>

			<div className="wpaim-usage__stats">
				<div className="wpaim-usage__stat-card">
					<Zap
						size={ 20 }
						style={ {
							color: 'var(--wp-admin-theme-color)',
							marginBottom: 'var(--space-2)',
						} }
					/>
					<div className="wpaim-usage__stat-value">
						{ totals.tokens.toLocaleString() }
					</div>
					<div className="wpaim-usage__stat-label">Tokens used</div>
				</div>
				<div className="wpaim-usage__stat-card">
					<DollarSign
						size={ 20 }
						style={ {
							color: 'var(--wp-admin-theme-color)',
							marginBottom: 'var(--space-2)',
						} }
					/>
					<div className="wpaim-usage__stat-value">
						${ totals.cost_usd.toFixed( 4 ) }
					</div>
					<div className="wpaim-usage__stat-label">Total cost</div>
				</div>
				<div className="wpaim-usage__stat-card">
					<BarChart2
						size={ 20 }
						style={ {
							color: 'var(--wp-admin-theme-color)',
							marginBottom: 'var(--space-2)',
						} }
					/>
					<div className="wpaim-usage__stat-value">
						{ totals.requests.toLocaleString() }
					</div>
					<div className="wpaim-usage__stat-label">API requests</div>
				</div>
			</div>

			{ buildSparkline( daily ) }

			{ breakdown.length === 0 ? (
				<div className="wpaim-usage__empty">
					<p>
						No usage recorded yet. Start chatting to see stats here.
					</p>
				</div>
			) : (
				<table className="widefat fixed striped">
					<thead>
						<tr>
							<th>Provider</th>
							<th>Feature</th>
							<th style={ { textAlign: 'right' } }>Tokens</th>
							<th style={ { textAlign: 'right' } }>Cost (USD)</th>
							<th style={ { textAlign: 'right' } }>Requests</th>
						</tr>
					</thead>
					<tbody>
						{ breakdown.map( ( row, i ) => (
							<tr key={ i }>
								<td style={ { fontWeight: 500 } }>
									{ row.provider }
								</td>
								<td
									style={ {
										color: 'var(--color-text-muted)',
									} }
								>
									{ row.feature }
								</td>
								<td style={ { textAlign: 'right' } }>
									{ parseInt( row.tokens ).toLocaleString() }
								</td>
								<td style={ { textAlign: 'right' } }>
									${ parseFloat( row.cost ).toFixed( 4 ) }
								</td>
								<td style={ { textAlign: 'right' } }>
									{ row.requests }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
