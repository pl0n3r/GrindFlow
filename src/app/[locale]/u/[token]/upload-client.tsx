'use client';

import { useState, type ChangeEvent } from 'react';
import { useTranslations } from 'next-intl';

type Status =
  | { kind: 'idle' }
  | { kind: 'uploading'; name: string }
  | { kind: 'done'; count: number }
  | { kind: 'error'; message: string };

/**
 * Upload files directly from the browser to R2 through signed URLs.
 *
 * Files never cross the Next server. The signed PUT headers are treated as a
 * strict contract and uploads run sequentially to remain reliable on mobile
 * connections.
 */
export function UploadClient({ token }: { token: string }) {
  const t = useTranslations('upload');
  const [status, setStatus] = useState<Status>({ kind: 'idle' });

  /** Upload one file using a freshly requested signed destination. */
  async function uploadOne(file: File): Promise<void> {
    setStatus({ kind: 'uploading', name: file.name });

    const presignResponse = await fetch('/api/uploads/presign', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token,
        filename: file.name,
        contentType: file.type,
        contentLength: file.size,
      }),
    });

    if (!presignResponse.ok) {
      const { error } = (await presignResponse.json().catch(() => ({}))) as {
        error?: string;
      };
      const key = error ?? '';
      const message = t.has(`errors.${key}`) ? t(`errors.${key}`) : t('genericError');
      throw new Error(message);
    }

    const { uploadUrl, requiredHeaders } = (await presignResponse.json()) as {
      uploadUrl: string;
      requiredHeaders: Record<string, string>;
    };

    // Content-Length lo fija el navegador a partir del cuerpo; enviarlo a mano
    // esta prohibido por la especificacion de fetch y provocaria un error.
    const { 'Content-Length': _length, ...headers } = requiredHeaders;

    const put = await fetch(uploadUrl, { method: 'PUT', headers, body: file });
    if (!put.ok) {
      throw new Error(t('storageError'));
    }
  }

  /** Process the current picker selection sequentially and expose its status. */
  async function onSelect(event: ChangeEvent<HTMLInputElement>) {
    const files = Array.from(event.target.files ?? []);
    if (files.length === 0) return;

    try {
      // En serie, no en paralelo: subir seis videos a la vez desde datos moviles
      // suele acabar en seis fallos por tiempo de espera en lugar de uno a uno.
      for (const file of files) {
        await uploadOne(file);
      }
      setStatus({ kind: 'done', count: files.length });
    } catch (error) {
      setStatus({
        kind: 'error',
        message: error instanceof Error ? error.message : t('unexpectedError'),
      });
    } finally {
      event.target.value = '';
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <label className="cursor-pointer rounded-xl border border-dashed border-ink-600 bg-ink-900 p-8 text-center transition hover:border-brand-400">
        <input
          type="file"
          multiple
          accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime"
          onChange={onSelect}
          className="sr-only"
          disabled={status.kind === 'uploading'}
        />
        <span className="text-sm text-ink-200">{t('pickFiles')}</span>
      </label>

      {status.kind === 'uploading' && (
        <p className="text-sm text-ink-400">{t('uploading', { name: status.name })}</p>
      )}
      {status.kind === 'done' && (
        <p className="text-sm text-ok-500">{t('done', { count: status.count })}</p>
      )}
      {status.kind === 'error' && (
        <p role="alert" className="text-sm text-danger-500">
          {status.message}
        </p>
      )}
    </div>
  );
}
