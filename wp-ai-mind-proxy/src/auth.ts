import { hashPassword, verifyPassword } from './password';
import { signJWT, verifyJWT } from './jwt';
import { json } from './utils';
import type { Env, User } from './types';

export async function handleRegister(
	req: Request,
	env: Env
): Promise< Response > {
	const body = await req.json< { email?: string; password?: string } >();
	const email = body.email?.trim().toLowerCase();
	const password = body.password;

	if ( ! email || ! password ) {
		return json( { error: 'email and password required' }, 400 );
	}
	if ( password.length < 8 ) {
		return json( { error: 'password must be at least 8 characters' }, 400 );
	}
	if ( ! email.includes( '@' ) ) {
		return json( { error: 'invalid email address' }, 400 );
	}

	const hash = await hashPassword( password );
	const expires = new Date( Date.now() + 7 * 86_400_000 ).toISOString();

	try {
		await env.DB.prepare(
			`INSERT INTO users (email, password_hash, plan, plan_expires) VALUES (?, ?, 'trial', ?)`
		)
			.bind( email, hash, expires )
			.run();
	} catch {
		return json( { error: 'Email already registered' }, 409 );
	}

	return json(
		{ message: 'Account created. Your 7-day trial starts now.' },
		201
	);
}

export async function handleToken(
	req: Request,
	env: Env
): Promise< Response > {
	const body = await req.json< { email?: string; password?: string } >();
	const email = body.email?.trim().toLowerCase() ?? '';

	const user = await env.DB.prepare( `SELECT * FROM users WHERE email = ?` )
		.bind( email )
		.first< User >();

	if (
		! user ||
		! ( await verifyPassword( body.password ?? '', user.password_hash ) )
	) {
		await new Promise( ( r ) => setTimeout( r, 200 ) ); // Constant-time delay to prevent timing attacks.
		return json( { error: 'Invalid credentials' }, 401 );
	}

	let plan = user.plan;
	if (
		plan === 'trial' &&
		user.plan_expires &&
		new Date( user.plan_expires ) < new Date()
	) {
		plan = 'free';
		await env.DB.prepare(
			`UPDATE users SET plan = 'free', plan_expires = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?`
		)
			.bind( user.id )
			.run();
	}

	const access = await signJWT(
		{ sub: user.id, email: user.email, plan },
		env.JWT_SECRET,
		3600
	);
	const refresh = await signJWT(
		{ sub: user.id, type: 'refresh' },
		env.JWT_SECRET,
		30 * 86_400
	);

	return json( { access_token: access, refresh_token: refresh, plan } );
}

export async function handleRefresh(
	req: Request,
	env: Env
): Promise< Response > {
	const body = await req.json< { refresh_token?: string } >();
	const payload = await verifyJWT( body.refresh_token ?? '', env.JWT_SECRET );

	if ( ! payload || payload.type !== 'refresh' ) {
		return json( { error: 'Invalid or expired refresh token' }, 401 );
	}

	const user = await env.DB.prepare( `SELECT * FROM users WHERE id = ?` )
		.bind( payload.sub )
		.first< User >();
	if ( ! user ) {
		return json( { error: 'User not found' }, 404 );
	}

	const access = await signJWT(
		{ sub: user.id, email: user.email, plan: user.plan },
		env.JWT_SECRET,
		3600
	);
	return json( { access_token: access } );
}
