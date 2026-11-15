#!/usr/bin/env node
/* eslint-disable no-console -- CLI script: stdout carries workflow commands (::warning::) and logs. */
/**
 * PR screenshots: publish, baseline and prune (review aid, never a check).
 *
 * Zero dependencies (node:* built-ins and global fetch only) so the job that
 * holds a write token runs no `npm ci`: the dependency surface stays in the
 * read-only `e2e` job that captures the PNGs.
 *
 * Subcommands:
 *   env               Print the capture environment fingerprint (JSON) to stdout.
 *   summary           Append a per-screen timing/status table to $GITHUB_STEP_SUMMARY.
 *   publish-pr        Push changed/new PNGs to the store branch, write the PR comment.
 *   publish-baseline  Replace `main/` in the store branch with this capture.
 *   prune             Delete `pr-<n>/` (PR_NUMBER set) or every closed PR's folder.
 *
 * Store branch layout (`ci-screenshots`, CI-only, never merged):
 *   main/manifest.json, main/<viewport>/<id>.png
 *   pr-<n>/<headSha>/<viewport>/<id>.png   (only screens that differ from main)
 *
 * Everything in the screenshot artefact is PR-controlled, so files are taken
 * only from an allow-list of paths, checked for PNG magic bytes and size, and
 * no file name or artefact text ever reaches the comment markdown.
 */

import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

export const STORE_BRANCH = 'ci-screenshots';
export const COMMENT_LIMIT = 60000;
export const MAX_PNG_BYTES = 8 * 1024 * 1024;
export const DEFAULT_SCREENS_FILE = 'tests/E2E/playwright/visual/screens.json';

const PNG_MAGIC = Buffer.from( [
	0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
] );
const ID_RE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const SHA_RE = /^[0-9a-f]{40}$/;
const HASH_RE = /^[0-9a-f]{64}$/;
const REPO_RE = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/;
const ENV_KEYS = [ 'runnerImage', 'fonts', 'playwright' ];
const ENV_LABELS = {
	runnerImage: 'runner image',
	fonts: 'installed fonts',
	playwright: 'Playwright version',
};
const STORE_README =
	'# ci-screenshots\n\nCI-only storage for PR screenshots (see `.github/scripts/pr-screenshots.mjs`).\nNever merge this branch and never base work on it.\n';

// ── Pure helpers (unit-tested) ──────────────────────────────────────────────

/**
 * Validates and normalises the screens.json definition.
 *
 * @param {unknown} def Parsed screens.json.
 * @return {{screens: Array<{id: string, title: string}>, viewports: Array<{id: string, title: string, label: string}>}} Definition.
 * @throws {Error} When the definition is malformed.
 */
export function normaliseScreens( def ) {
	const text = ( v ) =>
		typeof v === 'string' && v.length > 0 && v.length <= 80;
	const screens = def?.screens;
	const viewports = def?.viewports;
	if ( ! Array.isArray( screens ) || ! Array.isArray( viewports ) ) {
		throw new Error(
			'screens.json needs "screens" and "viewports" arrays'
		);
	}
	const seen = new Set();
	for ( const item of [ ...screens, ...viewports ] ) {
		if ( ! ID_RE.test( item?.id ?? '' ) || ! text( item.title ) ) {
			throw new Error(
				`screens.json: invalid entry ${ JSON.stringify( item?.id ) }`
			);
		}
	}
	for ( const s of screens ) {
		if ( seen.has( s.id ) ) {
			throw new Error( `screens.json: duplicate screen "${ s.id }"` );
		}
		seen.add( s.id );
	}
	return {
		screens: screens.map( ( { id, title } ) => ( { id, title } ) ),
		viewports: viewports.map( ( { id, title, label } ) => ( {
			id,
			title,
			label: text( label ) ? label : '',
		} ) ),
	};
}

/**
 * Checks an artefact-relative path against the allow-list.
 *
 * Only `<viewport>/<screen>.png` for ids defined in screens.json is accepted,
 * which rules out traversal (`../`), absolute paths, and stray files such as
 * `.auth.json`.
 *
 * @param {string} rel  Relative path using `/` separators.
 * @param {{screens: Array<{id: string}>, viewports: Array<{id: string}>}} def Screens definition.
 * @return {?{viewport: string, id: string, key: string}} Parsed path, or null if rejected.
 */
export function parseShotPath( rel, def ) {
	const m = /^([a-z0-9-]+)\/([a-z0-9-]+)\.png$/.exec( rel );
	if ( ! m ) {
		return null;
	}
	const [ , viewport, id ] = m;
	if (
		! def.viewports.some( ( v ) => v.id === viewport ) ||
		! def.screens.some( ( s ) => s.id === id )
	) {
		return null;
	}
	return { viewport, id, key: `${ viewport }/${ id }.png` };
}

/**
 * Whether a buffer starts with the PNG signature.
 *
 * @param {Buffer} buf File contents (or at least its first 8 bytes).
 * @return {boolean} True for a PNG.
 */
export function isPng( buf ) {
	return buf.length >= 8 && buf.subarray( 0, 8 ).equals( PNG_MAGIC );
}

