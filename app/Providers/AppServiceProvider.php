<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\StaffLogoutController;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePortalArea;
use App\Models\Applicant;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\Department;
use App\Models\Document;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Receipt;
use App\Models\Result;
use App\Models\Student;
use App\Policies\AttendanceRecordPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\CoursePolicy;
use App\Policies\CourseRegistrationPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\FacultyPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ProgrammePolicy;
use App\Policies\ReceiptPolicy;
use App\Policies\ResultPolicy;
use App\Policies\StudentPolicy;
use App\Support\DatabaseTls;
use App\Support\EnvironmentGuard;
use App\Support\Mail\BrevoTransport;
use App\Support\Rbac\Permissions;
use Filament\Auth\Http\Controllers\LogoutController as FilamentLogoutController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Filament's logout route is registered after the application boots.
        // Resolving its controller class runs AuthService instead.
        $this->app->bind(FilamentLogoutController::class, StaffLogoutController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(DatabaseTls::class)->apply();
        $this->app->make(EnvironmentGuard::class)->enforce();

        Relation::enforceMorphMap([
            'Applicant' => Applicant::class,
            'Student' => Student::class,
        ]);

        Model::preventLazyLoading(! $this->app->isProduction());

        Permissions::registerGates();
        $this->registerPolicies();
        $this->registerMailTransport();

        Livewire::addPersistentMiddleware([
            EnsureActive::class,
            EnsurePasswordChanged::class,
            EnsurePortalArea::class,
        ]);
    }

    /**
     * Register the Brevo HTTPS transport. Log and array stay Laravel's own.
     */
    private function registerMailTransport(): void
    {
        Mail::extend('brevo', function (): BrevoTransport {
            $timeout = (int) config('mail.mailers.brevo.timeout');

            return new BrevoTransport(
                api_url: (string) config('mail.mailers.brevo.api_url'),
                api_key: (string) config('mail.mailers.brevo.key'),
                timeout_seconds: $timeout > 0 ? $timeout : 10,
            );
        });
    }

    /**
     * Register the model policies so the gate and Filament resolve them.
     */
    private function registerPolicies(): void
    {
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(CourseRegistration::class, CourseRegistrationPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Receipt::class, ReceiptPolicy::class);
        Gate::policy(Result::class, ResultPolicy::class);
        Gate::policy(AttendanceRecord::class, AttendanceRecordPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Notification::class, NotificationPolicy::class);
        Gate::policy(Faculty::class, FacultyPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Programme::class, ProgrammePolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
    }
}
