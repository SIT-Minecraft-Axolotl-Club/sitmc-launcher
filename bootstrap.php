<?php

use App\Services\Hook;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use SITMinecraft\Launcher\Announcement\AnnouncementRules;
use SITMinecraft\Launcher\Auth\TokenVerifier;
use SITMinecraft\Launcher\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use SITMinecraft\Launcher\Http\Controllers\Admin\InstanceController;
use SITMinecraft\Launcher\Http\Controllers\Admin\ReleaseController;
use SITMinecraft\Launcher\Http\Controllers\AnnouncementController as PublicAnnouncementController;
use SITMinecraft\Launcher\Http\Controllers\ManifestController;
use SITMinecraft\Launcher\Http\Controllers\PackController;
use SITMinecraft\Launcher\Http\Controllers\SkinController;
use SITMinecraft\Launcher\Http\Controllers\UpdateController;
use SITMinecraft\Launcher\Manifest\PackStore;
use SITMinecraft\Launcher\Update\ReleaseRules;

/**
 * Wires the plugin into Blessing Skin without touching any core file.
 *
 * Everything the plugin needs is registered here: its own translation and view
 * namespaces, the pack storage binding, the admin menu entry, and the routes.
 * Only an enabled plugin is bootstrapped, so a disabled plugin adds nothing.
 */