/**
 * Compares the PR capture with the baseline, per screen and viewport.
 *
 * States: `changed` (hash differs), `new` (no baseline file), `unchanged`,
 * `missing` (this capture produced no file — the screen failed).
 *
 * @param {Record<string, string>}  prHashes   Map of `<viewport>/<id>.png` to sha256.
 * @param {?Record<string, string>} baseHashes Baseline map, or null when there is no baseline.
 * @param {{screens: Array<{id: string, title: string}>, viewports: Array<{id: string}>}} def Screens definition.
 * @return {Array<{id: string, title: string, cells: Record<string, string>}>} One row per screen, in screens.json order.
 */
export function diffShots( prHashes, baseHashes, def ) {
	return def.screens.map( ( { id, title } ) => {
		const cells = {};
		for ( const { id: viewport } of def.viewports ) {
			const key = `${ viewport }/${ id }.png`;
			const pr = prHashes[ key ];
			const base = baseHashes?.[ key ];
			if ( ! pr ) {
				cells[ viewport ] = 'missing';
			} else if ( ! base ) {
				cells[ viewport ] = 'new';
			} else {
				cells[ viewport ] = pr === base ? 'unchanged' : 'changed';
			}
		}
		return { id, title, cells };
	} );
}

/**
 * Whether a diff row counts as a changed screen (any viewport changed or new).
 *
 * @param {{cells: Record<string, string>}} row Diff row.
 * @return {boolean} True when the screen gets a before/after table.
 */
export function isChangedRow( row ) {
	return Object.values( row.cells ).some(
		( s ) => s === 'changed' || s === 'new'
	);
}

/**
 * Lists the files the PR must push: only changed or new screens.
 *
 * @param {Array<{id: string, cells: Record<string, string>}>} rows Diff rows.
 * @return {string[]} Keys such as `phone/chat.png`.
 */
export function filesToPush( rows ) {
	const keys = [];
	for ( const row of rows ) {
		for ( const [ viewport, state ] of Object.entries( row.cells ) ) {
			if ( state === 'changed' || state === 'new' ) {
				keys.push( `${ viewport }/${ row.id }.png` );
			}
		}
	}
	return keys;
}

/**
 * Builds a raw image URL pinned to a store commit, so later pruning or
 * baseline updates never break an image in an already-posted comment.
 *
 * @param {string} repo     `owner/name`.
 * @param {string} sha      40-character store commit SHA.
 * @param {string} filePath Path inside the store branch.
 * @return {string} URL.
 * @throws {Error} On an invalid repo, SHA or path.
 */
export function rawUrl( repo, sha, filePath ) {
	if ( ! REPO_RE.test( repo ) || ! SHA_RE.test( sha ) ) {
		throw new Error( 'rawUrl: invalid repo or sha' );
	}
	if ( ! /^[a-z0-9-]+(?:\/[a-z0-9-]+)*\.png$/.test( filePath ) ) {
		throw new Error( `rawUrl: invalid path ${ filePath }` );
	}
	return `https://github.com/${ repo }/blob/${ sha }/${ filePath }?raw=true`;
}

/**
 * Escapes text for use inside markdown table cells and HTML attributes.
 *
 * @param {string} value Text.
 * @return {string} Escaped text.
 */
