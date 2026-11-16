// Unit tests for pr-screenshots.mjs. Run: node --test .github/scripts/pr-screenshots.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import {
	normaliseScreens,
	parseShotPath,
	isPng,
	diffShots,
	isChangedRow,
	filesToPush,
	rawUrl,
	escapeText,
	envDifferences,
	renderComment,
	normaliseEnv,
	normaliseManifest,
	readResults,
	renderSummary,
	collectShots,
	openStore,
	MAX_PNG_BYTES,
	STORE_BRANCH,
} from './pr-screenshots.mjs';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const REAL_DEF = normaliseScreens(
	JSON.parse(
		fs.readFileSync(
			path.join( here, '../../tests/E2E/playwright/visual/screens.json' ),
			'utf8'
		)
	)
);
const DEF = normaliseScreens( {
	viewports: [
		{ id: 'desktop', title: 'Desktop', label: '1440×900' },
		{ id: 'phone', title: 'Phone', label: '390×844 @2×' },
	],
	screens: [
		{ id: 'dashboard', title: 'Dashboard' },
		{ id: 'chat', title: 'Chat' },
		{ id: 'chat-reply', title: 'Chat — reply' },
	],
} );
const SHA_A = 'a'.repeat( 40 );
const SHA_B = 'b'.repeat( 40 );
const HEAD = 'c'.repeat( 40 );
const H1 = '1'.repeat( 64 );
const H2 = '2'.repeat( 64 );
const PNG = Buffer.concat( [
	Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] ),
	Buffer.from( 'rest-of-png' ),
] );
const RUN = 'https://github.com/o/r/actions/runs/1';

const tmp = () => fs.mkdtempSync( path.join( os.tmpdir(), 'pr-shots-' ) );

test( 'the committed screens.json is valid and has the approved 11 screens', () => {
	assert.deepEqual(
		REAL_DEF.screens.map( ( s ) => s.id ),
		[
			'dashboard',
			'chat',
			'chat-reply',
			'chat-error',
			'chat-out-of-credits',
			'generator',
			'seo',
			'images',
			'usage',
			'settings',
			'editor-sidebar',
		]
	);
	assert.deepEqual(
		REAL_DEF.viewports.map( ( v ) => v.id ),
		[ 'desktop', 'phone' ]
	);
} );

test( 'normaliseScreens rejects bad ids and duplicates', () => {
	assert.throws( () => normaliseScreens( { screens: [], viewports: null } ) );
	assert.throws( () =>
		normaliseScreens( {
			viewports: [],
			screens: [ { id: '../x', title: 'X' } ],
		} )
	);
	assert.throws( () =>
		normaliseScreens( {
			viewports: [],
			screens: [
				{ id: 'a', title: 'A' },
				{ id: 'a', title: 'A again' },
			],
		} )
	);
} );

test( 'parseShotPath accepts only allow-listed viewport/screen PNGs', () => {
	assert.deepEqual( parseShotPath( 'phone/chat.png', DEF ), {
		viewport: 'phone',
		id: 'chat',
		key: 'phone/chat.png',
	} );
	for ( const bad of [
		'../phone/chat.png',
		'phone/../chat.png',
		'/phone/chat.png',
		'phone/chat.PNG',
		'phone/chat.png.exe',
		'phone/unknown.png',
		'tablet/chat.png',
		'.auth.json',
		'results.json',
		'phone/sub/chat.png',
		'phone\\chat.png',
	] ) {
		assert.equal( parseShotPath( bad, DEF ), null, bad );
	}
} );

test( 'isPng checks the signature', () => {
	assert.equal( isPng( PNG ), true );
	assert.equal( isPng( Buffer.from( 'GIF89a....' ) ), false );
	assert.equal( isPng( Buffer.alloc( 3 ) ), false );
} );

test( 'diffShots classifies changed, new, unchanged and missing', () => {
	const rows = diffShots(
		{
			'desktop/dashboard.png': H1,
			'phone/dashboard.png': H1,
			'desktop/chat.png': H2,
			'phone/chat.png': H1,
			'desktop/chat-reply.png': H1,
		},
		{
			'desktop/dashboard.png': H1,
			'phone/dashboard.png': H1,
			'desktop/chat.png': H1,
			'phone/chat.png': H1,
		},
		DEF
	);
	assert.deepEqual(
		rows.map( ( r ) => [ r.id, r.cells ] ),
		[
			[ 'dashboard', { desktop: 'unchanged', phone: 'unchanged' } ],
			[ 'chat', { desktop: 'changed', phone: 'unchanged' } ],
			[ 'chat-reply', { desktop: 'new', phone: 'missing' } ],
		]
	);
	assert.deepEqual( rows.map( isChangedRow ), [ false, true, true ] );
	assert.deepEqual( filesToPush( rows ), [
		'desktop/chat.png',
		'desktop/chat-reply.png',
	] );
} );

