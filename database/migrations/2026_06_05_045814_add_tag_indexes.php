<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('contact_tag', function (Blueprint $table) {
            $table->index('contact_id');
            $table->index('tag_id');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->index(['vault_id', 'name']);
            $table->index(['vault_id', 'slug']);
        });
    }

    public function down()
    {
        Schema::table('contact_tag', function (Blueprint $table) {
            $table->dropIndex(['contact_id']);
            $table->dropIndex(['tag_id']);
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropIndex(['vault_id', 'name']);
            $table->dropIndex(['vault_id', 'slug']);
        });
    }
};
