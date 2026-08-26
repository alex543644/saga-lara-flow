<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shipped indexes on both tables lead with flow_run_id, which answers "what
     * is this run waiting for" — the opposite of what the FlowQuery wait filters
     * ask.
     */
    public function up(): void
    {
        $this->add('flow_signals', ['status', 'name', 'flow_run_id']);
        $this->add('action_runs', ['status', 'retry_signal', 'flow_run_id']);
    }

    public function down(): void
    {
        $this->drop('action_runs', ['status', 'retry_signal', 'flow_run_id']);
        $this->drop('flow_signals', ['status', 'name', 'flow_run_id']);
    }

    /**
     * MySQL runs each statement on its own, so both directions ask first: a run
     * that died after the first index can simply be repeated.
     *
     * @param  list<string>  $columns
     */
    private function add(string $table, array $columns): void
    {
        if ($this->existing($table, $columns) === null) {
            $this->change($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $this->name($table, $columns)));
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function drop(string $table, array $columns): void
    {
        $name = $this->existing($table, $columns);

        if ($name !== null) {
            $this->change($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }

    private function change(string $table, Closure $change): void
    {
        Schema::connection($this->connection())->table($this->prefix().$table, $change);
    }

    /**
     * The index this migration owns, under the name the driver actually stored —
     * PostgreSQL truncates an identifier past 63 bytes, which a long table prefix
     * reaches. Null when it is absent, and also when the name belongs to something
     * shaped differently: creating ours then fails loudly instead of quietly
     * standing down.
     *
     * @param  list<string>  $columns
     */
    private function existing(string $table, array $columns): ?string
    {
        $wanted = $this->name($table, $columns);

        foreach (Schema::connection($this->connection())->getIndexes($this->prefix().$table) as $index) {
            $name = (string) $index['name'];

            $sameName = strcasecmp($name, $wanted) === 0
                || (strlen($name) < strlen($wanted) && str_starts_with(strtolower($wanted), strtolower($name)));

            if ($sameName && $index['columns'] === $columns && ! $index['unique']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The name Laravel would derive on its own, so an index created before this
     * migration started naming them explicitly is still recognised.
     *
     * @param  list<string>  $columns
     */
    private function name(string $table, array $columns): string
    {
        return strtolower($this->prefix().$table.'_'.implode('_', $columns).'_index');
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
