/**
 * Frozen fixtures for the PR screenshot capture.
 *
 * Every value is fixed (no `new Date()`, no random ids) so two captures of the
 * same code give byte-identical PNGs.
 *
 * SERVER_STATE — server-rendered values that depend on the site tier or on a
 * stored API key, and how each is pinned so PR and baseline runs agree:
 *
 * | Value                                   | Source                              | Pinned by |
 * |-----------------------------------------|-------------------------------------|-----------|
 * | "Upgrade ✦" admin menu entry            | AdminMenu.php (tier === 'free')     | visual-setup.js: plume_site_tier = pro_managed |
 * | plumeData.isPaid (every screen)         | TierManager::is_paid()              | site tier + PLUME_DATA override |
 * | plumeData.tier (Settings only)          | TierManager::get_user_tier()        | site tier |
 * | plumeData.defaultProvider / defaultModelLabel (Chat) | ChatPage.php, own key vs managed | PLUME_DATA override |
 * | plumeData.siteTitle                     | get_bloginfo( 'name' )              | PLUME_DATA override |
 * | plumeData.imageProvider (Images)        | ImagesModule.php, own key present?  | PLUME_DATA override (hasKey: false) |
 * | plumeDashboard.usage / bannerState / isPaid / version | DashboardPage.php        | DASHBOARD override |
 * | Site-level admin notices                | SiteUnreachableNotice, TierSyncBackfillNotice, ActivationNotice, core nags | hidden by stabilise.js CSS |
 * | WordPress version in the footer         | #footer-upgrade                     | masked by stabilise.js |
 * | /settings, /providers, /usage, posts…   | REST                                | fixtures below (router) |
 */

'use strict';

const FIXED_NOW = '2026-01-15T10:00:00Z';
const UPDATED = '2026-01-12T09:30:00';

const USAGE = {
	tier: 'pro_managed',
	tier_label: 'Pro',
	used: 1240,
	limit: 5000,
	remaining: 3760,
	can_use: true,
};

/** Merged over PHP's localised `plumeData` on every screen. */
const PLUME_DATA = {
	isPaid: true,
	siteTitle: 'Plume Demo',
	defaultProvider: 'claude',
	defaultModelLabel: 'Claude Sonnet',
};

/** Extra `plumeData` for the Images screen: the "no key yet" notice. */
const IMAGES_DATA = {
	imageProvider: { hasKey: false, label: 'OpenAI' },
};

/** Merged over PHP's localised `plumeDashboard`. */
const DASHBOARD = {
	isPaid: true,
	bannerState: 'none',
	onboardingSeen: true,
	usage: USAGE,
	version: '0.0.0',
};

const CONVERSATIONS = [
	{
		id: 3,
		title: 'Spring newsletter ideas',
		created_at: '2026-01-12T09:30:00',
		updated_at: '2026-01-12T09:30:00',
	},
	{
		id: 2,
		title: 'Rewrite the About page',
		created_at: '2026-01-09T14:00:00',
		updated_at: '2026-01-10T16:45:00',
	},
	{
		id: 1,
		title: 'SEO checklist for product posts',
		created_at: '2026-01-05T08:15:00',
		updated_at: '2026-01-05T08:15:00',
	},
];

const USER_PROMPT = 'Plan a launch post for the new recipe collection';

// ChatApp renames an inline-created conversation after the first reply,
// cutting the prompt at the last whole word (ChatApp.jsx sendMessage()).
const RENAMED_TITLE = 'Plan a launch post for the new recipe';

const NEW_CONVERSATION = {
	id: 101,
	title: USER_PROMPT,
	created_at: '2026-01-15T10:00:00',
	updated_at: '2026-01-15T10:00:00',
};

const REPLY = {
	content: [
		'## Launch post outline',
		'',
		'A short, friendly post works best for a collection launch:',
		'',
		'1. **Hook** — one sentence on why these recipes exist.',
		'2. **Highlights** — three favourite dishes with a photo each.',
		'3. **Call to action** — link to the collection page.',
		'',
		'Suggested excerpt:',
		'',
		'```',
		'Twelve weeknight recipes, tested in a real kitchen.',
		'```',
	].join( '\n' ),
	model: 'claude-sonnet-4-5',
	tokens: 120,
	credits: 2,
};

const PROVIDER_ERROR = {
	code: 'provider_error',
	message: 'The AI provider did not respond. Please try again.',
	data: { status: 500 },
};

