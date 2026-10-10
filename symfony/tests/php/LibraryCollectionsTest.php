<?php

declare(strict_types=1);

namespace GrindFlow\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use GrindFlow\Library\AssetCollectionApplication;
use GrindFlow\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class LibraryCollectionsTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAssetInMultipleCollectionsKeepsOnePhysicalBlob(): void
    {
        self::bootKernel();
        [$db, $library, $owner, $organization, , $asset] = $this->fixture();
        $first = $library->createCollection($organization, $owner);
        $second = $library->createCollection($organization, $owner);
        self::assertSame('ok', $first['status']);
        self::assertSame('ok', $second['status']);
        self::assertNotSame($first['id'], $second['id']);

        self::assertSame(['status' => 'ok', 'changed' => true],
            $library->attachAsset($organization, $owner, $first['id'], $asset));
        self::assertSame(['status' => 'ok', 'changed' => true],
            $library->attachAsset($organization, $owner, $second['id'], $asset));
        self::assertSame(['status' => 'ok', 'changed' => false],
            $library->attachAsset($organization, $owner, $first['id'], $asset));

        self::assertSame([$asset], $library->assetsForCollection(
            $organization, $owner, $first['id'],
        )['assets']);
        self::assertCount(2, $library->collectionsForAsset(
            $organization, $owner, $asset,
        )['collections']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_assets WHERE id = :asset AND organization_id = :org',
            ['asset' => $asset, 'org' => $organization],
        ));
        self::assertSame(2, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_collection_assets WHERE asset_id = :asset AND organization_id = :org',
            ['asset' => $asset, 'org' => $organization],
        ));
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(DISTINCT storage_key) FROM gf_vault_assets WHERE id = :asset',
            ['asset' => $asset],
        ));
    }

    public function testAttachAssetUuidCasingIsCanonicalAndIdempotent(): void
    {
        self::bootKernel();
        [$db, $library, $actor, $org, , $asset] = $this->fixture();
        $collection = $library->createCollection($org, $actor)['id'];

        self::assertSame(['status' => 'ok', 'changed' => true],
            $library->attachAsset($org, $actor, strtoupper($collection), strtoupper($asset)));
        self::assertSame(['status' => 'ok', 'changed' => false],
            $library->attachAsset($org, $actor, $collection, $asset));

        // Stable lower-case IDs also feed the keyset next_cursor.
        self::assertSame([$asset], $library->assetsForCollection($org, $actor, $collection)['assets']);
        self::assertSame([$collection], $library->collectionsForAsset($org, $actor, $asset)['collections']);
        $persisted = $db->fetchAssociative(
            'SELECT collection_id, asset_id FROM gf_vault_collection_assets WHERE organization_id = :org',
            ['org' => $org],
        );
        self::assertIsArray($persisted);
        self::assertSame($collection, $persisted['collection_id']);
        self::assertSame($asset, $persisted['asset_id']);
        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_collection_assets WHERE organization_id = :org',
            ['org' => $org],
        ));
    }

    public function testVariantsHaveTypedMasterAndCanBeListedWithoutMutation(): void
    {
        self::bootKernel();
        [$db, $library, $actor, $org, , $master] = $this->fixture();
        $variant = $this->addAsset($db, $org, $actor);
        self::assertSame(['status' => 'ok', 'changed' => true],
            $library->linkVariant($org, $actor, $master, $variant, 'format'));
        self::assertSame(['status' => 'ok', 'changed' => false],
            $library->linkVariant($org, $actor, $master, $variant, 'format'));
        self::assertSame([['asset_id' => $variant, 'type' => 'format']],
            $library->variantsForMaster($org, $actor, $master)['variants']);
        self::assertSame('invalid',
            $library->linkVariant($org, $actor, $master, $master, 'format')['status']);
        self::assertSame('invalid',
            $library->linkVariant($org, $actor, $master, $variant, 'unknown')['status']);
        $otherMaster = $this->addAsset($db, $org, $actor);
        self::assertSame('conflict',
            $library->linkVariant($org, $actor, $otherMaster, $variant, 'network')['status']);
        self::assertSame('conflict',
            $library->linkVariant($org, $actor, $variant, $otherMaster, 'campaign')['status']);
        self::assertSame('conflict',
            $library->linkVariant($org, $actor, $variant, $master, 'campaign')['status']);
    }

    public function testVariantUuidCaseIsIdempotentAndSelfLinkStillInvalid(): void
    {
        self::bootKernel();
        [$db, $library, $actor, $org, , $master] = $this->fixture();
        $variant = $this->addAsset($db, $org, $actor);
        self::assertSame(['status' => 'ok', 'changed' => true],
            $library->linkVariant($org, $actor, $master, $variant, 'format'));

        self::assertSame(['status' => 'ok', 'changed' => false],
            $library->linkVariant($org, $actor, strtoupper($master), strtoupper($variant), 'format'));
        self::assertSame(['status' => 'ok', 'changed' => false],
            $library->linkVariant($org, $actor, $master, strtoupper($variant), 'format'));

        self::assertSame('invalid',
            $library->linkVariant($org, $actor, strtoupper($master), $master, 'network')['status']);
        self::assertSame('invalid',
            $library->linkVariant($org, $actor, $variant, strtoupper($variant), 'campaign')['status']);

        self::assertSame(1, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_asset_variants WHERE organization_id = :org AND variant_asset_id = :variant',
            ['org' => $org, 'variant' => $variant],
        ));
    }

    public function testAllRelationsRemainDiscoverableBeyondHundredRows(): void
    {
        self::bootKernel();
        [$db, $library, $actor, $org, , $master] = $this->fixture();
        $bucket = $library->createCollection($org, $actor)['id'];

        // 101 links of each type: the last row must remain reachable.
        for ($index = 0; $index < 101; ++$index) {
            $collection = $library->createCollection($org, $actor);
            self::assertSame('ok', $collection['status']);
            self::assertSame('ok', $library->attachAsset(
                $org, $actor, $collection['id'], $master,
            )['status']);

            $variant = $this->addAsset($db, $org, $actor);
            self::assertSame('ok', $library->attachAsset(
                $org, $actor, $bucket, $variant,
            )['status']);
            self::assertSame('ok', $library->linkVariant(
                $org, $actor, $master, $variant, 'format',
            )['status']);
        }

        foreach ([
            ['method' => 'collectionsForAsset', 'id' => $master, 'field' => 'collections'],
            ['method' => 'assetsForCollection', 'id' => $bucket, 'field' => 'assets'],
            ['method' => 'variantsForMaster', 'id' => $master, 'field' => 'variants'],
        ] as $case) {
            $first = $library->{$case['method']}($org, $actor, $case['id']);
            self::assertSame('ok', $first['status']);
            self::assertCount(100, $first[$case['field']]);
            self::assertTrue($first['has_more']);
            self::assertNotNull($first['next_cursor']);
            $second = $library->{$case['method']}(
                $org, $actor, $case['id'], $first['next_cursor'],
            );
            self::assertSame('ok', $second['status']);
            self::assertCount(1, $second[$case['field']]);
            self::assertFalse($second['has_more']);
            self::assertNull($second['next_cursor']);
            self::assertSame('missing', $library->{$case['method']}(
                $org, $actor, $case['id'], 'not-a-uuid',
            )['status']);
        }
        self::assertSame(102, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_collections WHERE organization_id = :org',
            ['org' => $org],
        ));
    }

    public function testCrossTenantMembershipAndReferencesFailClosed(): void
    {
        self::bootKernel();
        [$db, $library, $actor, $org, $foreignOrg, $ownAsset, $foreignAsset] = $this->fixture();
        $collection = $library->createCollection($org, $actor)['id'];

        self::assertSame('missing',
            $library->attachAsset($org, $actor, $collection, $foreignAsset)['status']);
        self::assertSame('missing',
            $library->linkVariant($org, $actor, $ownAsset, $foreignAsset, 'network')['status']);

        // Mixed-case UUID aliases must not bypass tenant-scoped asset checks.
        self::assertSame('missing',
            $library->attachAsset($org, $actor, strtoupper($collection), strtoupper($foreignAsset))['status']);
        self::assertSame('missing',
            $library->linkVariant($org, $actor, strtoupper($ownAsset), strtoupper($foreignAsset), 'network')['status']);
        self::assertSame('forbidden',
            $library->createCollection($foreignOrg, $actor)['status']);
        self::assertSame('missing',
            $library->assetsForCollection($foreignOrg, $actor, $collection)['status']);
        self::assertSame('missing',
            $library->variantsForMaster($org, $actor, $foreignAsset)['status']);
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_collection_assets WHERE collection_id = :collection',
            ['collection' => $collection],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_asset_variants WHERE organization_id = :org',
            ['org' => $org],
        ));
    }

    public function testCompositeForeignKeysRejectAllFourCrossTenantInserts(): void
    {
        self::bootKernel();
        [$db, , $actor, $org, $foreignOrg, $ownAsset, $foreignAsset] = $this->fixture();
        $ownCollection = Uuid::v7()->toRfc4122();
        $foreignCollection = Uuid::v7()->toRfc4122();
        $createdAt = gmdate('Y-m-d H:i:s');

        foreach ([
            ['id' => $ownCollection, 'organization_id' => $org, 'created_at' => $createdAt],
            ['id' => $foreignCollection, 'organization_id' => $foreignOrg, 'created_at' => $createdAt],
        ] as $row) {
            $db->insert('gf_vault_collections', $row);
        }

        // Direct SQL bypasses application authorization. Each attempt violates
        // exactly one of the four composite tenant FKs of the migration.
        $invalidInserts = [
            'collection-assets: foreign asset' => [
                'gf_vault_collection_assets',
                ['organization_id' => $org, 'collection_id' => $ownCollection,
                 'asset_id' => $foreignAsset, 'created_at' => $createdAt],
            ],
            'collection-assets: foreign collection' => [
                'gf_vault_collection_assets',
                ['organization_id' => $org, 'collection_id' => $foreignCollection,
                 'asset_id' => $ownAsset, 'created_at' => $createdAt],
            ],
            'asset-variants: foreign variant' => [
                'gf_vault_asset_variants',
                ['organization_id' => $org, 'master_asset_id' => $ownAsset,
                 'variant_asset_id' => $foreignAsset, 'variant_type' => 'format',
                 'created_at' => $createdAt],
            ],
            'asset-variants: foreign master' => [
                'gf_vault_asset_variants',
                ['organization_id' => $org, 'master_asset_id' => $foreignAsset,
                 'variant_asset_id' => $ownAsset, 'variant_type' => 'network',
                 'created_at' => $createdAt],
            ],
        ];
        foreach ($invalidInserts as $name => [$table, $row]) {
            try {
                $db->insert($table, $row);
                self::fail('Cross-tenant insert unexpectedly succeeded: '.$name);
            } catch (ForeignKeyConstraintViolationException $exception) {
                self::assertNotSame('', $exception->getMessage(), $name);
            }
        }
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_collection_assets WHERE organization_id = :org',
            ['org' => $org],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM gf_vault_asset_variants WHERE organization_id = :org',
            ['org' => $org],
        ));
    }

    /** @return array{Connection,AssetCollectionApplication,string,string,string,string,string} */
    private function fixture(): array
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $actor = Uuid::v7()->toRfc4122();
        $organization = Uuid::v7()->toRfc4122();
        $foreign = Uuid::v7()->toRfc4122();
        $at = gmdate('Y-m-d H:i:s');
        $db->insert('gf_identity_users', [
            'id' => $actor,
            'name' => 'Library fixture',
            'email' => $actor.'@example.test',
            'password_hash' => password_hash('synthetic-only-fixture-secret', PASSWORD_BCRYPT),
            'platform_role' => 'model',
            'is_active' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        foreach ([$organization, $foreign] as $org) {
            $db->insert('gf_identity_organizations', [
                'id' => $org,
                'name' => 'Library fixture',
                'slug' => 'lib-'.substr($org, 0, 32),
                'type' => 'independent',
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
        $db->insert('gf_identity_memberships', [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $actor,
            'organization_id' => $organization,
            'role' => 'editor',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $ownAsset = $this->addAsset($db, $organization, $actor);
        $foreignAsset = $this->addAsset($db, $foreign, $actor);
        return [$db, new AssetCollectionApplication($db), $actor, $organization, $foreign, $ownAsset, $foreignAsset];
    }

    private function addAsset(Connection $db, string $org, string $user): string
    {
        $id = Uuid::v7()->toRfc4122();
        $db->insert('gf_vault_assets', [
            'id' => $id,
            'organization_id' => $org,
            'uploaded_by' => $user,
            'original_name' => 'synthetic.png',
            'mime_type' => 'image/png',
            'size_bytes' => 10,
            'sha256' => hash('sha256', $id),
            'storage_key' => Uuid::v7()->toRfc4122(),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }
}
