<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE user_action_logs DROP CONSTRAINT IF EXISTS user_action_logs_user_id_foreign');
        DB::statement('ALTER TABLE user_action_logs ALTER COLUMN user_id DROP NOT NULL');
        DB::statement(
            'ALTER TABLE user_action_logs ADD CONSTRAINT user_action_logs_user_id_foreign '
            .'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE user_action_logs DROP CONSTRAINT IF EXISTS user_action_logs_user_id_foreign');
        DB::statement('DELETE FROM user_action_logs WHERE user_id IS NULL');
        DB::statement('ALTER TABLE user_action_logs ALTER COLUMN user_id SET NOT NULL');
        DB::statement(
            'ALTER TABLE user_action_logs ADD CONSTRAINT user_action_logs_user_id_foreign '
            .'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
        );
    }
};