const OUT_OF_CREDITS = {
	code: 'rate_limit_exceeded',
	message: 'You have used all your credits for this billing period.',
	data: { status: 429 },
};

const PROVIDERS = [
	{
		slug: 'claude',
		models: {
			'claude-sonnet-4-5': 'Claude Sonnet 4.5',
			'claude-haiku-4-5': 'Claude Haiku 4.5',
		},
		is_available: true,
	},
	{
		slug: 'openai',
		models: { 'gpt-5-mini': 'GPT-5 mini' },
		is_available: true,
	},
];

const SETTINGS = {
	default_provider: 'claude',
	image_provider: 'openai',
	site_voice: 'Warm, practical and concise. British English.',
	enabled_modules: [
		'chat',
		'text_rewrite',
		'summaries',
		'generator',
		'seo',
		'images',
		'usage',
	],
	api_keys: { claude: '', openai: '', gemini: '', ollama_url: '' },
	allowed_post_types: [ 'post', 'page' ],
	available_post_types: [
		{ slug: 'post', label: 'Post' },
		{ slug: 'page', label: 'Page' },
	],
	enable_write_tools: true,
	is_paid: true,
	managed_providers: [ 'claude', 'openai', 'gemini' ],
};

// A 1×1 grey SVG: an <img src="data:…"> never reaches the network.
const THUMB =
	'data:image/svg+xml;utf8,' +
	encodeURIComponent(
		'<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150"><rect width="150" height="150" fill="#c3c4c7"/></svg>'
	);

/**
 * Builds a WordPress REST post object with only the fields the list uses.
 *
 * @param {Object}  args
 * @param {number}  args.id       Post ID.
 * @param {string}  args.type     'post' or 'page'.
 * @param {string}  args.title    Rendered title.
 * @param {string}  args.status   Post status.
 * @param {string}  args.modified ISO date without zone (as WordPress returns it).
 * @param {?Object} args.seo      Value for `plume_seo_status`, or null.
 * @param {boolean} args.image    Whether the post has a featured image.
 * @return {Object} REST post object.
 */
function makePost( { id, type, title, status, modified, seo, image } ) {
	return {
		id,
		type,
		status,
		modified,
		date: modified,
		link: `http://localhost:8888/?p=${ id }`,
		title: { rendered: title, raw: title },
		featured_media: image ? 900 + id : 0,
		plume_seo_status: seo,
		_embedded: image
			? {
					'wp:featuredmedia': [
						{
							id: 900 + id,
							source_url: THUMB,
							media_details: {
								sizes: { thumbnail: { source_url: THUMB } },
							},
						},
					],
				}
			: {},
	};
}

const filled = { status: 'filled', value: 'x' };
const empty = { status: 'missing', value: '' };

const POSTS = [
	makePost( {
		id: 11,
		type: 'post',
		title: 'Twelve weeknight recipes',
		status: 'publish',
		modified: '2026-01-10T09:00:00',
		seo: {
			meta_title: filled,
			meta_description: filled,
			excerpt: filled,
			alt_text: filled,
		},
		image: true,
	} ),
	makePost( {
		id: 12,
		type: 'post',
		title: 'How we test every recipe',
		status: 'draft',
		modified: '2026-01-08T09:00:00',
		seo: {
			meta_title: filled,
			meta_description: empty,
			excerpt: empty,
			alt_text: empty,
		},
		image: false,
	} ),
	makePost( {
		id: 13,
		type: 'post',
		title: 'Spring menu preview',
		status: 'future',
		modified: '2026-01-06T09:00:00',
		seo: null,
		image: false,
	} ),
];

const PAGES = [
	makePost( {
		id: 21,
		type: 'page',
		title: 'About',
		status: 'publish',
		modified: '2026-01-04T09:00:00',
		seo: null,
		image: true,
	} ),
];

module.exports = {
	FIXED_NOW,
	UPDATED,
	USAGE,
	PLUME_DATA,
	IMAGES_DATA,
	DASHBOARD,
	CONVERSATIONS,
	NEW_CONVERSATION,
	USER_PROMPT,
	RENAMED_TITLE,
	REPLY,
	PROVIDER_ERROR,
	OUT_OF_CREDITS,
	PROVIDERS,
	SETTINGS,
	POSTS,
	PAGES,
	makePost,
};
