<div id="student-course-registration">
    <x-page-header title="Course registration" description="Core courses stay on the registration. Tick the electives you want." />

    @if (session('registration_notice'))
        <p class="mb-space-16 text-body font-semibold text-text" role="status">{{ session('registration_notice') }}</p>
    @endif

    @if ($errors->any())
        <p class="mb-space-16 text-body font-semibold text-danger-text" role="alert">{{ $errors->first() }}</p>
    @endif

    @if (is_string($assessment['message']) && $assessment['message'] !== '')
        <p class="mb-space-16 text-body text-text">{{ $assessment['message'] }}</p>
    @endif

    @if ($status instanceof \App\Enums\RegistrationStatus)
        <div class="mb-space-16">
            <x-status-badge :status="$status" />
        </div>
    @endif

    @if (is_string($rejection_reason) && $rejection_reason !== '')
        <p class="mb-space-16 text-body text-text">{{ $rejection_reason }}</p>
    @endif

    <x-card title="Courses">
        @if ($assessment['courses'] === [])
            <x-empty-state message="No courses are available for this semester." />
        @else
            <ul class="flex flex-col gap-space-12">
                @foreach ($assessment['courses'] as $course)
                    <li wire:key="course-{{ $course['course_id'] }}">
                        @if ($course['locked'])
                            <x-checkbox
                                :name="'core-'.$course['course_id']"
                                :label="$course['code'].' · '.$course['title'].' · '.$course['credit_units'].' units · Core'"
                                checked
                                disabled
                            />
                        @elseif ($editable && $course['prerequisites_met'])
                            <x-checkbox
                                :name="'elective-'.$course['course_id']"
                                :label="$course['code'].' · '.$course['title'].' · '.$course['credit_units'].' units · Elective'"
                                :value="$course['course_id']"
                                wire:model.live="elective_ids"
                            />
                        @else
                            <x-checkbox
                                :name="'elective-'.$course['course_id']"
                                :label="$course['code'].' · '.$course['title'].' · '.$course['credit_units'].' units'.($course['prerequisites_met'] ? ' · Elective' : ' · Prerequisite not met')"
                                :checked="in_array((int) $course['course_id'], array_map('intval', $elective_ids), true)"
                                disabled
                            />
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <div class="sticky bottom-16 z-10 mt-space-16 rounded-card border border-border bg-surface p-space-16 shadow-sm md:bottom-0">
        <p class="text-body font-semibold text-text">
            {{ $total_units }} units
            <span class="font-normal text-muted">· {{ $assessment['min_units'] }} to {{ $assessment['max_units'] }}</span>
        </p>
        <div class="mt-space-12 flex flex-wrap gap-space-8">
            @if ($can_edit_start)
                <x-button type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft,submitRegistration">
                    Save draft
                </x-button>
                <x-button type="button" wire:click="submitRegistration" wire:loading.attr="disabled" wire:target="submitRegistration">
                    Submit
                </x-button>
            @endif
            @if ($can_edit_drop)
                <x-button type="button" wire:click="saveChanges" wire:loading.attr="disabled" wire:target="saveChanges">
                    Save changes
                </x-button>
            @endif
            @if ($slip_ready)
                <x-button href="{{ route('student.course-registration.slip') }}" variant="secondary">
                    Download slip
                </x-button>
            @endif
        </div>
    </div>
</div>
