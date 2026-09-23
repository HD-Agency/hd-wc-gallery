/**
 * HD WC Gallery — Timing & Pure Helpers.
 *
 * Environment-agnostic timing, responsive calculation, and conversion utilities.
 *
 * @package HDWCGallery\Shared
 */

/**
 * Standard debouncer.
 */
export function debounce<T extends (...args: any[]) => any>(fn: T, ms: number): (...args: Parameters<T>) => void {
	let timer: ReturnType<typeof setTimeout> | null = null;
	return (...args: Parameters<T>) => {
		if (timer) clearTimeout(timer);
		timer = setTimeout(() => {
			fn(...args);
			timer = null;
		}, ms);
	};
}

/**
 * Throttle function execution using requestAnimationFrame.
 * Essential for 60fps cursor hover tracking & scroll calculations.
 */
export function throttleRAF<T extends (...args: any[]) => any>(fn: T): (...args: Parameters<T>) => void {
	let queued = false;
	return (...args: Parameters<T>) => {
		if (queued) return;
		queued = true;
		requestAnimationFrame(() => {
			fn(...args);
			queued = false;
		});
	};
}

/**
 * Safe JSON parser with fallback default value.
 */
export function parseJSON<T = unknown>(str: unknown, fallback: T): T {
	if (typeof str !== 'string') return fallback;
	try {
		const parsed = JSON.parse(str);
		return (parsed ?? fallback) as T;
	} catch {
		return fallback;
	}
}

/**
 * Responsive breakpoint widths (px).
 */
export const BREAKPOINTS = {
	SM: 640,
	MD: 768,
	LG: 1024,
	XL: 1280,
	XXL: 1536,
} as const;

export interface ResponsiveConfig<T = number> {
	default: T;
	sm?: T;
	md?: T;
	lg?: T;
	xl?: T;
	xxl?: T;
}

/**
 * Resolves responsive value from viewport width.
 */
export function resolveResponsive<T = number>(config: ResponsiveConfig<T> | T, fallback: T): T {
	if (typeof config !== 'object' || config === null) {
		return config ?? fallback;
	}
	const w = typeof window !== 'undefined' ? window.innerWidth : 1200;
	const cfg = config as ResponsiveConfig<T>;

	if (w >= BREAKPOINTS.XXL && cfg.xxl !== undefined) return cfg.xxl;
	if (w >= BREAKPOINTS.XL && cfg.xl !== undefined) return cfg.xl;
	if (w >= BREAKPOINTS.LG && cfg.lg !== undefined) return cfg.lg;
	if (w >= BREAKPOINTS.MD && cfg.md !== undefined) return cfg.md;
	if (w >= BREAKPOINTS.SM && cfg.sm !== undefined) return cfg.sm;
	return cfg.default ?? fallback;
}

/**
 * Safe querySelector helper.
 */
export function qs<T extends HTMLElement = HTMLElement>(
	selector: string,
	parent: ParentNode = document
): T | null {
	return parent.querySelector<T>(selector);
}

/**
 * Safe querySelectorAll helper returning Array.
 */
export function qsa<T extends HTMLElement = HTMLElement>(
	selector: string,
	parent: ParentNode = document
): T[] {
	return Array.from(parent.querySelectorAll<T>(selector));
}
