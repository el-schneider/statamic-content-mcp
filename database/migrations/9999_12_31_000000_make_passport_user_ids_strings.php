<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statamic user ids are UUID strings; Passport's user_id columns are bigints,
 * which MySQL and Postgres reject them from. Dated last so it runs after
 * Passport's migrations however they were loaded or published.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_device_codes'] as $table) {
            if (! Schema::hasColumn($table, 'user_id') || ! str_contains(Schema::getColumnType($table, 'user_id'), 'int')) {
                continue;
            }

            Schema::table($table, function (Blueprint $table): void {
                $table->string('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Converting back would fail for every row that holds a Statamic id.
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
