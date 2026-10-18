<div id="student-profile">
    <x-page-header title="Profile" description="You can update your phone, address, and guardian contact." />

    <x-card title="Your record">
        <dl class="grid gap-space-12 sm:grid-cols-2">
            <div>
                <dt class="text-small font-normal text-muted">Name</dt>
                <dd class="text-body font-semibold text-text">{{ $full_name }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Email</dt>
                <dd class="text-body font-semibold text-text">{{ $student->user?->email }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Matric number</dt>
                <dd class="text-body font-semibold text-text tabular-nums">{{ $student->matric_no }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Programme</dt>
                <dd class="text-body font-semibold text-text">{{ $student->programme?->name }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Level</dt>
                <dd class="text-body font-semibold text-text tabular-nums">{{ $student->level }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Date of birth</dt>
                <dd class="text-body font-semibold text-text">{{ \App\Support\Dates::date($student->date_of_birth) }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">Gender</dt>
                <dd class="text-body font-semibold text-text">{{ $student->gender?->value }}</dd>
            </div>
            <div>
                <dt class="text-small font-normal text-muted">State of origin</dt>
                <dd class="text-body font-semibold text-text">{{ $student->state_of_origin }}</dd>
            </div>
        </dl>
    </x-card>

    <form wire:submit="save" class="mt-space-16 flex flex-col gap-space-16">
        <x-card title="Contact">
            <div class="flex flex-col gap-space-16">
                <x-field name="phone" label="Phone" :error="$errors->first('phone')">
                    <x-input name="phone" type="tel" inputmode="tel" wire:model="phone" :value="$phone" autocomplete="tel" />
                </x-field>
                <x-field name="address" label="Address" :error="$errors->first('address')">
                    <x-textarea name="address" wire:model="address">{{ $address }}</x-textarea>
                </x-field>
            </div>
        </x-card>

        <x-card title="Guardian">
            @if ($guardians === [])
                <x-empty-state message="No guardian is on your record. Ask the Registrar to add one." />
            @else
                <div class="flex flex-col gap-space-16">
                    @foreach ($guardians as $index => $guardian)
                        <fieldset class="flex flex-col gap-space-12">
                            <legend class="text-body font-semibold text-text">{{ $guardian['name'] ?? '' }} · {{ $guardian['relationship'] ?? '' }}</legend>
                            <x-field :name="'guardian_phone_'.($guardian['id'] ?? $index)" label="Guardian phone" :error="$errors->first('phone')">
                                <x-input :name="'guardian_phone_'.($guardian['id'] ?? $index)" type="tel" inputmode="tel" wire:model="guardians.{{ $index }}.phone" :value="$guardian['phone'] ?? ''" />
                            </x-field>
                            <x-field :name="'guardian_email_'.($guardian['id'] ?? $index)" label="Guardian email" :error="$errors->first('email')">
                                <x-input :name="'guardian_email_'.($guardian['id'] ?? $index)" type="email" inputmode="email" wire:model="guardians.{{ $index }}.email" :value="$guardian['email'] ?? ''" />
                            </x-field>
                        </fieldset>
                    @endforeach
                </div>
            @endif
        </x-card>

        <div>
            <x-button type="submit">Save</x-button>
        </div>
    </form>
</div>
