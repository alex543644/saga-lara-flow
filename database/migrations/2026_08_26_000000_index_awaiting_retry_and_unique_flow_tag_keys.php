<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table($this->prefix().'action_runs', function (Blueprint $table): void {
            $table->index(['status', 'retry_signal', 'flow_run_id']);
        });

        $this->collapseDuplicateTagKeys();

        $schema->table($this->prefix().'flow_tags', function (Blueprint $table): void {
            $table->dropUnique(['flow_run_id', 'key', 'value']);
            $table->unique(['flow_run_id', 'key']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table($this->prefix().'flow_tags', function (Blueprint $table): void {
            $table->dropUnique(['flow_run_id', 'key']);
            $table->unique(['flow_run_id', 'key', 'value']);
        });

        $schema->table($this->prefix().'action_runs', function (Blueprint $table): void {
            $table->dropIndex(['status', 'retry_signal', 'flow_run_id']);
        });
    }

    /**
     * Keep the newest row per (flow_run_id, key) so the unique(key) swap can apply.
     */
    private function collapseDuplicateTagKeys(): void
    {
        $connection = DB::connection($this->connection());
        $table = $this->prefix().'flow_tags';

        $duplicates = $connection
            ->table($table)
            ->select('flow_run_id', 'key')
            ->groupBy('flow_run_id', 'key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $keepId = $connection
                ->table($table)
                ->where('flow_run_id', $duplicate->flow_run_id)
                ->where('key', $duplicate->key)
                ->orderByDesc('id')
                ->value('id');

            $connection
                ->table($table)
                ->where('flow_run_id', $duplicate->flow_run_id)
                ->where('key', $duplicate->key)
                ->where('id', '!=', $keepId)
                ->delete();
        }
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
