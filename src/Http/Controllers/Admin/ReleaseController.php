<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SITMinecraft\Launcher\Support\Timestamps;
use SITMinecraft\Launcher\Update\ReleaseRepository;
use SITMinecraft\Launcher\Update\ReleaseRules;

/**
 * Admin screens for the launcher's own releases.
 *
 * A release feeds two documents with different requirements, so the list tells
 * them apart: a row missing a channel, version or date is never published, while
 * a row without an artifact and signature still answers `/update/latest` but
 * cannot be installed by the dynamic updater.
 */
final class ReleaseController
{
    /**
     * Shown on the release form so an administrator can copy the URL template
     * into the updater configuration without guessing the placeholders.
     */
    private const DYNAMIC_PATH = '/api/launcher/update/{{target}}/{{arch}}/{{current_version}}';

    public function __construct(private ReleaseRepository $repository) {}

    public function index(): View
    {
        $releases = [];

        foreach ($this->repository->rows() as $row) {
            $row['metadata_problem'] = ReleaseRules::metadataProblem($row);
            $row['artifact_problem'] = ReleaseRules::artifactProblem($row);
            $releases[] = $row;
        }

        return view('sitmc-launcher::admin.releases', [
            'releases' => $releases,
            'latestPath' => '/api/launcher/update/latest',
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function create(): View
    {
        return view('sitmc-launcher::admin.release', [
            'release' => null,
            'problems' => [],
            'nowInput' => Timestamps::inputValue(now()),
            'dynamicPath' => self::DYNAMIC_PATH,
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
            ->route('sitmc-launcher.admin.releases.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.release-created'));
    }

    public function edit(int $id): View
    {
        $row = $this->requireRelease($id);

        return view('sitmc-launcher::admin.release', [
            'release' => self::forForm($row),
            'problems' => ReleaseRules::problems($row),
            'nowInput' => Timestamps::inputValue(now()),
            'dynamicPath' => self::DYNAMIC_PATH,
            'status' => session('sitmc_launcher_status'),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->requireRelease($id);

        $values = $this->validated($request);
        $values['id'] = $id;
        $values['updated_at'] = now();

        $this->repository->save($values);

        return redirect()
            ->route('sitmc-launcher.admin.releases.edit', $id)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.release-saved'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->requireRelease($id);

        $this->repository->delete($id);

        return redirect()
            ->route('sitmc-launcher.admin.releases.index')
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.release-deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $values = $request->validate([
            'channel' => ['required', 'in:'.implode(',', ReleaseRules::CHANNELS)],
            'version' => [
                'required', 'string', 'max:64',
                'regex:/^'.ReleaseRules::VERSION_PATTERN.'$/',
            ],
            'notes' => ['nullable', 'string', 'max:'.ReleaseRules::MAX_NOTES_LENGTH],
            'pub_date' => ['required', 'string', 'max:32'],
            'url' => ['nullable', 'string', 'max:512'],
            'signature' => ['nullable', 'string', 'max:2000'],
            'target' => [
                'nullable', 'string', 'max:64',
                'regex:/^'.ReleaseRules::PLATFORM_PATTERN.'$/',
            ],
            'arch' => [
                'nullable', 'string', 'max:64',
                'regex:/^'.ReleaseRules::PLATFORM_PATTERN.'$/',
            ],
            'enabled' => ['nullable', 'boolean'],
        ]);

        // An artifact URL is optional — a release may only announce a version —
        // but once it is there the dynamic updater needs a signature with it.
        $problems = [];
        $problem = ReleaseRules::timestampProblem('pub-date', $values['pub_date']);

        if ($problem !== null) {
            $problems['pub_date'] = $problem;
        }

        if (trim((string) ($values['url'] ?? '')) !== '') {
            $problem = ReleaseRules::artifactUrlProblem($values['url']);

            if ($problem !== null) {
                $problems['url'] = $problem;
            }

            $problem = ReleaseRules::signatureProblem($values);

            if ($problem !== null) {
                $problems['signature'] = $problem;
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages(array_map(
                static fn (string $problem): string => trans('sitmc-launcher::launcher.problem.'.$problem),
                $problems
            ));
        }

        return [
            'channel' => $values['channel'],
            'version' => trim($values['version']),
            'notes' => self::textOrNull($values['notes'] ?? null),
            'pub_date' => Timestamps::fromInput($values['pub_date']),
            'url' => self::textOrNull($values['url'] ?? null),
            'signature' => self::textOrNull($values['signature'] ?? null),
            'target' => self::textOrNull($values['target'] ?? null),
            'arch' => self::textOrNull($values['arch'] ?? null),
            'enabled' => (bool) ($values['enabled'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function forForm(array $row): array
    {
        $row['pub_date_input'] = Timestamps::inputValue($row['pub_date'] ?? null);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRelease(int $id): array
    {
        $row = $this->repository->row($id);

        if ($row === null) {
            abort(404);
        }

        return $row;
    }

    private static function textOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