export function escapeText( value ) {
	return String( value )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /\|/g, '&#124;' )
		.replace( /([\\`*_[\]])/g, '\\$1' );
}

/**
 * Escapes text for an HTML attribute value (no markdown escaping, which would
 * show up literally inside the attribute).
 *
 * @param {string} value Text.
 * @return {string} Escaped text.
 */
export function escapeAttr( value ) {
	return String( value )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /\|/g, '&#124;' );
}

/**
 * Lists the fingerprint keys that differ between baseline and PR capture.
 *
 * @param {?Object} baseEnv Baseline capture environment.
 * @param {?Object} prEnv   PR capture environment.
 * @return {string[]} Keys from ENV_KEYS that differ (empty when either side is unknown).
 */
export function envDifferences( baseEnv, prEnv ) {
	if ( ! baseEnv || ! prEnv ) {
		return [];
	}
	return ENV_KEYS.filter( ( k ) => baseEnv[ k ] !== prEnv[ k ] );
}

/**
 * Renders the sticky PR comment.
 *
 * Text overview first, then a before/after table per changed screen (desktop
 * and phone rows), then unchanged screens collapsed in a <details>. When the
 * text would exceed `limit`, the remaining changed screens are replaced by one
 * line pointing to the artefact.
 *
 * @param {Object}  args
 * @param {Array<{id: string, title: string, cells: Record<string, string>}>} args.rows Diff rows.
 * @param {{viewports: Array<{id: string, title: string, label: string}>}} args.def Screens definition.
 * @param {string}  args.repo        `owner/name`.
 * @param {string}  args.prNumber    PR number.
 * @param {string}  args.headSha     PR head SHA.
 * @param {?string} args.prStoreSha  Store commit holding this PR's PNGs (null when none were pushed).
 * @param {?string} args.baseStoreSha Store commit holding the baseline (null when there is none).
 * @param {?{sha: string, capturedAt: string}} args.baseline Baseline manifest summary.
 * @param {string[]} args.envDiff    Output of envDifferences().
 * @param {string}  args.runUrl      Workflow run URL.
 * @param {number}  [args.limit]     Character budget.
 * @return {string} Markdown.
 */
export function renderComment( {
	rows,
	def,
	repo,
	prNumber,
	headSha,
	prStoreSha,
	baseStoreSha,
	baseline,
	envDiff,
	runUrl,
	limit = COMMENT_LIMIT,
} ) {
	const short = ( sha ) =>
		SHA_RE.test( sha ?? '' ) ? sha.slice( 0, 7 ) : 'unknown';
	const run = /^https:\/\/github\.com\/[^\s()]+$/.test( runUrl )
		? runUrl
		: '';
	const runLink = run ? `[workflow run](${ run })` : 'workflow run';
	const changed = rows.filter( isChangedRow );
	const unchanged = rows.filter(
		( r ) =>
			! isChangedRow( r ) &&
			Object.values( r.cells ).includes( 'unchanged' )
	);
	const missing = [];
	for ( const row of rows ) {
		for ( const v of def.viewports ) {
			if ( row.cells[ v.id ] === 'missing' ) {
				missing.push(
					`${ escapeText( row.title ) } (${ escapeText( v.title.toLowerCase() ) })`
				);
			}
		}
	}
	const viewportText = def.viewports
		.map( ( v ) =>
			escapeText( `${ v.title.toLowerCase() } ${ v.label }`.trim() )
		)
		.join( ' and ' );
	const basePath = ( viewport, id ) => `main/${ viewport }/${ id }.png`;
	const prPath = ( viewport, id ) =>
		`pr-${ prNumber }/${ headSha }/${ viewport }/${ id }.png`;
	const baseUrl = ( viewport, id ) =>
		rawUrl( repo, baseStoreSha, basePath( viewport, id ) );
	const prUrl = ( viewport, id ) =>
		rawUrl( repo, prStoreSha, prPath( viewport, id ) );
	const img = ( url, alt, width ) =>
		`<img src="${ url }" width="${ width }" alt="${ escapeAttr( alt ) }">`;

	// ── Overview ────────────────────────────────────────────────────────────
	const head = [ '## Screenshots — review aid (not a pass/fail check)', '' ];
	if ( changed.length ) {
		head.push(
			`**${ changed.length } of ${ rows.length } screens changed:** ${ changed
				.map( ( r ) => escapeText( r.title ) )
				.join( ', ' ) }.`
		);
	} else {
		head.push(
			`**No visual changes** against \`main@${ short( baseline?.sha ) }\` (${ rows.length } screens, ${ def.viewports.length } viewports).`
		);
	}
	head.push( '' );
	head.push(
		`Captured at ${ viewportText } for \`${ short( headSha ) }\` (this PR merged into \`main\`). ` +
			( baseline
				? `Baseline: \`main@${ short( baseline.sha ) }\`, captured ${ escapeText(
						String( baseline.capturedAt ).slice( 0, 10 )
					) }. `
				: '' ) +
			`See the ${ runLink }.`
	);
	if ( ! baseline ) {
		head.push(
			'',
			'> [!NOTE]',
			'> No baseline from `main` yet, so every screen is shown without a "before". This is expected until the baseline workflow has run on `main`.'
		);
	}
	if ( envDiff.length ) {
		head.push(
			'',
			`> [!NOTE]\n> The baseline was captured with a different ${ envDiff
				.map( ( k ) => ENV_LABELS[ k ] )
				.join(
					', '
				) }, so some differences may be rendering noise rather than UI changes.`
		);
	}
	if ( missing.length ) {
		head.push(
			'',
			`> [!WARNING]\n> Could not capture: ${ missing.join( ', ' ) }. See the ${ runLink }.`
		);
	}
	head.push( '' );

	// ── Changed screens ─────────────────────────────────────────────────────
	const sections = changed.map( ( row ) => {
		const lines = [
			`### ${ escapeText( row.title ) }`,
			'',
			'| | Before (`main`) | After (this PR) |',
			'|---|---|---|',
		];
		for ( const v of def.viewports ) {
			const width = v.id === 'phone' ? 200 : 420;
			const state = row.cells[ v.id ];
			const label = `${ row.title }, ${ v.title.toLowerCase() }`;
			let before;
			let after;
			if ( state === 'changed' ) {
				before = img(
					baseUrl( v.id, row.id ),
					`${ label }, before`,
					width
				);
				after = img(
					prUrl( v.id, row.id ),
					`${ label }, after`,
					width
				);
			} else if ( state === 'new' ) {
				before = '_no baseline_';
				after = img(
					prUrl( v.id, row.id ),
					`${ label }, after`,
					width
				);
			} else if ( state === 'unchanged' ) {
				before = '_unchanged_';
				after = `[view](${ baseUrl( v.id, row.id ) })`;
			} else {
				before =
					baseStoreSha && baseline
						? `[view](${ baseUrl( v.id, row.id ) })`
						: '—';
				after = '_not captured_';
			}
			lines.push(
				`| ${ escapeText( v.title ) } | ${ before } | ${ after } |`
			);
		}
		lines.push( '' );
		return lines.join( '\n' );
	} );

	// ── Unchanged screens ───────────────────────────────────────────────────
	const details = unchanged.length
		? [
				`<details><summary>Unchanged screens (${ unchanged.length })</summary>`,
				'',
				...unchanged.map(
					( row ) =>
						`- ${ escapeText( row.title ) }: ` +
						def.viewports
							.filter(
								( v ) => row.cells[ v.id ] === 'unchanged'
							)
							.map(
								( v ) =>
									`[${ escapeText( v.title.toLowerCase() ) }](${ baseUrl( v.id, row.id ) })`
							)
							.join( ' · ' )
				),
				'',
				'</details>',
				'',
			].join( '\n' )
		: '';

	const footer =
		'<sub>Screens hide site-level admin notices and mask version strings so diffs stay stable. ' +
		'Run locally with `npm run test:e2e:screens` (see CONTRIBUTING.md). ' +
		`Images live on the CI-only \`${ STORE_BRANCH }\` branch.</sub>\n`;

	// ── Budget: one fallback line instead of the screens that do not fit ──
	const fixed = head.join( '\n' ) + '\n' + details + footer;
	const out = [];
	let used = fixed.length;
	for ( let i = 0; i < sections.length; i++ ) {
		const remaining = sections.length - i - 1;
		const reserve = remaining ? 200 : 0;
		if ( used + sections[ i ].length + reserve > limit ) {
			out.push(
				`_…and ${ sections.length - i } more changed screens. Download the \`pr-screenshots\` artefact from the ${ runLink }._\n`
			);
			break;
		}
		out.push( sections[ i ] );
		used += sections[ i ].length + 1;
	}

	return [ head.join( '\n' ), ...out, details, footer ]
		.filter( Boolean )
		.join( '\n' );
}

