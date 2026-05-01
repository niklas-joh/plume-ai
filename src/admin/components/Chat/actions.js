/**
 * Shared chat action definitions.
 *
 * Single source of truth for prompt strings used by both QuickActions
 * and the launch-view suggestion chips in ChatApp. Centralising here
 * means a prompt update only needs to happen in one place.
 */
import { FilePenLine, Search, Image } from 'lucide-react';

export const FREE_ACTIONS = [
	{
		id: 'summarise',
		label: 'Summarise this post',
		prompt: 'Please summarise the current post in 2-3 sentences.',
		icon: FilePenLine,
	},
	{
		id: 'readability',
		label: 'Improve readability',
		prompt: 'Review this content and suggest readability improvements.',
		icon: FilePenLine,
	},
];

export const PRO_ACTIONS = [
	{
		id: 'write-post',
		label: 'Write a post',
		prompt: 'Help me write a new blog post. What topic should we start with?',
		icon: FilePenLine,
	},
	{
		id: 'seo-title',
		label: 'Generate SEO title',
		prompt: 'Generate an optimised SEO title for this post.',
		icon: Search,
	},
	{
		id: 'meta-description',
		label: 'Write meta description',
		prompt: 'Write a compelling 155-character meta description for this post.',
		icon: Search,
	},
	{
		id: 'featured-image',
		label: 'Create featured image',
		prompt: 'Generate a featured image for this post.',
		icon: Image,
	},
];

/** Actions shown as suggestion chips on the centred launch screen. */
export const LAUNCH_ACTIONS = [ FREE_ACTIONS[ 0 ], FREE_ACTIONS[ 1 ], PRO_ACTIONS[ 0 ] ];
