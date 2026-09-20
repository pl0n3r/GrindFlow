import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { AdminApp } from './AdminApp';
import { PreviewApp } from './PreviewApp';
import './preview.css';
import './admin.css';

const previewRoot = document.getElementById('grindflow-preview');
if (previewRoot) {
  const version = previewRoot.dataset.version ?? 'unavailable';
  createRoot(previewRoot).render(<StrictMode><PreviewApp version={version} /></StrictMode>);
}

const adminRoot = document.getElementById('grindflow-admin');
if (adminRoot) {
  createRoot(adminRoot).render(<StrictMode><AdminApp /></StrictMode>);
}
