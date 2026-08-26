<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table($this->prefix().'action_runs', function (Blueprint $table): void {
            $table->unsignedInteger('reclaim_stale_after_seconds')->nullable()->after('started_at');

            $table->timestamp('reclaim_stale_at')->nullable()->after('reclaim_stale_after_seconds');

            $table->index('reclaim_stale_at');
        });

        $schema->table($this->prefix().'compensation_runs', function (Blueprint $table): void {
            $table->unsignedInteger('reclaim_stale_after_seconds')->nullable()->after('started_at');
            $table->timestamp('reclaim_stale_at')->nullable()->after('reclaim_stale_after_seconds');

            $table->unsignedInteger('attempts')->default(0)->after('continue_on_failure');

            $table->index('reclaim_stale_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table($this->prefix().'action_runs', function (Blueprint $table): void {
            $table->dropIndex(['reclaim_stale_at']);
            $table->dropColumn(['reclaim_stale_after_seconds', 'reclaim_stale_at']);
        });

        $schema->table($this->prefix().'compensation_runs', function (Blueprint $table): void {
            $table->dropIndex(['reclaim_stale_at']);
            $table->dropColumn(['reclaim_stale_after_seconds', 'reclaim_stale_at', 'attempts']);
        });
    }

    private function connection(): ?string
    {
        return config('saga-lara-flow.database.connection');
    }

    private function prefix(): string
    {
        return (string) config('saga-lara-flow.database.table_prefix', '');
    }
};
