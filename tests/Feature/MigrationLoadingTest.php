<?php

use Illuminate\Support\Facades\Schema;

// The provider calls runsMigrations(), so a host app installs with just
// `php artisan migrate` — no vendor:publish step. Two things must hold, and each
// has bitten us before:
//   1. Every migration must actually load. They ship as real `.php` files (not
//      `.php.stub`), because Laravel's migrator only treats a registered path as a
//      migration file when it ends in `.php` — a `.php.stub` path is silently
//      globbed as a directory and skipped (the v1.0.1 bug).
//   2. Each name must carry a timestamp prefix, like every first-party Laravel
//      package migration, so it reads as `2026_07_02_000000_create_...` in the
//      migrations table and `migrate:status` — not a bare, dateless `create_...`
//      (the v1.0.2 wart).
// And since 1.1.0 there is a third: the package ships MORE THAN ONE migration, so
// each additional file needs its own ->hasMigration() call or it never loads.
it('resolves every shipped migration with a timestamped name so migrate:status is well-formed', function (): void {
    $registered = collect(app('migrator')->getMigrationFiles(app('migrator')->paths()))->keys();

    $shipped = collect(glob(__DIR__.'/../../database/migrations/*.php') ?: [])
        ->map(fn (string $path): string => basename($path, '.php'));

    expect($shipped)->not->toBeEmpty();

    foreach ($shipped as $name) {
        expect($name)->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/')
            ->and($registered)->toContain($name);
    }
});

it('creates the engine tables via artisan migrate, with no publish step', function (): void {
    $migration = include __DIR__.'/../../database/migrations/2026_07_02_000000_create_saga_lara_flow_initial_tables.php';
    $migration->down();

    expect(Schema::hasTable('saga_flow_runs'))->toBeFalse();

    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('saga_flow_runs'))->toBeTrue()
        ->and(Schema::hasTable('saga_action_runs'))->toBeTrue()
        ->and(Schema::hasTable('saga_flow_events'))->toBeTrue();
});

it('adds the retry-on-signal columns to action_runs', function (): void {
    expect(Schema::hasColumn('saga_action_runs', 'retry_signal'))->toBeTrue()
        ->and(Schema::hasColumn('saga_action_runs', 'retry_signal_attempts'))->toBeTrue()
        ->and(Schema::hasColumn('saga_action_runs', 'retry_signal_max_attempts'))->toBeTrue()
        ->and(Schema::hasColumn('saga_action_runs', 'queue_attempts_exhausted'))->toBeTrue();
});

it('rolls the retry-on-signal columns back down again', function (): void {
    // The awaiting-retry index references retry_signal; drop it first (same order
    // migrate:rollback would use) or SQLite refuses the column drop.
    $index = include __DIR__.'/../../database/migrations/2026_08_26_000000_index_awaiting_retry_and_unique_flow_tag_keys.php';
    $index->down();

    $migration = include __DIR__.'/../../database/migrations/2026_08_21_000000_add_retry_on_signal_to_action_runs.php';
    $migration->down();

    expect(Schema::hasColumn('saga_action_runs', 'retry_signal'))->toBeFalse()
        ->and(Schema::hasColumn('saga_action_runs', 'retry_signal_attempts'))->toBeFalse()
        ->and(Schema::hasColumn('saga_action_runs', 'retry_signal_max_attempts'))->toBeFalse()
        ->and(Schema::hasColumn('saga_action_runs', 'queue_attempts_exhausted'))->toBeFalse()
        ->and(Schema::hasColumn('saga_action_runs', 'attempts'))->toBeTrue();

    $migration->up();
    $index->up();

    expect(Schema::hasColumn('saga_action_runs', 'retry_signal'))->toBeTrue();
});