/**
 * Validates a capture-environment fingerprint read from untrusted JSON.
 *
 * @param {unknown} value Parsed JSON.
 * @return {?Record<string, string>} Fingerprint with only safe string values, or null.
 */
export function normaliseEnv( value ) {
	if ( ! value || typeof value !== 'object' ) {
		return null;
	}
	const out = {};
	for ( const k of ENV_KEYS ) {
		const v = value[ k ];
		out[ k ] =
			typeof v === 'string' && /^[\w.\/-]{1,80}$/.test( v )
				? v
				: 'unknown';
	}
	return out;
}

/**
 * Validates a baseline manifest read from the store branch.
 *
 * @param {unknown} value Parsed manifest.json.
 * @param {Object}  def   Screens definition.
 * @return {?{sha: string, capturedAt: string, env: ?Object, files: Record<string, string>}} Manifest, or null when unusable.
 */
export function normaliseManifest( value, def ) {
	if (
		! value ||
		typeof value !== 'object' ||
		typeof value.files !== 'object'
	) {
		return null;
	}
	const files = {};
	for ( const [ key, hash ] of Object.entries( value.files ?? {} ) ) {
		if ( parseShotPath( key, def ) && HASH_RE.test( String( hash ) ) ) {
			files[ key ] = hash;
		}
	}
	return {
		sha: SHA_RE.test( value.sha ?? '' ) ? value.sha : '',
		capturedAt: /^\d{4}-\d{2}-\d{2}T[\d:.]+Z$/.test(
			value.capturedAt ?? ''
		)
			? value.capturedAt
			: '',
		env: normaliseEnv( value.env ),
		files,
	};
}

/**
 * Extracts per-screen results from Playwright's JSON reporter output.
 *
 * @param {Object} report Parsed results.json.
 * @return {Array<{project: string, id: string, status: string, durationMs: number, error: string}>} Results.
 */
