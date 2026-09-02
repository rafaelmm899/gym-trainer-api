<?php

namespace App\Data\Profile;

use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class AthleteProfileData extends Data
{
    public function __construct(
        public readonly ExperienceLevel $experienceLevel,
        public readonly int $daysPerWeek,
        public readonly int $sessionMinutes,
        public readonly Goal $goal,
        public readonly ?string $notes = null,
    ) {}
}
