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
            $table->string('retry_signal')->nullable()->after('has_compensation');

            $table->unsignedInteger('retry_signal_max_attempts')->nullable()->after('attempts');

            $table->unsignedInteger('retry_signal_attempts')->default(0)->after('attempts');

            $table->boolean('queue_attempts_exhausted')->default(false)->after('attempts');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        $schema->table($this->prefix().'action_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'retry_signal',
                'retry_signal_attempts',
                'retry_signal_max_attempts',
                'queue_attempts_exhausted',
            ]);
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
