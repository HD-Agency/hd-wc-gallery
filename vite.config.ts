import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
	build: {
		outDir: 'assets',
		emptyOutDir: false,
		rollupOptions: {
			input: {
				'gallery-loader': resolve(__dirname, 'resources/gallery-loader.js'),
				'gallery-thumbs': resolve(__dirname, 'resources/gallery-thumbs.js'),
			},
			output: {
				entryFileNames: '[name].js',
				chunkFileNames: 'chunk/[name].js',
				assetFileNames: '[name].[ext]',
			},
		},
		minify: 'terser',
	},
});
