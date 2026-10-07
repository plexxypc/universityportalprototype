# Services

Entry points stay thin. Controllers, Livewire actions, and Filament actions validate input, authorise the action, call a service, and return a response.

Services hold the business logic: database transactions, audit writes, email enqueueing, and provider calls. Views do not change records or call providers directly.
