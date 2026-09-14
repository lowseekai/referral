<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('referral_invite_codes')) {
            return;
        }

        $connection = $schema->getConnection();

        if ($connection->getDriverName() !== 'mysql') {
            try {
                $schema->table('referral_invite_codes', function (Blueprint $table) {
                    $table->dropUnique(['user_id']);
                });
            } catch (\Throwable $e) {
                // The first multi-code migration already attempts this for non-MySQL test drivers.
            }

            return;
        }

        $table = $connection->getTablePrefix().'referral_invite_codes';
        $quotedTable = '`'.str_replace('`', '``', $table).'`';
        $indexes = $connection->select("SHOW INDEX FROM {$quotedTable} WHERE Column_name = 'user_id' AND Non_unique = 0");

        foreach ($indexes as $index) {
            $name = $index->Key_name ?? $index->key_name ?? null;

            if (! $name || $name === 'PRIMARY') {
                continue;
            }

            $quotedName = '`'.str_replace('`', '``', $name).'`';
            $connection->statement("ALTER TABLE {$quotedTable} DROP INDEX {$quotedName}");
        }
    },

    'down' => function (Builder $schema) {
        // The rollback in the main multi-code migration restores the old unique index.
    },
];