export function readResults( report ) {
	const out = [];
	const walk = ( suite ) => {
		for ( const spec of suite.specs ?? [] ) {
			for ( const t of spec.tests ?? [] ) {
				const last = ( t.results ?? [] ).at( -1 ) ?? {};
				const message = String(
					last.error?.message ?? last.errors?.[ 0 ]?.message ?? ''
				)
					.replace( /\u001b\[[0-9;]*m/g, '' )
					.split( '\n' )[ 0 ]
					.slice( 0, 200 );
				out.push( {
					project: String( t.projectName ?? '' ),
					id: String( spec.title ?? '' ),
					status: String( last.status ?? t.status ?? 'unknown' ),
					durationMs: Number( last.duration ?? 0 ),
					error: message,
				} );
			}
		}
		for ( const child of suite.suites ?? [] ) {
			walk( child );
		}
	};
	for ( const suite of report?.suites ?? [] ) {
		walk( suite );
	}
	return out;
}

/**
 * Renders the step-summary table for the capture step.
 *
 * @param {ReturnType<typeof readResults>} results Per-screen results.
 * @return {string} Markdown.
 */
export function renderSummary( results ) {
	const total = results.reduce( ( n, r ) => n + r.durationMs, 0 );
	const failed = results.filter( ( r ) => r.status !== 'passed' );
	const lines = [
		'### PR screenshots — capture',
		'',
		`${ results.length - failed.length } of ${ results.length } shots captured; ` +
			`summed test time ${ ( total / 1000 ).toFixed( 1 ) }s (tests run in parallel).`,
		'',
		'| Viewport | Screen | Status | Time | Error |',
		'|---|---|---|---|---|',
		...results.map(
			( r ) =>
				`| ${ escapeText( r.project ) } | ${ escapeText( r.id ) } | ${ r.status === 'passed' ? 'ok' : escapeText( r.status ) } | ${ (
					r.durationMs / 1000
				).toFixed( 1 ) }s | ${ escapeText( r.error ) } |`
		),
		'',
	];
	return lines.join( '\n' );
}

// ── I/O helpers ─────────────────────────────────────────────────────────────

/**
 * Hashes every allow-listed PNG in a capture directory.
 *
 * Symlinks, oversize files, non-PNGs and paths outside the allow-list are
 * skipped and counted.
 *
 * @param {string} dir Capture directory.
 * @param {Object} def Screens definition.
 * @return {{hashes: Record<string, string>, rejected: number}} Result.
 */
export function collectShots( dir, def ) {
	const hashes = {};
	let rejected = 0;
	const walk = ( abs, rel ) => {
		let entries = [];
		try {
			entries = fs.readdirSync( abs, { withFileTypes: true } );
		} catch {
			return;
		}
		for ( const e of entries ) {
			const childAbs = path.join( abs, e.name );
			const childRel = rel ? `${ rel }/${ e.name }` : e.name;
			if ( e.isDirectory() ) {
				walk( childAbs, childRel );
				continue;
			}
			if ( ! e.isFile() || ! childRel.endsWith( '.png' ) ) {
				continue;
			}
			const parsed = parseShotPath( childRel, def );
			const size = fs.statSync( childAbs ).size;
			if ( ! parsed || size > MAX_PNG_BYTES ) {
				rejected++;
				continue;
			}
			const buf = fs.readFileSync( childAbs );
			if ( ! isPng( buf ) ) {
				rejected++;
				continue;
			}
			hashes[ parsed.key ] = createHash( 'sha256' )
				.update( buf )
				.digest( 'hex' );
		}
	};
	walk( dir, '' );
	return { hashes, rejected };
}

/**
 * Reads and validates a JSON file, returning null when absent or invalid.
 *
 * @param {string} file Path.
 * @return {unknown} Parsed JSON or null.
 */
function readJson( file ) {
	try {
		return JSON.parse( fs.readFileSync( file, 'utf8' ) );
	} catch {
		return null;
	}
}

/**
 * Runs git and returns its result; throws on failure unless `allowFail`.
 *
 * @param {string}   cwd  Working directory.
 * @param {string[]} args git arguments.
 * @param {Object}   [opts]
 * @param {Object}   [opts.env]       Extra environment.
 * @param {boolean}  [opts.allowFail] Return the failed result instead of throwing.
 * @return {{status: number, stdout: string, stderr: string}} Result.
 */
function git( cwd, args, { env = {}, allowFail = false } = {} ) {
	const res = spawnSync( 'git', args, {
		cwd,
		encoding: 'utf8',
		env: { ...process.env, GIT_TERMINAL_PROMPT: '0', ...env },
	} );
	if ( res.status !== 0 && ! allowFail ) {
		throw new Error(
			`git ${ args[ 0 ] } failed: ${ ( res.stderr || res.stdout || '' ).trim().split( '\n' ).pop() }`
		);
	}
	return {
		status: res.status ?? 1,
		stdout: res.stdout ?? '',
		stderr: res.stderr ?? '',
	};
}

/**
 * Sleeps for a number of milliseconds.
 *
 * @param {number} ms Milliseconds.
 * @return {Promise<void>}
 */
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/**
 * Opens the store branch in a fresh, standalone clone-like repository.
 *
 * A standalone `git init` (rather than a worktree of the job's checkout) keeps
 * the job's sparse-checkout settings and persisted credentials out of the
 * store. Auth is passed per process through GIT_CONFIG_* env vars, never in
 * argv or a config file.
 *
 * @param {Object}  args
 * @param {string}  args.dir     Directory to (re)create.
 * @param {string}  args.remote  Remote URL (GitHub HTTPS, or a local path in tests).
 * @param {string}  [args.token] GitHub token for HTTPS remotes.
 * @return {Object} Store handle: `{ dir, exists, head, read(rel), commitAndPush(apply, message) }`.
 */
export function openStore( { dir, remote, token = '' } ) {
	fs.rmSync( dir, { recursive: true, force: true } );
	fs.mkdirSync( dir, { recursive: true } );
	const env = {};
	if ( token && remote.startsWith( 'https://github.com/' ) ) {
		env.GIT_CONFIG_COUNT = '1';
		env.GIT_CONFIG_KEY_0 = 'http.https://github.com/.extraheader';
		env.GIT_CONFIG_VALUE_0 = `AUTHORIZATION: basic ${ Buffer.from(
			`x-access-token:${ token }`
		).toString( 'base64' ) }`;
	}
	const run = ( args, opts = {} ) => git( dir, args, { env, ...opts } );

	run( [ 'init', '-q' ] );
	run( [ 'remote', 'add', 'origin', remote ] );
	const probe = run(
		[
			'ls-remote',
			'--exit-code',
			'--heads',
			'origin',
			`refs/heads/${ STORE_BRANCH }`,
		],
		{ allowFail: true }
	);
	if ( probe.status !== 0 && probe.status !== 2 ) {
		throw new Error(
			`cannot reach the store remote: ${ probe.stderr.trim().split( '\n' ).pop() }`
		);
	}

	const store = { dir, exists: probe.status === 0, head: null };

	const sync = () => {
		run( [ 'fetch', '-q', '--depth=1', 'origin', STORE_BRANCH ] );
		run( [ 'checkout', '-q', '-B', 'store', 'FETCH_HEAD' ] );
		run( [ 'reset', '-q', '--hard', 'FETCH_HEAD' ] );
		run( [ 'clean', '-qfdx' ] );
		store.head = run( [ 'rev-parse', 'HEAD' ] ).stdout.trim();
	};
	if ( store.exists ) {
		sync();
	} else {
		run( [ 'symbolic-ref', 'HEAD', 'refs/heads/store' ] );
	}

	/**
	 * Reads a JSON file from the store's working tree.
	 *
	 * @param {string} rel Store-relative path.
	 * @return {unknown} Parsed JSON or null.
	 */
	store.read = ( rel ) => readJson( path.join( dir, rel ) );

	/**
	 * Applies a change, commits and pushes, re-applying on a rejected push.
	 *
	 * Re-apply rather than rebase: shallow rebases are fragile, and every
	 * writer owns disjoint paths (`main/`, `pr-<n>/`), so re-running an
	 * idempotent `apply` on the newer tip gives the same result. Never forces.
	 *
	 * @param {(dir: string) => void} apply   Idempotent change to the working tree.
	 * @param {string}                message Commit message.
	 * @param {Object}                [opts]
	 * @param {number}                [opts.attempts] Push attempts.
	 * @param {number}                [opts.baseDelayMs] First backoff delay.
	 * @return {Promise<?string>} Store commit SHA after the push (or the unchanged head; null if the branch is still empty).
	 */
	store.commitAndPush = async (
		apply,
		message,
		{ attempts = 5, baseDelayMs = 2000 } = {}
	) => {
		for ( let attempt = 1; attempt <= attempts; attempt++ ) {
			apply( dir );
			fs.writeFileSync( path.join( dir, 'README.md' ), STORE_README );
			run( [ 'add', '-A' ] );
			const staged = run( [ 'diff', '--cached', '--quiet' ], {
				allowFail: true,
			} );
			if ( staged.status === 0 && store.head ) {
				return store.head;
			}
			if ( staged.status === 0 && ! store.exists ) {
				return null;
			}
			run( [
				'-c',
				'user.name=github-actions[bot]',
				'-c',
				'user.email=41898282+github-actions[bot]@users.noreply.github.com',
				'commit',
				'-q',
				'--no-verify',
				'-m',
				message,
			] );
			const push = run(
				[ 'push', '-q', 'origin', `HEAD:refs/heads/${ STORE_BRANCH }` ],
				{
					allowFail: true,
				}
			);
			if ( push.status === 0 ) {
				store.exists = true;
				store.head = run( [ 'rev-parse', 'HEAD' ] ).stdout.trim();
				return store.head;
			}
			if ( attempt === attempts ) {
				throw new Error(
					`push rejected after ${ attempts } attempts: ${ push.stderr.trim().split( '\n' ).pop() }`
				);
			}
			const delay = Math.min(
				baseDelayMs * 2 ** ( attempt - 1 ),
				baseDelayMs * 4
			);
			await sleep(
				delay + Math.floor( Math.random() * ( baseDelayMs / 2 ) )
			);
			// Someone else pushed (or created the branch): start again from the tip.
			store.exists = true;
			sync();
		}
		return store.head;
	};

	return store;
}

/**
 * Calls the GitHub REST API.
 *
 * @param {string} token GitHub token.
 * @param {string} route Path such as `/repos/o/r/pulls/1`.
 * @return {Promise<{data: Object, headers: Headers}>} Parsed response.
 * @throws {Error} On a non-2xx response.
 */
async function githubApi( token, route ) {
	const res = await fetch( `https://api.github.com${ route }`, {
		headers: {
			Accept: 'application/vnd.github+json',
			Authorization: `Bearer ${ token }`,
			'X-GitHub-Api-Version': '2022-11-28',
			'User-Agent': 'pr-screenshots-script',
		},
	} );
	if ( ! res.ok ) {
		throw new Error( `GitHub API ${ route } returned ${ res.status }` );
	}
	return { data: await res.json(), headers: res.headers };
}

/**
 * Reads required environment variables, validating their format.
 *
 * @param {Record<string, RegExp>} spec Variable name to pattern.
 * @return {Record<string, string>} Values.
 * @throws {Error} When a variable is missing or malformed.
 */
function requireEnv( spec ) {
	const out = {};
	for ( const [ name, re ] of Object.entries( spec ) ) {
		const value = process.env[ name ] ?? '';
		if ( ! re.test( value ) ) {
			throw new Error( `${ name } is missing or malformed` );
		}
		out[ name ] = value;
	}
	return out;
}

/**
 * Appends `key=value` lines to $GITHUB_OUTPUT (or prints them locally).
 *
 * @param {Record<string, string|number>} values Outputs.
 */
function setOutputs( values ) {
	const lines = Object.entries( values ).map(
		( [ k, v ] ) => `${ k }=${ v }`
	);
	if ( process.env.GITHUB_OUTPUT ) {
		fs.appendFileSync(
			process.env.GITHUB_OUTPUT,
			lines.join( '\n' ) + '\n'
		);
	} else {
		console.log( lines.join( '\n' ) );
	}
}

/**
 * Loads screens.json from the checkout.
 *
 * @return {ReturnType<typeof normaliseScreens>} Definition.
 */
function loadScreens() {
	const file = process.env.SCREENS_FILE || DEFAULT_SCREENS_FILE;
	return normaliseScreens( JSON.parse( fs.readFileSync( file, 'utf8' ) ) );
}

/**
 * Store remote and directory for this run.
 *
 * @param {string} repo `owner/name`.
 * @return {{remote: string, dir: string}} Location.
 */
function storeLocation( repo ) {
	return {
		remote: process.env.STORE_REMOTE || `https://github.com/${ repo }.git`,
		dir: path.resolve(
			process.env.STORE_DIR || '.artifacts/ci-screenshots-store'
		),
	};
}

// ── Subcommands ─────────────────────────────────────────────────────────────

/** Prints the capture environment fingerprint, recorded next to the PNGs. */
function cmdEnv() {
	let fonts = 'unknown';
	const fc = spawnSync( 'fc-list', [ '--format', '%{file}\\n' ], {
		encoding: 'utf8',
	} );
	if ( fc.status === 0 ) {
		const list = fc.stdout
			.split( '\n' )
			.filter( Boolean )
			.sort()
			.join( '\n' );
		fonts = createHash( 'sha256' )
			.update( list )
			.digest( 'hex' )
			.slice( 0, 16 );
	}
	const pw = readJson( 'node_modules/@playwright/test/package.json' );
	const image = [ process.env.ImageOS, process.env.ImageVersion ]
		.filter( Boolean )
		.join( '-' );
	process.stdout.write(
		JSON.stringify(
			normaliseEnv( {
				runnerImage: image || 'local',
				fonts,
				playwright: pw?.version ?? 'unknown',
			} )
		) + '\n'
	);
}

/** Writes the per-screen capture table to the step summary and warns per failure. */
function cmdSummary() {
	const dir = process.env.SHOTS_DIR || '.artifacts/screenshots/ci';
	const report = readJson( path.join( dir, 'results.json' ) );
	if ( ! report ) {
		console.log(
			'::warning::PR screenshots: no results.json — the capture did not run.'
		);
		return;
	}
	const results = readResults( report );
	for ( const r of results.filter( ( x ) => x.status !== 'passed' ) ) {
		console.log(
			`::warning::PR screenshot "${ r.id }" (${ r.project }) not captured: ${ r.error || r.status }`
		);
	}
	const md = renderSummary( results );
	if ( process.env.GITHUB_STEP_SUMMARY ) {
		fs.appendFileSync( process.env.GITHUB_STEP_SUMMARY, md );
	} else {
		console.log( md );
	}
}

/** Publishes changed screens for a PR and writes the comment. */
async function cmdPublishPr() {
	const env = requireEnv( {
		REPO: REPO_RE,
		PR_NUMBER: /^\d{1,7}$/,
		HEAD_SHA: SHA_RE,
		GITHUB_TOKEN: /^\S+$/,
		RUN_URL: /^https:\/\/github\.com\/\S+$/,
	} );
	// A newer push supersedes this run; its own run will comment.
	const { data: pr } = await githubApi(
		env.GITHUB_TOKEN,
		`/repos/${ env.REPO }/pulls/${ env.PR_NUMBER }`
	);
	if ( pr?.head?.sha !== env.HEAD_SHA ) {
		console.log( 'PR head moved on since this run started; skipping.' );
		setOutputs( { skip: 'true', changed_count: 0 } );
		return;
	}

	const shotsDir = process.env.SHOTS_DIR || '.artifacts/screenshots/ci';
	const commentPath = path.resolve(
		process.env.COMMENT_PATH ||
			'.artifacts/reports/pr-screenshots-comment.md'
	);
	const def = loadScreens();
	const { hashes, rejected } = collectShots( shotsDir, def );
	if ( rejected ) {
		console.log(
			`::warning::PR screenshots: ignored ${ rejected } file(s) outside the allow-list.`
		);
	}
	if ( ! Object.keys( hashes ).length ) {
		throw new Error( 'no screenshots in the artefact' );
	}
	const prEnv = normaliseEnv( readJson( path.join( shotsDir, 'env.json' ) ) );

	const store = openStore( {
		...storeLocation( env.REPO ),
		token: env.GITHUB_TOKEN,
	} );
	const baseline = store.exists
		? normaliseManifest( store.read( 'main/manifest.json' ), def )
		: null;
	const baseStoreSha = baseline ? store.head : null;
	const rows = diffShots( hashes, baseline?.files ?? null, def );
	const toPush = filesToPush( rows );
	const prDir = `pr-${ env.PR_NUMBER }`;

	const prStoreSha = await store.commitAndPush(
		( dir ) => {
			// Older head-SHA folders go; comments pointing at them stay valid
			// because their URLs are pinned to older store commits.
			fs.rmSync( path.join( dir, prDir ), {
				recursive: true,
				force: true,
			} );
			for ( const key of toPush ) {
				const target = path.join( dir, prDir, env.HEAD_SHA, key );
				fs.mkdirSync( path.dirname( target ), { recursive: true } );
				fs.copyFileSync( path.join( shotsDir, key ), target );
			}
		},
		`PR #${ env.PR_NUMBER } @ ${ env.HEAD_SHA.slice( 0, 7 ) }: ${ toPush.length } screenshot(s)`
	);

	const markdown = renderComment( {
		rows,
		def,
		repo: env.REPO,
		prNumber: env.PR_NUMBER,
		headSha: env.HEAD_SHA,
		prStoreSha,
		baseStoreSha,
		baseline,
		envDiff: envDifferences( baseline?.env, prEnv ),
		runUrl: env.RUN_URL,
	} );
	fs.mkdirSync( path.dirname( commentPath ), { recursive: true } );
	fs.writeFileSync( commentPath, markdown );

	const changedCount = rows.filter( isChangedRow ).length;
	console.log(
		`PR screenshots: ${ changedCount } changed screen(s), ${ toPush.length } file(s) pushed.`
	);
	setOutputs( {
		skip: 'false',
		changed_count: changedCount,
		comment_path: commentPath,
	} );
}

/** Replaces the baseline in the store with this capture (on `main`). */
async function cmdPublishBaseline() {
	const env = requireEnv( {
		REPO: REPO_RE,
		MAIN_SHA: SHA_RE,
		GITHUB_TOKEN: /^\S+$/,
	} );
	const shotsDir = process.env.SHOTS_DIR || '.artifacts/screenshots/ci';
	const def = loadScreens();
	const { hashes } = collectShots( shotsDir, def );
	if ( ! Object.keys( hashes ).length ) {
		throw new Error( 'no screenshots captured' );
	}
	const captureEnv = normaliseEnv(
		readJson( path.join( shotsDir, 'env.json' ) )
	);
	const store = openStore( {
		...storeLocation( env.REPO ),
		token: env.GITHUB_TOKEN,
	} );

	const sha = await store.commitAndPush(
		( dir ) => {
			const old = normaliseManifest(
				readJson( path.join( dir, 'main/manifest.json' ) ),
				def
			);
			const files = {};
			for ( const { id } of def.screens ) {
				for ( const { id: viewport } of def.viewports ) {
					const key = `${ viewport }/${ id }.png`;
					if ( hashes[ key ] ) {
						files[ key ] = hashes[ key ];
					} else if ( old?.files[ key ] ) {
						// A failed shot keeps its previous baseline instead of making
						// every later PR show the screen as new.
						console.log(
							`::warning::Baseline: ${ key } not captured; keeping the previous one.`
						);
						files[ key ] = old.files[ key ];
					}
				}
			}
			const same =
				old &&
				JSON.stringify( old.files ) === JSON.stringify( files ) &&
				JSON.stringify( old.env ) === JSON.stringify( captureEnv );
			if ( same ) {
				return; // Nothing visual changed: no commit, keep the old manifest.
			}
			const mainDir = path.join( dir, 'main' );
			for ( const vp of fs.existsSync( mainDir )
				? fs.readdirSync( mainDir )
				: [] ) {
				const vpDir = path.join( mainDir, vp );
				if ( ! fs.statSync( vpDir ).isDirectory() ) {
					continue;
				}
				for ( const f of fs.readdirSync( vpDir ) ) {
					if ( ! files[ `${ vp }/${ f }` ] ) {
						fs.rmSync( path.join( vpDir, f ), { force: true } );
					}
				}
			}
			for ( const key of Object.keys( hashes ) ) {
				const target = path.join( mainDir, key );
				fs.mkdirSync( path.dirname( target ), { recursive: true } );
				fs.copyFileSync( path.join( shotsDir, key ), target );
			}
			fs.writeFileSync(
				path.join( mainDir, 'manifest.json' ),
				JSON.stringify(
					{
						sha: env.MAIN_SHA,
						capturedAt: new Date().toISOString(),
						env: captureEnv,
						files,
					},
					null,
					2
				) + '\n'
			);
		},
		`Baseline from main @ ${ env.MAIN_SHA.slice( 0, 7 ) }`
	);
	console.log( `Baseline store commit: ${ sha }` );
}

/** Deletes PR folders from the store: one PR, or every PR that is no longer open. */
async function cmdPrune() {
	const env = requireEnv( { REPO: REPO_RE, GITHUB_TOKEN: /^\S+$/ } );
	const single = process.env.PR_NUMBER ?? '';
	if ( single && ! /^\d{1,7}$/.test( single ) ) {
		throw new Error( 'PR_NUMBER is malformed' );
	}
	const store = openStore( {
		...storeLocation( env.REPO ),
		token: env.GITHUB_TOKEN,
	} );
	if ( ! store.exists ) {
		console.log( 'No store branch yet; nothing to prune.' );
		return;
	}

	let targets;
	if ( single ) {
		targets = [ `pr-${ single }` ];
	} else {
		const open = new Set();
		for ( let page = 1; page <= 50; page++ ) {
			const { data } = await githubApi(
				env.GITHUB_TOKEN,
				`/repos/${ env.REPO }/pulls?state=open&per_page=100&page=${ page }`
			);
			for ( const p of data ) {
				open.add( `pr-${ p.number }` );
			}
			if ( data.length < 100 ) {
				break;
			}
		}
		targets = fs
			.readdirSync( store.dir )
			.filter( ( d ) => /^pr-\d+$/.test( d ) && ! open.has( d ) );
	}

	const sha = await store.commitAndPush(
		( dir ) => {
			for ( const t of targets ) {
				fs.rmSync( path.join( dir, t ), {
					recursive: true,
					force: true,
				} );
			}
		},
		`Prune ${ targets.length ? targets.join( ', ' ) : 'nothing' }`
	);
	console.log( `Pruned ${ targets.length } folder(s); store at ${ sha }.` );
}

// ── Entry point ─────────────────────────────────────────────────────────────

const COMMANDS = {
	env: cmdEnv,
	summary: cmdSummary,
	'publish-pr': cmdPublishPr,
	'publish-baseline': cmdPublishBaseline,
	prune: cmdPrune,
};

/**
 * Runs a subcommand. Never exits non-zero: this is a review aid, so failures
 * become a workflow warning (and `skip=true` for publish-pr) instead.
 *
 * @param {string} name Subcommand.
 * @return {Promise<void>}
 */
export async function main( name ) {
	const command = COMMANDS[ name ];
	if ( ! command ) {
		console.log( `::warning::pr-screenshots: unknown command "${ name }"` );
		return;
	}
	try {
		await command();
	} catch ( err ) {
		console.log(
			`::warning::PR screenshots (${ name }) not published: ${ err.message }`
		);
		if ( name === 'publish-pr' ) {
			setOutputs( { skip: 'true', changed_count: 0 } );
		}
	}
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	await main( process.argv[ 2 ] ?? '' );
}
