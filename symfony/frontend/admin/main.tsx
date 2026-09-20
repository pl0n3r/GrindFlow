import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { PreviewApp } from './PreviewApp';
import './preview.css';

const root = document.getElementById('grindflow-preview');

if (root) {
  const version = root.dataset.version ?? 'unavailable';
  createRoot(root).render(<StrictMode><PreviewApp version={version} /></StrictMode>);
}
