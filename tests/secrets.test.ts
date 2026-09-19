/**
 * Pruebas del cifrado de credenciales.
 *
 * El foco no esta en que el ida y vuelta funcione —eso lo hace cualquier
 * implementacion, incluida una insegura— sino en que el cifrado DETECTE
 * manipulacion. Ahi esta la diferencia entre AES-GCM y un modo sin autenticar:
 * con CBC, alterar el texto cifrado devuelve bytes distintos sin avisar; con
 * GCM, la operacion falla.
 */
import { describe, expect, it } from 'vitest';
import {
  SecretCryptoError,
  decryptSecret,
  encryptSecret,
  isEncryptedSecret,
  secretsMatch,
} from '@/lib/crypto/secrets';

const TOKEN = '1234567890:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';
const CONTEXT = 'grindflow:credential:org-alfa:telegram';

function tamperBase64Url(value: string): string {
  const bytes = Buffer.from(value, 'base64url');

  if (bytes.length === 0) {
    throw new Error('Cannot tamper with an empty Base64URL value.');
  }

  bytes[0] = bytes[0]! ^ 0x01;

  return bytes.toString('base64url');
}

describe('ida y vuelta', () => {
  it('devuelve el secreto original', () => {
    expect(decryptSecret(encryptSecret(TOKEN))).toBe(TOKEN);
  });

  it('funciona con contexto', () => {
    expect(decryptSecret(encryptSecret(TOKEN, CONTEXT), CONTEXT)).toBe(TOKEN);
  });

  it('conserva acentos, emoji y secretos largos', () => {
    const raro = 'contrasena-con-nes-y-acentos-áéíóúñ-🔐-' + 'x'.repeat(4000);
    expect(decryptSecret(encryptSecret(raro))).toBe(raro);
  });

  it('rechaza cifrar una cadena vacia', () => {
    expect(() => encryptSecret('')).toThrow(SecretCryptoError);
  });
});

describe('el texto cifrado no filtra nada', () => {
  it('no contiene el secreto en claro', () => {
    const cifrado = encryptSecret(TOKEN);
    expect(cifrado).not.toContain(TOKEN);
    expect(cifrado).not.toContain('AAHdqTcv');
  });

  it('cifrar dos veces lo mismo da resultados distintos', () => {
    // El IV es aleatorio en cada operacion. Si dos cifrados coincidieran, un
    // observador sabria que dos agencias configuraron el mismo token.
    expect(encryptSecret(TOKEN)).not.toBe(encryptSecret(TOKEN));
  });

  it('usa el formato versionado de cuatro partes', () => {
    const partes = encryptSecret(TOKEN).split('.');
    expect(partes).toHaveLength(4);
    expect(partes[0]).toBe('v1');
    expect(isEncryptedSecret(encryptSecret(TOKEN))).toBe(true);
  });

  it('no reconoce como cifrado un texto cualquiera', () => {
    expect(isEncryptedSecret('token-en-claro')).toBe(false);
    expect(isEncryptedSecret('v9.a.b.c')).toBe(false);
  });
});

describe('deteccion de manipulacion', () => {
  it('falla si se altera el texto cifrado', () => {
    const [v, iv, tag, ct] = encryptSecret(TOKEN).split('.') as [string, string, string, string];
    const alterado = [v, iv, tag, tamperBase64Url(ct)].join('.');
    expect(() => decryptSecret(alterado)).toThrow(SecretCryptoError);
  });

  it('falla si se altera la etiqueta de autenticacion', () => {
    const [v, iv, tag, ct] = encryptSecret(TOKEN).split('.') as [string, string, string, string];
    const alterado = [v, iv, tamperBase64Url(tag), ct].join('.');
    expect(() => decryptSecret(alterado)).toThrow(SecretCryptoError);
  });

  it('falla si se cambia el IV', () => {
    const [v, , tag, ct] = encryptSecret(TOKEN).split('.') as [string, string, string, string];
    const otro = encryptSecret(TOKEN).split('.')[1] as string;
    expect(() => decryptSecret([v, otro, tag, ct].join('.'))).toThrow(SecretCryptoError);
  });

  it('rechaza una version desconocida', () => {
    const partes = encryptSecret(TOKEN).split('.');
    partes[0] = 'v2';
    expect(() => decryptSecret(partes.join('.'))).toThrow(/version/i);
  });

  it('rechaza un formato que no tenga cuatro partes', () => {
    expect(() => decryptSecret('esto-no-es-un-secreto')).toThrow(SecretCryptoError);
    expect(() => decryptSecret('v1.solo.tres')).toThrow(SecretCryptoError);
  });
});

describe('el contexto ata el secreto a su fila', () => {
  it('no se descifra con el contexto de otra organizacion', () => {
    // Es el ataque concreto: copiar el secret_ciphertext de una agencia a la
    // fila de otra. Sin AAD funcionaria y el token quedaria robado.
    const cifrado = encryptSecret(TOKEN, 'grindflow:credential:org-alfa:telegram');
    expect(() =>
      decryptSecret(cifrado, 'grindflow:credential:org-beta:telegram'),
    ).toThrow(SecretCryptoError);
  });

  it('no se descifra con el contexto de otra plataforma', () => {
    const cifrado = encryptSecret(TOKEN, 'grindflow:credential:org-alfa:telegram');
    expect(() =>
      decryptSecret(cifrado, 'grindflow:credential:org-alfa:x'),
    ).toThrow(SecretCryptoError);
  });

  it('no se descifra omitiendo el contexto', () => {
    const cifrado = encryptSecret(TOKEN, CONTEXT);
    expect(() => decryptSecret(cifrado)).toThrow(SecretCryptoError);
  });

  it('no se descifra anadiendo un contexto que no se uso al cifrar', () => {
    const cifrado = encryptSecret(TOKEN);
    expect(() => decryptSecret(cifrado, CONTEXT)).toThrow(SecretCryptoError);
  });
});

describe('comparacion en tiempo constante', () => {
  it('reconoce dos secretos iguales', () => {
    expect(secretsMatch('firma-abc', 'firma-abc')).toBe(true);
  });

  it('distingue secretos distintos de la misma longitud', () => {
    expect(secretsMatch('firma-abc', 'firma-abd')).toBe(false);
  });

  it('no revienta con longitudes distintas', () => {
    expect(secretsMatch('corto', 'mucho-mas-largo')).toBe(false);
  });
});
