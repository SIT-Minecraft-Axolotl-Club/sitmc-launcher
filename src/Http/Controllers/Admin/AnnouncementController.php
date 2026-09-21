<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SITMinecraft\Launcher\Announcement\AnnouncementRepository;
use SITMinecraft\Launcher\Announcement\AnnouncementRules;
use SITMinecraft\Launcher\Support\Timestamps;

/**
 * Admin screens for the announcements handed to the launcher.
 *
 * The list answers the only question an administrator has about a stored
 * announcement — "will the launcher show this?" — by reporting the client rule it
 * breaks, or that its window has not opened yet. A stored row is never silently
 * broken: it is either published, or explained.
 */
final class AnnouncementController
{
    public function __construct(private AnnouncementRepository $repository) {}

    public function index(): View
    {
        $announcements = [];

        foreach ($this->repository->rows() as $row) {
            $row['problem'] = AnnouncementRules::publishProblem($row);
            $row['window'] = AnnouncementRules::window($row);
            $announcements[] = $row;
        }

        return view('sitmc-launcher::admin.announcements', [
            'announcements' => $announcements,
            'endpointPath' => '/api/launcher/announcements',
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function create(): View
    {
        return view('sitmc-launcher::admin.announcement', [
            'announcement' => null,
            'problems' => [],
            'nowInput' => Timestamps::inputValue(now()),
            'status' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $this->validated($request);

        $this->repository->save($values + [
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route('sitmc-launcher.admin.announcements.edit', $values['announcement_id'])
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.announcement-created'));
    }

    public function edit(string $announcementId): View
    {
        $row = $this->requireAnnouncement($announcementId);

        return view('sitmc-launcher::admin.announcement', [
            'announcement' => self::forForm($row),
            'problems' => AnnouncementRules::problems($row),
            'nowInput' => Timestamps::inputValue(now()),
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function update(Request $request, string $announcementId): RedirectResponse
    {
        $this->requireAnnouncement($announcementId);

        $values = $this->validated($request, $announcementId);
        $values['updated_at'] = now();

        $this->repository->save($values);

        return redirect()
            ->route('sitmc-launcher.admin.announcements.edit', $announcementId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.announcement-saved'));
    }

    public function destroy(string $announcementId): RedirectResponse
    {
        $this->requireAnnouncement($announcementId);

        $this->repository->delete($announcementId);

        return redirect()
            ->route('sitmc-launcher.admin.announcements.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.announcement-deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?string $announcementId = null): array
    {
        $unique = 'unique:'.AnnouncementRepository::table().',announcement_id';

        if ($announcementId !== null) {
            $unique .= ','.$announcementId.',announcement_id';
        }

        $values = $request->validate([
            'announcement_id' => [
                'required', 'string', 'max:'.AnnouncementRules::MAX_ID_LENGTH,
                'regex:/^'.AnnouncementRules::ID_PATTERN.'$/', $unique,
            ],
            'title' => ['required', 'string', 'max:'.AnnouncementRules::MAX_TITLE_LENGTH],
            'summary' => ['nullable', 'string', 'max:'.AnnouncementRules::MAX_SUMMARY_LENGTH],
            'content' => ['required', 'string', 'max:'.AnnouncementRules::MAX_CONTENT_LENGTH],
            'type' => ['required', 'in:'.implode(',', AnnouncementRules::TYPES)],
            'priority' => ['required', 'in:'.implode(',', AnnouncementRules::PRIORITIES)],
            'starts_at' => ['required', 'string', 'max:32'],
            'ends_at' => ['nullable', 'string', 'max:32'],
            'published_at' => ['required', 'string', 'max:32'],
            'action_url' => ['nullable', 'string', 'max:512'],
            'action_label' => ['nullable', 'string', 'max:'.AnnouncementRules::MAX_ACTION_LABEL_LENGTH],
            'enabled' => ['nullable', 'boolean'],
        ]);

        // The client silently drops an entry whose link is not a plain http(s)
        // URL, so the form refuses it here instead of publishing a dead entry.
        $problems = array_filter([
            'action_url' => AnnouncementRules::actionUrlProblem($values['action_url'] ?? null),
            'starts_at' => AnnouncementRules::timestampProblem('starts-at', $values['starts_at']),
            'ends_at' => AnnouncementRules::timestampProblem('ends-at', $values['ends_at'] ?? null, false),
            'published_at' => AnnouncementRules::timestampProblem('published-at', $values['published_at']),
        ]);

        if ($problems !== []) {
            throw ValidationException::withMessages(self::messages($problems));
        }

        return [
            'announcement_id' => $values['announcement_id'],
            'title' => $values['title'],
            'summary' => self::textOrNull($values['summary'] ?? null),
            'content' => $values['content'],
            'type' => $values['type'],
            'priority' => $values['priority'],
            'starts_at' => Timestamps::fromInput($values['starts_at']),
            'ends_at' => Timestamps::fromInput($values['ends_at'] ?? null),
            'published_at' => Timestamps::fromInput($values['published_at']),
            'action_url' => self::textOrNull($values['action_url'] ?? null),
            'action_label' => self::textOrNull($values['action_label'] ?? null),
            'enabled' => (bool) ($values['enabled'] ?? false),
        ];
    }

    /**
     * `datetime-local` inputs carry a wall-clock string, so the admin form needs
     * the value back in exactly that shape after a failed submission.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function forForm(array $row): array
    {
        foreach (['starts_at', 'ends_at', 'published_at'] as $field) {
            $row[$field.'_input'] = Timestamps::inputValue($row[$field] ?? null);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAnnouncement(string $announcementId): array
    {
        $row = $this->repository->row($announcementId);

        if ($row === null) {
            abort(404);
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $problems
     * @return array<string, string>
     */
    private static function messages(array $problems): array
    {
        return array_map(
            static fn (string $problem): string => trans('sitmc-launcher::launcher.problem.'.$problem),
            $problems
        );
    }

    private static function textOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
