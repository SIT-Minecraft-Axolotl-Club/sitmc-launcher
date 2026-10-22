<?php

use App\Events\PluginWasDeleted;
use App\Events\PluginWasEnabled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Lifecycle callbacks Blessing Skin runs when the plugin's state changes.
 *
 * A plugin that is being enabled is NOT autoloaded yet: Blessing Skin only
 * registers the PSR-4 mapping of plugins that were already enabled when the
 * request booted. The callback therefore registers the plugin's own mapping
 * first, and only then touches its classes.
 *
 * Tables are created when the plugin is enabled (idempotently) and removed,
 * together with the uploaded packs, only when the plugin is deleted.
 * {@see SITMinecraft\Launcher\Database\LauncherSchema} owns every table the
 * plugin creates — instances, packs, settings, announcements and releases — so
 * both callbacks only have to call into it.
 */
$registerAutoload = static function ($plugin): void {
    $prefix = rtrim((string) $plugin->namespace, '\\').'\\';
    $root = $plugin->getPath().'/src/';

    spl_autoload_register(static function (string $class) use ($prefix, $root): void {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = $root.$relative.'.php';

        if (is_file($file)) {
            require $file;
        }
    });
};

return [
    PluginWasEnabled::class => function ($plugin) use ($registerAutoload) {
        $registerAutoload($plugin);

        SITMinecraft\Launcher\Database\LauncherSchema::install();
    },

    PluginWasDeleted::class => function ($plugin) use ($registerAutoload) {
        $registerAutoload($plugin);

        $packs = SITMinecraft\Launcher\Database\LauncherSchema::PACKS;

        if (Schema::hasTable($packs)) {
            $store = new SITMinecraft\Launcher\Manifest\PackStore(
                Storage::disk(config('sitmc-launcher.disk', 'local'))
            );

            DB::table($packs)
                ->select('disk_path')
                ->orderBy('id')
                ->chunk(200, function ($rows) use ($store): void {
                    foreach ($rows as $row) {
                        $store->forget($row->disk_path);
                    }
                });
        }

        SITMinecraft\Launcher\Database\LauncherSchema::uninstall();
    },
];
