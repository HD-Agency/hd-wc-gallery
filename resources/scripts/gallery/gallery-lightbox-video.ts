/**
 * PhotoSwipe v5 Plugin — Video Content Provider.
 *
 * Adds rich video playback (YouTube, Vimeo, HTML5 MP4/WebM) to PhotoSwipe slides
 * with automatic playback sync on slide transitions and cleanup on close.
 *
 * @package HDWCGallery\Gallery
 */

import PhotoSwipeLightbox from 'photoswipe/lightbox';
import { extractYouTubeId, extractVimeoId } from '../shared/video';

const CLASS_VIDEO_CONTAINER = 'pswp__video-container';

/**
 * Builds video container DOM element for slide.
 */
function buildVideoEl(url: string, type: string): HTMLElement {
	const wrap = document.createElement('div');
	wrap.className = CLASS_VIDEO_CONTAINER;

	if (type === 'youtube' || url.includes('youtu')) {
		const id = extractYouTubeId(url);
		if (id) {
			const iframe = document.createElement('iframe');
			iframe.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&enablejsapi=1`;
			iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
			iframe.setAttribute('allowfullscreen', '');
			iframe.setAttribute('frameborder', '0');
			wrap.appendChild(iframe);
		}
	} else if (type === 'vimeo' || url.includes('vimeo.com')) {
		const id = extractVimeoId(url);
		if (id) {
			const iframe = document.createElement('iframe');
			iframe.src = `https://player.vimeo.com/video/${id}?autoplay=1&dnt=1`;
			iframe.allow = 'autoplay; fullscreen; picture-in-picture';
			iframe.setAttribute('allowfullscreen', '');
			iframe.setAttribute('frameborder', '0');
			wrap.appendChild(iframe);
		}
	} else {
		// Native HTML5 video
		const video = document.createElement('video');
		video.src = url;
		video.controls = true;
		video.autoplay = true;
		video.setAttribute('playsinline', '');
		wrap.appendChild(video);
	}

	return wrap;
}

/**
 * Pauses or stops video playback inside container.
 */
function stopVideo(container: HTMLElement): void {
	const iframe = container.querySelector<HTMLIFrameElement>('iframe');
	if (iframe) {
		iframe.dataset.srcBackup = iframe.src;
		iframe.src = '';
		return;
	}
	const video = container.querySelector<HTMLVideoElement>('video');
	if (video) {
		video.pause();
	}
}

/**
 * Resumes video playback inside container.
 */
function resumeVideo(container: HTMLElement): void {
	const iframe = container.querySelector<HTMLIFrameElement>('iframe');
	if (iframe && iframe.dataset.srcBackup) {
		iframe.src = iframe.dataset.srcBackup;
		delete iframe.dataset.srcBackup;
		return;
	}
	const video = container.querySelector<HTMLVideoElement>('video');
	if (video) {
		const p = video.play();
		if (p && typeof p.catch === 'function') p.catch(() => {});
	}
}

/**
 * Registers video plugin on PhotoSwipeLightbox.
 */
export function registerVideoPlugin(lightbox: PhotoSwipeLightbox): void {
	lightbox.addFilter('itemData', (itemData: any, _index: number) => {
		const element = itemData.element as HTMLElement | undefined;
		if (element) {
			const videoUrl = element.getAttribute('data-pswp-video-url');
			const videoType = element.getAttribute('data-pswp-video-type') || 'html5';
			if (videoUrl) {
				itemData.videoUrl = videoUrl;
				itemData.videoType = videoType;
				itemData.type = 'hd-video';
			}
		}
		return itemData;
	});

	lightbox.on('contentLoad', (e: any) => {
		const { content } = e;
		if (content.type === 'hd-video') {
			e.preventDefault();
			const el = buildVideoEl(content.data.videoUrl, content.data.videoType);
			content.element = el;
		}
	});

	lightbox.on('contentActivate', (e: any) => {
		const { content } = e;
		if (content.type === 'hd-video' && content.element) {
			resumeVideo(content.element);
		}
	});

	lightbox.on('contentDeactivate', (e: any) => {
		const { content } = e;
		if (content.type === 'hd-video' && content.element) {
			stopVideo(content.element);
		}
	});

	lightbox.on('contentDestroy', (e: any) => {
		const { content } = e;
		if (content.type === 'hd-video' && content.element) {
			stopVideo(content.element);
		}
	});
}
