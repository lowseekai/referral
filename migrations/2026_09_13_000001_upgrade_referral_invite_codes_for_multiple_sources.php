<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Turn personal invite codes into a single-use ledger for the 2026-09-13
 * deployment line.
 *
 * Existing installations used a unique user_id index and a uses counter.
 * The new flow allows multiple codes per user, records how each code was
 * obtained, and reserves a code briefly while a registration request is being
 * persisted so concurrent signups cannot pass validation with the same code.
 */
return [
    'up' => function (Builder $schema) {
        $connection = $schema->getConnection();

        try {
            $schema->table('referral_invite_codes', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
            });
        } catch (\Throwable $e) {
            // Fresh installs or drivers that use a generated index name may
            // not expose this exact index. The raw fallback below handles it.
        }

        $prefix = $connection->getTablePrefix();
        $table = $prefix.'referral_invite_codes';

        try {
            $connection->statement("ALTER TABLE {$table} DROP INDEX referral_invite_codes_user_id_unique");
        } catch (\Throwable $e) {
            // Index already removed or named differently.
        }

        $columns = [
            'channel' => fn (Blueprint $table) => $table->string('channel', 32)->default('legacy'),
            'source_group_id' => fn (Blueprint $table) => $table->unsignedInteger('source_group_id')->nullable(),
            'price_paid' => fn (Blueprint $table) => $table->unsignedInteger('price_paid')->nullable(),
            'purchased_at' => fn (Blueprint $table) => $table->timestamp('purchased_at')->nullable(),
            'reserved_at' => fn (Blueprint $table) => $table->timestamp('reserved_at')->nullable(),
            'reservation_token' => fn (Blueprint $table) => $table->string('reservation_token', 64)->nullable(),
            'used_at' => fn (Blueprint $table) => $table->timestamp('used_at')->nullable(),
            'used_by_user_id' => fn (Blueprint $table) => $table->unsignedInteger('used_by_user_id')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! $schema->hasColumn('referral_invite_codes', $name)) {
                $schema->table('referral_invite_codes', $definition);
            }
        }

        // Codes created by the campaign endpoint before this migration are
        // identifiable by their NULL owner.
        $connection->table('referral_invite_codes')
            ->whereNull('user_id')
            ->where(function ($query) {
                $query->whereNull('channel')->orWhere('channel', 'legacy');
            })
            ->update(['channel' => 'campaign']);

        // Historical uses mean the code has already been consumed. We do not
        // know the old invitee ID, so only the timestamp is backfilled.
        $connection->table('referral_invite_codes')
            ->where('uses', '>', 0)
            ->whereNull('used_at')
            ->update(['used_at' => $connection->raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);

        try {
            $schema->table('referral_invite_codes', function (Blueprint $table) {
                $table->index(['user_id', 'channel'], 'referral_invite_codes_user_channel_index');
                $table->index(['user_id', 'purchased_at'], 'referral_invite_codes_user_purchased_index');
                $table->index(['reservation_token'], 'referral_invite_codes_reservation_token_index');
            });
        } catch (\Throwable $e) {
            // Indexes may already exist after a partially completed deploy.
        }
    },

    'down' => function (Builder $schema) {
        $connection = $schema->getConnection();

        // Keep only the oldest code for each owner before restoring the old
        // one-code-per-user invariant. Campaign codes have NULL owners and are
        // unaffected by the unique index.
        $table = $connection->getTablePrefix().'referral_invite_codes';
        $connection->statement(
            "DELETE FROM {$table} WHERE user_id IS NOT NULL AND id NOT IN "
            ."(SELECT keep_id FROM (SELECT MIN(id) AS keep_id FROM {$table} WHERE user_id IS NOT NULL GROUP BY user_id) kept)"
        );

        try {
            $schema->table('referral_invite_codes', function (Blueprint $table) {
                $table->unique('user_id');
            });
        } catch (\Throwable $e) {
            // Already present.
        }

        foreach ([
            'used_by_user_id',
            'used_at',
            'reservation_token',
            'reserved_at',
            'purchased_at',
            'price_paid',
            'source_group_id',
            'channel',
        ] as $column) {
            if ($schema->hasColumn('referral_invite_codes', $column)) {
                $schema->table('referral_invite_codes', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    },
];
