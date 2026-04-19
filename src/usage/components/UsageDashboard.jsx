import { useState, useEffect } from '@wordpress/element';
import { BarChart2, Zap, Loader2 } from 'lucide-react';
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

	const { tier, used, limit, remaining, canUse: canUse } = data;

	const hasLimit = limit !== null && limit !== undefined;
	const usedPct = hasLimit ? Math.min( 100, ( used / limit ) * 100 ) : 0;

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
					Usage
				</h1>
				<span className="wpaim-usage__period">Tier: { tier }</span>
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
						{ used.toLocaleString() }
					</div>
					<div className="wpaim-usage__stat-label">Requests used</div>
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
						{ hasLimit ? remaining.toLocaleString() : '∞' }
					</div>
					<div className="wpaim-usage__stat-label">Remaining</div>
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
						{ hasLimit ? limit.toLocaleString() : '∞' }
					</div>
					<div className="wpaim-usage__stat-label">Monthly limit</div>
				</div>
			</div>

			{ hasLimit && (
				<div className="wpaim-usage__quota">
					<div
						className="wpaim-usage__quota-bar"
						style={ {
							background: 'var(--color-border, #ddd)',
							borderRadius: '4px',
							height: '8px',
							overflow: 'hidden',
							margin: 'var(--space-4) 0',
						} }
					>
						<div
							style={ {
								width: `${ usedPct }%`,
								height: '100%',
								background: canUse
									? 'var(--wp-admin-theme-color)'
									: '#d63638',
								transition: 'width 0.3s ease',
							} }
						/>
					</div>
					{ ! canUse && (
						<p style={ { color: '#d63638', margin: 0 } }>
							Monthly limit reached. Upgrade your plan to
							continue.
						</p>
					) }
				</div>
			) }
		</div>
	);
}
