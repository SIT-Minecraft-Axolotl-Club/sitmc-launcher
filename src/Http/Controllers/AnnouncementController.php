<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Response;
use SITMinecraft\Launcher\Announcement\AnnouncementBuilder;
use SITMinecraft\Launcher\Announcement\AnnouncementRepository;

/**
 * The public endpoint the launcher downloads remote announcements from.
 *
 * The body is a bare JSON array: the launcher parses the response as the array
 * itself, so a wrapper object would be read as an empty document. It is also the
 * only endpoint that decides which announcements are current — entries outside
 * their window, disabled ones and entries breaking a client rule are dropped
 * before the document is written.
 *
 * The launcher appends `?version=` and `?channel=` for future targeting. Nothing
 * is filtered on them today, so the document stays valid for every caller.
 */
final class AnnouncementController
{
    public function __construct(private AnnouncementRepository $repository) {}

    public function index(): Response
    {
        $builder = new AnnouncementBuilder();
        $entries = $builder->build($this->repository->rows());

        return response($builder->encode($entries), 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