test( 'diffShots without a baseline marks every captured screen new', () => {
	const rows = diffShots( { 'desktop/chat.png': H1 }, null, DEF );
	assert.equal( rows[ 1 ].cells.desktop, 'new' );
	assert.equal( rows[ 1 ].cells.phone, 'missing' );
} );

test( 'rawUrl pins to a commit and validates input', () => {
	assert.equal(
		rawUrl( 'o/r', SHA_A, 'main/phone/chat.png' ),
		`https://github.com/o/r/blob/${ SHA_A }/main/phone/chat.png?raw=true`
	);
	assert.throws( () => rawUrl( 'o/r', 'main', 'main/phone/chat.png' ) );
	assert.throws( () => rawUrl( 'o/r"x', SHA_A, 'main/phone/chat.png' ) );
	assert.throws( () => rawUrl( 'o/r', SHA_A, '../main/phone/chat.png' ) );
	assert.throws( () => rawUrl( 'o/r', SHA_A, 'main/phone/chat.png?x' ) );
} );

test( 'escapeText neutralises HTML and markdown', () => {
	assert.equal(
		escapeText( '<img src=x> | [a](b) *c*' ),
		'&lt;img src=x&gt; &#124; \\[a\\](b) \\*c\\*'
	);
} );

test( 'normaliseEnv and envDifferences', () => {
	const a = normaliseEnv( {
		runnerImage: 'ubuntu24-20260101.1',
		fonts: 'abc',
		playwright: '1.63.0',
	} );
	const b = normaliseEnv( {
		runnerImage: 'ubuntu24-20260201.1',
		fonts: 'abc',
		playwright: '1.63.0',
		extra: 'x',
	} );
	assert.deepEqual( Object.keys( b ), [
		'runnerImage',
		'fonts',
		'playwright',
	] );
	assert.equal(
		normaliseEnv( { runnerImage: '<script>' } ).runnerImage,
		'unknown'
	);
	assert.deepEqual( envDifferences( a, b ), [ 'runnerImage' ] );
	assert.deepEqual( envDifferences( null, b ), [] );
} );

test( 'normaliseManifest drops entries outside the allow-list', () => {
	const m = normaliseManifest(
		{
			sha: SHA_A,
			capturedAt: '2026-01-15T10:00:00.000Z',
			files: {
				'desktop/chat.png': H1,
				'../x.png': H1,
				'phone/chat.png': 'nothex',
			},
		},
		DEF
	);
	assert.deepEqual( m.files, { 'desktop/chat.png': H1 } );
	assert.equal( m.sha, SHA_A );
	assert.equal( normaliseManifest( null, DEF ), null );
} );

/**
 * Renders the comment for the standard test fixture.
 *
 * @param {Object} overrides renderComment() overrides.
 * @return {string} Markdown.
 */
function render( overrides = {} ) {
	const rows = diffShots(
		{
			'desktop/dashboard.png': H1,
			'phone/dashboard.png': H1,
			'desktop/chat.png': H2,
			'phone/chat.png': H1,
			'desktop/chat-reply.png': H1,
		},
		{
			'desktop/dashboard.png': H1,
			'phone/dashboard.png': H1,
			'desktop/chat.png': H1,
			'phone/chat.png': H1,
			'phone/chat-reply.png': H1,
		},
		DEF
	);
	return renderComment( {
		rows,
		def: DEF,
		repo: 'o/r',
		prNumber: '42',
		headSha: HEAD,
		prStoreSha: SHA_B,
		baseStoreSha: SHA_A,
		baseline: { sha: SHA_A, capturedAt: '2026-01-15T10:00:00.000Z' },
		envDiff: [],
		runUrl: RUN,
		...overrides,
	} );
}

