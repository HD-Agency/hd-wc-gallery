import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
	base: './',
	build: {
		outDir: 'assets',
		// Clear stale chunks from previous builds; scoped to the assets/ dir.
		emptyOutDir: true,
		rolldownOptions: {
			input: {
				'gallery-loader': resolve(import.meta.dirname, 'resources/scripts/loader.ts'),
				gallery: resolve(import.meta.dirname, 'resources/scripts/gallery/gallery.ts'),
			},
			output: {
				format: 'es',
				entryFileNames: '[name].js',
				chunkFileNames: (chunkInfo) => {
					const name = chunkInfo.name || 'chunk';
					return `chunk/${name}.js`;
				},
				assetFileNames: '[name].[ext]',
			},
		},
		sourcemap: false,
	},
	css: {
		preprocessorOptions: {
			scss: {
				quietDeps: true,
			},
		},
	},
});
