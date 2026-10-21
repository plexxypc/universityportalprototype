<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<AcademicSession>
 */
class AcademicSessionFactory extends Factory
{
    /**
     * Next first year. Names stay in 3000/3001 through 9998/9999.
     */
    private static int $next_start_year = 2999;

    /**
     * Define the model's default state.
     *
     * current_flag is omitted on purpose. MySQL generates it from is_current.
     * is_current defaults to false so more than one session can be created.
     * The name is a unique consecutive pair, which is the only shape the
     * session rule accepts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->consecutiveName(),
            'is_current' => false,
        ];
    }

    /**
     * One unused name whose second year is the next year.
     */
    private function consecutiveName(): string
    {
        self::$next_start_year++;

        if (self::$next_start_year > 9998) {
            throw new RuntimeException('No unused academic session names remain.');
        }

        return self::$next_start_year.'/'.(self::$next_start_year + 1);
    }

    /**
     * Mark this session as the single current session.
     *
     * current_flag is generated. Only one row in the table may use this state.
     */
    public function current(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_current' => true,
        ]);
    }
}
