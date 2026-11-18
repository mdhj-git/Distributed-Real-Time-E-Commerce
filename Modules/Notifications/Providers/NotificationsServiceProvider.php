<?php

namespace Modules\Notifications\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Notifications\Models\UserNotification;
use Modules\Notifications\Policies\UserNotificationPolicy;
use Modules\Notifications\Services\Email\EmailSender;
use Modules\Notifications\Services\Email\MailEmailSender;
use Nwidart\Modules\Support\ModuleServiceProvider;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notifications';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notifications';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // The email service is chosen here, and nowhere else. The transport (log or smtp) comes from MAIL_MAILER.
        $this->app->bind(EmailSender::class, MailEmailSender::class);
    }

    public function boot(): void
    {
        parent::boot();

        Gate::policy(UserNotification::class, UserNotificationPolicy::class);
    }
}
