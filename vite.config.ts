import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, '.'),
    },
  },
  build: {
    rollupOptions: {
      output: {
        // Pisahkan inti React dari kode aplikasi: chunk aplikasi jadi kecil dan
        // hash vendor stabil, sehingga bisa di-cache lintas deploy.
        // Bentuk fungsi dipakai karena bentuk array tidak menjaring sub-entry
        // seperti "react-dom/client" maupun "react/jsx-runtime".
        // CATATAN: recharts TIDAK dipisah manual. Vite menambahkan
        // <link rel="modulepreload"> untuk setiap manual chunk, jadi memisahkan
        // recharts justru membuat halaman login ikut mengunduhnya. Tanpa rule
        // itu, recharts otomatis masuk ke chunk DashboardOverview yang lazy.
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined;
          if (/[\\/]node_modules[\\/](react|react-dom|react-router|react-router-dom|scheduler)[\\/]/.test(id)) {
            return 'vendor';
          }
          return undefined;
        },
      },
    },
  },
  server: {
    hmr: process.env.DISABLE_HMR !== 'true',
    watch: process.env.DISABLE_HMR === 'true' ? null : {},
    // v2 pakai backend sendiri di 8011 agar tidak bertabrakan dengan backend
    // versi lama (root) yang memakai 8000. Jalankan:
    //   php -S 127.0.0.1:8011 -t api-php api-php/router_dev.php
    // '/uploads' ikut diproksikan karena lampiran disimpan di uploads/ root repo;
    // di produksi folder itu satu document root dengan dist/, sedangkan di
    // pengembangan disajikan api-php/router_dev.php.
    proxy: { '/api': 'http://127.0.0.1:8011', '/uploads': 'http://127.0.0.1:8011' },
    fs: {
      // node_modules v2 adalah junction ke D:\simars\node_modules, jadi file
      // font @fontsource resolve ke path di luar folder v2. Izinkan folder
      // induk supaya dev server bisa menyajikannya.
      allow: [path.resolve(__dirname, '..')],
    },
  },
});
