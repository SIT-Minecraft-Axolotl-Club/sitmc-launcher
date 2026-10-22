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

    /**
     * mc-interop v1 tables. They are additive: the legacy manifest keeps reading
     * its own tables, and removing this plugin still removes everything.
     */
    public const INTEROP_PEERS = 'sitmc_launcher_interop_peers';

    public const INTEROP_SNAPSHOTS = 'sitmc_launcher_interop_snapshots';

    public const INTEROP_SERVERS = 'sitmc_launcher_interop_servers';

    public const INTEROP_ENDPOINTS = 'sitmc_launcher_interop_endpoints';

    /**
     * The guard is per process: one request resolves both the legacy repository
     * and the interop repository, so without it the same `hasTable` checks would
     * run twice per request.
     */
    private static bool $installed = false;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

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

        if (! Schema::hasTable(self::INTEROP_PEERS)) {
            Schema::create(self::INTEROP_PEERS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('site_id', 64)->unique();
                $table->string('name', 128)->nullable();
                $table->text('description')->nullable();
                $table->string('origin', 512);
                $table->string('manifest_url', 512)->nullable();
                $table->string('trust', 16)->default('linked');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(self::INTEROP_SNAPSHOTS)) {
            Schema::create(self::INTEROP_SNAPSHOTS, static function (Blueprint $table): void {
                $table->string('site_id', 64)->primary();
                $table->string('origin', 512);
                $table->string('etag', 255)->nullable();
                $table->string('manifest_version', 16)->nullable();
                $table->longText('document')->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->boolean('stale')->default(true);
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(self::INTEROP_SERVERS)) {
            Schema::create(self::INTEROP_SERVERS, static function (Blueprint $table): void {
                $table->string('instance_id', 64)->primary();
                $table->string('update_policy', 16)->nullable();
                $table->string('optional_pack_id', 64)->nullable();
                $table->string('optional_pack_version', 64)->nullable();
                $table->string('season_kind', 16)->nullable();
                $table->string('season_pack_id', 64)->nullable();
                $table->string('season_pack_version', 64)->nullable();
                $table->string('season_game_version', 64)->nullable();
                $table->string('season_loader', 32)->nullable();
                $table->string('season_loader_version', 64)->nullable();
                $table->string('season_id', 64)->nullable();
                $table->string('season_name', 128)->nullable();
                $table->boolean('enabled')->default(true);
                $table->boolean('published')->default(false);
                $table->timestamps();
            });
        }

        // Additive columns for installations that already ran an older version
        // of the plugin. Publication defaults to off: an instance must be
        // explicitly selected before it appears in the unified exit.
        if (Schema::hasTable(self::INTEROP_PEERS) && ! Schema::hasColumn(self::INTEROP_PEERS, 'description')) {
            Schema::table(self::INTEROP_PEERS, static function (Blueprint $table): void {
                $table->text('description')->nullable();
            });
        }

        if (Schema::hasTable(self::INTEROP_SERVERS) && ! Schema::hasColumn(self::INTEROP_SERVERS, 'published')) {
            Schema::table(self::INTEROP_SERVERS, static function (Blueprint $table): void {
                $table->boolean('published')->default(false);
            });
        }

        if (! Schema::hasTable(self::INTEROP_ENDPOINTS)) {
            Schema::create(self::INTEROP_ENDPOINTS, static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('instance_id', 64);
                $table->string('name', 64)->nullable();
                $table->string('address', 255);
                $table->unsignedSmallInteger('port')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->integer('sort')->default(0);
                $table->timestamps();

                $table->index('instance_id');
            });
        }

        self::$installed = true;
    }

    /**
     * Drops everything the plugin created. Only the plugin's own tables are
     * touched, so a Blessing Skin installation keeps its users and players.
     */
    public static function uninstall(): void
    {
        self::$installed = false;

        Schema::dropIfExists(self::INTEROP_ENDPOINTS);
        Schema::dropIfExists(self::INTEROP_SERVERS);
        Schema::dropIfExists(self::INTEROP_SNAPSHOTS);
        Schema::dropIfExists(self::INTEROP_PEERS);
        Schema::dropIfExists(self::RELEASES);
        Schema::dropIfExists(self::ANNOUNCEMENTS);
        Schema::dropIfExists(self::PACKS);
        Schema::dropIfExists(self::INSTANCES);
        Schema::dropIfExists(self::SETTINGS);
    }
}
