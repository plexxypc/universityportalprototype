<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Programme;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Fee categories and the prices charged for a programme, level, and session.
 *
 * A category that is already on a price or an invoice is deactivated instead
 * of deleted. Changing a price does not rewrite invoice lines. Those lines
 * keep the kobo amount they were issued with.
 */
class FeeService
{
    public const string CATEGORY_CREATED = 'fee_category.created';

    public const string CATEGORY_UPDATED = 'fee_category.updated';

    public const string CATEGORY_DEACTIVATED = 'fee_category.deactivated';

    public const string CATEGORY_DELETED = 'fee_category.deleted';

    public const string STRUCTURE_CREATED = 'fee_structure.created';

    public const string STRUCTURE_UPDATED = 'fee_structure.updated';

    public const string MESSAGE_IN_USE = 'This category is in use, so it was deactivated.';

    /**
     * Remember the audit writer.
     */
    public function __construct(private readonly Audit $audit) {}

    /**
     * Create a category. Name and code are each unique.
     */
    public function createCategory(User $actor, string $name, string $code, ?string $description, int $sort, ?Request $request = null): FeeCategory
    {
        $this->assertCanManage($actor);
        $name = $this->name($name, null);
        $code = $this->code($code, null);

        return DB::transaction(function () use ($actor, $name, $code, $description, $sort, $request): FeeCategory {
            $category = FeeCategory::query()->create([
                'name' => $name,
                'code' => $code,
                'description' => $this->description($description),
                'is_active' => true,
                'sort' => $this->sort($sort),
            ]);

            $this->audit->record(
                $actor->id,
                self::CATEGORY_CREATED,
                'fee_categories',
                (int) $category->getKey(),
                null,
                $this->categorySnapshot($category),
                $this->audit->ipFromRequest($request),
            );

            return $category;
        });
    }

    /**
     * Update a category. A forged id changes nothing.
     */
    public function updateCategory(User $actor, int $category_id, string $name, string $code, ?string $description, int $sort, bool $is_active, ?Request $request = null): FeeCategory
    {
        $this->assertCanManage($actor);
        $category = $this->category($category_id);
        $name = $this->name($name, $category->id);
        $code = $this->code($code, $category->id);

        return DB::transaction(function () use ($actor, $category, $name, $code, $description, $sort, $is_active, $request): FeeCategory {
            $before = $this->categorySnapshot($category);
            $category->fill([
                'name' => $name,
                'code' => $code,
                'description' => $this->description($description),
                'is_active' => $is_active,
                'sort' => $this->sort($sort),
            ]);
            $category->save();

            $this->audit->record(
                $actor->id,
                self::CATEGORY_UPDATED,
                'fee_categories',
                (int) $category->getKey(),
                $before,
                $this->categorySnapshot($category),
                $this->audit->ipFromRequest($request),
            );

            return $category;
        });
    }

    /**
     * Delete an unused category. A category on a price or an invoice is deactivated.
     */
    public function deleteCategory(User $actor, int $category_id, ?Request $request = null): string
    {
        $this->assertCanManage($actor);
        $category = $this->category($category_id);

        return DB::transaction(function () use ($actor, $category, $request): string {
            $before = $this->categorySnapshot($category);
            $in_use = $category->feeStructures()->exists() || $category->invoiceItems()->exists();

            if ($in_use) {
                $category->is_active = false;
                $category->save();

                $this->audit->record(
                    $actor->id,
                    self::CATEGORY_DEACTIVATED,
                    'fee_categories',
                    (int) $category->getKey(),
                    $before,
                    $this->categorySnapshot($category),
                    $this->audit->ipFromRequest($request),
                );

                return self::MESSAGE_IN_USE;
            }

            $key = (int) $category->getKey();
            $category->delete();

            $this->audit->record(
                $actor->id,
                self::CATEGORY_DELETED,
                'fee_categories',
                $key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );

            return 'deleted';
        });
    }

    /**
     * Store a price. The amount is typed in Naira and kept as kobo.
     */
    public function createStructure(User $actor, int $category_id, int $programme_id, int $level, int $session_id, string $amount, ?Request $request = null): FeeStructure
    {
        $this->assertCanManage($actor);
        $this->category($category_id);
        $this->programme($programme_id);
        $this->session($session_id);
        $this->assertLevel($level);
        $this->assertUniqueStructure($category_id, $programme_id, $level, $session_id, null);
        $kobo = $this->kobo($amount);

        return DB::transaction(function () use ($actor, $category_id, $programme_id, $level, $session_id, $kobo, $request): FeeStructure {
            $structure = FeeStructure::query()->create([
                'fee_category_id' => $category_id,
                'programme_id' => $programme_id,
                'level' => $level,
                'session_id' => $session_id,
                'amount_kobo' => $kobo,
            ]);

            $this->audit->record(
                $actor->id,
                self::STRUCTURE_CREATED,
                'fee_structures',
                (int) $structure->getKey(),
                null,
                $this->structureSnapshot($structure),
                $this->audit->ipFromRequest($request),
            );

            return $structure;
        });
    }

