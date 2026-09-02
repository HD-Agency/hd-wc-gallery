/**
 * HD WC Gallery — Video Parsing & Embed Generator.
 *
 * Robust URL parsing for YouTube (standard, shorts, embed), Vimeo,
 * and HTML5 MP4/WebM video sources.
 *
 * @package HDWCGallery\Shared
 */

/**
 * Regex for detecting supported video URLs.
 */
export const VIDEO_URL_REGEX = /\.(mp4|webm)(\?|$)|youtu\.?be|vimeo\.com/i;

export type VideoProviderType = 'youtube' | 'vimeo' | 'html5';

export interface VideoMetadata {
	type: VideoProviderType;
	id: string | null;
	url: string;
}

/**
 * Extract 11-character YouTube video ID.
 */
export function extractYouTubeId(url: string): string | null {
	if (!url) return null;
	const match = url.match(
		/(?:youtube(?:-nocookie)?\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?|shorts)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/
	);
	return match ? match[1] : null;
}

/**
 * Extract Vimeo numeric ID.
 */
export function extractVimeoId(url: string): string | null {
	if (!url) return null;
	const match = url.match(/(?:vimeo\.com\/(?:video\/)?|player\.vimeo\.com\/video\/)(\d+)/);
	return match ? match[1] : null;
}

/**
 * Detect if string is a video URL.
 */
export function isVideoUrl(url: string): boolean {
	return VIDEO_URL_REGEX.test(url);
}

/**
 * Parse video URL into structured metadata.
 */
export function parseVideoUrl(url: string): VideoMetadata | null {
	if (!url) return null;

	const ytId = extractYouTubeId(url);
	if (ytId) {
		return { type: 'youtube', id: ytId, url };
	}

	const vimeoId = extractVimeoId(url);
	if (vimeoId) {
		return { type: 'vimeo', id: vimeoId, url };
	}

	if (/\.(mp4|webm)(\?|$)/i.test(url)) {
		return { type: 'html5', id: null, url };
	}

	return null;
}

/**
 * Build iframe embed URL with safe autoplay parameters.
 */
export function buildEmbedUrl(metadata: VideoMetadata, autoplay = true): string | null {
	if (metadata.type === 'youtube' && metadata.id) {
		return `https://www.youtube-nocookie.com/embed/${metadata.id}?autoplay=${autoplay ? 1 : 0}&rel=0&enablejsapi=1`;
	}
	if (metadata.type === 'vimeo' && metadata.id) {
		return `https://player.vimeo.com/video/${metadata.id}?autoplay=${autoplay ? 1 : 0}&dnt=1`;
	}
	return null;
}
