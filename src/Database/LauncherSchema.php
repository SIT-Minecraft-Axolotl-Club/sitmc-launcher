<?php

namespace SITMinecraft\Launcher\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables owned by this plugin.
 *
 * A Blessing Skin installation runs the core migration set on its own schedule,
 * so the plugin keeps creating its tables idempotently instead of depending on
 * the core migration runner. Every check is a `hasTable` guard, which makes the
 * call safe to repeat on every request that boots the plugin.
 */
final class LauncherSchema
{
    public const INSTANCES = 'sitmc_launcher_instances';

    public const PACKS = 'sitmc_launcher_packs';

    public const SETTINGS = 'sitmc_launcher_settings';

    public const ANNOUNCEMENTS = 'sitmc_launcher_announcements';

    public const RELEASES = 'sitmc_launcher_releases';

    public static function install(): void
    {
        if (! Schema::hasTable(self::INSTANCES)) {
            Schema::create(self::INSTANCES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('instance_id', 64)->unique();
                $table->string('name', 128);
                $table->text('description')->nullable();
                $table->string('icon_url', 512)->nullable();
                $table->integer('sort')->default(0);
                $table->unsignedInteger('revision')->default(1);
                $table->string('game_version', 64);
                $table->string('loader', 32)->nullable();
                $table->string('loader_version', 64)->nullable();
                $table->string('server_address', 255)->nullable();
                $table->unsignedSmallInteger('server_port')->nullable();
                $table->boolean('required')->default(true);
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(self::PACKS)) {
            Schema::create(self::PACKS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('instance_id', 64);
                $table->unsignedInteger('revision');
                $table->string('url', 512)->nullable();
                $table->string('disk_path', 512)->nullable();
                $table->string('original_name', 255)->nullable();
                $table->string('sha1', 40);
                $table->unsignedBigInteger('size');
                $table->timestamps();

                $table->unique(['instance_id', 'revision']);
            });
        }

        if (! Schema::hasTable(self::SETTINGS)) {
            Schema::create(self::SETTINGS, static function (Blueprint $table): void {
                $table->string('name', 64)->primary();
                $table->text('value')->nullable();
            });
        }

        if (! Schema::hasTable(self::ANNOUNCEMENTS)) {
            Schema::create(self::ANNOUNCEMENTS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('announcement_id', 100)->unique();
                $table->string('title', 120);
                $table->string('summary', 300)->nullable();
                $table->mediumText('content');
                $table->string('type', 16)->default('notification');
                $table->string('priority', 16)->default('normal');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->string('action_url', 512)->nullable();
                $table->string('action_label', 80)->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(self::RELEASES)) {
            Schema::create(self::RELEASES, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('channel', 16);
                $table->string('version', 64);
                $table->mediumText('notes')->nullable();
                $table->timestamp('pub_date')->nullable();
                $table->string('url', 512)->nullable();
                $table->text('signature')->nullable();
                $table->string('target', 64)->nullable();
                $table->string('arch', 64)->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->index(['channel', 'enabled']);
            });
        }
    }

    /**
     * Drops everything the plugin created. Only the plugin's own tables are
     * touched, so a Blessing Skin installation keeps its users and players.
     */
    public static function uninstall(): void
    {
        Schema::dropIfExists(self::RELEASES);
        Schema::dropIfExists(self::ANNOUNCEMENTS);
        Schema::dropIfExists(self::PACKS);
        Schema::dropIfExists(self::INSTANCES);
        Schema::dropIfExists(self::SETTINGS);
    }
}
