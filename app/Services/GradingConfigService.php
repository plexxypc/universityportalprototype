<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RepeatPolicy;
use App\Enums\ResultStatus;
use App\Models\AcademicStandingThreshold;
use App\Models\AssessmentComponent;
use App\Models\ClassificationBand;
use App\Models\GradeBand;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\User;
use App\Support\Audit;
use App\Support\Grading\DefaultScheme;
use App\Support\Grading\Hundredths;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves a new grading-scheme version and keeps unpublished results on it.
 *
 * The default numbers are inserted only when loadDefault() is called and the
 * table is empty. A migration does not insert them.
 */
class GradingConfigService
{
    public const string CACHE_KEY = 'grading.active';

    public const string VERSIONED = 'grading_scheme.versioned';

    /**
     * Remember the audit writer.
     */
    public function __construct(private readonly Audit $audit) {}

    /**
     * The active scheme, cached until the next successful save.
     */
    public function activeScheme(): ?GradingScheme
    {
        $cached = Cache::rememberForever(self::CACHE_KEY, function (): int {
            return (int) (GradingScheme::query()->active()->value('id') ?? 0);
        });

        if ($cached === 0) {
            return null;
        }

        $scheme = GradingScheme::query()->find($cached);

        return $scheme instanceof GradingScheme ? $scheme : null;
    }

