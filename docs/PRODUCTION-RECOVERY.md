# Production Recovery · DB + Private Vault

This runbook covers the pre-pilot recovery contract. It does **not** authorize a destructive production restore.

## Authority and preconditions

Production Backup remains a manual, repository-owner-only workflow. Before starting it, the operator must confirm the current release and migration batch are the intended ones and that the application is in a controlled write window. The workflow never runs migrations and never uploads a real backup to GitHub Actions artifacts.

The recovery key is `GF_RECOVERY_KEY_B64`. It must contain exactly 32 random bytes encoded as base64 and must live only in protected production configuration / secret storage. Never paste the key into GitHub, Issues, logs, summaries or artifacts. Missing libsodium, missing secretstream functions, a missing/invalid key, an unsafe Vault root, or failed integrity checks must stop the backup.

## Encrypted bundle

The server builds a private plaintext staging directory containing:

- `database.sql.gz` — verified MariaDB dump;
- `vault/` — a snapshot of the Private Vault originals;
- `vault-manifest.json` — sorted SHA-256/size index of the staged Vault;
- `metadata.json` — release version/SHA, migration fingerprint, Vault index hash and UTC creation time.

The plaintext tar is ephemeral. `scripts/recovery-secretstream.php` encrypts it with libsodium secretstream XChaCha20-Poly1305 into a `.gfrec` ciphertext. The plaintext tar and Vault staging copy are deleted on success and error. The existing database archive remains governed by `VerifiedBackupEvidence`; the joint recovery bundle has separate `VerifiedRecoveryEvidence`.

The private receipt binds:

- `ciphertext_sha256`;
- `migration_fingerprint`;
- `vault_index_sha256`;
- `release_version`;
- `release_sha`;
- UTC creation time.

Only hashes and release identity are safe evidence. Filesystem paths, Vault filenames, database credentials, the key and backup contents are not copied to GitHub.

## Disposable restore drill

CI uses synthetic data and a synthetic test key. The drill must use the same `recovery-secretstream.php` format:

1. create synthetic MariaDB + Vault state;
2. build the bundle and encrypt it;
3. delete the plaintext bundle/stage used as the recovery source;
4. decrypt into a fresh private directory;
5. verify bundle metadata and Vault manifest;
6. restore MariaDB;
7. restore Vault originals;
8. run `grindflow:vault:verify-restore`;
9. verify schema parity plus tenant/IDOR guards.

The drill is valid only with `APP_ENV=test`, `CI=true` and `GF_RESTORE_DRILL_APPROVED=1`.

## Restore destructivo

A restore destructivo on production is human-only. Do not automate or infer authorization from a passing backup, CI run or receipt.

Before any production restore:

1. escalate to the owner and obtain explicit authorization for the exact release/receipt;
2. preserve the current production state and stop writes;
3. verify the ciphertext receipt and checksum server-side;
4. decrypt only into a private isolated staging area;
5. perform a disposable/isolated restore first and compare MariaDB schema + Vault manifest;
6. document the rollback target and success criteria without copying PII or secrets.

If any checksum, manifest, schema parity, tenant/IDOR guard or release identity fails, stop and escalate. Do not partially restore production.

## Evidence and rollback

Successful backup evidence is a private server-side receipt. A failed bundle build must remove temporary plaintext and incomplete ciphertext. A failed disposable restore leaves production untouched. Code changes are reversible by Git revert; already-created encrypted backups are never deleted automatically by a code rollback.
