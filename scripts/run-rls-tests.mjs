#!/usr/bin/env node
/**
 * Ejecuta las pruebas de RLS contra una base desechable.
 *
 * Crea una base nueva, aplica el arranque de auth, todas las migraciones en
 * orden y luego los archivos de prueba. Cualquier asercion fallida aborta psql
 * con codigo distinto de cero y con el la ejecucion entera.
 *
 * La base se crea y se destruye en cada corrida: las pruebas nunca dependen de
 * lo que dejo la anterior.
 */
import { execFileSync, spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';

const ADMIN_URL = process.env.RLS_TEST_ADMIN_URL ?? 'postgresql://postgres:postgres@127.0.0.1:5432/postgres';
const PSQL_BIN = process.env.RLS_TEST_PSQL ?? '/usr/bin/psql';
if (!PSQL_BIN.startsWith('/')) {
  throw new Error('RLS_TEST_PSQL debe ser una ruta absoluta');
}
const DB_NAME = process.env.RLS_TEST_DB ?? `grindflow_rls_${process.pid}`;
const TEST_URL = new URL(ADMIN_URL);
TEST_URL.pathname = `/${DB_NAME}`;

const psql = (url, args) =>
  execFileSync(PSQL_BIN, ['-v', 'ON_ERROR_STOP=1', '--no-psqlrc', url.toString(), ...args], {
    stdio: ['ignore', 'pipe', 'pipe'],
    encoding: 'utf8',
  });

/**
 * Corre un archivo de pruebas mostrando su salida completa. Las aserciones se
 * reportan como NOTICE, que psql escribe en stderr: si solo se mirara stdout,
 * una corrida en verde no mostraria absolutamente nada.
 */
const runTestFile = (file) => {
  const result = spawnSync(
    PSQL_BIN,
    ['-v', 'ON_ERROR_STOP=1', '--no-psqlrc', TEST_URL.toString(), '-f', file],
    { encoding: 'utf8' },
  );
  const output = `${result.stderr ?? ''}${result.stdout ?? ''}`
    .split('\n')
    .filter((line) => !/^\s*$|^\s*\(?\d* ?rows?\)?$|^-+$|^ *assert/.test(line))
    .map((line) => line.replace(/^psql:[^:]+:\d+: (NOTICE|INFO):\s*/, '    '))
    .join('\n');
  process.stdout.write(`${output}\n`);
  if (result.status !== 0) {
    throw new Error(`fallo en ${file}`);
  }
};

const sqlFiles = (dir) =>
  readdirSync(dir)
    .filter((f) => f.endsWith('.sql'))
    .sort()
    .map((f) => join(dir, f));

const log = (msg) => process.stdout.write(`${msg}\n`);

let created = false;
try {
  psql(new URL(ADMIN_URL), ['-c', `drop database if exists ${DB_NAME}`]);
  psql(new URL(ADMIN_URL), ['-c', `create database ${DB_NAME}`]);
  created = true;
  log(`base de prueba: ${DB_NAME}`);

  psql(TEST_URL, ['-f', 'supabase/tests/00_bootstrap_auth.sql']);
  log('arranque de auth aplicado');

  for (const file of sqlFiles('supabase/migrations')) {
    psql(TEST_URL, ['-f', file]);
    log(`migracion aplicada: ${file.split('/').pop()}`);
  }

  psql(TEST_URL, ['-f', 'supabase/tests/05_helpers.sql']);
  psql(TEST_URL, ['-f', 'supabase/tests/10_seed.sql']);
  log('datos de prueba sembrados\n');

  for (const file of sqlFiles('supabase/tests').filter((f) => /\/[2-9]\d_/.test(f))) {
    runTestFile(file);
  }

  log('\nPRUEBAS DE RLS: TODO EN VERDE');
} catch (error) {
  process.stderr.write('\nPRUEBAS DE RLS: FALLARON\n');
  if (error.stdout) process.stderr.write(error.stdout);
  if (error.stderr) process.stderr.write(error.stderr);
  process.exitCode = 1;
} finally {
  if (created && !process.env.RLS_TEST_KEEP) {
    try {
      psql(new URL(ADMIN_URL), ['-c', `drop database if exists ${DB_NAME}`]);
    } catch {
      process.stderr.write(`aviso: no se pudo eliminar la base ${DB_NAME}\n`);
    }
  }
}