    /**
     * Change a price. Issued invoice lines keep their own amount_kobo.
     */
    public function updateStructure(User $actor, int $structure_id, int $category_id, int $programme_id, int $level, int $session_id, string $amount, ?Request $request = null): FeeStructure
    {
        $this->assertCanManage($actor);
        $structure = $this->structure($structure_id);
        $this->category($category_id);
        $this->programme($programme_id);
        $this->session($session_id);
        $this->assertLevel($level);
        $this->assertUniqueStructure($category_id, $programme_id, $level, $session_id, $structure->id);
        $kobo = $this->kobo($amount);

        return DB::transaction(function () use ($actor, $structure, $category_id, $programme_id, $level, $session_id, $kobo, $request): FeeStructure {
            $before = $this->structureSnapshot($structure);
            $structure->fill([
                'fee_category_id' => $category_id,
                'programme_id' => $programme_id,
                'level' => $level,
                'session_id' => $session_id,
                'amount_kobo' => $kobo,
            ]);
            $structure->save();

            $this->audit->record(
                $actor->id,
                self::STRUCTURE_UPDATED,
                'fee_structures',
                (int) $structure->getKey(),
                $before,
                $this->structureSnapshot($structure),
                $this->audit->ipFromRequest($request),
            );

            return $structure;
        });
    }

    /**
     * Super Admin and Bursar. Registrar can view the screens and cannot save.
     */
    private function assertCanManage(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('fees.manage')) {
            throw new AuthorizationException('You cannot change fees.');
        }
    }

    private function category(int $category_id): FeeCategory
    {
        $category = FeeCategory::query()->find($category_id);

        if (! $category instanceof FeeCategory) {
            throw ValidationException::withMessages([
                'name' => 'That category was not found.',
            ]);
        }

        return $category;
    }

    private function structure(int $structure_id): FeeStructure
    {
        $structure = FeeStructure::query()->find($structure_id);

        if (! $structure instanceof FeeStructure) {
            throw ValidationException::withMessages([
                'amount' => 'That fee was not found.',
            ]);
        }

        return $structure;
    }

    private function programme(int $programme_id): void
    {
        if (! Programme::query()->whereKey($programme_id)->exists()) {
            throw ValidationException::withMessages([
                'programme_id' => 'Choose a programme.',
            ]);
        }
    }

    private function session(int $session_id): void
    {
        if (! AcademicSession::query()->whereKey($session_id)->exists()) {
            throw ValidationException::withMessages([
                'session_id' => 'Choose a session.',
            ]);
        }
    }

    private function name(string $name, ?int $ignore_id): string
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > 255) {
            throw ValidationException::withMessages([
                'name' => 'Enter a category name.',
            ]);
        }

        $query = FeeCategory::query()->where('name', $name);

        if ($ignore_id !== null) {
            $query->whereKeyNot($ignore_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'That category name is already in use.',
            ]);
        }

        return $name;
    }

    private function code(string $code, ?int $ignore_id): string
    {
        $code = trim($code);

        if ($code === '' || strlen($code) > 32) {
            throw ValidationException::withMessages([
                'code' => 'Enter a category code of at most 32 characters.',
            ]);
        }

        $query = FeeCategory::query()->where('code', $code);

        if ($ignore_id !== null) {
            $query->whereKeyNot($ignore_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => 'That category code is already in use.',
            ]);
        }

        return $code;
    }

    private function description(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : $description;
    }

    private function sort(int $sort): int
    {
        if ($sort < 0 || $sort > 65535) {
            throw ValidationException::withMessages([
                'sort' => 'Enter a sort order from 0 to 65535.',
            ]);
        }

        return $sort;
    }

    private function assertLevel(int $level): void
    {
        if (! in_array($level, [100, 200, 300, 400, 500, 600], true)) {
            throw ValidationException::withMessages([
                'level' => 'Choose a level from 100 to 600.',
            ]);
        }
    }

    private function assertUniqueStructure(int $category_id, int $programme_id, int $level, int $session_id, ?int $ignore_id): void
    {
        $query = FeeStructure::query()
            ->where('fee_category_id', $category_id)
            ->where('programme_id', $programme_id)
            ->where('level', $level)
            ->where('session_id', $session_id);

        if ($ignore_id !== null) {
            $query->whereKeyNot($ignore_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'amount' => 'That programme, level, session, and category already has a price.',
            ]);
        }
    }

    private function kobo(string $amount): int
    {
        try {
            $kobo = Money::parseNaira($amount);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => 'Enter an amount in naira, with at most two decimal places.',
            ]);
        }

        if ($kobo < 1) {
            throw ValidationException::withMessages([
                'amount' => 'Enter at least 0.01.',
            ]);
        }

        return $kobo;
    }

    /**
     * @return array<string, mixed>
     */
    private function categorySnapshot(FeeCategory $category): array
    {
        return [
            'name' => $category->name,
            'code' => $category->code,
            'description' => $category->description,
            'is_active' => $category->is_active,
            'sort' => $category->sort,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function structureSnapshot(FeeStructure $structure): array
    {
        return [
            'fee_category_id' => $structure->fee_category_id,
            'programme_id' => $structure->programme_id,
            'level' => $structure->level,
            'session_id' => $structure->session_id,
            'amount_kobo' => $structure->amount_kobo,
        ];
    }
}