    /**
     * Insert the Nigerian 5-point scheme. Refused once any scheme exists.
     */
    public function loadDefault(User $actor, ?Request $request = null): GradingScheme
    {
        if (GradingScheme::query()->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A grading scheme already exists.',
            ]);
        }

        return $this->saveVersion($actor, $this->defaultInput(), $request);
    }

    /**
     * Grade up to three sample courses from the form. This writes nothing.
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *     courses: list<array{total: string, letter: string, points: string, passed: bool}>,
     *     gpa: string|null,
     *     classification: string|null
     * }
     */
    public function preview(array $input): array
    {
        $parsed = $this->parsed($input);
        $samples = $input['preview_courses'] ?? null;

        if (! is_array($samples) || $samples === [] || count($samples) > 3) {
            throw ValidationException::withMessages([
                'preview_courses' => 'Enter one, two, or three sample courses.',
            ]);
        }

        $grading = new GradingService;
        $bands = [];

        foreach ($parsed['bands'] as $band) {
            $bands[] = [
                'min_hundredths' => $band['min'],
                'max_hundredths' => $band['max'],
                'letter' => $band['letter'],
                'points_hundredths' => $band['points'],
                'remark' => $band['remark'],
            ];
        }

        $classes = [];

        foreach ($parsed['classifications'] as $class) {
            $classes[] = [
                'min_hundredths' => $class['min'],
                'max_hundredths' => $class['max'],
                'name' => $class['name'],
            ];
        }

        $lines = [];
        $averages = [];

        foreach ($samples as $sample) {
            if (! is_array($sample)) {
                throw ValidationException::withMessages([
                    'preview_courses' => 'Each sample course needs credit units and a score for every component.',
                ]);
            }

            $units = $this->units($sample);
            $scores = $this->sampleScores($sample, $parsed['components']);
            $total = $grading->total($scores);
            $grade = $grading->grade($total, $bands, $parsed['pass_mark']);
            $lines[] = [
                'total' => Hundredths::toDecimal($total),
                'letter' => $grade['letter'],
                'points' => Hundredths::toDecimal($grade['points_hundredths']),
                'passed' => $grade['passed'],
            ];
            $averages[] = [
                'points_hundredths' => $grade['points_hundredths'],
                'units' => $units,
            ];
        }

        $gpa = $grading->average($averages);

        return [
            'courses' => $lines,
            'gpa' => $gpa === null ? null : Hundredths::toDecimal($gpa),
            'classification' => $grading->classification($gpa, $classes),
        ];
    }

    /**
     * Validate the form and store the next version in one transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveVersion(User $actor, array $input, ?Request $request = null): GradingScheme
    {
        if (! Gate::forUser($actor)->allows('grading_configuration.manage')) {
            throw new AuthorizationException('You cannot change the grading scheme.');
        }

        $parsed = $this->parsed($input);

        $scheme = DB::transaction(function () use ($actor, $parsed, $request): GradingScheme {
            $previous = GradingScheme::query()->active()->lockForUpdate()->first();
            $before = $previous instanceof GradingScheme ? $this->snapshot($previous) : null;

            if ($previous instanceof GradingScheme) {
                $this->assertComponentsCanMove($previous, $parsed['components']);
                $previous->is_active = false;
                $previous->save();
            }

            $next_version = ((int) GradingScheme::query()->max('version')) + 1;
            $scheme = GradingScheme::query()->create([
                'name' => $parsed['name'],
                'pass_mark' => Hundredths::toDecimal($parsed['pass_mark']),
                'version' => $next_version,
                'is_active' => true,
                'repeat_policy' => $parsed['repeat_policy'],
                'resit_points_cap' => $parsed['resit_cap'] === null ? null : Hundredths::toDecimal($parsed['resit_cap']),
            ]);

            $this->writeChildren($scheme, $parsed);

            if ($previous instanceof GradingScheme) {
                $this->repointUnpublished($previous, $scheme);
            }

            $this->audit->record(
                $actor->id,
                self::VERSIONED,
                'grading_schemes',
                (int) $scheme->getKey(),
                $before,
                $this->snapshot($scheme),
                $this->audit->ipFromRequest($request),
            );

            return $scheme;
        });

        Cache::forget(self::CACHE_KEY);

        return $scheme;
    }

    /**
     * Form values for the active scheme, or an empty form when none exists.
     *
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        $scheme = $this->activeScheme();

        if (! $scheme instanceof GradingScheme) {
            return $this->blankForm();
        }

        $scheme->load(['assessmentComponents', 'gradeBands', 'classificationBands', 'standing']);

        $standing = $scheme->standing;
        $components = [];

        foreach ($scheme->assessmentComponents->sortBy('sort') as $component) {
            $components[] = [
                'name' => $component->name,
                'max_score' => (string) $component->max_score,
            ];
        }

        $bands = [];

        foreach ($scheme->gradeBands->sortBy('min_score') as $band) {
            $bands[] = [
                'letter' => $band->letter,
                'min_score' => (string) $band->min_score,
                'max_score' => (string) $band->max_score,
                'points' => (string) $band->points,
                'remark' => $band->remark,
            ];
        }

        $classes = [];

        foreach ($scheme->classificationBands->sortBy('min_cgpa') as $class) {
            $classes[] = [
                'name' => $class->name,
                'min_cgpa' => (string) $class->min_cgpa,
                'max_cgpa' => (string) $class->max_cgpa,
            ];
        }

        return [
            'name' => $scheme->name,
            'pass_mark' => (string) $scheme->pass_mark,
            'repeat_policy' => $scheme->repeat_policy->value,
            'resit_points_cap' => $scheme->resit_points_cap === null ? '' : (string) $scheme->resit_points_cap,
            'track_standing' => $standing instanceof AcademicStandingThreshold,
            'warning_cgpa' => $standing instanceof AcademicStandingThreshold && $standing->warning_cgpa !== null
                ? (string) $standing->warning_cgpa
                : '',
            'probation_cgpa' => $standing instanceof AcademicStandingThreshold && $standing->probation_cgpa !== null
                ? (string) $standing->probation_cgpa
                : '',
            'components' => $components,
            'bands' => $bands,
            'classifications' => $classes,
        ];
    }

    /**
     * The default form, used only by the button when the table is empty.
     *
     * @return array<string, mixed>
     */
    public function defaultInput(): array
    {
        $components = [];

        foreach (DefaultScheme::components() as $component) {
            $components[] = [
                'name' => $component['name'],
                'max_score' => Hundredths::toDecimal($component['max_hundredths']),
            ];
        }

        $bands = [];

        foreach (DefaultScheme::bands() as $band) {
            $bands[] = [
                'letter' => $band['letter'],
                'min_score' => Hundredths::toDecimal($band['min_hundredths']),
                'max_score' => Hundredths::toDecimal($band['max_hundredths']),
                'points' => Hundredths::toDecimal($band['points_hundredths']),
                'remark' => $band['remark'],
            ];
        }

        $classes = [];

        foreach (DefaultScheme::classifications() as $class) {
            $classes[] = [
                'name' => $class['name'],
                'min_cgpa' => Hundredths::toDecimal($class['min_hundredths']),
                'max_cgpa' => Hundredths::toDecimal($class['max_hundredths']),
            ];
        }

        return [
            'name' => DefaultScheme::NAME,
            'pass_mark' => Hundredths::toDecimal(DefaultScheme::passMarkHundredths()),
            'repeat_policy' => RepeatPolicy::Latest->value,
            'resit_points_cap' => '',
            'track_standing' => false,
            'warning_cgpa' => '',
            'probation_cgpa' => '',
            'components' => $components,
            'bands' => $bands,
            'classifications' => $classes,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     name: string,
     *     pass_mark: int,
     *     repeat_policy: RepeatPolicy,
     *     resit_cap: int|null,
     *     components: list<array{name: string, max: int}>,
     *     bands: list<array{min: int, max: int, letter: string, points: int, remark: string}>,
     *     classifications: list<array{name: string, min: int, max: int}>,
     *     warning: int|null,
     *     probation: int|null,
     *     track_standing: bool
     * }
     */
    private function parsed(array $input): array
    {
        $name = trim($this->text($input, 'name'));

        if ($name === '' || strlen($name) > 64) {
            throw ValidationException::withMessages([
                'name' => 'Enter a scheme name of at most 64 characters.',
            ]);
        }

        $policy = RepeatPolicy::tryFrom($this->text($input, 'repeat_policy'));

        if (! $policy instanceof RepeatPolicy) {
            throw ValidationException::withMessages([
                'repeat_policy' => 'Choose Latest or Best.',
            ]);
        }

        $components = $this->components($input);
        $bands = $this->bands($input);
        $pass_mark = $this->decimal($input, 'pass_mark', 'Enter the pass mark with at most two decimal places.');
        $this->assertPassMark($bands, $pass_mark);
        $classifications = $this->classifications($input, $bands);
        $resit_cap = $this->optionalDecimal($input, 'resit_points_cap', 'Enter the resit cap with at most two decimal places.');
        $standing = $this->standing($input, $bands);

        if ($resit_cap !== null) {
            $highest = 0;

            foreach ($bands as $band) {
                $highest = max($highest, $band['points']);
            }

            if ($resit_cap > $highest) {
                throw ValidationException::withMessages([
                    'resit_points_cap' => 'The resit cap cannot be above the highest grade point.',
                ]);
            }
        }

        return [
            'name' => $name,
            'pass_mark' => $pass_mark,
            'repeat_policy' => $policy,
            'resit_cap' => $resit_cap,
            'components' => $components,
            'bands' => $bands,
            'classifications' => $classifications,
            'warning' => $standing['warning'],
            'probation' => $standing['probation'],
            'track_standing' => $standing['track'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{name: string, max: int}>
     */
    private function components(array $input): array
    {
        $rows = $input['components'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'components' => 'Add at least one assessment component.',
            ]);
        }

        $parsed = [];
        $names = [];
        $total = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'components' => 'Each component needs a name and a maximum.',
                ]);
            }

            $name = trim($this->text($row, 'name'));
            $max = $this->decimal($row, 'max_score', 'Enter each component maximum with at most two decimal places.');

            if ($name === '' || strlen($name) > 64) {
                throw ValidationException::withMessages([
                    'components' => 'Each component needs a name of at most 64 characters.',
                ]);
            }

            if (isset($names[$name])) {
                throw ValidationException::withMessages([
                    'components' => 'Component names must be unique.',
                ]);
            }

            if ($max < 1 || $max > 10_000) {
                throw ValidationException::withMessages([
                    'components' => 'Each component maximum must be above 0 and at most 100.00.',
                ]);
            }

            $names[$name] = true;
            $total += $max;
            $parsed[] = ['name' => $name, 'max' => $max];
        }

        if ($total !== 10_000) {
            throw ValidationException::withMessages([
                'components' => 'Component maximums must add up to 100.00.',
            ]);
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{min: int, max: int, letter: string, points: int, remark: string}>
     */
    private function bands(array $input): array
    {
        $rows = $input['bands'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'bands' => 'Add the grade bands.',
            ]);
        }

        $parsed = [];
        $letters = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'bands' => 'Each grade band needs a letter and a score range.',
                ]);
            }

            $letter = trim($this->text($row, 'letter'));
            $remark = trim($this->text($row, 'remark'));
            $min = $this->decimal($row, 'min_score', 'Enter each band minimum with at most two decimal places.');
            $max = $this->decimal($row, 'max_score', 'Enter each band maximum with at most two decimal places.');
            $points = $this->decimal($row, 'points', 'Enter each grade point with at most two decimal places.');

            if ($letter === '' || strlen($letter) > 8 || isset($letters[$letter])) {
                throw ValidationException::withMessages([
                    'bands' => 'Each letter must be unique and at most 8 characters.',
                ]);
            }

            if ($remark === '' || strlen($remark) > 64 || $min > $max) {
                throw ValidationException::withMessages([
                    'bands' => 'Each grade band needs a remark and a minimum that is not above its maximum.',
                ]);
            }

            $letters[$letter] = true;
            $parsed[] = [
                'min' => $min,
                'max' => $max,
                'letter' => $letter,
                'points' => $points,
                'remark' => $remark,
            ];
        }

        usort($parsed, fn (array $left, array $right): int => $left['min'] <=> $right['min']);
        $this->assertContiguous($parsed, 0, 10_000, 'bands', 'Grade bands must run from 0.00 to 100.00 without a gap.');

        return $parsed;
    }

    /**
     * @param  list<array{min: int, max: int, letter: string, points: int, remark: string}>  $bands
     */
    private function assertPassMark(array $bands, int $pass_mark): void
    {
        $fail_max = null;

        foreach ($bands as $band) {
            if ($band['min'] === 0) {
                $fail_max = $band['max'];
            }
        }

        if ($fail_max !== $pass_mark - 1) {
            throw ValidationException::withMessages([
                'pass_mark' => 'The pass mark must be one hundredth above the fail band.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array{min: int, max: int, letter: string, points: int, remark: string}>  $bands
     * @return list<array{name: string, min: int, max: int}>
     */
    private function classifications(array $input, array $bands): array
    {
        $rows = $input['classifications'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'classifications' => 'Add the classification bands.',
            ]);
        }

        $highest = 0;

        foreach ($bands as $band) {
            $highest = max($highest, $band['points']);
        }

        $parsed = [];
        $names = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'classifications' => 'Each class needs a name and a CGPA range.',
                ]);
            }

            $name = trim($this->text($row, 'name'));
            $min = $this->decimal($row, 'min_cgpa', 'Enter each class minimum with at most two decimal places.');
            $max = $this->decimal($row, 'max_cgpa', 'Enter each class maximum with at most two decimal places.');

            if ($name === '' || strlen($name) > 64 || isset($names[$name]) || $min > $max) {
                throw ValidationException::withMessages([
                    'classifications' => 'Each class name must be unique, and its minimum cannot be above its maximum.',
                ]);
            }

            $names[$name] = true;
            $parsed[] = ['name' => $name, 'min' => $min, 'max' => $max];
        }

        usort($parsed, fn (array $left, array $right): int => $left['min'] <=> $right['min']);
        $top = Hundredths::toDecimal($highest);
        $this->assertContiguous(
            $parsed,
            0,
            $highest,
            'classifications',
            'Classification bands must run from 0.00 to '.$top.' without a gap.',
        );

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array{min: int, max: int, letter: string, points: int, remark: string}>  $bands
     * @return array{track: bool, warning: int|null, probation: int|null}
     */
    private function standing(array $input, array $bands): array
    {
        $track = (bool) ($input['track_standing'] ?? false);
        $warning = $this->optionalDecimal($input, 'warning_cgpa', 'Enter the warning CGPA with at most two decimal places.');
        $probation = $this->optionalDecimal($input, 'probation_cgpa', 'Enter the probation CGPA with at most two decimal places.');

        if (! $track) {
            return ['track' => false, 'warning' => null, 'probation' => null];
        }

        if ($warning === null && $probation === null) {
            throw ValidationException::withMessages([
                'warning_cgpa' => 'Set a warning CGPA, a probation CGPA, or leave standing off.',
            ]);
        }

        $highest = 0;

        foreach ($bands as $band) {
            $highest = max($highest, $band['points']);
        }

        if (($warning !== null && $warning > $highest) || ($probation !== null && $probation > $highest)) {
            throw ValidationException::withMessages([
                'warning_cgpa' => 'Standing thresholds cannot be above the highest grade point.',
            ]);
        }

        if ($warning !== null && $probation !== null && $probation >= $warning) {
            throw ValidationException::withMessages([
                'probation_cgpa' => 'Probation must be below the warning CGPA.',
            ]);
        }

        return ['track' => true, 'warning' => $warning, 'probation' => $probation];
    }

    /**
     * @param  list<array{min: int, max: int}>  $rows
     */
    private function assertContiguous(array $rows, int $start, int $end, string $key, string $message): void
    {
        $cursor = $start;

        foreach ($rows as $row) {
            if ($row['min'] !== $cursor) {
                throw ValidationException::withMessages([$key => $message]);
            }

            $cursor = $row['max'] + 1;
        }

        if ($cursor !== $end + 1) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    /**
     * @param  list<array{name: string, max: int}>  $components
     */
    private function assertComponentsCanMove(GradingScheme $previous, array $components): void
    {
        $names = [];

        foreach ($components as $component) {
            $names[$component['name']] = true;
        }

        $scores = ResultScore::query()
            ->whereHas('result', function ($query) use ($previous): void {
                $query->where('grading_scheme_id', $previous->id)
                    ->where('status', '!=', ResultStatus::Published->value);
            })
            ->with('assessmentComponent')
            ->get();

        foreach ($scores as $score) {
            $name = $score->assessmentComponent?->name;

            if (! is_string($name) || ! isset($names[$name])) {
                $label = is_string($name) && $name !== '' ? $name : 'A component';

                throw ValidationException::withMessages([
                    'components' => $label.' still has an unpublished score, so it cannot be removed.',
                ]);
            }
        }
    }

    /**
     * @param  array{
     *     name: string,
     *     pass_mark: int,
     *     repeat_policy: RepeatPolicy,
     *     resit_cap: int|null,
     *     components: list<array{name: string, max: int}>,
     *     bands: list<array{min: int, max: int, letter: string, points: int, remark: string}>,
     *     classifications: list<array{name: string, min: int, max: int}>,
     *     warning: int|null,
     *     probation: int|null,
     *     track_standing: bool
     * }  $parsed
     */
    private function writeChildren(GradingScheme $scheme, array $parsed): void
    {
        foreach ($parsed['components'] as $index => $component) {
            AssessmentComponent::query()->create([
                'scheme_id' => $scheme->id,
                'name' => $component['name'],
                'max_score' => Hundredths::toDecimal($component['max']),
                'sort' => $index + 1,
            ]);
        }

        foreach ($parsed['bands'] as $band) {
            GradeBand::query()->create([
                'scheme_id' => $scheme->id,
                'min_score' => Hundredths::toDecimal($band['min']),
                'max_score' => Hundredths::toDecimal($band['max']),
                'letter' => $band['letter'],
                'points' => Hundredths::toDecimal($band['points']),
                'remark' => $band['remark'],
            ]);
        }

        foreach ($parsed['classifications'] as $class) {
            ClassificationBand::query()->create([
                'scheme_id' => $scheme->id,
                'name' => $class['name'],
                'min_cgpa' => Hundredths::toDecimal($class['min']),
                'max_cgpa' => Hundredths::toDecimal($class['max']),
            ]);
        }

        if (! $parsed['track_standing']) {
            return;
        }

        AcademicStandingThreshold::query()->create([
            'scheme_id' => $scheme->id,
            'warning_cgpa' => $parsed['warning'] === null ? null : Hundredths::toDecimal($parsed['warning']),
            'probation_cgpa' => $parsed['probation'] === null ? null : Hundredths::toDecimal($parsed['probation']),
        ]);
    }

    /**
     * Move unpublished results and their scores onto the new components.
     */
    private function repointUnpublished(GradingScheme $previous, GradingScheme $scheme): void
    {
        $new_ids = AssessmentComponent::query()
            ->where('scheme_id', $scheme->id)
            ->pluck('id', 'name');

        $scores = ResultScore::query()
            ->whereHas('result', function ($query) use ($previous): void {
                $query->where('grading_scheme_id', $previous->id)
                    ->where('status', '!=', ResultStatus::Published->value);
            })
            ->with('assessmentComponent')
            ->get();

        foreach ($scores as $score) {
            $name = $score->assessmentComponent?->name;
            $new_id = is_string($name) ? $new_ids->get($name) : null;

            if (! is_numeric($new_id)) {
                throw ValidationException::withMessages([
                    'components' => 'A component still has an unpublished score, so it cannot be removed.',
                ]);
            }

            $score->component_id = (int) $new_id;
            $score->save();
        }

        Result::query()
            ->where('grading_scheme_id', $previous->id)
            ->where('status', '!=', ResultStatus::Published->value)
            ->update([
                'grading_scheme_id' => $scheme->id,
                'scheme_version' => $scheme->version,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(GradingScheme $scheme): array
    {
        $scheme->load(['assessmentComponents', 'gradeBands', 'classificationBands', 'standing']);
        $standing = $scheme->standing;

        return [
            'version' => $scheme->version,
            'name' => $scheme->name,
            'pass_mark' => (string) $scheme->pass_mark,
            'is_active' => $scheme->is_active,
            'repeat_policy' => $scheme->repeat_policy->value,
            'resit_points_cap' => $scheme->resit_points_cap === null ? null : (string) $scheme->resit_points_cap,
            'components' => $scheme->assessmentComponents->sortBy('sort')->map(fn (AssessmentComponent $component): array => [
                'name' => $component->name,
                'max_score' => (string) $component->max_score,
            ])->values()->all(),
            'bands' => $scheme->gradeBands->sortBy('min_score')->map(fn (GradeBand $band): array => [
                'letter' => $band->letter,
                'min_score' => (string) $band->min_score,
                'max_score' => (string) $band->max_score,
                'points' => (string) $band->points,
            ])->values()->all(),
            'classifications' => $scheme->classificationBands->sortBy('min_cgpa')->map(fn (ClassificationBand $class): array => [
                'name' => $class->name,
                'min_cgpa' => (string) $class->min_cgpa,
                'max_cgpa' => (string) $class->max_cgpa,
            ])->values()->all(),
            'warning_cgpa' => $standing instanceof AcademicStandingThreshold && $standing->warning_cgpa !== null
                ? (string) $standing->warning_cgpa
                : null,
            'probation_cgpa' => $standing instanceof AcademicStandingThreshold && $standing->probation_cgpa !== null
                ? (string) $standing->probation_cgpa
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blankForm(): array
    {
        return [
            'name' => '',
            'pass_mark' => '',
            'repeat_policy' => RepeatPolicy::Latest->value,
            'resit_points_cap' => '',
            'track_standing' => false,
            'warning_cgpa' => '',
            'probation_cgpa' => '',
            'components' => [],
            'bands' => [],
            'classifications' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function text(array $input, string $key): string
    {
        $value = $input[$key] ?? '';

        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : '');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function decimal(array $input, string $key, string $message): int
    {
        try {
            return Hundredths::fromDecimal($this->text($input, $key));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }

    /**
     * Credit units for one sample course.
     *
     * @param  array<string, mixed>  $sample
     */
    private function units(array $sample): int
    {
        $units = $this->text($sample, 'units');

        if (preg_match('/^\d+$/', $units) !== 1) {
            throw ValidationException::withMessages([
                'preview_courses' => 'Enter credit units as a whole number from 1 to 255.',
            ]);
        }

        $value = (int) $units;

        if ($value < 1 || $value > 255) {
            throw ValidationException::withMessages([
                'preview_courses' => 'Enter credit units as a whole number from 1 to 255.',
            ]);
        }

        return $value;
    }

    /**
     * One score per component, in the same order, not above that component's maximum.
     *
     * @param  array<string, mixed>  $sample
     * @param  list<array{name: string, max: int}>  $components
     * @return list<int>
     */
    private function sampleScores(array $sample, array $components): array
    {
        $scores = $sample['scores'] ?? null;

        if (! is_array($scores)) {
            throw ValidationException::withMessages([
                'preview_courses' => 'Enter a score for every assessment component.',
            ]);
        }

        while ($scores !== []) {
            $last = end($scores);

            if ((is_string($last) || is_numeric($last)) && trim((string) $last) !== '') {
                break;
            }

            array_pop($scores);
        }

        if (count($scores) !== count($components)) {
            throw ValidationException::withMessages([
                'preview_courses' => 'Enter a score for every assessment component.',
            ]);
        }

        $parsed = [];

        foreach ($components as $index => $component) {
            $raw = $scores[$index] ?? null;

            if (! is_string($raw) && ! is_numeric($raw)) {
                throw ValidationException::withMessages([
                    'preview_courses' => 'Enter a score for every assessment component.',
                ]);
            }

            try {
                $score = Hundredths::fromDecimal((string) $raw);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'preview_courses' => 'Enter each sample score with at most two decimal places.',
                ]);
            }

            if ($score > $component['max']) {
                throw ValidationException::withMessages([
                    'preview_courses' => $component['name'].' cannot be above its maximum.',
                ]);
            }

            $parsed[] = $score;
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optionalDecimal(array $input, string $key, string $message): ?int
    {
        $value = trim($this->text($input, $key));

        if ($value === '') {
            return null;
        }

        try {
            return Hundredths::fromDecimal($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }
}
