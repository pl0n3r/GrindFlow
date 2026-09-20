import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    sourcemap: false,
    rollupOptions: {
      input: { preview: 'frontend/admin/main.tsx', admin: 'frontend/admin/admin.tsx' },
      output: {
        entryFileNames: (chunk) => chunk.name === 'admin' ? 'assets/admin-[hash].js' : 'assets/preview-[hash].js',
        chunkFileNames: 'assets/chunk-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]'
      }
    }
  }
});
