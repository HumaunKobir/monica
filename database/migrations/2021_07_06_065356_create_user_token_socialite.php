<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('driver_id', 100);
            $table->string('driver', 50);
            $table->char('format', 6);
            $table->string('email', 100)->nullable();
            $table->string('token', 100);
            $table->string('token_secret', 100)->nullable();
            $table->string('refresh_token', 100)->nullable();
            $table->unsignedBigInteger('expires_in')->nullable();
            $table->timestamps();

            $table->index(['driver', 'driver_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::dropIfExists('user_tokens');
    }
};
