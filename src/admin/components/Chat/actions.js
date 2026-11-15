/**
 * Shared chat action definitions.
 *
 * Single source of truth for prompt strings used by both QuickActions
 * and the launch-view suggestion chips in ChatApp. Centralising here
 * means a prompt update only needs to happen in one place.
 *
 * Each action object has the shape:
 *   { id: string, label: string, prompt: string, icon: Component, requiresPost: boolean }
 *
 * When `requiresPost` is true the consumer must ensure a post is attached
 * before dispatching the prompt; otherwise the context picker should be opened first.
 *
 * Every action is available to every tier — credit exhaustion is enforced
 * by the Worker, not a tier-based action split, so there is no longer a
 * Free/Pro distinction here.
 */
import { __ } from '@wordpress/i18n';
import { FilePenLine, Search, Image } from 'lucide-react';

export const QUICK_ACTIONS = [
	{
		id: 'summarise',
		label: __( 'Summarise this post', 'plume' ),
		prompt: __(
			'Please summarise the current post in 2–3 sentences.',
			'plume'
		),
		icon: FilePenLine,
		requiresPost: true,
	},
	{
		id: 'readability',
		label: __( 'Improve readability', 'plume' ),
		prompt: __(
			'Review this content and suggest readability improvements.',
			'plume'
		),
		icon: FilePenLine,
		requiresPost: true,
	},
	{
		id: 'write-post',
		label: __( 'Write a post', 'plume' ),
		prompt: __(
			'Help me write a new blog post. What topic should we start with?',
			'plume'
		),
		icon: FilePenLine,
		requiresPost: false,
	},
	{
		id: 'seo-title',
		label: __( 'Generate SEO title', 'plume' ),
		prompt: __( 'Generate an optimised SEO title for this post.', 'plume' ),
		icon: Search,
		requiresPost: true,
	},
	{
		id: 'meta-description',
		label: __( 'Write meta description', 'plume' ),
		prompt: __(
			'Write a compelling 155-character meta description for this post.',
			'plume'
		),
		icon: Search,
		requiresPost: true,
	},
	{
		id: 'featured-image',
		label: __( 'Create featured image', 'plume' ),
		prompt: __( 'Generate a featured image for this post.', 'plume' ),
		icon: Image,
		requiresPost: true,
	},
];

/** Actions shown as suggestion chips on the centred launch screen. */
export const LAUNCH_ACTIONS = [
	QUICK_ACTIONS[ 0 ],
	QUICK_ACTIONS[ 1 ],
	QUICK_ACTIONS[ 2 ],
];