return function ($plugin) {
    $path = $plugin->getPath();

    app('translator')->addNamespace('sitmc-launcher', $path.'/lang');
    app('view')->addNamespace('sitmc-launcher', $path.'/views');

    app()->singleton(PackStore::class, function () {
        return new PackStore(Storage::disk(config('sitmc-launcher.disk', 'local')));
    });

    app()->singleton(TokenVerifier::class, function () {
        return new TokenVerifier(config(
            'sitmc-launcher.userinfo_url',
            'https://skin.sitmc.club/api/janus/userinfo'
        ));
    });

    Hook::addMenuItem('admin', 3, [
        'title' => 'sitmc-launcher::launcher.menu',
        'link' => 'admin/launcher',
        'icon' => 'fa-cube',
    ]);

    Hook::addMenuItem('admin', 4, [
        'title' => 'sitmc-launcher::launcher.announcements.menu',
        'link' => 'admin/launcher/announcements',
        'icon' => 'fa-bullhorn',
    ]);

    Hook::addMenuItem('admin', 5, [
        'title' => 'sitmc-launcher::launcher.releases.menu',
        'link' => 'admin/launcher/releases',
        'icon' => 'fa-cloud-download-alt',
    ]);

    Hook::addRoute(function (Router $router) {
        $router->group(
            ['middleware' => ['api', 'throttle:60,1'], 'prefix' => 'api/launcher'],
            function () use ($router) {
                $router->get('manifest', [ManifestController::class, 'show'])
                    ->name('sitmc-launcher.manifest');

                $router->get('packs/{instanceId}/{revision}', [PackController::class, 'show'])
                    ->where(['instanceId' => '[A-Za-z0-9_-]+', 'revision' => '[0-9]+'])
                    ->name('sitmc-launcher.pack');

                $router->get('announcements', [PublicAnnouncementController::class, 'index'])
                    ->name('sitmc-launcher.announcements');

                $router->get('update/latest', [UpdateController::class, 'latest'])
                    ->name('sitmc-launcher.update.latest');

                $router->get('update/{target}/{arch}/{currentVersion}', [UpdateController::class, 'dynamic'])
                    ->where([
                        'target' => ReleaseRules::PLATFORM_PATTERN,
                        'arch' => ReleaseRules::PLATFORM_PATTERN,
                        'currentVersion' => '[0-9A-Za-z._+-]+',
                    ])
                    ->name('sitmc-launcher.update.dynamic');

                // Skin management for the launcher. These authenticate the
                // player's own launcher session instead of a Passport token, so
                // they throttle a little tighter than the read-only endpoints.
                $router->group(['middleware' => ['throttle:30,1']], function () use ($router) {
                    $router->get('skins', [SkinController::class, 'index'])
                        ->name('sitmc-launcher.skins.index');

                    $router->post('skins', [SkinController::class, 'upload'])
                        ->name('sitmc-launcher.skins.upload');

                    $router->put('skins/equip', [SkinController::class, 'equip'])
                        ->name('sitmc-launcher.skins.equip');

                    $router->delete('skins', [SkinController::class, 'destroy'])
                        ->name('sitmc-launcher.skins.destroy');
                });
            }
        );

        $router->group(
            ['middleware' => ['web', 'authorize', 'role:admin'], 'prefix' => 'admin/launcher'],
            function () use ($router) {
                $router->get('', [InstanceController::class, 'index'])
                    ->name('sitmc-launcher.admin.index');

                $router->post('settings', [InstanceController::class, 'saveSettings'])
                    ->name('sitmc-launcher.admin.settings');

                $router->get('instances/create', [InstanceController::class, 'create'])
                    ->name('sitmc-launcher.admin.create');

                $router->post('instances', [InstanceController::class, 'store'])
                    ->name('sitmc-launcher.admin.store');

                $router->get('instances/{instanceId}/edit', [InstanceController::class, 'edit'])
                    ->where('instanceId', '[A-Za-z0-9_-]+')
                    ->name('sitmc-launcher.admin.edit');

                $router->put('instances/{instanceId}', [InstanceController::class, 'update'])
                    ->where('instanceId', '[A-Za-z0-9_-]+')
                    ->name('sitmc-launcher.admin.update');

                $router->delete('instances/{instanceId}', [InstanceController::class, 'destroy'])
                    ->where('instanceId', '[A-Za-z0-9_-]+')
                    ->name('sitmc-launcher.admin.destroy');

                $router->post('instances/{instanceId}/packs', [InstanceController::class, 'storePack'])
                    ->where('instanceId', '[A-Za-z0-9_-]+')
                    ->name('sitmc-launcher.admin.packs.store');

                $router->post(
                    'instances/{instanceId}/packs/external',
                    [InstanceController::class, 'storeExternalPack']
                )
                    ->where('instanceId', '[A-Za-z0-9_-]+')
                    ->name('sitmc-launcher.admin.packs.external');

                $router->post(
                    'instances/{instanceId}/packs/{revision}/activate',
                    [InstanceController::class, 'activatePack']
                )
                    ->where(['instanceId' => '[A-Za-z0-9_-]+', 'revision' => '[0-9]+'])
                    ->name('sitmc-launcher.admin.packs.activate');

                $router->delete(
                    'instances/{instanceId}/packs/{revision}',
                    [InstanceController::class, 'destroyPack']
                )
                    ->where(['instanceId' => '[A-Za-z0-9_-]+', 'revision' => '[0-9]+'])
                    ->name('sitmc-launcher.admin.packs.destroy');

                $router->get('announcements', [AdminAnnouncementController::class, 'index'])
                    ->name('sitmc-launcher.admin.announcements.index');

                $router->get('announcements/create', [AdminAnnouncementController::class, 'create'])
                    ->name('sitmc-launcher.admin.announcements.create');

                $router->post('announcements', [AdminAnnouncementController::class, 'store'])
                    ->name('sitmc-launcher.admin.announcements.store');

                $router->get('announcements/{announcementId}/edit', [
                    AdminAnnouncementController::class, 'edit',
                ])
                    ->where('announcementId', AnnouncementRules::ID_PATTERN)
                    ->name('sitmc-launcher.admin.announcements.edit');

                $router->put('announcements/{announcementId}', [
                    AdminAnnouncementController::class, 'update',
                ])
                    ->where('announcementId', AnnouncementRules::ID_PATTERN)
                    ->name('sitmc-launcher.admin.announcements.update');

                $router->delete('announcements/{announcementId}', [
                    AdminAnnouncementController::class, 'destroy',
                ])
                    ->where('announcementId', AnnouncementRules::ID_PATTERN)
                    ->name('sitmc-launcher.admin.announcements.destroy');

                $router->get('releases', [ReleaseController::class, 'index'])
                    ->name('sitmc-launcher.admin.releases.index');

                $router->get('releases/create', [ReleaseController::class, 'create'])
                    ->name('sitmc-launcher.admin.releases.create');

                $router->post('releases', [ReleaseController::class, 'store'])
                    ->name('sitmc-launcher.admin.releases.store');

                $router->get('releases/{id}/edit', [ReleaseController::class, 'edit'])
                    ->where('id', '[0-9]+')
                    ->name('sitmc-launcher.admin.releases.edit');

                $router->put('releases/{id}', [ReleaseController::class, 'update'])
                    ->where('id', '[0-9]+')
                    ->name('sitmc-launcher.admin.releases.update');

                $router->delete('releases/{id}', [ReleaseController::class, 'destroy'])
                    ->where('id', '[0-9]+')
                    ->name('sitmc-launcher.admin.releases.destroy');
            }
        );
    });
};
