<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\StudentStatus;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Fixed demo rows for the local design preview table.
 *
 * This is not a student directory. It only sorts and pages a hard-coded list.
 */
final class DesignPreviewTable
{
    /**
     * Build the preview table state from a query string.
     *
     * @param  array<string, mixed>  $query
     * @return array{
     *     columns: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>,
     *     paginator: LengthAwarePaginator<int, array<string, mixed>>,
     *     sort: string,
     *     direction: string,
     *     q: string,
     *     status: string
     * }
     */
    public static function fromQuery(array $query, int $per_page = 25): array
    {
        $per_page = $per_page > 0 ? $per_page : 25;
        $sort = is_string($query['sort'] ?? null) ? $query['sort'] : 'name';
        $direction = ($query['direction'] ?? null) === 'desc' ? 'desc' : 'asc';
        $q = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        $status = is_string($query['status'] ?? null) ? $query['status'] : '';

        $sortable = ['name', 'matric', 'programme', 'balance'];

        if (! in_array($sort, $sortable, true)) {
            $sort = 'name';
        }

        $rows = self::filter(self::rows(), $q, $status);
        self::sortRows($rows, $sort, $direction);

        $page = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1;
        $page = max(1, $page);
        $offset = ($page - 1) * $per_page;
        $slice = array_slice($rows, $offset, $per_page);

        $paginator = new LengthAwarePaginator(
            $slice,
            count($rows),
            $per_page,
            $page,
            ['path' => '/design-preview', 'pageName' => 'page'],
        );
        $paginator->appends([
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
            'status' => $status,
        ]);

        return [
            'columns' => self::columns($sort, $direction, $q, $status),
            'rows' => $slice,
            'paginator' => $paginator,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
            'status' => $status,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(): array
    {
        $names = [
            'Ada Okonkwo', 'Chinedu Bello', 'Fatima Sule', 'Emeka Nwosu', 'Aisha Bello',
            'Tunde Adeyemi', 'Ngozi Eze', 'Ibrahim Musa', 'Chioma Okafor', 'Yusuf Danjuma',
            'Amaka Ike', 'Kunle Ajayi', 'Zainab Lawal', 'Ifeanyi Okeke', 'Halima Abubakar',
            'Segun Balogun', 'Nneka Umeh', 'Musa Garba', 'Blessing Ojo', 'Farouk Sani',
            'Kemi Adebayo', 'Obinna Chukwu', 'Amina Yusuf', 'Femi Oladipo', 'Chika Nnaji',
            'Sadiq Bello', 'Ijeoma Nwankwo', 'Babatunde Ogun', 'Ruth Danladi', 'Efe Omoregie',
        ];
        $programmes = ['Computer Science', 'Accounting', 'Law'];
        $statuses = StudentStatus::cases();
        $rows = [];

        foreach ($names as $index => $name) {
            $number = $index + 1;
            $rows[] = [
                'name' => $name,
                'matric' => sprintf('CSC/2026/%03d', $number),
                'programme' => $programmes[$index % count($programmes)],
                'status' => $statuses[$index % count($statuses)],
                'balance' => ($index % 4) * 2_500_000,
                'view_url' => '#student-'.$number,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function filter(array $rows, string $q, string $status): array
    {
        $needle = mb_strtolower($q);

        return array_values(array_filter($rows, function (array $row) use ($needle, $status): bool {
            $status_value = $row['status'] instanceof StudentStatus ? $row['status']->value : '';
            $text = mb_strtolower($row['name'].' '.$row['matric']);
            $matches_text = $needle === '' || str_contains($text, $needle);
            $matches_status = $status === '' || $status_value === $status;

            return $matches_text && $matches_status;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function sortRows(array &$rows, string $sort, string $direction): void
    {
        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $result = $left[$sort] <=> $right[$sort];

            return $direction === 'desc' ? -$result : $result;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function columns(string $sort, string $direction, string $q, string $status): array
    {
        $definitions = [
            ['key' => 'name', 'label' => 'Name', 'important' => true, 'sortable' => true, 'format' => 'text'],
            ['key' => 'matric', 'label' => 'Matric number', 'important' => true, 'sortable' => true, 'format' => 'text'],
            ['key' => 'programme', 'label' => 'Programme', 'important' => true, 'sortable' => true, 'format' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'important' => true, 'sortable' => false, 'format' => 'badge'],
            ['key' => 'balance', 'label' => 'Balance', 'important' => false, 'sortable' => true, 'format' => 'money'],
            ['key' => 'view', 'label' => 'View', 'important' => false, 'sortable' => false, 'format' => 'view'],
        ];

        foreach ($definitions as $index => $column) {
            if (! $column['sortable']) {
                $definitions[$index]['href'] = null;

                continue;
            }

            $next = ($sort === $column['key'] && $direction === 'asc') ? 'desc' : 'asc';
            $definitions[$index]['href'] = '/design-preview?'.http_build_query([
                'sort' => $column['key'],
                'direction' => $next,
                'q' => $q,
                'status' => $status,
                'page' => 1,
            ]);
        }

        return $definitions;
    }
}
