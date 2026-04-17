export function json(
	data: unknown,
	status = 200,
	headers: HeadersInit = {}
): Response {
	return new Response( JSON.stringify( data ), {
		status,
		headers: { 'Content-Type': 'application/json', ...headers },
	} );
}

export function hex( bytes: Uint8Array ): string {
	return Array.from( bytes )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

export function yyyyMM(): string {
	const d = new Date();
	return `${ d.getFullYear() }-${ String( d.getMonth() + 1 ).padStart(
		2,
		'0'
	) }`;
}

export function nextMonthStart(): string {
	const d = new Date();
	return new Date( d.getFullYear(), d.getMonth() + 1, 1 ).toISOString();
}

export function secondsUntilMonthEnd(): number {
	const now = new Date();
	const next = new Date( now.getFullYear(), now.getMonth() + 1, 1 );
	return Math.floor( ( next.getTime() - now.getTime() ) / 1000 );
}