test( 'renderComment: text overview, then before/after per changed screen, unchanged collapsed', () => {
	const md = render();
	const overview = md.indexOf(
		'**2 of 3 screens changed:** Chat, Chat — reply.'
	);
	const chat = md.indexOf( '### Chat\n' );
	const reply = md.indexOf( '### Chat — reply' );
	const details = md.indexOf(
		'<details><summary>Unchanged screens (1)</summary>'
	);
	assert.ok(
		overview > 0 && overview < chat && chat < reply && reply < details,
		md
	);
	// Changed desktop row: before from main, after from the PR folder.
	assert.match(
		md,
		new RegExp(
			`\\| Desktop \\| <img src="https://github.com/o/r/blob/${ SHA_A }/main/desktop/chat.png\\?raw=true" width="420" alt="Chat, desktop, before"> \\| <img src="https://github.com/o/r/blob/${ SHA_B }/pr-42/${ HEAD }/desktop/chat.png\\?raw=true" width="420"`
		)
	);
	// Unchanged phone row of a changed screen links to the baseline only.
	assert.match(
		md,
		/\| Phone \| _unchanged_ \| \[view\]\(https:\/\/github.com\/o\/r\/blob\/a+\/main\/phone\/chat.png\?raw=true\) \|/
	);
	// New screen and a failed capture.
	assert.match( md, /\| Desktop \| _no baseline_ \| <img/ );
	assert.match( md, /\| Phone \| \[view\]\(.*\) \| _not captured_ \|/ );
	assert.match( md, /Could not capture: Chat — reply \(phone\)/ );
	assert.match(
		md,
		/- Dashboard: \[desktop\]\(.*main\/desktop\/dashboard.png\?raw=true\) · \[phone\]\(/
	);
	assert.ok( ! md.includes( 'No baseline from' ) );
	assert.ok( md.length < 60000 );
} );

test( 'renderComment: no baseline note, env note, and no-change summary', () => {
	const rows = diffShots( { 'desktop/chat.png': H1 }, null, DEF );
	const md = renderComment( {
		rows,
		def: DEF,
		repo: 'o/r',
		prNumber: '42',
		headSha: HEAD,
		prStoreSha: SHA_B,
		baseStoreSha: null,
		baseline: null,
		envDiff: [],
		runUrl: RUN,
	} );
	assert.match( md, /No baseline from `main` yet/ );
	assert.match( md, /\| Phone \| — \| _not captured_ \|/ );

	assert.match(
		render( { envDiff: [ 'fonts', 'runnerImage' ] } ),
		/different installed fonts, runner image/
	);

	const same = diffShots(
		{ 'desktop/chat.png': H1 },
		{ 'desktop/chat.png': H1 },
		DEF
	);
	const quiet = renderComment( {
		rows: same,
		def: DEF,
		repo: 'o/r',
		prNumber: '42',
		headSha: HEAD,
		prStoreSha: null,
		baseStoreSha: SHA_A,
		baseline: { sha: SHA_A, capturedAt: '2026-01-15T10:00:00.000Z' },
		envDiff: [],
		runUrl: RUN,
	} );
	assert.match(
		quiet,
		/\*\*No visual changes\*\* against `main@aaaaaaa` \(3 screens, 2 viewports\)/
	);
	assert.ok( ! quiet.includes( '###' ) );
} );

test( 'renderComment: falls back to one artefact line when over budget', () => {
	const md = render( { limit: 1700 } );
	assert.ok( md.length <= 1700 + 400, `length ${ md.length }` );
	assert.match(
		md,
		/_…and \d+ more changed screens\. Download the `pr-screenshots` artefact/
	);
	assert.ok( md.includes( '<details>' ) );
} );

test( 'renderComment: an untrusted run URL is not linked', () => {
	const md = render( { runUrl: 'javascript:alert(1)' } );
	assert.ok( ! md.includes( 'javascript:' ) );
} );

test( 'collectShots hashes only allow-listed PNGs and skips symlinks', () => {
	const dir = tmp();
	fs.mkdirSync( path.join( dir, 'desktop' ) );
	fs.mkdirSync( path.join( dir, 'phone' ) );
	fs.writeFileSync( path.join( dir, 'desktop/chat.png' ), PNG );
	fs.writeFileSync( path.join( dir, 'desktop/unknown.png' ), PNG );
	fs.writeFileSync( path.join( dir, 'phone/chat.png' ), 'not a png' );
	fs.writeFileSync(
		path.join( dir, 'phone/dashboard.png' ),
		Buffer.alloc( MAX_PNG_BYTES + 1 )
	);
	fs.writeFileSync( path.join( dir, '.auth.json' ), '{}' );
	fs.symlinkSync(
		path.join( dir, 'desktop/chat.png' ),
		path.join( dir, 'desktop/dashboard.png' )
	);
	const { hashes, rejected } = collectShots( dir, DEF );
	assert.deepEqual( Object.keys( hashes ), [ 'desktop/chat.png' ] );
	assert.match( hashes[ 'desktop/chat.png' ], /^[0-9a-f]{64}$/ );
	assert.equal( rejected, 3 );
} );

test( 'readResults and renderSummary report per-screen status and time', () => {
	const report = {
		suites: [
			{
				title: 'screens.spec.js',
				specs: [
					{
						title: 'chat',
						tests: [
							{
								projectName: 'desktop',
								results: [
									{ status: 'passed', duration: 2100 },
								],
							},
							{
								projectName: 'phone',
								results: [
									{
										status: 'failed',
										duration: 15000,
										error: {
											message:
												'\u001b[31mError: unmocked Plume route(s) on "chat": GET /plume/v1/x\u001b[39m\nstack',
										},
									},
								],
							},
						],
					},
				],
				suites: [],
			},
		],
	};
	const results = readResults( report );
	assert.deepEqual( results[ 1 ], {
		project: 'phone',
		id: 'chat',
		status: 'failed',
		durationMs: 15000,
		error: 'Error: unmocked Plume route(s) on "chat": GET /plume/v1/x',
	} );
	const md = renderSummary( results );
	assert.match( md, /1 of 2 shots captured; summed test time 17\.1s/ );
	assert.match( md, /\| desktop \| chat \| ok \| 2\.1s \|/ );
} );

// ── Store round-trip against a local bare repository ───────────────────────

/**
 * Runs git synchronously and returns stdout.
 *
 * @param {string}   cwd  Directory.
 * @param {string[]} args Arguments.
 * @return {string} stdout.
 */
function gitOut( cwd, args ) {
	const r = spawnSync( 'git', args, { cwd, encoding: 'utf8' } );
	assert.equal( r.status, 0, r.stderr );
	return r.stdout.trim();
}

test( 'openStore creates the orphan branch, pushes, skips no-op commits and re-applies after a race', async () => {
	const root = tmp();
	const remote = path.join( root, 'remote.git' );
	gitOut( root, [ 'init', '-q', '--bare', remote ] );

	// First writer: branch does not exist yet.
	const a = openStore( { dir: path.join( root, 'a' ), remote } );
	assert.equal( a.exists, false );
	const sha1 = await a.commitAndPush( ( dir ) => {
		fs.mkdirSync( path.join( dir, 'pr-1' ), { recursive: true } );
		fs.writeFileSync( path.join( dir, 'pr-1/x.png' ), PNG );
	}, 'first' );
	assert.match( sha1, /^[0-9a-f]{40}$/ );
	assert.equal(
		gitOut( remote, [ 'rev-parse', `refs/heads/${ STORE_BRANCH }` ] ),
		sha1
	);

	// Re-applying the same change makes no new commit.
	const again = openStore( { dir: path.join( root, 'a2' ), remote } );
	assert.equal( again.exists, true );
	assert.equal( again.head, sha1 );
	const same = await again.commitAndPush( ( dir ) => {
		fs.writeFileSync( path.join( dir, 'pr-1/x.png' ), PNG );
	}, 'noop' );
	assert.equal( same, sha1 );

	// Two writers open the same tip; the second push is rejected and re-applied.
	const b = openStore( { dir: path.join( root, 'b' ), remote } );
	const c = openStore( { dir: path.join( root, 'c' ), remote } );
	await b.commitAndPush( ( dir ) => {
		fs.mkdirSync( path.join( dir, 'pr-2' ), { recursive: true } );
		fs.writeFileSync( path.join( dir, 'pr-2/y.png' ), PNG );
	}, 'b' );
	let applies = 0;
	const shaC = await c.commitAndPush(
		( dir ) => {
			applies++;
			fs.rmSync( path.join( dir, 'pr-1' ), {
				recursive: true,
				force: true,
			} );
		},
		'c',
		{ baseDelayMs: 1 }
	);
	assert.equal( applies, 2 );
	const tree = gitOut( remote, [
		'ls-tree',
		'-r',
		'--name-only',
		shaC,
	] ).split( '\n' );
	assert.deepEqual( tree.sort(), [ 'README.md', 'pr-2/y.png' ] );
	// History is linear and never rewritten.
	assert.equal( gitOut( remote, [ 'rev-list', '--count', shaC ] ), '3' );
} );
