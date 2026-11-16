import { useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { BarChart2, Zap, Loader2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import { getErrorMessage } from '../../shared/errors';
import PageHeader from '../../shared/PageHeader';
import Screen from '../../shared/Screen';

/**
 * Admin page showing monthly credit usage stats fetched from the REST API.
 *
 * Displays used, remaining, and monthly limit counts with a proportional
 * progress bar. When no limit applies (unlimited tier), the bar is hidden
 * and remaining shows ∞. The bar turns red when the limit is exhausted.
 *
 * @return {ReactElement}
 */
export default function UsageDashboard() {
	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/plume/v1/usage' } )
			.then( setData )
			.catch( ( e ) =>
				setError(
					getErrorMessage(
						e,
						__( 'Failed to load usage data.', 'plume' )
					)
				)
			)
			.finally( () => setIsLoading( false ) );
	}, [] );

	const header = (
		<PageHeader
			title={ __( 'Usage', 'plume' ) }
			description={ __(
				'Credits used on the Plume service this month.',
				'plume'
			) }
			actions={
				data?.tier_label ? (
					<span className="plume-usage__period">
						{ data.tier_label }
					</span>
				) : null
			}
		/>
	);

	if ( isLoading ) {
		return (
			<Screen header={ header }>
				<p className="plume-usage__loading" role="status">
					<Loader2 size={ 20 } className="plume-spin" />{ ' ' }
					{ __( 'Loading usage…', 'plume' ) }
				</p>
			</Screen>
		);
	}

	if ( error ) {
		return (
			<Screen header={ header }>
				<div className="plume-usage__empty">{ error }</div>
			</Screen>
		);
	}

	const { used, limit, can_use: canUse } = data;

	const hasLimit = limit !== null && limit !== undefined;
	const usedPct = hasLimit ? Math.min( 100, ( used / limit ) * 100 ) : 0;
	// Round once so both cards derive from the same value and always sum to 100.
	const usedPctRounded = hasLimit
		? Math.min( 100, Math.round( usedPct ) )
		: 0;

	return (
		<Screen header={ header }>
			<div className="plume-usage__stats">
				<div className="plume-usage__stat-card">
					<Zap size={ 20 } className="plume-usage__stat-icon" />
					<div className="plume-usage__stat-value">
						{ hasLimit ? usedPctRounded + '%' : '∞' }
					</div>
					<div className="plume-usage__stat-label">
						{ __( 'of quota used', 'plume' ) }
					</div>
				</div>
				<div className="plume-usage__stat-card">
					<BarChart2 size={ 20 } className="plume-usage__stat-icon" />
					<div className="plume-usage__stat-value">
						{ hasLimit ? 100 - usedPctRounded + '%' : '∞' }
					</div>
					<div className="plume-usage__stat-label">
						{ __( 'remaining', 'plume' ) }
					</div>
				</div>
				<div className="plume-usage__stat-card">
					<BarChart2 size={ 20 } className="plume-usage__stat-icon" />
					<div className="plume-usage__stat-value">
						{ hasLimit ? limit.toLocaleString() : '∞' }
					</div>
					<div className="plume-usage__stat-label">
						{ __( 'Monthly limit (credits)', 'plume' ) }
					</div>
				</div>
			</div>

			{ hasLimit && (
				<div className="plume-usage__quota">
					{ /* Native <progress>: the width is its value attribute, so
					     no inline style is needed. Named and given a text value
					     for assistive technology. */ }
					<progress
						className={ `plume-progress${
							canUse ? '' : ' plume-progress--exhausted'
						}` }
						max={ 100 }
						value={ usedPct }
						aria-label={ __( 'Monthly credit usage', 'plume' ) }
					>
						{ usedPctRounded }%
					</progress>
					<p className="plume-usage__quota-text">
						{ sprintf(
							/* translators: 1: credits used, 2: monthly credit limit. */
							__( '%1$s of %2$s credits used', 'plume' ),
							used.toLocaleString(),
							limit.toLocaleString()
						) }
					</p>
					{ ! canUse && (
						<p className="plume-usage__exhausted">
							{ __(
								'Monthly limit reached. Upgrade your plan to continue.',
								'plume'
							) }
						</p>
					) }
				</div>
			) }
		</Screen>
	);
}
